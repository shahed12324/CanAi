import psycopg
import pytest

from conftest import as_admin, set_tenant, ulid
from test_mobile import HASH, device, pin_ok


@pytest.fixture(autouse=True)
def clean(owner, world):
    yield
    as_admin(owner)
    owner.execute("ALTER TABLE emergency_events DISABLE TRIGGER emergency_events_no_change")
    owner.execute("ALTER TABLE stock_movements DISABLE TRIGGER stock_movements_no_change")
    owner.execute("ALTER TABLE product_events DISABLE TRIGGER product_events_no_change")
    for t in ("stock_movements", "product_events", "product_variants", "products", "catalog_imports", "store_connections", "emergency_events", "mobile_devices"):
        owner.execute(f"DELETE FROM {t}")
    owner.execute("ALTER TABLE emergency_events ENABLE TRIGGER emergency_events_no_change")
    owner.execute("ALTER TABLE stock_movements ENABLE TRIGGER stock_movements_no_change")
    owner.execute("ALTER TABLE product_events ENABLE TRIGGER product_events_no_change")


def ctx(app, w, who="a"):
    set_tenant(app, w[who]["tenant"])
    return w[who]["user"]


def store(app, w, who="a"):
    i = ulid()
    app.execute("INSERT INTO store_connections (id,tenant_id,platform,name) VALUES (%s,%s,'native','Main')", (i, w[who]["tenant"]))
    return i


def product(app, w, who="a", title="Tee", price=50000, desc="Soft cotton", sku=None, stock=None):
    s = store(app, w, who) if not app.execute("SELECT 1 FROM store_connections").fetchone() else app.execute("SELECT id FROM store_connections").fetchone()[0]
    p = ulid()
    app.execute("INSERT INTO products (id,tenant_id,store_id,title,description,price_minor,sku) VALUES (%s,%s,%s,%s,%s,%s,%s)",
                (p, w[who]["tenant"], s, title, desc, price, sku))
    v = ulid()
    app.execute("INSERT INTO product_variants (id,tenant_id,product_id) VALUES (%s,%s,%s)", (v, w[who]["tenant"], p))
    if stock:
        app.execute("SELECT stock_adjust(%s,%s,'initial',NULL,%s)", (v, stock, w[who]["user"]))
    return p, v


def status(app, p):
    return app.execute("SELECT status FROM products WHERE id=%s", (p,)).fetchone()[0]


def go_live(app, w, p):
    u = w["a"]["user"]
    assert app.execute("SELECT catalog_submit(%s,%s)", (p, u)).fetchone()[0] == "submitted"
    assert app.execute("SELECT catalog_decide(%s,%s,'approve')", (p, u)).fetchone()[0] == "approved"
    assert app.execute("SELECT catalog_publish(%s,%s)", (p, u)).fetchone()[0] == "published"


def test_full_workflow_and_audit(app, world):
    u = ctx(app, world)
    p, v = product(app, world)
    go_live(app, world, p)
    assert status(app, p) == "published"
    live = app.execute("SELECT live FROM products WHERE id=%s", (p,)).fetchone()[0]
    assert live["title"] == "Tee" and len(live["variants"]) == 1
    acts = [r[0] for r in app.execute("SELECT action FROM product_events WHERE product_id=%s ORDER BY seq", (p,))]
    assert acts == ["submitted", "approved", "published"]


def test_app_cannot_set_workflow_columns(app, world):
    ctx(app, world)
    p, v = product(app, world)
    for col, val in (("status", "published"), ("approved_by", world["a"]["user"]), ("live", "{}"), ("published_at", "2099-01-01")):
        with pytest.raises(psycopg.errors.InsufficientPrivilege):
            app.execute(f"UPDATE products SET {col}=%s WHERE id=%s", (val, p))
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("UPDATE product_variants SET stock_qty=99 WHERE id=%s", (v,))
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("INSERT INTO products (id,tenant_id,store_id,title,status) VALUES (%s,%s,%s,'x','published')",
                    (ulid(), world["a"]["tenant"], app.execute("SELECT id FROM store_connections").fetchone()[0]))


