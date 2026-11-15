import psycopg
import pytest

from conftest import as_admin, ulid
from test_accounting import ctx
from test_gads import acct, clean as _clean  # noqa: F401  (autouse cleanup fixture)
from test_gads_drafts import caps, clean_drafts as _clean2, decide, draft, status, submit  # noqa: F401


def approved(app, world, a, who="a", **kw):
    caps(app, world, who)
    id, r = draft(app, world, a, who=who, **kw)
    assert r == "ok"
    assert submit(app, world, id, who) == "ok"
    assert decide(app, world, id, True, who) == "ok"
    return id


def claim(admin, n=5, cap=10):
    as_admin(admin)
    return admin.execute("SELECT draft_id, tenant_id, customer_id, login_customer_id, currency, credentials_ct, country, language, remote_budget, remote_campaign, remote_adgroup, remote_ad, keywords_done, targeting_done FROM gads_launch_claim(%s,%s)", (n, cap)).fetchall()


def lstate(owner, id):
    as_admin(owner)
    return owner.execute("SELECT launch_state, launch_unknown, launch_error, remote_budget, remote_campaign, remote_adgroup, remote_ad, keywords_done, targeting_done, launch_attempts FROM gads_drafts WHERE id=%s", (id,)).fetchone()


def step(admin, world, id, field, value, who="a"):
    as_admin(admin)
    return admin.execute("SELECT gads_launch_step(%s,%s,%s,%s)", (id, world[who]["tenant"], field, value)).fetchone()[0]


def finish_all(admin, world, id):
    for f, v in (("budget", "1111111"), ("campaign", "2222222"), ("adgroup", "3333333"), ("ad", "4444444"), ("keywords", "done"), ("targeting", "done")):
        assert step(admin, world, id, f, v) == "ok"


def test_only_supported_countries_can_be_submitted(app, world):
    a, _ = acct(app, world)
    caps(app, world)
    for cc, want in (("BD", "ok"), ("US", "ok"), ("FR", "unsupported"), ("ZZ", "unsupported")):
        id, r = draft(app, world, a, country=cc)
        assert r == "ok"
        assert submit(app, world, id) == want, cc


def test_claim_is_admin_only_and_returns_what_is_needed(app, world, admin):
    a, c = acct(app, world, login="1234567890")
    id = approved(app, world, a)
    ctx(app, world)
    t = world["a"]["tenant"]
    for sql, args in (("SELECT * FROM gads_launch_claim(1,5)", ()), ("SELECT gads_launch_step(%s,%s,'budget','12345')", (id, t)), ("SELECT gads_launch_done(%s,%s)", (id, t)), ("SELECT gads_launch_fail(%s,%s,'x',false)", (id, t))):
        with pytest.raises(psycopg.errors.InsufficientPrivilege):
            app.execute(sql, args)
        app.rollback()
    rows = claim(admin)
    assert len(rows) == 1
    r = rows[0]
    assert (r[0], r[1], r[2], r[3], r[4], r[5], r[6], r[7]) == (id, t, c, "1234567890", "BDT", "CT1", "BD", "bn")
    assert r[8:] == (None, None, None, None, False, False)
    assert claim(admin) == []


def test_each_attempt_is_claimed_once(app, world, admin, owner):
    a, _ = acct(app, world)
    id = approved(app, world, a)
    assert len(claim(admin)) == 1
    assert claim(admin) == []
    s = lstate(owner, id)
    assert s[0] == "launching" and s[9] == 1


def test_steps_are_saved_once_and_only_while_launching(app, world, admin, owner):
    a, _ = acct(app, world)
    id = approved(app, world, a)
    assert step(admin, world, id, "campaign", "1111111") == "not_found"
    claim(admin)
    assert step(admin, world, id, "budget", "1111111") == "ok"
    assert step(admin, world, id, "budget", "2222222") == "not_found"
    for bad in (("campaign", "abc"), ("bogus", "1111111"), ("adgroup", "12"), ("ad", None), ("keywords", "1111111"), ("budget", "done"), ("targeting", "yes")):
        assert step(admin, world, id, *bad) == "bad_input", bad
    assert step(admin, world, id, "campaign", "3333333", who="b") == "not_found"
    assert step(admin, world, id, "keywords", "done") == "ok"
    assert step(admin, world, id, "keywords", "done") == "not_found"
    assert lstate(owner, id)[3:9] == ("1111111", None, None, None, True, False)


