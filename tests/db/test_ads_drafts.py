import itertools
from datetime import date, timedelta

import psycopg
import pytest

from conftest import as_admin, ulid
from test_accounting import ctx
from test_ads_meta import acct

_n = itertools.count(1)


@pytest.fixture(autouse=True)
def clean(owner, world):
    yield
    as_admin(owner)
    owner.execute("DELETE FROM ads_drafts")
    owner.execute("DELETE FROM ads_settings")
    owner.execute("DELETE FROM ads_accounts")
    owner.execute("DELETE FROM social_accounts")
    owner.execute("ALTER TABLE emergency_events DISABLE TRIGGER emergency_events_no_change")
    owner.execute("DELETE FROM emergency_events")
    owner.execute("ALTER TABLE emergency_events ENABLE TRIGGER emergency_events_no_change")


def caps(app, world, who="a", daily=500, total=3000):
    ctx(app, world, who)
    return app.execute("SELECT ads_settings_save(%s,%s,%s::numeric,%s::numeric)", (world[who]["tenant"], world[who]["user"], daily, total)).fetchone()[0]


def page(app, world, who="a"):
    ctx(app, world, who)
    have = app.execute("SELECT id FROM social_accounts WHERE platform='facebook' LIMIT 1").fetchone()
    if have:
        return have[0]
    return app.execute("SELECT social_account_save(%s,%s,'facebook',%s,%s,%s,1::smallint)", (ulid(), world[who]["tenant"], "আমার পেজ", str(20000000 + next(_n)), "CT")).fetchone()[0]


def draft(app, world, account, who="a", new=True, id=None, with_page=True, pixel=True, **kw):
    ctx(app, world, who)
    id = id or ulid()
    a = dict(name="ঈদ অফার", objective="sales", country="BD", age_min=18, age_max=45, gender="all", interests="কাজু বাদাম", primary="ঈদে সেরা কাজু।", headline="ঈদ অফার", description="", cta="SHOP_NOW",
             link="https://shop.example/eid", daily=200, days=7, start=date.today() + timedelta(days=1))
    a.update(kw)
    r = app.execute("SELECT ads_draft_save(%s::boolean,%s,%s,%s,%s,%s,%s,%s,%s::integer,%s::integer,%s,%s,%s,%s,%s,%s,%s,%s::numeric,%s::integer,%s::date)", (new, id, world[who]["tenant"], world[who]["user"], account, a["name"], a["objective"], a["country"], a["age_min"], a["age_max"],
                    a["gender"], a["interests"], a["primary"], a["headline"], a["description"], a["cta"], a["link"], a["daily"], a["days"], a["start"])).fetchone()[0]
    if new and r == "ok" and with_page:
        ctx(app, world, who)
        if pixel:
            app.execute("SELECT ads_account_set_pixel(%s,%s,'12345678')", (account, world[who]["tenant"]))
        assert app.execute("SELECT ads_draft_set_page(%s,%s,%s,%s)", (id, world[who]["tenant"], world[who]["user"], page(app, world, who))).fetchone()[0] == "ok"
    return id, r


def submit(app, world, id, who="a"):
    ctx(app, world, who)
    return app.execute("SELECT ads_draft_submit(%s,%s,%s)", (id, world[who]["tenant"], world[who]["user"])).fetchone()[0]


def decide(app, world, id, ok=True, who="a", note=None):
    ctx(app, world, who)
    return app.execute("SELECT ads_draft_decide(%s,%s,%s,%s::boolean,%s)", (id, world[who]["tenant"], world[who]["user"], ok, note)).fetchone()[0]


def status(owner, id):
    as_admin(owner)
    return owner.execute("SELECT status FROM ads_drafts WHERE id=%s", (id,)).fetchone()[0]


def test_caps_validate_and_update(app, world):
    ctx(app, world)
    for d, t in ((0, 100), (-1, 100), (100, 50), (100, 1e9)):
        assert app.execute("SELECT ads_settings_save(%s,%s,%s::numeric,%s::numeric)", (world["a"]["tenant"], world["a"]["user"], d, t)).fetchone()[0] == "bad_input"
    assert caps(app, world, daily=100, total=700) == "ok"
    assert caps(app, world, daily=300, total=900) == "ok"
    assert app.execute("SELECT count(*), max(max_daily), max(max_total) FROM ads_settings").fetchone() == (1, 300, 900)