def test_submit_needs_complete_product(app, world):
    u = ctx(app, world)
    p, v = product(app, world, desc="")
    assert app.execute("SELECT catalog_submit(%s,%s)", (p, u)).fetchone()[0] == "incomplete:description"
    app.execute("UPDATE products SET description='ok', price_minor=0 WHERE id=%s", (p,))
    assert app.execute("SELECT catalog_submit(%s,%s)", (p, u)).fetchone()[0] == "incomplete:price"
    app.execute("UPDATE products SET price_minor=100 WHERE id=%s", (p,))
    app.execute("UPDATE product_variants SET archived_at=now() WHERE id=%s", (v,))
    assert app.execute("SELECT catalog_submit(%s,%s)", (p, u)).fetchone()[0] == "incomplete:variant"


def test_cannot_skip_steps(app, world):
    u = ctx(app, world)
    p, v = product(app, world)
    assert app.execute("SELECT catalog_decide(%s,%s,'approve')", (p, u)).fetchone()[0] == "not_pending"
    assert app.execute("SELECT catalog_publish(%s,%s)", (p, u)).fetchone()[0] == "not_approved"
    app.execute("SELECT catalog_submit(%s,%s)", (p, u))
    assert app.execute("SELECT catalog_publish(%s,%s)", (p, u)).fetchone()[0] == "not_approved"
    assert app.execute("SELECT catalog_submit(%s,%s)", (p, u)).fetchone()[0] == "not_draft"


def test_reject_returns_to_draft_with_note(app, world):
    u = ctx(app, world)
    p, v = product(app, world)
    app.execute("SELECT catalog_submit(%s,%s)", (p, u))
    assert app.execute("SELECT catalog_decide(%s,%s,'reject','price too low')", (p, u)).fetchone()[0] == "rejected"
    assert app.execute("SELECT status, review_note FROM products WHERE id=%s", (p,)).fetchone() == ("draft", "price too low")


def test_editing_sends_back_to_draft_but_live_copy_stays(app, world):
    ctx(app, world)
    p, v = product(app, world)
    go_live(app, world, p)
    app.execute("UPDATE products SET price_minor=60000 WHERE id=%s", (p,))
    assert status(app, p) == "draft"
    live = app.execute("SELECT live FROM products WHERE id=%s", (p,)).fetchone()[0]
    assert live["price_minor"] == 50000


def test_pending_edit_and_approved_edit_reset(app, world):
    u = ctx(app, world)
    p, v = product(app, world)
    app.execute("SELECT catalog_submit(%s,%s)", (p, u))
    app.execute("UPDATE products SET title='New' WHERE id=%s", (p,))
    assert status(app, p) == "draft"
    app.execute("SELECT catalog_submit(%s,%s)", (p, u))
    app.execute("SELECT catalog_decide(%s,%s,'approve')", (p, u))
    app.execute("UPDATE products SET description='changed' WHERE id=%s", (p,))
    assert status(app, p) == "draft"


def test_variant_change_resets_but_stock_change_does_not(app, world):
    u = ctx(app, world)
    p, v = product(app, world, stock=5)
    go_live(app, world, p)
    app.execute("SELECT stock_adjust(%s,3,'adjust',NULL,%s)", (v, u))
    assert status(app, p) == "published"
    app.execute("UPDATE product_variants SET price_minor=1 WHERE id=%s", (v,))
    assert status(app, p) == "draft"


def test_new_variant_resets(app, world):
    ctx(app, world)
    p, v = product(app, world)
    go_live(app, world, p)
    app.execute("UPDATE product_variants SET options='{\"Size\":\"M\"}' WHERE id=%s", (v,))
    app.execute("INSERT INTO product_variants (id,tenant_id,product_id,options) VALUES (%s,%s,%s,'{\"Size\":\"L\"}')", (ulid(), world["a"]["tenant"], p))
    assert status(app, p) == "draft"
    with pytest.raises(psycopg.errors.UniqueViolation):
        app.execute("INSERT INTO product_variants (id,tenant_id,product_id,options) VALUES (%s,%s,%s,'{\"Size\":\"L\"}')", (ulid(), world["a"]["tenant"], p))


