import psycopg
import pytest

from conftest import as_admin, ulid
from test_accounting import ctx
from test_ads_drafts import caps, clean as _clean, decide, draft, page, status, submit  # noqa: F401  (autouse cleanup fixture)
from test_ads_meta import acct

T = "r"


def approved(app, world, a, who="a", **kw):
    caps(app, world, who)
    id, r = draft(app, world, a, who=who, objective=kw.pop("objective", "traffic"), cta=kw.pop("cta", "LEARN_MORE"), **kw)
    assert r == "ok"
    assert submit(app, world, id, who) == "ok"
    assert decide(app, world, id, True, who) == "ok"
    return id


def claim(admin, n=5, cap=10):
    as_admin(admin)
    return admin.execute("SELECT draft_id, tenant_id, account_ext, currency, pixel_id, credentials_ct, page_ext, objective, remote_campaign, remote_adset, remote_creative, remote_ad FROM ads_launch_claim(%s,%s)", (n, cap)).fetchall()


def lstate(owner, id):
    as_admin(owner)
    return owner.execute("SELECT launch_state, launch_unknown, launch_error, remote_campaign, remote_adset, remote_creative, remote_ad, launch_attempts FROM ads_drafts WHERE id=%s", (id,)).fetchone()


def step(admin, world, id, field, value, who="a"):
    as_admin(admin)
    return admin.execute("SELECT ads_launch_step(%s,%s,%s,%s)", (id, world[who]["tenant"], field, value)).fetchone()[0]


def test_only_launchable_drafts_can_be_submitted(app, world):
    a, _ = acct(app, world)
    caps(app, world)
    for kw, want in (({"objective": "leads", "cta": "SIGN_UP"}, "unsupported"), ({"objective": "messages", "cta": "LEARN_MORE"}, "unsupported"), ({"cta": "SEND_MESSAGE"}, "unsupported"), ({"with_page": False}, "no_page"), ({"start": None}, "incomplete"),
                     ({"objective": "sales", "cta": "SHOP_NOW"}, "no_pixel"), ({"objective": "sales", "cta": "SHOP_NOW", "pixel": True}, "ok"), ({"objective": "awareness"}, "ok"), ({"objective": "traffic"}, "ok")):
        kw = {"pixel": False} | kw
        id, r = draft(app, world, a, **kw)
        assert r == "ok"
        assert submit(app, world, id) == want, kw


def test_page_must_be_a_facebook_page_of_the_workspace(app, world):
    a, _ = acct(app, world)
    id, _ = draft(app, world, a, with_page=False)
    t, u = world["a"]["tenant"], world["a"]["user"]
    ctx(app, world)
    assert app.execute("SELECT ads_draft_set_page(%s,%s,%s,%s)", (id, t, u, ulid())).fetchone()[0] == "bad_page"
    other = page(app, world, "b")
    ctx(app, world)
    assert app.execute("SELECT ads_draft_set_page(%s,%s,%s,%s)", (id, t, u, other)).fetchone()[0] == "bad_page"
    assert app.execute("SELECT ads_draft_set_page(%s,%s,%s,%s)", (id, t, world["b"]["user"], page(app, world))).fetchone()[0] == "not_found"
    ctx(app, world)
    assert app.execute("SELECT ads_draft_set_page(%s,%s,%s,NULL)", (id, t, u)).fetchone()[0] == "ok"
    submit(app, world, id)
    ctx(app, world)
    assert app.execute("SELECT ads_draft_set_page(%s,%s,%s,NULL)", (id, t, u)).fetchone()[0] in ("locked", "ok")