def test_create_edit_and_lock(app, world):
    a, _ = acct(app, world)
    id, r = draft(app, world, a)
    assert r == "ok"
    assert draft(app, world, a, new=False, id=id, name="নতুন নাম", daily=250)[1] == "ok"
    assert submit(app, world, id) == "no_cap"
    caps(app, world)
    assert submit(app, world, id) == "ok"
    assert draft(app, world, a, new=False, id=id, name="x")[1] == "locked"
    assert draft(app, world, a, new=False, id=ulid())[1] == "not_found"
    b, _ = acct(app, world, "b")
    assert draft(app, world, b, who="b", new=False, id=id)[1] == "not_found"
    assert draft(app, world, a, who="b", new=False, id=id)[1] == "bad_account"


@pytest.mark.parametrize("kw", [
    {"name": " "}, {"objective": "profit"}, {"country": "bd"}, {"age_min": 17}, {"age_max": 66}, {"age_min": 40, "age_max": 30}, {"gender": "x"}, {"cta": "BUY"}, {"link": "http://x.example"}, {"link": "https://x.example/a b"},
    {"daily": 0}, {"days": 0}, {"days": 61}, {"primary": "a" * 1001}, {"headline": "a" * 81}, {"description": "a" * 201}, {"headline": "<b>x</b>"}, {"interests": "<x>"}, {"start": date.today() - timedelta(days=5)}, {"start": date.today() + timedelta(days=200)},
])
def test_bad_input_is_refused(app, world, kw):
    a, _ = acct(app, world)
    assert draft(app, world, a, **kw)[1] == "bad_input"


def test_account_must_belong_to_the_workspace(app, world):
    a, _ = acct(app, world, "a")
    b, _ = acct(app, world, "b")
    assert draft(app, world, a, who="b")[1] == "bad_account"
    assert draft(app, world, ulid())[1] == "bad_account"
    assert draft(app, world, b, who="b")[1] == "ok"


def test_draft_limit(app, world, owner):
    a, _ = acct(app, world)
    first, _ = draft(app, world, a)
    as_admin(owner)
    owner.execute("INSERT INTO ads_drafts (id,tenant_id,user_id,account_id,name,objective,daily_budget) SELECT substr(md5(g::text)||md5(g::text||'x'),1,26), tenant_id, user_id, account_id, 'x', 'awareness', 1 FROM ads_drafts, generate_series(1,99) g WHERE id=%s", (first,))
    assert draft(app, world, a)[1] == "limit"


def test_submit_needs_complete_draft_and_caps(app, world):
    a, _ = acct(app, world)
    caps(app, world, daily=500, total=1000)
    for kw, want in (({"primary": ""}, "incomplete"), ({"headline": " "}, "incomplete"), ({"link": None}, "incomplete"), ({"daily": 501}, "over_cap"), ({"daily": 200, "days": 6}, "over_cap")):
        id, r = draft(app, world, a, **kw)
        assert r == "ok"
        assert submit(app, world, id) == want, kw
    id, _ = draft(app, world, a, objective="messages", cta="SEND_MESSAGE", daily=100, days=10)
    assert submit(app, world, id) == "unsupported"
    id, _ = draft(app, world, a, objective="traffic", cta="LEARN_MORE", daily=100, days=10)
    assert submit(app, world, id) == "ok"
    id2, _ = draft(app, world, a, daily=500, days=2)
    assert submit(app, world, id2) == "ok"


def test_only_the_author_submits_and_only_drafts(app, world):
    a, _ = acct(app, world)
    caps(app, world)
    id, _ = draft(app, world, a)
    assert submit(app, world, id, who="b") == "not_found"
    assert submit(app, world, id) == "ok"
    assert submit(app, world, id) == "not_found"


def test_approve_and_send_back(app, world, owner):
    a, _ = acct(app, world)
    caps(app, world)
    id, _ = draft(app, world, a)
    assert decide(app, world, id) == "not_found"
    submit(app, world, id)
    assert decide(app, world, id, False, note="ছবি বদলান") == "ok"
    assert status(owner, id) == "draft"
    as_admin(owner)
    assert owner.execute("SELECT reject_note FROM ads_drafts WHERE id=%s", (id,)).fetchone()[0] == "ছবি বদলান"
    submit(app, world, id)
    assert decide(app, world, id, who="b") == "not_found"
    assert decide(app, world, id) == "ok"
    assert status(owner, id) == "approved"
    as_admin(owner)
    assert owner.execute("SELECT approved_by IS NOT NULL, approved_at IS NOT NULL FROM ads_drafts WHERE id=%s", (id,)).fetchone() == (True, True)
    assert decide(app, world, id) == "not_found"


