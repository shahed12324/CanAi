"""The SQL strings in PgOrderRepo.php, executed for real with the parameter shapes PHP passes."""
import json

import pytest

from conftest import as_admin, set_tenant, ulid
from test_catalog import ctx
from test_lead_php_sql import sql_of
from test_orders import ADDR, clean, create, live, stock  # noqa: F401  (clean is the autouse cleanup fixture)

REPO = "app/Orders/PgOrderRepo.php"


def test_order_create_call_shape_and_transition(app, world):
    u = ctx(app, world)
    t = world["a"]["tenant"]
    p, v = live(app, world, price=25000, stock=5)
    oid = ulid()
    r = app.execute(sql_of(REPO, "from order_create"), (oid, t, u, "staff", None, "করিম", "০১৭১১২২৩৩৪৪", ADDR, "outside", None,
                                                        json.dumps([{"variant_id": v, "qty": 2}], ensure_ascii=False), 0.0)).fetchone()
    assert r[0] == "ok" and r[3] == 50000 + 12000
    assert app.execute(sql_of(REPO, "select order_transition"), (oid, u, "confirmed", None)).fetchone()[0] == "ok"
    assert app.execute(sql_of(REPO, "select order_transition"), (oid, u, "packed", None)).fetchone()[0] == "ok"


def test_listing_detail_sellable_and_settings(app, world):
    u = ctx(app, world)
    t = world["a"]["tenant"]
    p, v = live(app, world, title="Tee", price=25000, stock=5)
    oid, r = create(app, world, [{"variant_id": v, "qty": 1}], phone="01711223344", addr="short")  # refused: address too short
    assert r[0] == "bad_address"
    oid, r = create(app, world, [{"variant_id": v, "qty": 1}], phone="01711223344", addr="x" * 12)
    assert r[0] == "ok"
    rows = app.execute(sql_of(REPO, "from store_orders where (? = 'all'"), ("all", "all", 50)).fetchall()
    assert len(rows) == 1
    assert app.execute(sql_of(REPO, "from store_orders where (? = 'all'"), ("pending", "pending", 50)).fetchall() != []
    assert app.execute(sql_of(REPO, "from store_orders where (? = 'all'"), ("shipped", "shipped", 50)).fetchall() == []
    o = app.execute(sql_of(REPO, "from store_orders where id = ?"), (oid,)).fetchone()
    assert o is not None and o[8] is not None
    assert len(app.execute(sql_of(REPO, "from store_order_items where order_id"), (oid,)).fetchall()) == 1
    assert len(app.execute(sql_of(REPO, "from store_order_events where order_id"), (oid,)).fetchall()) == 1
    sell = app.execute(sql_of(REPO, "from products p join product_variants v")).fetchall()
    assert len(sell) == 1 and sell[0][3] == 25000 and sell[0][5] == 4
    assert app.execute(sql_of(REPO, "from order_settings")).fetchone() is None
    up = sql_of(REPO, "insert into order_settings")
    app.execute(up, (t, 10, 5000, 9000, 40, "false"))
    app.execute(up, (t, 12, 5500, 9500, 60, "true"))
    assert app.execute(sql_of(REPO, "from order_settings")).fetchone() == (12, 5500, 9500, 60, True)
    set_tenant(app, world["b"]["tenant"])
    assert app.execute(sql_of(REPO, "from products p join product_variants v")).fetchall() == []
    assert app.execute(sql_of(REPO, "from store_orders where (? = 'all'"), ("all", "all", 50)).fetchall() == []