def test_pixel_set_and_clear(app, world):
    a, _ = acct(app, world)
    ctx(app, world)
    t = world["a"]["tenant"]
    assert app.execute("SELECT ads_account_set_pixel(%s,%s,'abc')", (a, t)).fetchone()[0] == "bad_input"
    assert app.execute("SELECT ads_account_set_pixel(%s,%s,'123456789')", (a, t)).fetchone()[0] == "ok"
    assert app.execute("SELECT pixel_id FROM ads_accounts").fetchone()[0] == "123456789"
    assert app.execute("SELECT ads_account_set_pixel(%s,%s,'')", (a, t)).fetchone()[0] == "ok"
    assert app.execute("SELECT pixel_id FROM ads_accounts").fetchone()[0] is None
    assert app.execute("SELECT ads_account_set_pixel(%s,%s,'123456789')", (ulid(), t)).fetchone()[0] == "not_found"
    ctx(app, world, "b")
    assert app.execute("SELECT ads_account_set_pixel(%s,%s,'123456789')", (a, world["b"]["tenant"])).fetchone()[0] == "not_found"


def test_claim_is_admin_only_and_returns_what_is_needed(app, world, admin):
    a, ext = acct(app, world)
    id = approved(app, world, a, objective="sales", cta="SHOP_NOW")
    ctx(app, world)
    for sql, args in (("SELECT * FROM ads_launch_claim(1,5)", ()), ("SELECT ads_launch_step(%s,%s,'campaign','12345')", (id, world["a"]["tenant"])), ("SELECT ads_launch_done(%s,%s)", (id, world["a"]["tenant"])), ("SELECT ads_launch_fail(%s,%s,'x',false)", (id, world["a"]["tenant"]))):
        with pytest.raises(psycopg.errors.InsufficientPrivilege):
            app.execute(sql, args)
        app.rollback()
    rows = claim(admin)
    assert len(rows) == 1
    r = rows[0]
    assert (r[0], r[1], r[2], r[3], r[4], r[5], r[7]) == (id, world["a"]["tenant"], ext, "BDT", "12345678", "CT1", "sales")
    assert len(r[6]) >= 5 and r[8:] == (None, None, None, None)
    assert claim(admin) == []


def test_each_attempt_is_claimed_once(app, world, admin, owner):
    a, _ = acct(app, world)
    id = approved(app, world, a)
    assert len(claim(admin)) == 1
    assert claim(admin) == []
    assert lstate(owner, id)[0] == "launching" and lstate(owner, id)[7] == 1


def test_steps_are_saved_once_and_only_while_launching(app, world, admin, owner):
    a, _ = acct(app, world)
    id = approved(app, world, a)
    assert step(admin, world, id, "campaign", "1111111") == "not_found"
    claim(admin)
    assert step(admin, world, id, "campaign", "1111111") == "ok"
    assert step(admin, world, id, "campaign", "2222222") == "not_found"
    for bad in (("campaign", "abc"), ("bogus", "1111111"), ("adset", "12"), ("adset", None)):
        assert step(admin, world, id, *bad) == "bad_input"
    assert step(admin, world, id, "adset", "3333333", who="b") == "not_found"
    assert lstate(owner, id)[3:7] == ("1111111", None, None, None)


def test_done_needs_all_four_ids(app, world, admin, owner):
    a, _ = acct(app, world)
    id = approved(app, world, a)
    claim(admin)
    for f, v in (("campaign", "1111111"), ("adset", "2222222"), ("creative", "3333333")):
        step(admin, world, id, f, v)
    as_admin(admin)
    t = world["a"]["tenant"]
    assert admin.execute("SELECT ads_launch_done(%s,%s)", (id, t)).fetchone()[0] == "not_found"
    step(admin, world, id, "ad", "4444444")
    as_admin(admin)
    assert admin.execute("SELECT ads_launch_done(%s,%s)", (id, ulid())).fetchone()[0] == "not_found"
    assert admin.execute("SELECT ads_launch_done(%s,%s)", (id, t)).fetchone()[0] == "ok"
    assert lstate(owner, id)[0:2] == ("created", False)
    assert admin.execute("SELECT ads_launch_done(%s,%s)", (id, t)).fetchone()[0] == "not_found"
    assert claim(admin) == []


