import json

import psycopg
import pytest

from conftest import as_admin, ulid
from test_accounting import ctx
from test_clients import save as save_client


@pytest.fixture(autouse=True)
def clean(owner, world):
    yield
    as_admin(owner)
    owner.execute("DELETE FROM seo_audits")
    owner.execute("DELETE FROM clients")
    owner.execute("DELETE FROM brand_kits")


def result(score=80, **extra):
    return json.dumps({"status": "ok", "score": score, "issues": [], "pages": []} | extra)


def seo(app, world, who="a", client=None, url="https://x.test/", domain="x.test", keyword=None, status="ok", score=80, pages=3, res=None, aid=None):
    ctx(app, world, who)
    aid = aid or ulid()
    r = app.execute("SELECT seo_audit_save(%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s::jsonb)", (aid, world[who]["tenant"], world[who]["user"], client, url, domain, keyword, status, score, pages, res or result(score or 0))).fetchone()[0]
    return r, aid


def count(app, world, who="a"):
    ctx(app, world, who)
    return app.execute("SELECT count(*) FROM seo_audits").fetchone()[0]


def test_save_stores_the_audit_and_trims_the_keyword(app, world):
    r, a = seo(app, world, keyword="  ঢাকা এসইও  ")
    assert r == "ok"
    ctx(app, world)
    row = app.execute("SELECT url, domain, keyword, status, score, pages_checked, result->>'score', created_by FROM seo_audits WHERE id=%s", (a,)).fetchone()
    assert row == ("https://x.test/", "x.test", "ঢাকা এসইও", "ok", 80, 3, "80", world["a"]["user"])
    assert seo(app, world, keyword="   ")[0] == "ok"
    ctx(app, world)
    assert app.execute("SELECT count(*) FROM seo_audits WHERE keyword IS NULL").fetchone()[0] == 1


def test_a_failed_crawl_is_stored_without_a_score_and_a_scored_one_needs_a_score(app, world):
    assert seo(app, world, status="blocked_by_robots", score=None, pages=0, res=json.dumps({"status": "blocked_by_robots"}))[0] == "ok"
    for kw in ({"status": "ok", "score": None}, {"status": "unreachable", "score": 50}, {"status": "weird", "score": None}, {"score": 101}, {"score": -1}, {"pages": 9}):
        with pytest.raises(psycopg.errors.CheckViolation):
            seo(app, world, **kw)
        app.rollback()


def test_bad_input_is_refused(app, world):
    for kw in ({"url": "   "}, {"url": "h" * 2049}, {"res": "[]"}, {"res": "null"}, {"res": '"x"'}):
        with pytest.raises(psycopg.errors.InvalidParameterValue):
            seo(app, world, **kw)
        app.rollback()
    ctx(app, world)
    with pytest.raises(psycopg.errors.InvalidParameterValue):
        app.execute("SELECT seo_audit_save(%s,%s,NULL,NULL,'https://x.test/','x.test',NULL,'ok',80,1,'{}'::jsonb)", (ulid(), world["a"]["tenant"]))
    app.rollback()
    assert count(app, world) == 0


def test_a_result_over_300kb_is_refused_but_just_under_is_kept(app, world):
    assert seo(app, world, res=json.dumps({"pad": "x" * 300100}))[0] == "too_large"
    assert seo(app, world, res=json.dumps({"pad": "x" * 299900}))[0] == "ok"
    ctx(app, world)
    n = app.execute("SELECT length(%s::jsonb::text)", (json.dumps({"pad": "x" * 299989}),)).fetchone()[0]
    assert n == 300000                                                     # exactly at the limit: kept
    assert seo(app, world, res=json.dumps({"pad": "x" * 299989}))[0] == "ok"
    assert seo(app, world, res=json.dumps({"pad": "x" * 299990}))[0] == "too_large"   # one character over
    assert count(app, world) == 2


def test_client_must_be_an_active_client_of_the_same_workspace(app, world):
    _, c = save_client(app, world, name="Acme")
    assert seo(app, world, client=c)[0] == "ok"
    assert seo(app, world, client=ulid())[0] == "client"
    _, cb = save_client(app, world, name="B", who="b")
    assert seo(app, world, client=cb)[0] == "client"          # another workspace's client
    ctx(app, world)
    app.execute("SELECT client_archive(%s,%s,%s)", (world["a"]["tenant"], world["a"]["user"], c))
    assert seo(app, world, client=c)[0] == "client"           # archived
    ctx(app, world)
    assert app.execute("SELECT client_id FROM seo_audits").fetchone()[0] == c   # the old audit keeps its label
    assert count(app, world) == 1


