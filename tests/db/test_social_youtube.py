import itertools

import psycopg
import pytest

from conftest import as_admin, ulid
from test_accounting import ctx
from test_social import design, post, video
from test_social_publish import PAST, account, claim, state

_n = itertools.count(1)


@pytest.fixture(autouse=True)
def clean(owner, world):
    yield
    as_admin(owner)
    owner.execute("DELETE FROM social_posts")
    owner.execute("DELETE FROM social_accounts")
    owner.execute("DELETE FROM video_jobs")
    owner.execute("DELETE FROM design_reviews")
    owner.execute("DELETE FROM designs")


def channel(app, world, who="a", name="আমার চ্যানেল", ext=None, ct="RT1", ver=1):
    ctx(app, world, who)
    ext = ext or "UC" + str(next(_n)).zfill(22)
    return app.execute("SELECT social_channel_save(%s,%s,%s,%s,%s,%s)", (ulid(), world[who]["tenant"], name, ext, ct, ver)).fetchone()[0], ext


def ytpost(app, admin, world, ch, *, who="a", video_ready=True, platform="youtube", when=PAST):
    vid = video(app, admin, world, who) if video_ready else None
    pid, r = post(app, world, who=who, platform=platform, kind="video" if vid else "none", media=vid, when=when)
    assert r == "ok"
    t, u = world[who]["tenant"], world[who]["user"]
    if ch:
        assert app.execute("SELECT social_post_set_account(%s,%s,%s,%s)", (pid, t, u, ch)).fetchone()[0] == "ok"
    assert app.execute("SELECT social_post_submit(%s,%s,%s)", (pid, t, u)).fetchone()[0] == "ok"
    assert app.execute("SELECT social_post_decide(%s,%s,%s,true,NULL)", (pid, t, u)).fetchone()[0] == "ok"
    return pid


def yclaim(admin, n=5, g=10, t=10):
    as_admin(admin)
    return admin.execute("SELECT post_id, tenant_id, account_id, title, caption, media_id, yt_tags, yt_privacy, thumb_design_id, thumb_size FROM social_youtube_claim(%s,%s,%s)", (n, g, t)).fetchall()


def test_connect_a_channel_and_reconnect_with_a_new_token(app, world, owner):
    a, ext = channel(app, world)
    assert len(a) == 26
    b, _ = channel(app, world, name="নতুন নাম", ext=ext, ct="RT2", ver=2)
    assert a == b
    as_admin(owner)
    assert owner.execute("SELECT platform, name, credentials_ct, key_version, status FROM social_accounts WHERE id=%s", (a,)).fetchone() == ("youtube", "নতুন নাম", "RT2", 2, "active")
    ctx(app, world, "b")
    assert app.execute("SELECT count(*) FROM social_accounts").fetchone()[0] == 0
    # the app role can never read the refresh token
    ctx(app, world, "a")
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("SELECT credentials_ct FROM social_accounts")


@pytest.mark.parametrize("ext", ["123456789012", "UC123", "UC" + "!" * 22, "XX" + "a" * 22, "UC" + "a" * 23, None])
def test_bad_channel_ids_are_refused(app, world, ext):
    ctx(app, world, "a")
    with pytest.raises(psycopg.errors.InvalidParameterValue):
        app.execute("SELECT social_channel_save(%s,%s,'n',%s,'ct',1::smallint)", (ulid(), world["a"]["tenant"], ext))


def test_five_accounts_per_workspace_in_total(app, world):
    for _ in range(4):
        channel(app, world)
    account(app, world, page="40000999")
    ctx(app, world, "a")
    assert app.execute("SELECT social_channel_save(%s,%s,'n',%s,'ct',1::smallint)", (ulid(), world["a"]["tenant"], "UC" + "z" * 22)).fetchone()[0] == "limit"


