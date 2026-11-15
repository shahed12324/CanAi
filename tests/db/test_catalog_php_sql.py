"""The SQL strings in PgCatalogRepo.php, executed for real with the parameter shapes PHP passes."""
import json

import pytest

from conftest import as_admin, set_tenant, ulid
from test_lead_php_sql import sql_of

REPO = "app/Catalog/PgCatalogRepo.php"


@pytest.fixture(autouse=True)
def clean(owner, world):
    yield
    as_admin(owner)
    for t in ("stock_movements", "product_events", "product_variants", "products", "catalog_imports", "store_connections"):
        owner.execute(f"ALTER TABLE {t} DISABLE TRIGGER USER")
        owner.execute(f"DELETE FROM {t}")
        owner.execute(f"ALTER TABLE {t} ENABLE TRIGGER USER")


def test_repo_statements(app, world):
    t, u = world["a"]["tenant"], world["a"]["user"]
    set_tenant(app, t)
    store = ulid()
    app.execute(sql_of(REPO, "insert into store_connections", fragment=True), (store, t))
    assert app.execute(sql_of(REPO, "select id from store_connections where platform")).fetchone()[0].strip() == store
    app.execute(sql_of(REPO, "insert into store_connections", fragment=True), (ulid(), t))          # 'on conflict do nothing': still one native store
    assert app.execute("SELECT count(*) FROM store_connections").fetchone()[0] == 1

    p = ulid()
    app.execute(sql_of(REPO, "insert into products"), (p, t, store, "টি-শার্ট", "নরম", 125000, 99900, "BDT", "T1", "জামা", '{"a b","c,d","q\\"x"}', json.dumps(["https://x.test/a.jpg"]), json.dumps({"seo_title": "s"}), u, None))
    v = ulid()
    app.execute(sql_of(REPO, "insert into product_variants"), (v, t, p, None, json.dumps({}), None, "true"))
    v2 = ulid()
    app.execute(sql_of(REPO, "insert into product_variants"), (v2, t, p, "T1-M", json.dumps({"Size": "M"}, ensure_ascii=False), 130000, "true"))

    row = app.execute(sql_of(REPO, "from products where id = ? and deleted_at is null"), (p,)).fetchone()
    assert row[3] == "টি-শার্ট" and row[11] == ["a b", "c,d", 'q"x']
    vs = app.execute(sql_of(REPO, "from product_variants where product_id = ?"), (p,)).fetchall()
    assert len(vs) == 2 and vs[1][2] == {"Size": "M"}

    assert app.execute(sql_of(REPO, "select id from products where store_id"), (store, "t1")).fetchone()[0].strip() == p
    assert app.execute(sql_of(REPO, "select id from product_variants where product_id = ? and options"), (p, json.dumps({"Size": "M"}))).fetchone()[0].strip() == v2
    assert app.execute(sql_of(REPO, "select id from product_variants where product_id = ? and options"), (p, json.dumps({"Size": "L"}))).fetchone() is None

    app.execute(sql_of(REPO, "update products set title"), ("টি-শার্ট ২", "নরম", 120000, None, "BDT", "T1", "জামা", "{}", json.dumps([]), json.dumps({}), "true", p))
    assert app.execute("SELECT title, ai_generated, status FROM products WHERE id=%s", (p,)).fetchone() == ("টি-শার্ট ২", True, "draft")
    app.execute(sql_of(REPO, "update product_variants set sku"), ("NEW", 1, "false", v2))
    app.execute(sql_of(REPO, "update product_variants set archived_at"), (v,))

    app.execute("UPDATE product_variants SET track_stock=true WHERE id=%s", (v2,))
    assert app.execute(sql_of(REPO, "select stock_adjust"), (v2, 5, "initial", None, u)).fetchone()[0] == 5
    assert app.execute(sql_of(REPO, "select catalog_submit"), (p, u)).fetchone()[0] in ("submitted", "incomplete:description")
    mv = app.execute(sql_of(REPO, "from stock_movements where variant_id"), (v2, 10)).fetchall()
    assert mv[0][:3] == (5, 5, "initial")
    ev = app.execute(sql_of(REPO, "from product_events e left join users"), (p,)).fetchall()
    assert isinstance(ev, list)

    like = "%t%"
    rows = app.execute(sql_of(REPO, "from products p"), (None, None, "t1", like, like, 50)).fetchall()
    assert len(rows) == 1 and rows[0][9] == 5 and rows[0][10] == 1                    # stock sum, active variants
    assert app.execute(sql_of(REPO, "from products p"), ("published", "published", None, None, None, 50)).fetchall() == []

    app.execute(sql_of(REPO, "insert into catalog_imports"), (ulid(), t, u, "a.csv", 3, 2, 1, 0, json.dumps([{"row": 2, "message": "x"}])))
    assert app.execute(sql_of(REPO, "from catalog_imports order by"), (10,)).fetchone()[:5] == ("a.csv", 3, 2, 1, 0)

    app.execute(sql_of(REPO, "update products set deleted_at"), (p,))
    assert app.execute(sql_of(REPO, "from products where id = ? and deleted_at is null"), (p,)).fetchone() is None


def test_list_search_escapes_like_wildcards(app, world):
    t = world["a"]["tenant"]
    set_tenant(app, t)
    store = ulid()
    app.execute(sql_of(REPO, "insert into store_connections", fragment=True), (store, t))
    for title in ("100% cotton", "plain"):
        app.execute(sql_of(REPO, "insert into products"), (ulid(), t, store, title, "", 0, None, "BDT", None, None, "{}", "[]", "{}", world["a"]["user"], None))
    pat = "%" + "100\\%" + "%"
    assert len(app.execute(sql_of(REPO, "from products p"), (None, None, "100%", pat, pat, 50)).fetchall()) == 1
