import json
import pathlib
import re
from decimal import Decimal

import pytest

from conftest import as_admin, ulid
from test_accounting import ctx
from test_ads_meta import acct as meta_acct, store as meta_store
from test_gads import acct as g_acct, store as g_store
from test_social_publish import account, approved, claim
from test_social_stats import save as stats_save

PHP = (pathlib.Path(__file__).resolve().parents[2] / "apps/web-overlay/app/Reports/PgReportRepo.php").read_text()


def const(name):
    m = re.search(r"const %s = '(.*?)';" % name, PHP, re.S)
    assert m, name
    return m.group(1).replace("?", "%s")


META, GOOGLE = const("META"), const("GOOGLE")
m = re.search(r'\$in = "(.*?)";', PHP, re.S)
assert m
IN = m.group(1).replace("?", "%s")


@pytest.fixture(autouse=True)
def clean(owner, world):
    yield
    as_admin(owner)
    for t in ("ads_accounts", "gads_accounts", "social_post_stats", "social_posts", "social_accounts", "clients", "brand_kits"):
        owner.execute(f"DELETE FROM {t}")


def rows(app, world, sql, args, who="a"):
    ctx(app, world, who)
    r = app.execute(sql, args).fetchall()
    app.rollback()
    return r


def test_meta_totals_only_inside_the_period_and_skip_idle_campaigns(app, world, admin):
    a, _ = meta_acct(app, world, name="Meta A", cur="BDT")
    camps = [{"id": "1111111", "name": "Eid", "status": "ACTIVE"}, {"id": "2222222", "name": "Idle", "status": "ACTIVE"}, {"id": "3333333", "name": "Big", "status": "ACTIVE"}]
    ins = [{"campaign": "1111111", "day": d, "spend": s, "impressions": "1000", "clicks": "10", "results": "2"} for d, s in (("2026-11-01", "10.50"), ("2026-11-02", "4.50"), ("2026-11-09", "99"))]
    ins += [{"campaign": "2222222", "day": "2026-11-01", "spend": "0", "impressions": "0", "clicks": "0", "results": "0"}, {"campaign": "3333333", "day": "2026-11-02", "spend": "50", "impressions": "5", "clicks": "1", "results": "0"}]
    assert meta_store(admin, world, a, camps, ins) == "ok"
    r = rows(app, world, META, ("2026-11-01", "2026-11-07", None, None))
    assert [(x[5], x[6], x[7], x[8], x[9]) for x in r] == [("Big", Decimal("50"), 5, 1, Decimal("0")), ("Eid", Decimal("15.00"), 2000, 20, Decimal("4"))]
    assert r[0][2].strip() == "BDT" and r[0][1] == "Meta A"
    assert rows(app, world, META, ("2026-12-01", "2026-12-07", None, None)) == []
    assert len(rows(app, world, META, ("2026-11-09", "2026-11-09", None, None))) == 1


def test_google_maps_cost_and_conversions_and_keeps_workspaces_apart(app, world, admin):
    a, _ = g_acct(app, world, name="G A", cur="USD")
    assert g_store(admin, world, a, [{"id": "12345678901", "name": "Search", "channel": "SEARCH", "status": "ENABLED"}],
                   [{"campaign": "12345678901", "day": "2026-11-01", "cost": "12.34", "impressions": "1000", "clicks": "20", "conversions": "3.5"}]) == "ok"
    r = rows(app, world, GOOGLE, ("2026-11-01", "2026-11-01", None, None))
    assert len(r) == 1 and (r[0][6], r[0][7], r[0][8], r[0][9]) == (Decimal("12.34"), 1000, 20, Decimal("3.50")) and r[0][2].strip() == "USD"
    assert rows(app, world, GOOGLE, ("2026-11-01", "2026-11-01", None, None), who="b") == []
    assert rows(app, world, META, ("2026-11-01", "2026-11-01", None, None)) == []


def test_social_counts_only_posted_in_the_period_in_the_workspace_zone(app, world, admin, owner):
    acc = account(app, world, page="55000001")
    ps = []
    for _ in range(3):
        p = approved(app, world, acc)
        claim(admin)
        assert admin.execute("SELECT social_publish_done(%s,%s,%s,'https://x.test')", (p, world["a"]["tenant"], f"1_{len(ps)}")).fetchone()[0] == "ok"
        ps.append(p)
    as_admin(owner)
    # 18:30 UTC on Nov 3 is already Nov 4 in Dhaka (+6)
    for p, ts in zip(ps, ("2026-11-03 18:30:00+00", "2026-11-03 17:30:00+00", "2026-11-20 10:00:00+00")):
        owner.execute("UPDATE social_posts SET posted_at=%s WHERE id=%s", (ts, p))
    stats_save(admin, ps[0], world, r=30, c=3, s=2, reach=500)
    stats_save(admin, ps[1], world, r=10, c=1, s=1, reach=100)
    n = lambda z, a, b: rows(app, world, f"select count(*) from social_posts p where {IN}", (z, a, b, None, None))[0][0]
    assert n("Asia/Dhaka", "2026-11-04", "2026-11-04") == 1
    assert n("Asia/Dhaka", "2026-11-03", "2026-11-03") == 1
    assert n("UTC", "2026-11-03", "2026-11-03") == 2
    assert n("UTC", "2026-11-01", "2026-11-30") == 3
    e = rows(app, world, f"select coalesce(sum(l.reactions),0), coalesce(sum(l.reach),0) from social_posts p join social_post_latest_stats l on l.post_id=p.id and l.tenant_id=p.tenant_id where {IN}", ("UTC", "2026-11-01", "2026-11-30", None, None))
    assert e == [(40, 600)]
    assert rows(app, world, f"select count(*) from social_posts p where {IN}", ("UTC", "2026-11-01", "2026-11-30", None, None), who="b") == [(0,)]