def test_failure_keeps_progress_and_retry_resumes(app, world, admin, owner):
    a, _ = acct(app, world)
    id = approved(app, world, a)
    claim(admin)
    step(admin, world, id, "campaign", "1111111")
    as_admin(admin)
    t, u = world["a"]["tenant"], world["a"]["user"]
    assert admin.execute("SELECT ads_launch_fail(%s,%s,%s,false)", (id, t, "Meta বলল: ভুল")).fetchone()[0] == "ok"
    assert admin.execute("SELECT ads_launch_fail(%s,%s,'x',false)", (id, t)).fetchone()[0] == "not_found"
    assert lstate(owner, id)[0:3] == ("failed", False, "Meta বলল: ভুল")
    assert claim(admin) == []
    ctx(app, world)
    assert app.execute("SELECT ads_launch_retry(%s,%s,%s,false)", (id, t, u)).fetchone()[0] == "ok"
    rows = claim(admin)
    assert len(rows) == 1 and rows[0][8:] == ("1111111", None, None, None)
    assert lstate(owner, id)[7] == 2


def test_unknown_outcome_needs_confirmation_and_blocks_reopen(app, world, admin, owner):
    a, _ = acct(app, world)
    id = approved(app, world, a)
    claim(admin)
    as_admin(admin)
    t, u = world["a"]["tenant"], world["a"]["user"]
    admin.execute("SELECT ads_launch_fail(%s,%s,'timeout',true)", (id, t))
    ctx(app, world)
    assert app.execute("SELECT ads_draft_reopen(%s,%s,%s)", (id, t, u)).fetchone()[0] == "launched"
    assert app.execute("SELECT ads_launch_retry(%s,%s,%s,false)", (id, t, u)).fetchone()[0] == "confirm"
    assert app.execute("SELECT ads_launch_retry(%s,%s,%s,NULL)", (id, t, u)).fetchone()[0] == "confirm"
    assert app.execute("SELECT ads_launch_retry(%s,%s,%s,true)", (id, t, u)).fetchone()[0] == "ok"
    assert app.execute("SELECT ads_launch_retry(%s,%s,%s,true)", (id, t, u)).fetchone()[0] == "not_found"
    assert lstate(owner, id)[0:2] == (None, False)


def test_a_stale_claim_becomes_unknown_failure(app, world, admin, owner):
    a, _ = acct(app, world)
    id = approved(app, world, a)
    claim(admin)
    as_admin(owner)
    owner.execute("UPDATE ads_drafts SET launch_started_at = now() - interval '20 minutes' WHERE id=%s", (id,))
    assert claim(admin) == []
    s = lstate(owner, id)
    assert s[0] == "failed" and s[1] is True and "Ads Manager" in s[2]


def test_reopen_rules_after_launch_attempts(app, world, admin, owner):
    a, _ = acct(app, world)
    id = approved(app, world, a)
    claim(admin)
    t, u = world["a"]["tenant"], world["a"]["user"]
    ctx(app, world)
    assert app.execute("SELECT ads_draft_reopen(%s,%s,%s)", (id, t, u)).fetchone()[0] == "launched"
    as_admin(admin)
    admin.execute("SELECT ads_launch_fail(%s,%s,'rejected',false)", (id, t))
    ctx(app, world)
    assert app.execute("SELECT ads_draft_reopen(%s,%s,%s)", (id, t, u)).fetchone()[0] == "ok"
    assert lstate(owner, id)[0] is None and status(owner, id) == "draft"
    submit(app, world, id)
    decide(app, world, id)
    claim(admin)
    step(admin, world, id, "campaign", "1111111")
    as_admin(admin)
    admin.execute("SELECT ads_launch_fail(%s,%s,'rejected',false)", (id, t))
    ctx(app, world)
    assert app.execute("SELECT ads_draft_reopen(%s,%s,%s)", (id, t, u)).fetchone()[0] == "launched"


