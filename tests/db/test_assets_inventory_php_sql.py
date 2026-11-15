"""The SQL strings behind the fixed-asset and inventory screens (PgAssetRepo, PgInventoryRepo), run against real PG."""
from conftest import ulid
from test_accounting import acc, cr, ctx, dr, post  # noqa: F401
from test_accounting_docs import setup
from test_assets_inventory import clean, cost, make_variant, move, register, run  # noqa: F401  (autouse cleanup reused)
from test_lead_php_sql import sql_of

ASSET = "app/Accounting/PgAssetRepo.php"
INV = "app/Accounting/PgInventoryRepo.php"


def test_asset_statements_run(app, world):
    t, u = world["a"]["tenant"], world["a"]["user"]
    A = setup(app, world)
    equip = acc(app, world, "fixed_equipment")
    accounts = app.execute(sql_of(ASSET, "not cash_account and coalesce(system_key")).fetchall()
    codes = [r[1] for r in accounts]
    assert "1610" in codes and "1620" in codes and "1110" not in codes and "1200" not in codes and "1690" not in codes
    assert [r[1] for r in app.execute(sql_of(ASSET, "where cash_account and active")).fetchall()] == ["1110", "1120", "1130"]
    r = app.execute(sql_of(ASSET, "select fa_register("), (ulid(), t, u, "Laptop", equip, "2026-09-10", 120000, 0, 12)).fetchone()[0]
    assert r == "ok"
    post(app, world, [dr(equip, 120000), cr(A["bank"], 120000)], date="2026-09-10")
    pend = app.execute(sql_of(ASSET, "from fa_pending("), ("2026-11-30",)).fetchall()
    assert [(x[0], x[1], x[2]) for x in pend] == [("2026-09", 1, 10000), ("2026-10", 1, 10000), ("2026-11", 1, 10000)]
    res = app.execute(sql_of(ASSET, "from fa_run("), (ulid(), t, u, "2026-09-01")).fetchone()
    assert tuple(res) == ("ok", 1, 10000)
    rows = app.execute(sql_of(ASSET, "from fixed_assets f join accounts a on a.id = f.asset_account_id and a.tenant_id = f.tenant_id order by")).fetchall()
    assert len(rows) == 1 and rows[0][1] == "Laptop" and rows[0][-1] == 10000
    aid = rows[0][0].strip()
    one = app.execute(sql_of(ASSET, "from fixed_assets f join accounts a on a.id = f.asset_account_id and a.tenant_id = f.tenant_id where f.id"), (aid,)).fetchone()
    assert one[0].strip() == aid and one[-1] == 10000
    sch = app.execute(sql_of(ASSET, "generate_series(0, f.life_months - 1)"), (aid,)).fetchall()
    assert len(sch) == 12 and sch[0] == ("2026-09", 10000, True) and sch[1] == ("2026-10", 10000, False) and sch[-1][0] == "2027-08"
    assert sum(x[1] for x in sch) == 120000


def test_dispose_and_schedule_after_disposal(app, world):
    t, u = world["a"]["tenant"], world["a"]["user"]
    A = setup(app, world)
    equip = acc(app, world, "fixed_equipment")
    aid, _ = register(app, world, A, date="2026-09-10", cost=120000, life=12)
    post(app, world, [dr(equip, 120000), cr(A["bank"], 120000)], date="2026-09-10")
    run(app, world, "2026-09-01")
    run(app, world, "2026-10-01")
    assert app.execute(sql_of(ASSET, "select fa_dispose("), (ulid(), aid, u, "2026-11-02", 50000, A["cash"])).fetchone()[0] == "ok"
    sch = app.execute(sql_of(ASSET, "generate_series(0, f.life_months - 1)"), (aid,)).fetchall()
    assert [x[0] for x in sch] == ["2026-09", "2026-10"]                                                      # a disposed asset's plan ends before the disposal month


def test_inventory_statements_run(app, world, owner):
    t, u = world["a"]["tenant"], world["a"]["user"]
    A = setup(app, world)
    inv = acc(app, world, "inventory")
    v = make_variant(app, world, owner)
    move(owner, world, v, 10, "2026-10-05 12:00+06")
    ctx(app, world)
    assert app.execute(sql_of(INV, "select inv_cost_set("), (v, 500, "2026-10-01", u)).fetchone()[0] == "ok"
    assert app.execute(sql_of(INV, "select inv_cost_set("), (v, 600, "2026-10-10", u)).fetchone()[0] == "ok"
    rows = app.execute(sql_of(INV, "from inv_valuation("), ("2026-10-31",)).fetchall()
    assert len(rows) == 1 and rows[0][0].strip() == v and rows[0][4] == 10 and rows[0][5] == 600 and rows[0][6] == 6000
    hist = app.execute(sql_of(INV, "from inv_costs where variant_id"), (v, 10)).fetchall()
    assert [(str(x[0]), x[1]) for x in hist] == [("2026-10-10", 600), ("2026-10-01", 500)]
    assert app.execute(sql_of(INV, "as b from journal_lines l"), ("2026-10-31",)).fetchone()[0] == 0
    cogs = [r for r in app.execute(sql_of(INV, "where type in ('expense', 'income')")).fetchall() if r[4] == "cogs"]
    assert len(cogs) == 1
    r = app.execute(sql_of(INV, "from inv_revalue("), (ulid(), t, u, "2026-10-31", cogs[0][0])).fetchone()
    assert tuple(r) == ("ok", 6000, 0, 6000)
    assert app.execute(sql_of(INV, "as b from journal_lines l"), ("2026-10-31",)).fetchone()[0] == 6000
    assert app.execute(sql_of(INV, "as b from journal_lines l"), ("2026-10-01",)).fetchone()[0] == 0
