"""The SQL strings in PgStoreRepo.php, executed for real with the parameter shapes PHP passes."""
import json

import pytest

from conftest import as_admin, set_tenant, ulid
from test_lead_php_sql import sql_of

REPO = "app/Catalog/Stores/PgStoreRepo.php"


@pytest.fixture(autouse=True)
def clean(owner, world):
    yield
    as_admin(owner)
    for t in ("store_sync_runs", "stock_movements", "product_events", "product_variants", "products", "store_connections"):
        owner.execute(f"ALTER TABLE {t} DISABLE TRIGGER USER")
        owner.execute(f"DELETE FROM {t}")
        owner.execute(f"ALTER TABLE {t} ENABLE TRIGGER USER")


def test_store_repo_statements(app, world):
    t, u = world["a"]["tenant"], world["a"]["user"]
    set_tenant(app, t)
    s = ulid()
    app.execute(sql_of(REPO, "insert into store_connections"), (s, t, "woocommerce", "Shop", "https://shop.example.com", "CIPHER", 1, "BDT"))
    assert app.execute("SELECT platform FROM store_connections WHERE id=%s", (s,)).fetchone()[0] == "woocommerce"
    assert len(app.execute(sql_of(REPO, "where platform <> 'native' order by created_at")).fetchall()) == 1

    app.execute(sql_of(REPO, "update store_connections set name = ?, base_url = ?, currency = ? where id"), ("Shop 2", "https://shop2.example.com", "USD", s))
    app.execute("UPDATE store_connections SET status='error' WHERE id=%s", (s,))
    app.execute(sql_of(REPO, "credentials_ct = ?, key_version = ?, status = 'active'"), ("Shop 3", "https://shop3.example.com", "BDT", "NEWCIPHER", 2, s))
    assert app.execute("SELECT status, last_error FROM store_connections WHERE id=%s", (s,)).fetchone() == ("active", None)
    assert app.execute(sql_of(REPO, "from store_credentials(?)"), (s,)).fetchone() == ("NEWCIPHER", 2)

    app.execute(sql_of(REPO, "update store_connections set status = ?, last_error"), ("error", "bad key", "true", s))
    r = app.execute("SELECT status, last_error, last_sync_at IS NOT NULL FROM store_connections WHERE id=%s", (s,)).fetchone()
    assert r == ("error", "bad key", True)
    assert app.execute(sql_of(REPO, "select emergency_active"), (t,)).fetchone()[0] is False
    app.execute(sql_of(REPO, "select store_sync_reap"), (s,))

    run = ulid()
    app.execute(sql_of(REPO, "insert into store_sync_runs"), (run, t, s, "manual", u))
    with pytest.raises(Exception, match="store_sync_one_running"):
        app.execute(sql_of(REPO, "insert into store_sync_runs"), (ulid(), t, s, "schedule", None))
    app.rollback()
    set_tenant(app, t)
    app.execute(sql_of(REPO, "update store_sync_runs set status"), ("partial", 3, 2, 1, 1, 1, "note", json.dumps([{"ref": "x", "message": "m"}]), run))
    assert app.execute(sql_of(REPO, "from store_sync_runs where store_id"), (s, 5)).fetchone()[:2] == ("manual", "partial")

    p, v = ulid(), ulid()
    app.execute("INSERT INTO products (id,tenant_id,store_id,title,description,price_minor) VALUES (%s,%s,%s,'T','d',100)", (p, t, s))
    app.execute("INSERT INTO product_variants (id,tenant_id,product_id) VALUES (%s,%s,%s)", (v, t, p))
    app.execute(sql_of(REPO, "update products set external_id = coalesce"), ("W1", "h1", p))
    assert app.execute(sql_of(REPO, "select id from products where store_id = ? and external_id"), (s, "W1")).fetchone()[0].strip() == p
    assert app.execute(sql_of(REPO, "select remote_hash from products"), (p,)).fetchone()[0] == "h1"
    app.execute(sql_of(REPO, "update product_variants set external_id"), ("W1/1", v))
    app.execute(sql_of(REPO, "update product_variants set remote_stock"), (3, v))
    st = app.execute(sql_of(REPO, "from product_variants where product_id = ?"), (p,)).fetchone()
    assert st[1] == "W1/1" and st[2] == {} and st[3] == 0 and st[4] == 3 and st[5] is True

    assert app.execute(sql_of(REPO, "status = 'published'"), (s, 10)).fetchall() == []         # a draft is never a push candidate
    assert app.execute(sql_of(REPO, "from product_variants v join products p"), (s, 10)).fetchall() == []   # and not a stock-push one until it has a live copy