def test_archive_and_restore(app, world):
    u = ctx(app, world)
    p, v = product(app, world)
    go_live(app, world, p)
    assert app.execute("SELECT catalog_archive(%s,%s)", (p, u)).fetchone()[0] == "archived"
    assert app.execute("SELECT live FROM products WHERE id=%s", (p,)).fetchone()[0] is None
    assert app.execute("SELECT catalog_archive(%s,%s)", (p, u)).fetchone()[0] == "already"
    with pytest.raises(psycopg.errors.RaiseException):
        app.execute("UPDATE products SET title='x' WHERE id=%s", (p,))
    assert app.execute("SELECT catalog_restore(%s,%s)", (p, u)).fetchone()[0] == "restored"
    assert status(app, p) == "draft"


def test_stock_ledger_never_negative_and_append_only(app, world):
    u = ctx(app, world)
    p, v = product(app, world, stock=5)
    assert app.execute("SELECT stock_adjust(%s,-2,'sale',NULL,%s)", (v, u)).fetchone()[0] == 3
    with pytest.raises(psycopg.errors.RaiseException, match="insufficient_stock"):
        app.execute("SELECT stock_adjust(%s,-4,'sale',NULL,%s)", (v, u))
    app.rollback()
    ctx(app, world)
    rows = app.execute("SELECT delta, qty_after FROM stock_movements WHERE variant_id=%s ORDER BY seq", (v,)).fetchall()
    assert rows == [(5, 5), (-2, 3)]
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("UPDATE stock_movements SET delta=100")
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("INSERT INTO stock_movements (tenant_id,variant_id,delta,qty_after,reason) VALUES (%s,%s,1,1,'adjust')", (world["a"]["tenant"], v))


def test_untracked_variant_rejects_stock(app, world):
    u = ctx(app, world)
    p, v = product(app, world)
    app.execute("UPDATE product_variants SET track_stock=false WHERE id=%s", (v,))
    with pytest.raises(psycopg.errors.RaiseException, match="not_tracked"):
        app.execute("SELECT stock_adjust(%s,1,'adjust',NULL,%s)", (v, u))


def test_isolation_between_workspaces(app, world):
    ctx(app, world, "b")
    pb, vb = product(app, world, "b", sku="X1")
    ctx(app, world, "a")
    assert app.execute("SELECT count(*) FROM products").fetchone()[0] == 0
    for sql, args in (("SELECT catalog_submit(%s,%s)", (pb, world["a"]["user"])), ("SELECT catalog_decide(%s,%s,'approve')", (pb, world["a"]["user"])),
                      ("SELECT catalog_publish(%s,%s)", (pb, world["a"]["user"])), ("SELECT stock_adjust(%s,1,'adjust',NULL,NULL)", (vb,))):
        with pytest.raises(psycopg.errors.InsufficientPrivilege):
            app.execute(sql, args)
        app.rollback()
        ctx(app, world, "a")


def test_cross_tenant_store_reference_is_refused(app, world):
    ctx(app, world, "b")
    sb = store(app, world, "b")
    ctx(app, world, "a")
    with pytest.raises((psycopg.errors.ForeignKeyViolation, psycopg.errors.InsufficientPrivilege)):
        app.execute("INSERT INTO products (id,tenant_id,store_id,title) VALUES (%s,%s,%s,'x')", (ulid(), world["a"]["tenant"], sb))


def test_checks(app, world):
    ctx(app, world)
    p, v = product(app, world, sku="DUP")
    with pytest.raises(psycopg.errors.CheckViolation):
        app.execute("UPDATE products SET sale_price_minor=50000 WHERE id=%s", (p,))          # sale must be below price
    app.rollback(); ctx(app, world)
    s = app.execute("SELECT id FROM store_connections").fetchone()[0]
    with pytest.raises(psycopg.errors.UniqueViolation):
        app.execute("INSERT INTO products (id,tenant_id,store_id,title,sku) VALUES (%s,%s,%s,'t','dup')", (ulid(), world["a"]["tenant"], s))
    app.rollback(); ctx(app, world)
    with pytest.raises(psycopg.errors.CheckViolation):
        app.execute("INSERT INTO store_connections (id,tenant_id,platform,name,base_url) VALUES (%s,%s,'native','x','https://x')", (ulid(), world["a"]["tenant"]))


