from datetime import datetime, timedelta, timezone

import psycopg
import pytest

from conftest import as_admin, ulid
from test_accounting import ctx
from test_social import post

PAGE = "123456789012345"


def ago(h):
    return (datetime.now(timezone.utc) - timedelta(hours=h)).isoformat()


PAST = ago(1)


@pytest.fixture(autouse=True)
def clean(owner, world):
    yield
    as_admin(owner)
    owner.execute("DELETE FROM social_posts")
    owner.execute("DELETE FROM social_accounts")


def account(app, world, who="a", page=PAGE, name="আমার পেজ", ct="CT1", ver=1):
    ctx(app, world, who)
    return app.execute("SELECT social_account_save(%s,%s,'facebook',%s,%s,%s,%s)", (ulid(), world[who]["tenant"], name, page, ct, ver)).fetchone()[0]


def approved(app, world, acc, *, when=PAST, who="a", **kw):
    pid, r = post(app, world, who=who, when=when, **kw)
    assert r == "ok"
    t, u = world[who]["tenant"], world[who]["user"]
    if acc:
        assert app.execute("SELECT social_post_set_account(%s,%s,%s,%s)", (pid, t, u, acc)).fetchone()[0] == "ok"
    assert app.execute("SELECT social_post_submit(%s,%s,%s)", (pid, t, u)).fetchone()[0] == "ok"
    assert app.execute("SELECT social_post_decide(%s,%s,%s,true,NULL)", (pid, t, u)).fetchone()[0] == "ok"
    return pid


def claim(admin, n=5):
    as_admin(admin)
    return admin.execute("SELECT post_id, tenant_id, account_id, caption FROM social_publish_claim(%s)", (n,)).fetchall()


def state(app, pid):
    return app.execute("SELECT status, publish_state, publish_error, attempts, post_url, remote_id, account_id FROM social_posts WHERE id=%s", (pid,)).fetchone()


def test_connect_a_page_and_reconnect_with_a_new_token(app, world, admin, owner):
    a = account(app, world)
    assert len(a) == 26
    assert account(app, world, name="নতুন নাম", ct="CT2", ver=2) == a                      # same Page: same row, new token
    as_admin(owner)
    assert owner.execute("SELECT name, credentials_ct, key_version, status FROM social_accounts WHERE id=%s", (a,)).fetchone() == ("নতুন নাম", "CT2", 2, "active")
    assert owner.execute("SELECT count(*) FROM social_accounts").fetchone()[0] == 1


@pytest.mark.parametrize("kw", [{"page": "12"}, {"page": "abc123456"}, {"page": "1" * 31}, {"name": "  "}, {"name": "ক" * 121}, {"ct": "  "}])
def test_bad_account_input(app, world, kw):
    with pytest.raises(psycopg.errors.InvalidParameterValue):
        account(app, world, **kw)


def test_five_pages_per_workspace(app, world):
    for i in range(5):
        assert len(account(app, world, page=str(10000 + i))) == 26
    assert account(app, world, page="99999") == "limit"
    assert len(account(app, world, page="10003")) == 26                                      # re-connecting an existing one is not blocked
    assert len(account(app, world, who="b", page="10000")) == 26                              # the other workspace has its own five


def test_the_app_role_can_never_read_the_token(app, world, admin):
    a = account(app, world)
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("SELECT credentials_ct FROM social_accounts")
    app.rollback()
    ctx(app, world)
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("SELECT * FROM social_account_credentials(%s)", (a,))
    app.rollback()
    ctx(app, world)
    assert app.execute("SELECT name, external_id, status FROM social_accounts").fetchall() == [("আমার পেজ", PAGE, "active")]
    as_admin(admin)
    assert admin.execute("SELECT credentials_ct, key_version, external_id, status FROM social_account_credentials(%s)", (a,)).fetchone() == ("CT1", 1, PAGE, "active")


