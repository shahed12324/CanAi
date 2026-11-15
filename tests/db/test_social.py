import psycopg
import pytest

from conftest import as_admin, ulid
from test_accounting import ctx

SAVE = "SELECT social_post_save(%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)"
WHEN = "2026-12-01 10:00:00+06"
BRAND = '{"primary":"#112233","accent":"#445566","name":"GV"}'


@pytest.fixture(autouse=True)
def clean(owner, world):
    yield
    as_admin(owner)
    owner.execute("DELETE FROM social_posts")
    owner.execute("DELETE FROM video_jobs")
    owner.execute("DELETE FROM design_reviews")
    owner.execute("DELETE FROM designs")


def post(app, world, *, who="a", user=None, pid=None, new=True, title="ঈদ পোস্ট", platform="facebook", caption="ঈদ মোবারক!", kind="none", media=None, size=None, when=WHEN):
    ctx(app, world, who)
    pid = pid or ulid()
    r = app.execute(SAVE, (new, pid, world[who]["tenant"], user or world[who]["user"], title, platform, caption, kind, media, size, when)).fetchone()[0]
    return pid, r


def row(app, pid):
    return app.execute("SELECT status, title, platform, caption, media_kind, media_id, media_size, scheduled_at IS NOT NULL, reject_note, approved_by, posted_at IS NOT NULL, post_url FROM social_posts WHERE id=%s", (pid,)).fetchone()


def design(app, world, who="a"):
    ctx(app, world, who)
    did = ulid()
    app.execute("SELECT design_save(%s,%s,%s,'t','b','H','S','C','dark',%s,%s::jsonb,NULL,NULL)", (did, world[who]["tenant"], world[who]["user"], ["post", "story"], BRAND))
    return did


def video(app, admin, world, who="a", done=True):
    ctx(app, world, who)
    vid = ulid()
    app.execute("SELECT video_job_create(%s,%s,%s,'v','post','[{}]'::jsonb,NULL,NULL,%s::jsonb,NULL,NULL,%s,3,6)", (vid, world[who]["tenant"], world[who]["user"], BRAND, ulid()))
    if done:
        as_admin(admin)
        admin.execute("SELECT video_job_claim(5)")
        admin.execute("SELECT video_job_done(%s,%s,'f.mp4',10,3)", (vid, world[who]["tenant"]))
        ctx(app, world, who)
    return vid


def test_create_normalises_and_starts_as_a_draft(app, world):
    pid, r = post(app, world, title="  ঈদ   পোস্ট ", caption="  ঈদ মোবারক!  ")
    assert r == "ok"
    assert row(app, pid) == ("draft", "ঈদ পোস্ট", "facebook", "ঈদ মোবারক!", "none", None, None, True, None, None, False, None)


@pytest.mark.parametrize("kw", [{"title": ""}, {"title": None}, {"title": "ক" * 121}, {"caption": ""}, {"caption": "  "}, {"caption": None}, {"caption": "x" * 3001}, {"platform": "myspace"}, {"platform": None},
                                {"kind": "photo"}, {"kind": None}, {"kind": "design", "media": None, "size": "post"}, {"kind": "none", "media": "A" * 26}, {"kind": "design", "media": "A" * 26, "size": None},
                                {"kind": "design", "media": "A" * 26, "size": "huge"}, {"kind": "video", "media": "A" * 26, "size": "post"}])
def test_bad_input(app, world, kw):
    assert post(app, world, **kw)[1] == "bad_input"
    assert app.execute("SELECT count(*) FROM social_posts").fetchone()[0] == 0


def test_exact_edges_and_every_platform(app, world):
    assert post(app, world, title="ক" * 120, caption="x" * 3000, when=None)[1] == "ok"
    for p in ("facebook", "instagram", "youtube", "tiktok", "linkedin", "other"):
        assert post(app, world, platform=p)[1] == "ok"


def test_design_media_must_be_the_authors_own_and_include_the_size(app, world):
    d = design(app, world)
    assert post(app, world, kind="design", media=d, size="story")[1] == "ok"
    assert post(app, world, kind="design", media=d, size="ad")[1] == "bad_media"          # not one of the design's sizes
    assert post(app, world, kind="design", media=ulid(), size="post")[1] == "bad_media"    # no such design
    assert post(app, world, who="b", kind="design", media=d, size="post")[1] == "bad_media"  # other workspace