def test_one_native_store_per_workspace(app, world):
    ctx(app, world)
    store(app, world)
    with pytest.raises(psycopg.errors.UniqueViolation):
        store(app, world)


def test_emergency_blocks_approve_and_publish(app, world):
    u = ctx(app, world)
    d = device(app, world)
    app.execute("SELECT mobile_set_pin(%s,%s,%s)", (d, u, HASH))
    p, v = product(app, world)
    app.execute("SELECT catalog_submit(%s,%s)", (p, u))
    app.execute("SELECT mobile_emergency(%s,%s,true,'t')", (d, u))
    assert app.execute("SELECT catalog_decide(%s,%s,'approve')", (p, u)).fetchone()[0] == "emergency"
    assert status(app, p) == "pending_approval"
    pin_ok(app, d, u)
    assert app.execute("SELECT mobile_decide(%s,%s,'product',%s,'approve')", (d, u, p)).fetchone()[0] == "emergency"


def test_emergency_blocks_publish_of_approved(app, world, owner):
    u = ctx(app, world)
    d = device(app, world)
    p, v = product(app, world)
    app.execute("SELECT catalog_submit(%s,%s)", (p, u))
    app.execute("SELECT catalog_decide(%s,%s,'approve')", (p, u))
    app.execute("SELECT mobile_emergency(%s,%s,true,'t')", (d, u))
    assert app.execute("SELECT catalog_publish(%s,%s)", (p, u)).fetchone()[0] == "emergency"
    assert status(app, p) == "approved"


def test_mobile_inbox_lists_and_decides_products(app, world):
    u = ctx(app, world)
    d = device(app, world)
    app.execute("SELECT mobile_set_pin(%s,%s,%s)", (d, u, HASH))
    p, v = product(app, world, title="Phone case")
    assert app.execute("SELECT count(*) FROM mobile_approvals(50) WHERE kind='product'").fetchone()[0] == 0   # draft is not waiting
    app.execute("SELECT catalog_submit(%s,%s)", (p, u))
    row = app.execute("SELECT kind, ref, title, summary FROM mobile_approvals(50) WHERE kind='product'").fetchone()
    assert row[1] == p and row[2] == "Phone case" and "500.00 BDT" in row[3]
    assert app.execute("SELECT mobile_decide(%s,%s,'product',%s,'approve')", (d, u, p)).fetchone()[0] == "step_up_required"
    pin_ok(app, d, u)
    assert app.execute("SELECT mobile_decide(%s,%s,'product',%s,'approve')", (d, u, p)).fetchone()[0] == "approved"
    assert status(app, p) == "approved"
    assert app.execute("SELECT mobile_decide(%s,%s,'product',%s,'approve')", (d, u, p)).fetchone()[0] == "not_pending"
    assert app.execute("SELECT mobile_decide(%s,%s,'bogus',%s,'approve')", (d, u, p)).fetchone()[0] == "unknown_kind"


def test_mobile_cannot_decide_another_workspaces_product(app, world):
    ctx(app, world, "b")
    pb, vb = product(app, world, "b")
    app.execute("SELECT catalog_submit(%s,%s)", (pb, world["b"]["user"]))
    app.commit()
    u = ctx(app, world, "a")
    d = device(app, world)
    app.execute("SELECT mobile_set_pin(%s,%s,%s)", (d, u, HASH))
    pin_ok(app, d, u)
    assert app.execute("SELECT count(*) FROM mobile_approvals(50)").fetchone()[0] == 0
    assert app.execute("SELECT mobile_decide(%s,%s,'product',%s,'approve')", (d, u, pb)).fetchone()[0] == "not_pending"
    ctx(app, world, "b")
    assert status(app, pb) == "pending_approval"