def test_a_created_draft_cannot_be_reopened_or_deleted_but_account_can_go(app, world, admin, owner):
    a, _ = acct(app, world)
    id = approved(app, world, a)
    claim(admin)
    for f, v in (("campaign", "1111111"), ("adset", "2222222"), ("creative", "3333333"), ("ad", "4444444")):
        step(admin, world, id, f, v)
    as_admin(admin)
    t, u = world["a"]["tenant"], world["a"]["user"]
    admin.execute("SELECT ads_launch_done(%s,%s)", (id, t))
    ctx(app, world)
    assert app.execute("SELECT ads_draft_reopen(%s,%s,%s)", (id, t, u)).fetchone()[0] == "launched"
    assert app.execute("SELECT ads_draft_delete(%s,%s,%s)", (id, t, u)).fetchone()[0] == "not_found"
    assert app.execute("SELECT ads_launch_retry(%s,%s,%s,true)", (id, t, u)).fetchone()[0] == "not_found"
    assert app.execute("SELECT ads_account_delete(%s,%s)", (a, t)).fetchone()[0] == "ok"


def test_account_is_busy_while_launch_is_in_progress(app, world, admin):
    a, _ = acct(app, world)
    approved(app, world, a)
    t = world["a"]["tenant"]
    ctx(app, world)
    assert app.execute("SELECT ads_account_delete(%s,%s)", (a, t)).fetchone()[0] == "busy"
    claim(admin)
    ctx(app, world)
    assert app.execute("SELECT ads_account_delete(%s,%s)", (a, t)).fetchone()[0] == "busy"


def test_conditions_are_rechecked_at_claim(app, world, admin, owner):
    a, _ = acct(app, world)
    id = approved(app, world, a, daily=400)
    caps(app, world, daily=300, total=3000)
    assert claim(admin) == []
    s = lstate(owner, id)
    assert s[0] == "failed" and s[1] is False and "over_cap" in s[2] and s[3] is None
    id2 = approved(app, world, a, daily=100)
    as_admin(owner)
    owner.execute("UPDATE social_accounts SET status='invalid'")
    assert claim(admin) == []
    assert "no_page" in lstate(owner, id2)[2]


def test_inactive_ad_account_creates_nothing(app, world, admin, owner):
    a, _ = acct(app, world)
    id = approved(app, world, a)
    as_admin(admin)
    admin.execute("SELECT ads_sync_fail(%s,%s,'x',true)", (a, world["a"]["tenant"]))
    assert claim(admin) == []
    assert "সংযোগ" in lstate(owner, id)[2]


def test_hourly_cap_and_emergency_stop(app, world, admin, owner):
    a, _ = acct(app, world)
    ids = [approved(app, world, a, daily=10) for _ in range(3)]
    assert len(claim(admin, 5, cap=2)) == 2
    assert claim(admin, 5, cap=2) == []
    assert claim(admin, 5, cap=3) != []
    t, u = world["a"]["tenant"], world["a"]["user"]
    b, _ = acct(app, world, "b")
    idb = approved(app, world, b, who="b", daily=10)
    as_admin(owner)
    owner.execute("INSERT INTO emergency_events (tenant_id, action, by_user) VALUES (%s,'stop',%s)", (world["b"]["tenant"], world["b"]["user"]))
    assert claim(admin) == []
    assert lstate(owner, idb)[0] is None
    assert ids


def test_claims_are_private_to_each_workspace(app, world, admin):
    a, _ = acct(app, world, "a")
    b, _ = acct(app, world, "b")
    ia = approved(app, world, a, "a", daily=10)
    ib = approved(app, world, b, "b", daily=10)
    rows = claim(admin)
    assert {r[0]: r[1] for r in rows} == {ia: world["a"]["tenant"], ib: world["b"]["tenant"]}


def test_nothing_can_be_launched_without_approval(app, world, admin, owner):
    a, _ = acct(app, world)
    caps(app, world)
    id, _ = draft(app, world, a, objective="traffic", cta="LEARN_MORE")
    submit(app, world, id)
    assert claim(admin) == []
    as_admin(owner)
    with pytest.raises(psycopg.errors.CheckViolation):
        owner.execute("UPDATE ads_drafts SET launch_state='launching' WHERE id=%s", (id,))