def test_thirty_per_rolling_day_then_refused_and_allowed_flips(app, world, owner):
    for _ in range(29):
        assert seo(app, world)[0] == "ok"
    ctx(app, world)
    assert app.execute("SELECT seo_audit_allowed(%s)", (world["a"]["tenant"],)).fetchone()[0] is True
    assert seo(app, world)[0] == "ok"
    ctx(app, world)
    assert app.execute("SELECT seo_audit_allowed(%s)", (world["a"]["tenant"],)).fetchone()[0] is False
    assert seo(app, world)[0] == "limit" and count(app, world) == 30
    assert seo(app, world, who="b")[0] == "ok"               # another workspace is unaffected
    as_admin(owner)                                           # audits older than a day stop counting
    owner.execute("UPDATE seo_audits SET created_at = now() - interval '25 hours' WHERE tenant_id=%s", (world["a"]["tenant"],))
    ctx(app, world)
    assert app.execute("SELECT seo_audit_allowed(%s)", (world["a"]["tenant"],)).fetchone()[0] is True
    assert seo(app, world)[0] == "ok"


def test_only_the_newest_three_hundred_are_kept(app, world, owner):
    as_admin(owner)
    owner.execute("INSERT INTO seo_audits (id,tenant_id,url,status,score,created_by,created_at) SELECT left(md5(g::text),26), %s, 'https://old'||g||'.test/', 'ok', 50, %s, now() - interval '3 days' - g * interval '1 minute' FROM generate_series(1,300) g", (world["a"]["tenant"], world["a"]["user"]))
    owner.execute("INSERT INTO seo_audits (id,tenant_id,url,status,score,created_by) SELECT left(md5('b'||g::text),26), %s, 'https://b'||g||'.test/', 'ok', 50, %s FROM generate_series(1,3) g", (world["b"]["tenant"], world["b"]["user"]))
    r, new = seo(app, world)
    assert r == "ok" and count(app, world) == 300
    ctx(app, world)
    assert app.execute("SELECT count(*) FROM seo_audits WHERE id=%s", (new,)).fetchone()[0] == 1
    assert app.execute("SELECT count(*) FROM seo_audits WHERE url='https://old300.test/'").fetchone()[0] == 0   # the oldest went
    assert app.execute("SELECT count(*) FROM seo_audits WHERE url='https://old1.test/'").fetchone()[0] == 1
    assert count(app, world, "b") == 3


def test_delete_removes_only_own_audits(app, world):
    _, a = seo(app, world)
    ctx(app, world, "b")
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("SELECT seo_audit_delete(%s,%s,%s)", (world["a"]["tenant"], world["b"]["user"], a))   # A's tenant id from B's session
    app.rollback()
    ctx(app, world, "b")
    assert app.execute("SELECT seo_audit_delete(%s,%s,%s)", (world["b"]["tenant"], world["b"]["user"], a)).fetchone()[0] == "missing"
    assert count(app, world) == 1
    ctx(app, world)
    assert app.execute("SELECT seo_audit_delete(%s,%s,%s)", (world["a"]["tenant"], world["a"]["user"], a)).fetchone()[0] == "ok"
    assert app.execute("SELECT seo_audit_delete(%s,%s,%s)", (world["a"]["tenant"], world["a"]["user"], a)).fetchone()[0] == "missing"


def test_audits_are_private_and_the_app_role_cannot_write_directly(app, world):
    seo(app, world)
    assert count(app, world, "b") == 0
    ctx(app, world)
    for q in ("INSERT INTO seo_audits (id,tenant_id,url,status,score,created_by) VALUES ('%s','%s','https://x.test','ok',1,'%s')" % (ulid(), world["a"]["tenant"], world["a"]["user"]), "UPDATE seo_audits SET score=100", "DELETE FROM seo_audits"):
        with pytest.raises(psycopg.errors.InsufficientPrivilege):
            app.execute(q)
        app.rollback()
        ctx(app, world)
    for fn, args in (("seo_audit_save", "(%s,%s,%s,NULL,'https://x.test','x.test',NULL,'ok',1,1,'{}'::jsonb)"), ("seo_audit_allowed", "(%s)")):
        ctx(app, world, "b")
        n = args.count("%s")
        params = (ulid(), world["a"]["tenant"], world["b"]["user"])[:n] if fn == "seo_audit_save" else (world["a"]["tenant"],)
        if fn == "seo_audit_save":
            with pytest.raises(psycopg.errors.InsufficientPrivilege):
                app.execute(f"SELECT {fn}{args}", params)
            app.rollback()
    ctx(app, world, "b")
    assert app.execute("SELECT count(*) FROM seo_audits").fetchone()[0] == 0