def test_pages_are_private_to_their_workspace(app, world, admin):
    a = account(app, world)
    ctx(app, world, "b")
    assert app.execute("SELECT count(*) FROM social_accounts").fetchone()[0] == 0
    assert app.execute("SELECT social_account_delete(%s,%s)", (a, world["b"]["tenant"])).fetchone()[0] == "not_found"
    ctx(app, world, "b")
    pid, _ = post(app, world, who="b")
    assert app.execute("SELECT social_post_set_account(%s,%s,%s,%s)", (pid, world["b"]["tenant"], world["b"]["user"], a)).fetchone()[0] == "bad_account"
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("SELECT social_account_save(%s,%s,'facebook','x','12345678',%s,1::smallint)", (ulid(), world["a"]["tenant"], "CT"))   # foreign tenant id


def test_attach_a_page_only_to_the_authors_own_open_post_of_the_same_platform(app, world):
    a = account(app, world)
    t, u = world["a"]["tenant"], world["a"]["user"]
    pid, _ = post(app, world)
    assert app.execute("SELECT social_post_set_account(%s,%s,%s,%s)", (pid, t, ulid(), a)).fetchone()[0] == "not_found"
    ig, _ = post(app, world, platform="instagram")
    assert app.execute("SELECT social_post_set_account(%s,%s,%s,%s)", (ig, t, u, a)).fetchone()[0] == "bad_account"
    assert app.execute("SELECT social_post_set_account(%s,%s,%s,%s)", (pid, t, u, ulid())).fetchone()[0] == "bad_account"
    assert app.execute("SELECT social_post_set_account(%s,%s,%s,%s)", (pid, t, u, a)).fetchone()[0] == "ok"
    assert app.execute("SELECT social_post_set_account(%s,%s,%s,NULL)", (pid, t, u)).fetchone()[0] == "ok"
    assert state(app, pid)[6] is None
    app.execute("SELECT social_post_set_account(%s,%s,%s,%s)", (pid, t, u, a))
    app.execute("SELECT social_post_submit(%s,%s,%s)", (pid, t, u))
    app.execute("SELECT social_post_decide(%s,%s,%s,true,NULL)", (pid, t, u))
    assert app.execute("SELECT social_post_set_account(%s,%s,%s,NULL)", (pid, t, u)).fetchone()[0] == "locked"      # approved: reopen first


def test_an_inactive_page_cannot_be_attached(app, world, admin, owner):
    a = account(app, world)
    as_admin(admin)
    assert admin.execute("SELECT social_account_mark(%s,'invalid')", (a,)).fetchone()[0] == "ok"
    pid, _ = post(app, world)
    assert app.execute("SELECT social_post_set_account(%s,%s,%s,%s)", (pid, world["a"]["tenant"], world["a"]["user"], a)).fetchone()[0] == "bad_account"
    with pytest.raises(psycopg.errors.InvalidParameterValue):
        admin.execute("SELECT social_account_mark(%s,'weird')", (a,))


def test_claim_takes_only_due_approved_posts_with_a_page_and_only_once(app, world, admin):
    a = account(app, world)
    due = approved(app, world, a, caption="এখনই")
    future = approved(app, world, a, when="2099-01-01 10:00:00+06", caption="ভবিষ্যৎ")
    nopage = approved(app, world, None, caption="পেজ নেই")
    pending, _ = post(app, world, when=PAST)
    app.execute("SELECT social_post_set_account(%s,%s,%s,%s)", (pending, world["a"]["tenant"], world["a"]["user"], a))
    app.execute("SELECT social_post_submit(%s,%s,%s)", (pending, world["a"]["tenant"], world["a"]["user"]))
    got = claim(admin)
    assert [g[0] for g in got] == [due] and got[0][1] == world["a"]["tenant"] and got[0][2] == a and got[0][3] == "এখনই"
    assert claim(admin) == []                                                               # never claimed twice
    assert state(app, due)[:4] == ("approved", "publishing", None, 1)
    for p in (future, nopage, pending):
        assert state(app, p)[1] is None