def test_video_media_must_be_a_finished_video_of_the_author(app, world, admin):
    done = video(app, admin, world)
    running = video(app, admin, world, done=False)
    assert post(app, world, kind="video", media=done)[1] == "ok"
    assert post(app, world, kind="video", media=running)[1] == "bad_media"
    assert post(app, world, who="b", kind="video", media=done)[1] == "bad_media"


def test_only_the_author_edits_and_only_a_draft(app, world):
    pid, _ = post(app, world, title="first")
    ctx(app, world)
    assert post(app, world, pid=pid, new=False, title="second", caption="new caption")[1] == "ok"
    assert row(app, pid)[1] == "second" and row(app, pid)[3] == "new caption"
    assert post(app, world, pid=pid, new=False, user=ulid())[1] == "not_found"
    assert post(app, world, pid=ulid(), new=False)[1] == "not_found"
    me = world["a"]["user"]
    assert app.execute("SELECT social_post_submit(%s,%s,%s)", (pid, world["a"]["tenant"], me)).fetchone()[0] == "ok"
    assert post(app, world, pid=pid, new=False, title="third")[1] == "locked"
    assert row(app, pid)[1] == "second"


def test_same_id_never_overwrites_on_create(app, world):
    pid, _ = post(app, world, title="first")
    post(app, world, pid=pid, title="second")
    assert row(app, pid)[1] == "first"


def test_at_most_500_posts_per_workspace(app, world, owner):
    t, u = world["a"]["tenant"], world["a"]["user"]
    as_admin(owner)
    owner.execute("INSERT INTO social_posts (id,tenant_id,user_id,title,platform,caption) SELECT lpad(g::text,26,'p'),%s,%s,'t','other','c' FROM generate_series(1,500) g", (t, u))
    assert post(app, world)[1] == "limit"
    assert post(app, world, who="b")[1] == "ok"


def test_submit_needs_a_time(app, world):
    t, me = world["a"]["tenant"], world["a"]["user"]
    pid, _ = post(app, world, when=None)
    assert app.execute("SELECT social_post_submit(%s,%s,%s)", (pid, t, me)).fetchone()[0] == "no_schedule"
    assert row(app, pid)[0] == "draft"
    assert post(app, world, pid=pid, new=False, when=WHEN)[1] == "ok"
    assert app.execute("SELECT social_post_submit(%s,%s,%s)", (pid, t, me)).fetchone()[0] == "ok"
    assert row(app, pid)[0] == "pending"
    assert app.execute("SELECT social_post_submit(%s,%s,%s)", (pid, t, me)).fetchone()[0] == "not_found"        # already pending
    assert app.execute("SELECT social_post_submit(%s,%s,%s)", (pid, t, ulid())).fetchone()[0] == "not_found"    # someone else's


def submitted(app, world):
    pid, _ = post(app, world)
    app.execute("SELECT social_post_submit(%s,%s,%s)", (pid, world["a"]["tenant"], world["a"]["user"]))
    return pid


def test_approval_and_sending_back(app, world):
    t, me = world["a"]["tenant"], world["a"]["user"]
    pid = submitted(app, world)
    boss = ulid()
    assert app.execute("SELECT social_post_decide(%s,%s,%s,false,%s)", (pid, t, boss, "  ছবি বদলান  ")).fetchone()[0] == "ok"
    assert row(app, pid)[0] == "draft" and row(app, pid)[8] == "ছবি বদলান" and row(app, pid)[9] is None
    app.execute("SELECT social_post_submit(%s,%s,%s)", (pid, t, me))
    assert row(app, pid)[8] is None                                                    # the note is cleared when sent again
    assert app.execute("SELECT social_post_decide(%s,%s,%s,true,NULL)", (pid, t, me)).fetchone()[0] == "ok"
    assert row(app, pid)[0] == "approved" and row(app, pid)[9] == me
    assert app.execute("SELECT social_post_decide(%s,%s,%s,true,NULL)", (pid, t, me)).fetchone()[0] == "not_found"   # only pending posts are decided
    assert app.execute("SELECT social_post_decide(%s,%s,%s,false,'x')", (pid, t, me)).fetchone()[0] == "not_found"


def test_a_note_is_trimmed_and_limited_and_empty_means_none(app, world):
    t = world["a"]["tenant"]
    pid = submitted(app, world)
    app.execute("SELECT social_post_decide(%s,%s,%s,false,%s)", (pid, t, ulid(), "ক" * 400))
    assert len(row(app, pid)[8]) == 300
    app.execute("SELECT social_post_submit(%s,%s,%s)", (pid, t, world["a"]["user"]))
    app.execute("SELECT social_post_decide(%s,%s,%s,false,'   ')", (pid, t, ulid()))
    assert row(app, pid)[8] is None


