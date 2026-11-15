import itertools

import psycopg
import pytest

from conftest import as_admin, ulid
from test_accounting import ctx
from test_social_publish import account, approved, claim

_page = itertools.count(50000001)
_cid = itertools.count(900001)


@pytest.fixture(autouse=True)
def clean(owner, world):
    yield
    as_admin(owner)
    owner.execute("DELETE FROM social_comments")
    owner.execute("DELETE FROM social_posts")
    owner.execute("DELETE FROM social_accounts")


def posted(app, world, admin, owner, *, age="1 hour", who="a", acc=None):
    a = acc or account(app, world, who=who, page=str(next(_page)))
    p = approved(app, world, a, who=who)
    claim(admin)
    assert admin.execute("SELECT social_publish_done(%s,%s,%s,'https://www.facebook.com/x')", (p, world[who]["tenant"], "777_" + str(next(_cid)))).fetchone()[0] == "ok"
    as_admin(owner)
    owner.execute("UPDATE social_posts SET posted_at = now() - %s::interval WHERE id=%s", (age, p))
    return p, a


def ingest(admin, world, p, who="a", remote=None, msg="দাম কত?", author="করিম"):
    as_admin(admin)
    cid = ulid()
    r = admin.execute("SELECT social_comment_ingest(%s,%s,%s,%s,%s,%s,now())", (cid, world[who]["tenant"], p, remote or f"777_{next(_cid)}", author, msg)).fetchone()[0]
    return cid, r


def st(app, cid):
    return app.execute("SELECT status, reply_text, approved_by IS NOT NULL, reply_error, reply_remote_id FROM social_comments WHERE id=%s", (cid,)).fetchone()


def user(app, world, who="a"):
    ctx(app, world, who)
    return world[who]["tenant"], world[who]["user"]


def make(app, world, admin, owner, text="৩৫০ টাকা, ইনবক্স করুন"):
    p, a = posted(app, world, admin, owner)
    cid, r = ingest(admin, world, p)
    assert r == "new"
    t, u = user(app, world)
    assert app.execute("SELECT social_comment_draft(%s,%s,%s,%s)", (cid, t, u, text)).fetchone()[0] == "ok"
    return p, a, cid, t, u


def test_ingest_once_per_facebook_comment_and_only_for_posted_posts(app, world, admin, owner):
    p, _ = posted(app, world, admin, owner)
    cid, r = ingest(admin, world, p, remote="777_1")
    assert r == "new"
    assert ingest(admin, world, p, remote="777_1")[1] == "exists"
    for bad in [dict(msg="   "), dict(remote="abc"), dict(remote="1")]:
        assert ingest(admin, world, p, **bad)[1] == "bad_input"
    assert ingest(admin, world, ulid())[1] == "not_found"                              # no such post
    other, _ = posted(app, world, admin, owner, who="b")
    assert ingest(admin, world, other, who="a")[1] == "not_found"                      # another workspace's post
    ctx(app, world, "a")
    assert app.execute("SELECT status, author_name, left(message, 3) FROM social_comments WHERE id=%s", (cid,)).fetchone() == ("new", "করিম", "দাম")
    ctx(app, world, "b")
    assert app.execute("SELECT count(*) FROM social_comments").fetchone()[0] == 0     # RLS


def test_long_text_is_trimmed_and_a_post_keeps_at_most_300(app, world, admin, owner):
    p, _ = posted(app, world, admin, owner)
    cid, _ = ingest(admin, world, p, msg="ক" * 2500, author="খ" * 500)
    ctx(app, world, "a")
    assert app.execute("SELECT char_length(message), char_length(author_name) FROM social_comments WHERE id=%s", (cid,)).fetchone() == (2000, 200)
    as_admin(owner)
    owner.execute("INSERT INTO social_comments (id, tenant_id, post_id, account_id, remote_id, message, commented_at) SELECT substr(md5(g::text) || md5(g::text), 1, 26), p.tenant_id, p.id, p.account_id, '888_' || g, 'x', now() FROM social_posts p, generate_series(1, 299) g WHERE p.id=%s", (p,))
    assert ingest(admin, world, p)[1] == "full"


def test_drafting_edits_the_text_until_it_is_approved(app, world, admin, owner):
    p, _ = posted(app, world, admin, owner)
    cid, _ = ingest(admin, world, p)
    t, u = user(app, world)
    D = "SELECT social_comment_draft(%s,%s,%s,%s)"
    assert app.execute(D, (cid, t, u, "  প্রথম  ")).fetchone()[0] == "ok"
    assert st(app, cid)[:2] == ("drafted", "প্রথম")
    assert app.execute(D, (cid, t, u, "দ্বিতীয়")).fetchone()[0] == "ok"
    for bad in ["", "   ", None, "ক" * 1001]:
        assert app.execute(D, (cid, t, u, bad)).fetchone()[0] == "bad_input"
    assert app.execute(D, (ulid(), t, u, "x")).fetchone()[0] == "not_found"
    with pytest.raises(psycopg.errors.Error):
        app.execute(D, (cid, t, None, "x"))
    app.rollback() if hasattr(app, "rollback") else None


