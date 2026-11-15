import json
from datetime import date, timedelta

import psycopg
import pytest

from conftest import as_admin, ulid
from test_accounting import ctx
from test_gads import acct, clean as _clean  # noqa: F401  (autouse cleanup fixture)


@pytest.fixture(autouse=True)
def clean_drafts(owner, world):
    yield
    as_admin(owner)
    owner.execute("DELETE FROM gads_drafts")
    owner.execute("DELETE FROM gads_settings")


def caps(app, world, who="a", daily=500, total=3000):
    ctx(app, world, who)
    return app.execute("SELECT gads_settings_save(%s,%s,%s::numeric,%s::numeric)", (world[who]["tenant"], world[who]["user"], daily, total)).fetchone()[0]


H = ["ঈদ অফার", "সেরা কাজু বাদাম", "ঢাকায় হোম ডেলিভারি"]
D = ["ঈদে সেরা কাজু বাদাম এখনই অর্ডার করুন।", "সারা ঢাকায় দ্রুত ডেলিভারি।"]
K = [{"t": "কাজু বাদাম", "m": "phrase"}, {"t": "কাজু কিনুন", "m": "exact"}]


def draft(app, world, account, who="a", new=True, id=None, **kw):
    ctx(app, world, who)
    id = id or ulid()
    a = dict(name="ঈদ সার্চ", country="BD", language="bn", url="https://shop.example/eid", h=H, d=D, k=K, n=["ফ্রি"], daily=200, days=7, start=date.today() + timedelta(days=1))
    a.update(kw)
    r = app.execute("SELECT gads_draft_save(%s::boolean,%s,%s,%s,%s,%s,%s,%s,%s,%s::jsonb,%s::jsonb,%s::jsonb,%s::jsonb,%s::numeric,%s::integer,%s::date)",
                    (new, id, world[who]["tenant"], world[who]["user"], account, a["name"], a["country"], a["language"], a["url"], json.dumps(a["h"]), json.dumps(a["d"]), json.dumps(a["k"]), json.dumps(a["n"]), a["daily"], a["days"], a["start"])).fetchone()[0]
    return id, r


def submit(app, world, id, who="a"):
    ctx(app, world, who)
    return app.execute("SELECT gads_draft_submit(%s,%s,%s)", (id, world[who]["tenant"], world[who]["user"])).fetchone()[0]


def decide(app, world, id, ok=True, who="a", note=None):
    ctx(app, world, who)
    return app.execute("SELECT gads_draft_decide(%s,%s,%s,%s::boolean,%s)", (id, world[who]["tenant"], world[who]["user"], ok, note)).fetchone()[0]


def status(owner, id):
    as_admin(owner)
    return owner.execute("SELECT status FROM gads_drafts WHERE id=%s", (id,)).fetchone()[0]


def test_caps_validate_and_update(app, world):
    ctx(app, world)
    for d, t in ((0, 100), (-1, 100), (100, 50), (100, 1e9)):
        assert app.execute("SELECT gads_settings_save(%s,%s,%s::numeric,%s::numeric)", (world["a"]["tenant"], world["a"]["user"], d, t)).fetchone()[0] == "bad_input"
    assert caps(app, world, daily=100, total=700) == "ok"
    assert caps(app, world, daily=300, total=900) == "ok"
    assert app.execute("SELECT count(*), max(max_daily), max(max_total) FROM gads_settings").fetchone() == (1, 300, 900)


def test_create_edit_lock_and_delete(app, world, owner):
    a, _ = acct(app, world)
    id, r = draft(app, world, a)
    assert r == "ok"
    assert draft(app, world, a, new=False, id=id, name="নতুন নাম")[1] == "ok"
    caps(app, world)
    assert submit(app, world, id) == "ok"
    assert draft(app, world, a, new=False, id=id)[1] == "locked"
    ctx(app, world)
    assert app.execute("SELECT gads_draft_delete(%s,%s,%s)", (id, world["a"]["tenant"], world["a"]["user"])).fetchone()[0] == "not_found"
    ctx(app, world)
    assert app.execute("SELECT gads_draft_reopen(%s,%s,%s)", (id, world["a"]["tenant"], world["a"]["user"])).fetchone()[0] == "ok"
    ctx(app, world)
    assert app.execute("SELECT gads_draft_delete(%s,%s,%s)", (id, world["a"]["tenant"], world["a"]["user"])).fetchone()[0] == "ok"


def test_input_validation(app, world):
    a, _ = acct(app, world)
    bad = [dict(name="  "), dict(country="bd"), dict(language="xx"), dict(url="http://x.example"), dict(url="https://x.example/<b>"), dict(h=["x" * 31]), dict(h=[""]), dict(h=["ok <b>"]), dict(h=[f"h{i}" for i in range(16)]), dict(h="notalist"), dict(h=[1]),
           dict(d=["y" * 91]), dict(d=["a", "b", "c", "d", "e"]), dict(k=[{"t": "x", "m": "broad"}]), dict(k=[{"t": "x"}]), dict(k=["x"]), dict(k=[{"t": "y" * 81, "m": "exact"}]), dict(k=[{"t": "k%d" % i, "m": "exact"} for i in range(31)]),
           dict(n=["z" * 81]), dict(n=[f"n{i}" for i in range(31)]), dict(daily=0), dict(daily=-5), dict(days=0), dict(days=61), dict(start=date.today() - timedelta(days=5)), dict(start=date.today() + timedelta(days=100))]
    for kw in bad:
        id, r = draft(app, world, a, **kw)
        assert r == "bad_input", kw
        app.rollback()
    assert draft(app, world, ulid())[1] == "bad_account"
    app.rollback()
    ok, r = draft(app, world, a, url=None, start=None, h=[], d=[], k=[])
    assert r == "ok"