def test_youtube_details_are_set_only_by_the_author_on_an_open_youtube_post(app, world, admin):
    ch, _ = channel(app, world)
    pid = ytpost(app, admin, world, ch)
    t, u = world["a"]["tenant"], world["a"]["user"]
    S = "SELECT social_post_set_youtube(%s,%s,%s,%s,%s,%s,%s)"
    assert app.execute(S, (pid, t, u, "a", "public", None, None)).fetchone()[0] == "locked"            # approved already
    app.execute("SELECT social_post_reopen(%s,%s,%s)", (pid, t, u))
    d = design(app, world)
    assert app.execute(S, (pid, t, u, " ঈদ, অফার ", "unlisted", d, "post")).fetchone()[0] == "ok"
    assert app.execute("SELECT yt_tags, yt_privacy, thumb_design_id, thumb_size FROM social_posts WHERE id=%s", (pid,)).fetchone() == ("ঈদ, অফার", "unlisted", d, "post")
    assert app.execute(S, (pid, t, u, None, "private", None, None)).fetchone()[0] == "ok"
    assert app.execute("SELECT yt_tags, thumb_design_id FROM social_posts WHERE id=%s", (pid,)).fetchone() == (None, None)
    for bad in [("x", "secret", None, None), ("<b>", "private", None, None), ("x" * 501, "private", None, None), ("x", None, None, None), ("x", "private", d, None), ("x", "private", None, "post"), ("x", "private", d, "huge")]:
        assert app.execute(S, (pid, t, u, *bad)).fetchone()[0] == "bad_input"
    assert app.execute(S, (pid, t, u, "x", "private", d, "story")).fetchone()[0] == "ok"
    assert app.execute(S, (pid, t, u, "x", "private", d, "cover")).fetchone()[0] == "bad_media"       # that size does not exist on the design
    assert app.execute(S, (pid, t, u, "x", "private", ulid(), "post")).fetchone()[0] == "bad_media"
    assert app.execute(S, (pid, t, ulid(), "x", "private", None, None)).fetchone()[0] == "not_found"  # someone else's post
    fb, _ = post(app, world)
    assert app.execute(S, (fb, t, u, "x", "private", None, None)).fetchone()[0] == "locked"           # not a YouTube post
    ctx(app, world, "b")
    other = design(app, world, "b")
    ctx(app, world, "a")
    assert app.execute(S, (pid, t, u, "x", "private", other, "post")).fetchone()[0] == "bad_media"     # another workspace's design


def test_the_youtube_worker_claims_each_post_once_and_facebook_worker_ignores_it(app, world, admin):
    ch, _ = channel(app, world)
    pid = ytpost(app, admin, world, ch)
    assert claim(admin) == []                                                                         # the Facebook claim never takes a YouTube post
    got = yclaim(admin)
    assert [(g[0], g[2], g[3], g[7]) for g in got] == [(pid, ch, "ঈদ পোস্ট", "private")]
    assert yclaim(admin) == []
    ctx(app, world, "a")
    assert state(app, pid)[:2] == ("approved", "publishing")


def test_a_facebook_post_is_not_taken_by_the_youtube_worker(app, world, admin):
    fb = account(app, world, page="40000777")
    from test_social_publish import approved
    p = approved(app, world, fb)
    assert yclaim(admin) == []
    assert [c[0] for c in claim(admin)] == [p]


def test_done_works_only_on_a_youtube_post_being_published_and_keeps_a_note(app, world, admin):
    ch, _ = channel(app, world)
    pid = ytpost(app, admin, world, ch)
    t = world["a"]["tenant"]
    D = "SELECT social_youtube_done(%s,%s,%s,%s,%s)"
    as_admin(admin)
    assert admin.execute(D, (pid, t, "abcDEF_-123", "https://www.youtube.com/watch?v=abcDEF_-123", "n")).fetchone()[0] == "not_found"        # not claimed yet
    yclaim(admin)
    as_admin(admin)
    assert admin.execute(D, (pid, ulid(), "abc", None, None)).fetchone()[0] == "not_found"
    assert admin.execute(D, (pid, t, "abcDEF_-123", "http://insecure", "ক" * 400)).fetchone()[0] == "ok"
    ctx(app, world, "a")
    assert app.execute("SELECT status, remote_id, post_url, publish_state, char_length(publish_note) FROM social_posts WHERE id=%s", (pid,)).fetchone() == ("posted", "abcDEF_-123", None, None, 300)
    as_admin(admin)
    assert admin.execute(D, (pid, t, "other", None, None)).fetchone()[0] == "not_found"                # only once