def test_decide_needs_an_approver(app, world):
    t = world["a"]["tenant"]
    pid = submitted(app, world)
    with pytest.raises(psycopg.Error) as e:
        app.execute("SELECT social_post_decide(%s,%s,NULL,true,NULL)", (pid, t))
    assert e.value.sqlstate == "22023"
    app.rollback()


def test_reopen_takes_pending_or_approved_back_to_draft_for_the_author_only(app, world):
    t, me = world["a"]["tenant"], world["a"]["user"]
    pid = submitted(app, world)
    assert app.execute("SELECT social_post_reopen(%s,%s,%s)", (pid, t, ulid())).fetchone()[0] == "not_found"
    assert app.execute("SELECT social_post_reopen(%s,%s,%s)", (pid, t, me)).fetchone()[0] == "ok" and row(app, pid)[0] == "draft"
    app.execute("SELECT social_post_submit(%s,%s,%s)", (pid, t, me))
    app.execute("SELECT social_post_decide(%s,%s,%s,true,NULL)", (pid, t, me))
    assert app.execute("SELECT social_post_reopen(%s,%s,%s)", (pid, t, me)).fetchone()[0] == "ok"
    assert row(app, pid)[0] == "draft" and row(app, pid)[9] is None
    assert app.execute("SELECT social_post_reopen(%s,%s,%s)", (pid, t, me)).fetchone()[0] == "not_found"        # a draft cannot be reopened


def approved(app, world):
    pid = submitted(app, world)
    app.execute("SELECT social_post_decide(%s,%s,%s,true,NULL)", (pid, world["a"]["tenant"], world["a"]["user"]))
    return pid


def test_mark_posted_only_for_approved_posts_by_the_author(app, world):
    t, me = world["a"]["tenant"], world["a"]["user"]
    pid, _ = post(app, world)
    assert app.execute("SELECT social_post_mark_posted(%s,%s,%s,NULL)", (pid, t, me)).fetchone()[0] == "not_found"       # still a draft
    pid = approved(app, world)
    assert app.execute("SELECT social_post_mark_posted(%s,%s,%s,NULL)", (pid, t, ulid())).fetchone()[0] == "not_found"
    for bad in ("http://x.com/p", "ftp://x", "javascript:alert(1)", "https://a b", "https://" + "a" * 300):
        assert app.execute("SELECT social_post_mark_posted(%s,%s,%s,%s)", (pid, t, me, bad)).fetchone()[0] == "bad_url"
    assert app.execute("SELECT social_post_mark_posted(%s,%s,%s,%s)", (pid, t, me, " https://facebook.com/p/1 ")).fetchone()[0] == "ok"
    assert row(app, pid)[0] == "posted" and row(app, pid)[10] is True and row(app, pid)[11] == "https://facebook.com/p/1"
    assert app.execute("SELECT social_post_mark_posted(%s,%s,%s,NULL)", (pid, t, me)).fetchone()[0] == "not_found"       # only once


def test_posted_without_a_link_is_fine(app, world):
    pid = approved(app, world)
    assert app.execute("SELECT social_post_mark_posted(%s,%s,%s,'')", (pid, world["a"]["tenant"], world["a"]["user"])).fetchone()[0] == "ok"
    assert row(app, pid)[11] is None


def test_cancel_and_delete_rules(app, world):
    t, me = world["a"]["tenant"], world["a"]["user"]
    d, _ = post(app, world)
    p = submitted(app, world)
    a = approved(app, world)
    for pid in (d, p, a):
        assert app.execute("SELECT social_post_cancel(%s,%s,%s)", (pid, t, ulid())).fetchone()[0] == "not_found"
        assert app.execute("SELECT social_post_cancel(%s,%s,%s)", (pid, t, me)).fetchone()[0] == "ok" and row(app, pid)[0] == "cancelled"
        assert app.execute("SELECT social_post_cancel(%s,%s,%s)", (pid, t, me)).fetchone()[0] == "not_found"
    posted = approved(app, world)
    app.execute("SELECT social_post_mark_posted(%s,%s,%s,NULL)", (posted, t, me))
    assert app.execute("SELECT social_post_cancel(%s,%s,%s)", (posted, t, me)).fetchone()[0] == "not_found"      # already published
    live = submitted(app, world)
    assert app.execute("SELECT social_post_delete(%s,%s,%s)", (live, t, me)).fetchone()[0] == "not_found"          # waiting for approval
    assert app.execute("SELECT social_post_delete(%s,%s,%s)", (d, t, ulid())).fetchone()[0] == "not_found"
    for pid in (d, posted):
        assert app.execute("SELECT social_post_delete(%s,%s,%s)", (pid, t, me)).fetchone()[0] == "ok"
        assert app.execute("SELECT count(*) FROM social_posts WHERE id=%s", (pid,)).fetchone()[0] == 0