def test_claim_respects_the_limit_and_oldest_first(app, world, admin):
    a = account(app, world)
    p2 = approved(app, world, a, when=ago(3))
    p1 = approved(app, world, a, when=ago(4))
    p3 = approved(app, world, a, when=ago(2))
    assert [g[0] for g in claim(admin, 2)] == [p1, p2]
    assert [g[0] for g in claim(admin, 2)] == [p3]


def test_done_marks_posted_once_and_drops_a_non_https_link(app, world, admin):
    a = account(app, world)
    t = world["a"]["tenant"]
    p = approved(app, world, a)
    claim(admin)
    assert admin.execute("SELECT social_publish_done(%s,%s,'1_2','https://www.facebook.com/1_2')", (p, ulid())).fetchone()[0] == "not_found"   # wrong workspace
    assert admin.execute("SELECT social_publish_done(%s,%s,'1_2','https://www.facebook.com/1_2')", (p, t)).fetchone()[0] == "ok"
    assert state(app, p) == ("posted", None, None, 1, "https://www.facebook.com/1_2", "1_2", a)
    assert admin.execute("SELECT social_publish_done(%s,%s,'1_2','https://x.com')", (p, t)).fetchone()[0] == "not_found"                      # already posted
    q = approved(app, world, a, when=PAST)
    claim(admin)
    admin.execute("SELECT social_publish_done(%s,%s,'9','http://evil.example/x')", (q, t))
    assert state(app, q)[0] == "posted" and state(app, q)[4] is None


def test_done_and_fail_need_a_claim(app, world, admin):
    a = account(app, world)
    p = approved(app, world, a)
    as_admin(admin)
    t = world["a"]["tenant"]
    assert admin.execute("SELECT social_publish_done(%s,%s,'1','https://x.com')", (p, t)).fetchone()[0] == "not_found"
    assert admin.execute("SELECT social_publish_fail(%s,%s,'boom')", (p, t)).fetchone()[0] == "not_found"
    assert state(app, p)[:2] == ("approved", None)


def test_fail_then_the_author_retries_and_it_goes_again(app, world, admin):
    a = account(app, world)
    t, u = world["a"]["tenant"], world["a"]["user"]
    p = approved(app, world, a)
    claim(admin)
    assert admin.execute("SELECT social_publish_fail(%s,%s,%s)", (p, t, "ক" * 400)).fetchone()[0] == "ok"
    s = state(app, p)
    assert s[:2] == ("approved", "failed") and len(s[2]) == 300 and s[3] == 1
    assert claim(admin) == []                                                                # failed posts are never picked again by themselves
    assert app.execute("SELECT social_post_retry(%s,%s,%s)", (p, t, ulid())).fetchone()[0] == "not_found"   # not the author
    assert app.execute("SELECT social_post_retry(%s,%s,%s)", (p, t, u)).fetchone()[0] == "ok"
    assert app.execute("SELECT social_post_retry(%s,%s,%s)", (p, t, u)).fetchone()[0] == "not_found"        # nothing failed any more
    assert [g[0] for g in claim(admin)] == [p] and state(app, p)[3] == 2


def test_a_lost_claim_is_failed_not_retried(app, world, admin, owner):
    a = account(app, world)
    p = approved(app, world, a)
    claim(admin)
    as_admin(owner)
    owner.execute("UPDATE social_posts SET publish_started_at = now() - interval '16 minutes' WHERE id=%s", (p,))
    assert claim(admin) == []
    s = state(app, p)
    assert s[:2] == ("approved", "failed") and "অজানা" in s[2]
    owner.execute("UPDATE social_posts SET publish_started_at = now() - interval '14 minutes', publish_state='publishing', publish_error=NULL WHERE id=%s", (p,))
    claim(admin)
    assert state(app, p)[1] == "publishing"                                                  # still within 15 minutes: left alone