def test_brand_default_only_and_private(app, world, owner):
    as_admin(owner)
    t = world["a"]["tenant"]
    owner.execute("INSERT INTO brand_kits (id,tenant_id,name,is_default) VALUES (%s,%s,'plain',false)", (ulid(), t))
    owner.execute("INSERT INTO brand_kits (id,tenant_id,name,is_default) VALUES (%s,%s,'main',true)", (ulid(), t))
    q = "select name from brand_kits where is_default order by created_at limit 1"
    assert rows(app, world, q, ()) == [("main",)]
    assert rows(app, world, q, (), who="b") == []


def mkclient(app, world, name, who="a"):
    ctx(app, world, who)
    cid = ulid()
    assert app.execute("SELECT client_save(%s,%s,%s,%s,true,%s,'#112233','#445566','','','','','',NULL,false)", (cid, ulid(), world[who]["tenant"], world[who]["user"], name)).fetchone()[0] == "ok"
    return cid


def test_reports_can_be_limited_to_one_clients_accounts_and_pages(app, world, admin, owner):
    c1, c2 = mkclient(app, world, "Client One"), mkclient(app, world, "Client Two")
    ma, _ = meta_acct(app, world, name="M1")
    mb, _ = meta_acct(app, world, name="M2")
    ga, _ = g_acct(app, world, name="G1")
    camps = [{"id": "1111111", "name": "C", "status": "ACTIVE"}]
    ins = [{"campaign": "1111111", "day": "2026-11-01", "spend": "10", "impressions": "100", "clicks": "5", "results": "1"}]
    assert meta_store(admin, world, ma, camps, ins) == "ok" and meta_store(admin, world, mb, camps, ins) == "ok"
    assert g_store(admin, world, ga, [{"id": "12345678901", "name": "S", "channel": "SEARCH", "status": "ENABLED"}], [{"campaign": "12345678901", "day": "2026-11-01", "cost": "3", "impressions": "10", "clicks": "1", "conversions": "1"}]) == "ok"
    ctx(app, world)
    for kind, acc, cl in (("meta", ma, c1), ("meta", mb, c2), ("google", ga, c1)):
        assert app.execute("SELECT client_assign(%s,%s,%s,%s,%s)", (world["a"]["tenant"], world["a"]["user"], kind, acc, cl)).fetchone()[0] == "ok"
    app.commit()
    names = lambda sql, cl: sorted(x[1] for x in rows(app, world, sql, ("2026-11-01", "2026-11-07", cl, cl)))
    assert names(META, None) == ["M1", "M2"]
    assert names(META, c1) == ["M1"] and names(META, c2) == ["M2"]
    assert names(GOOGLE, c1) == ["G1"] and names(GOOGLE, c2) == []
    assert names(META, ulid()) == []
    # social: only posts made through a page that belongs to the client
    acc1, acc2 = account(app, world, page="55000011"), account(app, world, page="55000012")
    ctx(app, world)
    for acc, cl in ((acc1, c1), (acc2, c2)):
        assert app.execute("SELECT client_assign(%s,%s,'social',%s,%s)", (world["a"]["tenant"], world["a"]["user"], acc, cl)).fetchone()[0] == "ok"
    app.commit()
    for acc in (acc1, acc1, acc2):
        p = approved(app, world, acc)
        claim(admin)
        assert admin.execute("SELECT social_publish_done(%s,%s,%s,'https://x.test')", (p, world["a"]["tenant"], f"9_{ulid()[:6]}")).fetchone()[0] == "ok"
    as_admin(owner)
    owner.execute("UPDATE social_posts SET posted_at='2026-11-02 10:00:00+00'")
    n = lambda cl: rows(app, world, f"select count(*) from social_posts p where {IN}", ("UTC", "2026-11-01", "2026-11-30", cl, cl))[0][0]
    assert (n(None), n(c1), n(c2), n(ulid())) == (3, 2, 1, 0)


def test_a_clients_brand_comes_from_its_own_kit_and_is_private(app, world, owner):
    m = re.search(r"\$own = DB::selectOne\('(.*?)', \[\$clientId\]\)", PHP, re.S)
    assert m
    q = m.group(1).replace("\\'", "'").replace("?", "%s")
    as_admin(owner)
    owner.execute("INSERT INTO brand_kits (id,tenant_id,name,is_default) VALUES (%s,%s,'workspace',true)", (ulid(), world["a"]["tenant"]))
    c = mkclient(app, world, "Client One")
    assert [x[1] for x in rows(app, world, q, (c,))] == ["Client One"]
    assert rows(app, world, q, (c,), who="b") == []
    assert rows(app, world, q, (ulid(),)) == []