def test_done_needs_every_step(app, world, admin, owner):
    a, _ = acct(app, world)
    id = approved(app, world, a)
    claim(admin)
    t = world["a"]["tenant"]
    for f, v in (("budget", "1111111"), ("campaign", "2222222"), ("adgroup", "3333333"), ("ad", "4444444"), ("keywords", "done")):
        step(admin, world, id, f, v)
        as_admin(admin)
        assert admin.execute("SELECT gads_launch_done(%s,%s)", (id, t)).fetchone()[0] == "not_found"
    step(admin, world, id, "targeting", "done")
    as_admin(admin)
    assert admin.execute("SELECT gads_launch_done(%s,%s)", (id, ulid())).fetchone()[0] == "not_found"
    assert admin.execute("SELECT gads_launch_done(%s,%s)", (id, t)).fetchone()[0] == "ok"
    assert lstate(owner, id)[0:2] == ("created", False)
    assert admin.execute("SELECT gads_launch_done(%s,%s)", (id, t)).fetchone()[0] == "not_found"
    assert claim(admin) == []


def test_failure_keeps_progress_and_retry_resumes(app, world, admin, owner):
    a, _ = acct(app, world)
    id = approved(app, world, a)
    claim(admin)
    step(admin, world, id, "budget", "1111111")
    step(admin, world, id, "campaign", "2222222")
    as_admin(admin)
    t, u = world["a"]["tenant"], world["a"]["user"]
    assert admin.execute("SELECT gads_launch_fail(%s,%s,%s,false)", (id, t, "Google বলল: ভুল")).fetchone()[0] == "ok"
    assert admin.execute("SELECT gads_launch_fail(%s,%s,'x',false)", (id, t)).fetchone()[0] == "not_found"
    assert lstate(owner, id)[0:3] == ("failed", False, "Google বলল: ভুল")
    assert claim(admin) == []
    ctx(app, world)
    assert app.execute("SELECT gads_launch_retry(%s,%s,%s,false)", (id, t, u)).fetchone()[0] == "ok"
    rows = claim(admin)
    assert len(rows) == 1 and rows[0][8:] == ("1111111", "2222222", None, None, False, False)
    assert lstate(owner, id)[9] == 2


def test_unknown_outcome_needs_confirmation_and_blocks_reopen(app, world, admin, owner):
    a, _ = acct(app, world)
    id = approved(app, world, a)
    claim(admin)
    as_admin(admin)
    t, u = world["a"]["tenant"], world["a"]["user"]
    admin.execute("SELECT gads_launch_fail(%s,%s,'timeout',true)", (id, t))
    ctx(app, world)
    assert app.execute("SELECT gads_draft_reopen(%s,%s,%s)", (id, t, u)).fetchone()[0] == "launched"
    assert app.execute("SELECT gads_launch_retry(%s,%s,%s,false)", (id, t, u)).fetchone()[0] == "confirm"
    assert app.execute("SELECT gads_launch_retry(%s,%s,%s,NULL)", (id, t, u)).fetchone()[0] == "confirm"
    assert app.execute("SELECT gads_launch_retry(%s,%s,%s,true)", (id, t, u)).fetchone()[0] == "ok"
    assert app.execute("SELECT gads_launch_retry(%s,%s,%s,true)", (id, t, u)).fetchone()[0] == "not_found"
    assert lstate(owner, id)[0:2] == (None, False)


def test_a_stale_claim_becomes_unknown_failure(app, world, admin, owner):
    a, _ = acct(app, world)
    id = approved(app, world, a)
    claim(admin)
    as_admin(owner)
    owner.execute("UPDATE gads_drafts SET launch_started_at = now() - interval '20 minutes' WHERE id=%s", (id,))
    assert claim(admin) == []
    s = lstate(owner, id)
    assert s[0] == "failed" and s[1] is True and "Google Ads" in s[2]