def test_a_lowered_cap_stops_approval(app, world, owner):
    a, _ = acct(app, world)
    caps(app, world, daily=500, total=3000)
    id, _ = draft(app, world, a, daily=400, days=7)
    submit(app, world, id)
    caps(app, world, daily=300, total=3000)
    assert decide(app, world, id) == "over_cap"
    assert status(owner, id) == "pending"


def test_emergency_stop_blocks_approval(app, world, owner):
    a, _ = acct(app, world)
    caps(app, world)
    id, _ = draft(app, world, a)
    submit(app, world, id)
    t, u = world["a"]["tenant"], world["a"]["user"]
    as_admin(owner)
    owner.execute("INSERT INTO emergency_events (tenant_id, action, by_user) VALUES (%s,'stop',%s)", (t, u))
    assert decide(app, world, id) == "emergency"
    assert decide(app, world, id, False, note="stop") == "ok"


def test_reopen_clears_approval_and_delete_only_drafts(app, world, owner):
    a, _ = acct(app, world)
    caps(app, world)
    id, _ = draft(app, world, a)
    submit(app, world, id)
    decide(app, world, id)
    ctx(app, world)
    t, u = world["a"]["tenant"], world["a"]["user"]
    assert app.execute("SELECT ads_draft_delete(%s,%s,%s)", (id, t, u)).fetchone()[0] == "not_found"
    assert app.execute("SELECT ads_draft_reopen(%s,%s,%s)", (id, t, world["b"]["user"])).fetchone()[0] == "not_found"
    assert app.execute("SELECT ads_draft_reopen(%s,%s,%s)", (id, t, u)).fetchone()[0] == "ok"
    as_admin(owner)
    assert owner.execute("SELECT status, approved_by, approved_at FROM ads_drafts WHERE id=%s", (id,)).fetchone() == ("draft", None, None)
    ctx(app, world)
    assert app.execute("SELECT ads_draft_reopen(%s,%s,%s)", (id, t, u)).fetchone()[0] == "not_found"
    assert app.execute("SELECT ads_draft_delete(%s,%s,%s)", (id, t, world["b"]["user"])).fetchone()[0] == "not_found"
    assert app.execute("SELECT ads_draft_delete(%s,%s,%s)", (id, t, u)).fetchone()[0] == "ok"


def test_disconnect_is_blocked_while_a_draft_waits_or_is_approved(app, world):
    a, _ = acct(app, world)
    caps(app, world)
    id, _ = draft(app, world, a)
    ctx(app, world)
    t, u = world["a"]["tenant"], world["a"]["user"]
    submit(app, world, id)
    ctx(app, world)
    assert app.execute("SELECT ads_account_delete(%s,%s)", (a, t)).fetchone()[0] == "busy"
    decide(app, world, id)
    ctx(app, world)
    assert app.execute("SELECT ads_account_delete(%s,%s)", (a, t)).fetchone()[0] == "busy"
    app.execute("SELECT ads_draft_reopen(%s,%s,%s)", (id, t, u))
    assert app.execute("SELECT ads_account_delete(%s,%s)", (a, t)).fetchone()[0] == "ok"


def test_privacy_between_workspaces_and_no_direct_writes(app, world):
    a, _ = acct(app, world)
    id, _ = draft(app, world, a)
    ctx(app, world, "b")
    assert app.execute("SELECT count(*) FROM ads_drafts").fetchone()[0] == 0
    assert app.execute("SELECT count(*) FROM ads_settings").fetchone()[0] == 0
    ctx(app, world, "a")
    for sql in ("UPDATE ads_drafts SET status='approved'", "DELETE FROM ads_drafts", "INSERT INTO ads_settings (tenant_id,max_daily,max_total,updated_by) VALUES ('x',1,1,'y')"):
        with pytest.raises(psycopg.errors.InsufficientPrivilege):
            app.execute(sql)
        app.rollback()
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("SELECT ads_draft_check(%s,%s)", (id, world["a"]["tenant"]))
    app.rollback()


def test_cannot_approve_without_going_through_pending(app, world, owner):
    a, _ = acct(app, world)
    caps(app, world)
    id, _ = draft(app, world, a)
    assert decide(app, world, id) == "not_found"
    as_admin(owner)
    with pytest.raises(psycopg.errors.CheckViolation):
        owner.execute("UPDATE ads_drafts SET status='approved' WHERE id=%s", (id,))