def test_person_must_be_given(app, world):
    ctx(app, world)
    t = world["a"]["tenant"]
    for sql in ("SELECT social_post_submit(%s,%s,NULL)", "SELECT social_post_reopen(%s,%s,NULL)", "SELECT social_post_cancel(%s,%s,NULL)", "SELECT social_post_delete(%s,%s,NULL)", "SELECT social_post_mark_posted(%s,%s,NULL,NULL)"):
        with pytest.raises(psycopg.Error) as e:
            app.execute(sql, (ulid(), t))
        assert e.value.sqlstate == "22023"
        app.rollback()
        ctx(app, world)


def test_other_workspaces_cannot_see_or_change_posts(app, world):
    pid, _ = post(app, world)
    ctx(app, world, "b")
    assert app.execute("SELECT count(*) FROM social_posts").fetchone()[0] == 0
    for sql in ("SELECT social_post_submit(%s,%s,%s)", "SELECT social_post_cancel(%s,%s,%s)", "SELECT social_post_delete(%s,%s,%s)", "SELECT social_post_reopen(%s,%s,%s)"):
        with pytest.raises(psycopg.Error) as e:
            app.execute(sql, (pid, world["a"]["tenant"], world["a"]["user"]))
        assert e.value.sqlstate == "42501"
        app.rollback()
        ctx(app, world, "b")
    with pytest.raises(psycopg.Error) as e:
        app.execute("SELECT social_post_decide(%s,%s,%s,true,NULL)", (pid, world["a"]["tenant"], world["b"]["user"]))
    assert e.value.sqlstate == "42501"
    app.rollback()


def test_the_calendar_rules_are_enforced_by_the_table_itself(owner, app, world):
    pid, _ = post(app, world, when=None)
    as_admin(owner)
    for sql in ("UPDATE social_posts SET status='approved' WHERE id=%s",                      # approved needs a time
                "UPDATE social_posts SET status='posted' WHERE id=%s",                        # posted needs a time and posted_at
                "UPDATE social_posts SET posted_at=now() WHERE id=%s",                        # posted_at without the posted status
                "UPDATE social_posts SET media_kind='video' WHERE id=%s"):                    # media kind without a media id
        with pytest.raises(psycopg.Error):
            owner.execute(sql, (pid,))
        owner.rollback()
        as_admin(owner)


def test_app_role_cannot_write_the_table_directly(app, world):
    ctx(app, world)
    with pytest.raises(psycopg.Error):
        app.execute("INSERT INTO social_posts (id,tenant_id,user_id,title,platform,caption) VALUES (%s,%s,%s,'t','other','c')", (ulid(), world["a"]["tenant"], world["a"]["user"]))
    app.rollback()


def test_editing_a_sent_back_draft_clears_the_note(app, world):
    t = world["a"]["tenant"]
    pid = submitted(app, world)
    app.execute("SELECT social_post_decide(%s,%s,%s,false,'বদলান')", (pid, t, ulid()))
    assert row(app, pid)[8] == "বদলান"
    assert post(app, world, pid=pid, new=False, caption="নতুন")[1] == "ok"
    assert row(app, pid)[8] is None


def test_a_colleague_cannot_attach_someone_elses_design_or_video(app, world, owner, admin):
    t = world["a"]["tenant"]
    d = design(app, world)
    v = video(app, admin, world)
    other = ulid()
    as_admin(owner)
    owner.execute("INSERT INTO users (id,name,email,password,email_verified_at) VALUES (%s,'c',%s,'x',now())", (other, f"c-{other}@example.com"))
    owner.execute("INSERT INTO tenant_users (id,tenant_id,user_id,joined_at) VALUES (%s,%s,%s,now())", (ulid(), t, other))
    ctx(app, world)
    for kind, media, size in (("design", d, "post"), ("video", v, None)):
        assert app.execute(SAVE, (True, ulid(), t, other, "t", "facebook", "c", kind, media, size, WHEN)).fetchone()[0] == "bad_media"
        assert app.execute(SAVE, (True, ulid(), t, world["a"]["user"], "t", "facebook", "c", kind, media, size, WHEN)).fetchone()[0] == "ok"