def test_reopen_rules_after_launch_attempts(app, world, admin, owner):
    a, _ = acct(app, world)
    id = approved(app, world, a)
    claim(admin)
    t, u = world["a"]["tenant"], world["a"]["user"]
    ctx(app, world)
    assert app.execute("SELECT gads_draft_reopen(%s,%s,%s)", (id, t, u)).fetchone()[0] == "launched"
    as_admin(admin)
    admin.execute("SELECT gads_launch_fail(%s,%s,'rejected',false)", (id, t))
    ctx(app, world)
    assert app.execute("SELECT gads_draft_reopen(%s,%s,%s)", (id, t, u)).fetchone()[0] == "ok"
    assert lstate(owner, id)[0] is None and status(owner, id) == "draft"
    submit(app, world, id)
    decide(app, world, id)
    claim(admin)
    step(admin, world, id, "budget", "1111111")
    as_admin(admin)
    admin.execute("SELECT gads_launch_fail(%s,%s,'rejected',false)", (id, t))
    ctx(app, world)
    assert app.execute("SELECT gads_draft_reopen(%s,%s,%s)", (id, t, u)).fetchone()[0] == "launched"


def test_created_draft_is_final_but_account_can_go(app, world, admin, owner):
    a, _ = acct(app, world)
    id = approved(app, world, a)
    claim(admin)
    finish_all(admin, world, id)
    as_admin(admin)
    t, u = world["a"]["tenant"], world["a"]["user"]
    assert admin.execute("SELECT gads_launch_done(%s,%s)", (id, t)).fetchone()[0] == "ok"
    ctx(app, world)
    assert app.execute("SELECT gads_draft_reopen(%s,%s,%s)", (id, t, u)).fetchone()[0] == "launched"
    assert app.execute("SELECT gads_draft_delete(%s,%s,%s)", (id, t, u)).fetchone()[0] == "not_found"
    assert app.execute("SELECT gads_launch_retry(%s,%s,%s,true)", (id, t, u)).fetchone()[0] == "not_found"
    assert app.execute("SELECT gads_account_delete(%s,%s)", (a, t)).fetchone()[0] == "ok"


def test_account_is_busy_while_launch_is_in_progress(app, world, admin):
    a, _ = acct(app, world)
    approved(app, world, a)
    t = world["a"]["tenant"]
    ctx(app, world)
    assert app.execute("SELECT gads_account_delete(%s,%s)", (a, t)).fetchone()[0] == "busy"
    claim(admin)
    ctx(app, world)
    assert app.execute("SELECT gads_account_delete(%s,%s)", (a, t)).fetchone()[0] == "busy"


def test_conditions_are_rechecked_at_claim(app, world, admin, owner):
    a, _ = acct(app, world)
    id = approved(app, world, a, daily=400)
    caps(app, world, daily=300, total=3000)
    assert claim(admin) == []
    s = lstate(owner, id)
    assert s[0] == "failed" and s[1] is False and "over_cap" in s[2] and s[3] is None


def test_inactive_google_account_creates_nothing(app, world, admin, owner):
    a, _ = acct(app, world)
    id = approved(app, world, a)
    as_admin(admin)
    admin.execute("SELECT gads_sync_fail(%s,%s,'x',true)", (a, world["a"]["tenant"]))
    assert claim(admin) == []
    assert "সংযোগ" in lstate(owner, id)[2]


def test_hourly_cap_and_emergency_stop(app, world, admin, owner):
    a, _ = acct(app, world)
    for _ in range(3):
        approved(app, world, a, daily=10)
    assert len(claim(admin, 5, cap=2)) == 2
    assert claim(admin, 5, cap=2) == []
    assert claim(admin, 5, cap=3) != []
    b, _ = acct(app, world, "b")
    idb = approved(app, world, b, who="b", daily=10)
    as_admin(owner)
    owner.execute("INSERT INTO emergency_events (tenant_id, action, by_user) VALUES (%s,'stop',%s)", (world["b"]["tenant"], world["b"]["user"]))
    assert claim(admin) == []
    assert lstate(owner, idb)[0] is None


def test_claims_are_private_to_each_workspace(app, world, admin):
    a, _ = acct(app, world, "a")
    b, _ = acct(app, world, "b")
    ia = approved(app, world, a, "a", daily=10)
    ib = approved(app, world, b, "b", daily=10)
    assert {r[0]: r[1] for r in claim(admin)} == {ia: world["a"]["tenant"], ib: world["b"]["tenant"]}


def test_nothing_launches_without_approval(app, world, admin, owner):
    a, _ = acct(app, world)
    caps(app, world)
    id, _ = draft(app, world, a)
    submit(app, world, id)
    assert claim(admin) == []
    as_admin(owner)
    with pytest.raises(psycopg.errors.CheckViolation):
        owner.execute("UPDATE gads_drafts SET launch_state='launching' WHERE id=%s", (id,))