def test_a_broken_page_or_an_old_time_fails_with_a_reason(app, world, admin):
    a = account(app, world)
    p = approved(app, world, a)
    old = approved(app, world, a, when="2020-01-01 10:00:00+06")
    as_admin(admin)
    admin.execute("SELECT social_account_mark(%s,'invalid')", (a,))
    assert claim(admin) == []
    assert "সংযোগ" in state(app, p)[2] and state(app, p)[1] == "failed" and state(app, old)[1] == "failed"
    b = account(app, world, page="55555555")
    stale = approved(app, world, b, when="2020-01-01 10:00:00+06")
    assert claim(admin) == []
    assert "২৪ ঘণ্টা" in state(app, stale)[2]


def test_nothing_changes_while_a_post_is_being_published(app, world, admin):
    a = account(app, world)
    t, u = world["a"]["tenant"], world["a"]["user"]
    p = approved(app, world, a)
    claim(admin)
    assert app.execute("SELECT social_post_reopen(%s,%s,%s)", (p, t, u)).fetchone()[0] == "not_found"
    assert app.execute("SELECT social_post_cancel(%s,%s,%s)", (p, t, u)).fetchone()[0] == "not_found"
    assert app.execute("SELECT social_post_mark_posted(%s,%s,%s,NULL)", (p, t, u)).fetchone()[0] == "not_found"
    assert app.execute("SELECT social_account_delete(%s,%s)", (a, t)).fetchone()[0] == "busy"
    assert state(app, p)[:2] == ("approved", "publishing")


def test_reopen_cancel_and_manual_posted_clear_a_failed_state(app, world, admin):
    a = account(app, world)
    t, u = world["a"]["tenant"], world["a"]["user"]
    for how in ("reopen", "cancel", "posted"):
        p = approved(app, world, a)
        claim(admin)
        admin.execute("SELECT social_publish_fail(%s,%s,'x')", (p, t))
        q = {"reopen": "SELECT social_post_reopen(%s,%s,%s)", "cancel": "SELECT social_post_cancel(%s,%s,%s)", "posted": "SELECT social_post_mark_posted(%s,%s,%s,NULL)"}[how]
        assert app.execute(q, (p, t, u)).fetchone()[0] == "ok"
        s = state(app, p)
        assert s[1] is None and s[2] is None and s[0] == {"reopen": "draft", "cancel": "cancelled", "posted": "posted"}[how]


def test_disconnecting_a_page_keeps_the_posts_but_detaches_them(app, world, admin):
    a = account(app, world)
    t = world["a"]["tenant"]
    p = approved(app, world, a)
    claim(admin)
    admin.execute("SELECT social_publish_fail(%s,%s,'x')", (p, t))
    assert app.execute("SELECT social_account_delete(%s,%s)", (a, t)).fetchone()[0] == "ok"
    assert state(app, p)[1:3] == (None, None) and state(app, p)[6] is None and state(app, p)[0] == "approved"
    assert app.execute("SELECT social_account_delete(%s,%s)", (a, t)).fetchone()[0] == "not_found"


def test_only_the_admin_role_may_run_the_worker_functions(app, world):
    ctx(app, world)
    for q in ("SELECT * FROM social_publish_claim(1)", "SELECT social_publish_done('%s','%s','1','https://x.com')" % (ulid(), world["a"]["tenant"]),
              "SELECT social_publish_fail('%s','%s','x')" % (ulid(), world["a"]["tenant"]), "SELECT social_account_mark('%s','invalid')" % ulid()):
        with pytest.raises(psycopg.errors.InsufficientPrivilege):
            app.execute(q)
        app.rollback()
        ctx(app, world)


def test_table_rules_hold_even_for_the_owner(owner, app, world):
    a = account(app, world)
    pid, _ = post(app, world)                                                               # a draft
    as_admin(owner)
    with pytest.raises(psycopg.errors.CheckViolation):
        owner.execute("UPDATE social_posts SET publish_state='failed', account_id=%s WHERE id=%s", (a, pid))   # only approved posts can have a publish state
    owner.rollback()
    as_admin(owner)
    with pytest.raises(psycopg.errors.CheckViolation):
        owner.execute("UPDATE social_accounts SET status='weird' WHERE id=%s", (a,))