def test_a_reply_is_sent_only_after_approving_the_exact_text(app, world, admin, owner):
    p, a, cid, t, u = make(app, world, admin, owner)
    assert claim_replies(admin) == []                                                  # a draft is never sent
    ctx(app, world, "a")
    assert app.execute("SELECT social_comment_approve(%s,%s,%s,%s)", (cid, t, u, "অন্য লেখা")).fetchone()[0] == "not_found"   # text changed since viewing
    assert st(app, cid)[0] == "drafted"
    assert app.execute("SELECT social_comment_approve(%s,%s,%s,%s)", (cid, t, u, " ৩৫০ টাকা, ইনবক্স করুন ")).fetchone()[0] == "ok"
    assert st(app, cid)[:3] == ("approved", "৩৫০ টাকা, ইনবক্স করুন", True)
    assert app.execute("SELECT social_comment_draft(%s,%s,%s,'নতুন')", (cid, t, u)).fetchone()[0] == "locked"      # no editing once approved
    got = claim_replies(admin)
    assert [(g[0], g[4]) for g in got] == [(cid, "৩৫০ টাকা, ইনবক্স করুন")]
    assert claim_replies(admin) == []                                                  # claimed exactly once
    ctx(app, world, "a")
    assert st(app, cid)[0] == "replying"
    assert app.execute("SELECT social_comment_withdraw(%s,%s,%s)", (cid, t, u)).fetchone()[0] == "not_found"
    assert app.execute("SELECT social_comment_ignore(%s,%s,%s)", (cid, t, u)).fetchone()[0] == "not_found"


def claim_replies(admin, n=5):
    as_admin(admin)
    return admin.execute("SELECT comment_id, tenant_id, account_id, remote_id, reply_text FROM social_comment_reply_claim(%s)", (n,)).fetchall()


def approve(app, cid, t, u, text="৩৫০ টাকা, ইনবক্স করুন"):
    assert app.execute("SELECT social_comment_approve(%s,%s,%s,%s)", (cid, t, u, text)).fetchone()[0] == "ok"


def test_done_and_fail_work_only_on_a_reply_being_sent(app, world, admin, owner):
    p, a, cid, t, u = make(app, world, admin, owner)
    approve(app, cid, t, u)
    as_admin(admin)
    assert admin.execute("SELECT social_comment_reply_done(%s,%s,'1')", (cid, t)).fetchone()[0] == "not_found"        # not claimed yet
    assert admin.execute("SELECT social_comment_reply_fail(%s,%s,'x')", (cid, t)).fetchone()[0] == "not_found"
    claim_replies(admin)
    assert admin.execute("SELECT social_comment_reply_done(%s,%s,%s)", (cid, ulid(), "999_1")).fetchone()[0] == "not_found"   # wrong tenant
    assert admin.execute("SELECT social_comment_reply_done(%s,%s,%s)", (cid, t, "999_1")).fetchone()[0] == "ok"
    assert admin.execute("SELECT social_comment_reply_done(%s,%s,%s)", (cid, t, "999_2")).fetchone()[0] == "not_found"    # only once
    ctx(app, world, "a")
    assert st(app, cid)[0] == "replied" and st(app, cid)[4] == "999_1"
    assert app.execute("SELECT social_comment_ignore(%s,%s,%s)", (cid, t, u)).fetchone()[0] == "not_found"


def test_failure_then_a_person_retries(app, world, admin, owner):
    p, a, cid, t, u = make(app, world, admin, owner)
    approve(app, cid, t, u)
    claim_replies(admin)
    as_admin(admin)
    assert admin.execute("SELECT social_comment_reply_fail(%s,%s,%s)", (cid, t, "ক" * 400)).fetchone()[0] == "ok"
    ctx(app, world, "a")
    s = st(app, cid)
    assert s[0] == "failed" and len(s[3]) == 300
    as_admin(admin)
    assert claim_replies(admin) == []                                                  # never retried by itself
    ctx(app, world, "a")
    assert app.execute("SELECT social_comment_retry(%s,%s,%s)", (cid, t, u)).fetchone()[0] == "ok"
    assert st(app, cid)[0] == "approved" and st(app, cid)[3] is None
    assert app.execute("SELECT social_comment_retry(%s,%s,%s)", (cid, t, u)).fetchone()[0] == "not_found"
    assert len(claim_replies(admin)) == 1