def test_social_publish_done_still_finishes_nothing_it_should_not(app, world, admin):
    ch, _ = channel(app, world)
    pid = ytpost(app, admin, world, ch)
    yclaim(admin)
    as_admin(admin)
    assert admin.execute("SELECT social_publish_fail(%s,%s,'boom')", (pid, world["a"]["tenant"])).fetchone()[0] == "ok"      # the shared failure path works for YouTube posts
    ctx(app, world, "a")
    assert state(app, pid)[:3] == ("approved", "failed", "boom")
    assert app.execute("SELECT social_post_retry(%s,%s,%s)", (pid, world["a"]["tenant"], world["a"]["user"])).fetchone()[0] == "ok"
    assert len(yclaim(admin)) == 1


def test_daily_caps_hold_uploads_back_until_the_window_passes(app, world, admin, owner):
    ch, _ = channel(app, world)
    ids = [ytpost(app, admin, world, ch) for _ in range(3)]
    assert len(yclaim(admin, g=2, t=10)) == 2                                                          # global cap 2
    assert yclaim(admin, g=2, t=10) == []
    assert len(yclaim(admin, g=5, t=10)) == 1                                                          # the third goes when the cap allows
    as_admin(owner)
    owner.execute("UPDATE social_posts SET publish_started_at = now() - interval '25 hours'")
    ids2 = [ytpost(app, admin, world, ch) for _ in range(2)]
    assert len(yclaim(admin, g=10, t=1)) == 1                                                          # per-workspace cap 1
    assert yclaim(admin, g=10, t=1) == []
    assert yclaim(admin, g=0, t=10) == [] and yclaim(admin, g=10, t=0) == []
    assert ids and ids2


def test_a_second_workspace_is_not_blocked_by_the_first_ones_per_workspace_cap(app, world, admin):
    ca, _ = channel(app, world, "a")
    cb, _ = channel(app, world, "b")
    pa = ytpost(app, admin, world, ca, who="a")
    pb = ytpost(app, admin, world, cb, who="b")
    ytpost(app, admin, world, ca, who="a")
    got = {g[0] for g in yclaim(admin, g=10, t=1)}
    assert pa in got and pb in got and len(got) == 2


def test_not_a_video_old_or_inactive_channel_posts_are_not_uploaded(app, world, admin, owner):
    ch, _ = channel(app, world)
    p1 = ytpost(app, admin, world, ch, video_ready=False)
    assert yclaim(admin) == []
    ctx(app, world, "a")
    assert state(app, p1)[:2] == ("approved", "failed") and "ভিডিও" in state(app, p1)[2]
    p2 = ytpost(app, admin, world, ch)
    as_admin(owner)
    owner.execute("UPDATE social_posts SET scheduled_at = now() - interval '25 hours' WHERE id=%s", (p2,))
    assert yclaim(admin) == []
    p3 = ytpost(app, admin, world, ch)
    as_admin(owner)
    owner.execute("UPDATE social_accounts SET status='invalid'")
    assert yclaim(admin) == []


def test_numbers_and_comments_are_for_facebook_only(app, world, admin, owner):
    ch, _ = channel(app, world)
    pid = ytpost(app, admin, world, ch)
    yclaim(admin)
    as_admin(admin)
    admin.execute("SELECT social_youtube_done(%s,%s,'abcDEF_-123',NULL,NULL)", (pid, world["a"]["tenant"]))
    as_admin(owner)
    owner.execute("UPDATE social_posts SET posted_at = now() - interval '1 day' WHERE id=%s", (pid,))
    as_admin(admin)
    assert admin.execute("SELECT count(*) FROM social_stats_due(10)").fetchone()[0] == 0
    assert admin.execute("SELECT count(*) FROM social_comments_due(10)").fetchone()[0] == 0


def test_the_app_role_cannot_use_worker_functions(app, world, admin):
    ch, _ = channel(app, world)
    pid = ytpost(app, admin, world, ch)
    ctx(app, world, "a")
    t = world["a"]["tenant"]
    for sql in ["SELECT social_youtube_claim(1,1,1)", f"SELECT social_youtube_done('{pid}','{t}','x',NULL,NULL)", f"UPDATE social_posts SET yt_privacy='public' WHERE id='{pid}'"]:
        with pytest.raises(psycopg.errors.InsufficientPrivilege):
            app.execute(sql)
            app.execute("SELECT 1")
        ctx(app, world, "a")