def test_incomplete_drafts_cannot_be_submitted(app, world):
    a, _ = acct(app, world)
    caps(app, world)
    for kw in (dict(url=None), dict(start=None), dict(h=H[:2]), dict(d=D[:1]), dict(k=[])):
        id, r = draft(app, world, a, **kw)
        assert r == "ok"
        assert submit(app, world, id) == "incomplete", kw


def test_no_cap_and_over_cap_block_submit_and_approval(app, world, owner):
    a, _ = acct(app, world)
    id, _ = draft(app, world, a, daily=200, days=10)
    assert submit(app, world, id) == "no_cap"
    caps(app, world, daily=100, total=5000)
    assert submit(app, world, id) == "over_cap"
    caps(app, world, daily=300, total=1500)
    assert submit(app, world, id) == "over_cap"                       # 200 x 10 = 2000 > 1500
    caps(app, world, daily=300, total=2000)
    assert submit(app, world, id) == "ok"
    caps(app, world, daily=300, total=1999)                           # lowered after submit: approval re-checks
    assert decide(app, world, id) == "over_cap"
    assert status(owner, id) == "pending"
    caps(app, world, daily=300, total=2000)
    assert decide(app, world, id) == "ok"
    assert status(owner, id) == "approved"


def test_reject_reopen_and_approver_recorded(app, world, owner):
    a, _ = acct(app, world)
    caps(app, world)
    id, _ = draft(app, world, a)
    assert decide(app, world, id) == "not_found"                      # not pending
    submit(app, world, id)
    assert decide(app, world, id, False, note="শিরোনাম বদলান") == "ok"
    as_admin(owner)
    assert owner.execute("SELECT status, reject_note, approved_by FROM gads_drafts WHERE id=%s", (id,)).fetchone() == ("draft", "শিরোনাম বদলান", None)
    submit(app, world, id)
    decide(app, world, id)
    as_admin(owner)
    assert owner.execute("SELECT status, approved_by IS NOT NULL, approved_at IS NOT NULL FROM gads_drafts WHERE id=%s", (id,)).fetchone() == ("approved", True, True)
    ctx(app, world)
    assert app.execute("SELECT gads_draft_reopen(%s,%s,%s)", (id, world["a"]["tenant"], world["a"]["user"])).fetchone()[0] == "ok"
    as_admin(owner)
    assert owner.execute("SELECT status, approved_by, approved_at FROM gads_drafts WHERE id=%s", (id,)).fetchone() == ("draft", None, None)


def test_emergency_stop_blocks_approval(app, world, owner):
    a, _ = acct(app, world)
    caps(app, world)
    id, _ = draft(app, world, a)
    submit(app, world, id)
    t, u = world["a"]["tenant"], world["a"]["user"]
    as_admin(owner)
    owner.execute("INSERT INTO emergency_events (tenant_id, action, by_user) VALUES (%s,'stop',%s)", (t, u))
    assert decide(app, world, id) == "emergency"
    as_admin(owner)
    owner.execute("INSERT INTO emergency_events (tenant_id, action, by_user) VALUES (%s,'resume',%s)", (t, u))
    assert decide(app, world, id) == "ok"


def test_only_the_author_changes_a_draft_and_workspaces_are_isolated(app, world):
    a, _ = acct(app, world)
    id, _ = draft(app, world, a)
    ctx(app, world, "b")
    assert app.execute("SELECT count(*) FROM gads_drafts").fetchone()[0] == 0
    assert app.execute("SELECT gads_draft_submit(%s,%s,%s)", (id, world["b"]["tenant"], world["b"]["user"])).fetchone()[0] == "not_found"
    assert app.execute("SELECT gads_draft_delete(%s,%s,%s)", (id, world["b"]["tenant"], world["b"]["user"])).fetchone()[0] == "not_found"
    ctx(app, world)
    assert app.execute("SELECT gads_draft_submit(%s,%s,%s)", (id, world["a"]["tenant"], ulid())).fetchone()[0] == "not_found"
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("INSERT INTO gads_drafts (id,tenant_id,user_id,account_id,name,daily_budget) VALUES (%s,%s,%s,%s,'x',1)", (ulid(), world["a"]["tenant"], world["a"]["user"], a))
    app.rollback()
    with pytest.raises(psycopg.errors.InvalidParameterValue):
        app.execute("SELECT gads_draft_save(true,%s,%s,NULL,%s,'x','BD','bn',NULL,'[]','[]','[]','[]',1,1,NULL)", (ulid(), world["a"]["tenant"], a))
    app.rollback()


def test_limit_of_100_drafts(app, world):
    a, _ = acct(app, world)
    ctx(app, world)
    for i in range(100):
        app.execute("SELECT gads_draft_save(true,%s,%s,%s,%s,'d','BD','bn',NULL,'[]','[]','[]','[]',1,1,NULL)", (ulid(), world["a"]["tenant"], world["a"]["user"], a))
    assert draft(app, world, a)[1] == "limit"


def test_disconnecting_an_account_with_an_open_draft_is_refused(app, world):
    a, _ = acct(app, world)
    caps(app, world)
    id, _ = draft(app, world, a)
    submit(app, world, id)
    ctx(app, world)
    assert app.execute("SELECT gads_account_delete(%s,%s)", (a, world["a"]["tenant"])).fetchone()[0] == "busy"
    ctx(app, world)
    app.execute("SELECT gads_draft_reopen(%s,%s,%s)", (id, world["a"]["tenant"], world["a"]["user"]))
    ctx(app, world)
    assert app.execute("SELECT gads_account_delete(%s,%s)", (a, world["a"]["tenant"])).fetchone()[0] == "ok"