def test_a_stuck_reply_becomes_failed_not_retried(app, world, admin, owner):
    p, a, cid, t, u = make(app, world, admin, owner)
    approve(app, cid, t, u)
    claim_replies(admin)
    as_admin(owner)
    owner.execute("UPDATE social_comments SET reply_started_at = now() - interval '16 minutes' WHERE id=%s", (cid,))
    assert claim_replies(admin) == []
    ctx(app, world, "a")
    assert st(app, cid)[0] == "failed" and "অজানা" in st(app, cid)[3]


def test_old_approvals_and_inactive_pages_are_failed_with_a_reason(app, world, admin, owner):
    p, a, cid, t, u = make(app, world, admin, owner)
    approve(app, cid, t, u)
    as_admin(owner)
    owner.execute("UPDATE social_comments SET approved_at = now() - interval '4 days' WHERE id=%s", (cid,))
    assert claim_replies(admin) == []
    ctx(app, world, "a")
    assert st(app, cid)[0] == "failed" and "৩ দিন" in st(app, cid)[3]
    p2, a2, c2, t2, u2 = make(app, world, admin, owner)
    approve(app, c2, t2, u2)
    as_admin(owner)
    owner.execute("UPDATE social_accounts SET status='invalid' WHERE id=%s", (a2,))
    assert claim_replies(admin) == []
    ctx(app, world, "a")
    assert st(app, c2)[0] == "failed" and "সংযোগ" in st(app, c2)[3]


def test_withdraw_and_ignore(app, world, admin, owner):
    p, a, cid, t, u = make(app, world, admin, owner)
    approve(app, cid, t, u)
    assert app.execute("SELECT social_comment_withdraw(%s,%s,%s)", (cid, t, u)).fetchone()[0] == "ok"
    assert st(app, cid)[:3] == ("drafted", "৩৫০ টাকা, ইনবক্স করুন", False)
    assert claim_replies(admin) == []
    ctx(app, world, "a")
    assert app.execute("SELECT social_comment_withdraw(%s,%s,%s)", (cid, t, u)).fetchone()[0] == "not_found"
    assert app.execute("SELECT social_comment_ignore(%s,%s,%s)", (cid, t, u)).fetchone()[0] == "ok"
    assert st(app, cid)[0] == "ignored"
    assert app.execute("SELECT social_comment_draft(%s,%s,%s,'x')", (cid, t, u)).fetchone()[0] == "locked"
    ctx(app, world, "b")
    tb, ub = world["b"]["tenant"], world["b"]["user"]
    assert app.execute("SELECT social_comment_ignore(%s,%s,%s)", (cid, tb, ub)).fetchone()[0] == "not_found"       # another workspace


def test_comment_checks_are_spaced_by_post_age_and_need_an_active_page(app, world, admin, owner):
    def due(n=10):
        as_admin(admin)
        return [r[0] for r in admin.execute("SELECT post_id FROM social_comments_due(%s)", (n,)).fetchall()]

    p, a = posted(app, world, admin, owner, age="1 minute")
    assert due() == []                                                                  # too fresh
    as_admin(owner)
    owner.execute("UPDATE social_posts SET posted_at = now() - interval '5 minutes' WHERE id=%s", (p,))
    assert due() == [p]
    assert due() == []                                                                  # stamped: not again at once
    for age, gap, due_after in [("1 day", "11 minutes", True), ("1 day", "9 minutes", False), ("5 days", "3 hours", True), ("5 days", "1 hour", False), ("20 days", "25 hours", True), ("20 days", "5 hours", False), ("31 days", "99 hours", False)]:
        as_admin(owner)
        owner.execute("UPDATE social_posts SET posted_at = now() - %s::interval, comments_checked_at = now() - %s::interval WHERE id=%s", (age, gap, p))
        assert (due() == [p]) is due_after, (age, gap)
    as_admin(owner)
    owner.execute("UPDATE social_posts SET posted_at = now() - interval '1 day', comments_checked_at = NULL WHERE id=%s", (p,))
    owner.execute("UPDATE social_accounts SET status='invalid' WHERE id=%s", (a,))
    assert due() == []


def test_the_app_role_cannot_use_worker_functions_or_write_directly(app, world, admin, owner):
    p, a, cid, t, u = make(app, world, admin, owner)
    ctx(app, world, "a")
    for sql in ["SELECT social_comments_due(1)", "SELECT social_comment_reply_claim(1)", f"SELECT social_comment_reply_done('{cid}','{t}','1')", f"SELECT social_comment_reply_fail('{cid}','{t}','x')",
                f"SELECT social_comment_ingest('{ulid()}','{t}','{p}','777_5','a','b',now())", f"UPDATE social_comments SET status='approved' WHERE id='{cid}'", f"DELETE FROM social_comments WHERE id='{cid}'"]:
        with pytest.raises(psycopg.errors.InsufficientPrivilege):
            app.execute(sql)
            app.execute("SELECT 1")
        app.rollback() if hasattr(app, "rollback") else None
        ctx(app, world, "a")
