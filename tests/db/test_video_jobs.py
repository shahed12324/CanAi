import psycopg
import pytest

from conftest import as_admin, ulid
from test_accounting import ctx

BRAND = '{"primary":"#112233","accent":"#445566","name":"GV"}'
SCENES = '[{"headline":"H","seconds":3}]'
CREATE = "SELECT video_job_create(%s,%s,%s,%s,%s,%s::jsonb,%s,%s,%s::jsonb,%s,%s,%s,%s,%s)"


@pytest.fixture(autouse=True)
def clean(owner, world):
    yield
    as_admin(owner)
    owner.execute("DELETE FROM video_jobs")


def make(app, world, *, who="a", user=None, jid=None, title="ঈদ ভিডিও", size="post", scenes=SCENES, narr=None, voice=None, brand=BRAND, logo=None, audio=None, res=None, amount="3.0000", secs="6"):
    ctx(app, world, who)
    jid = jid or ulid()
    r = app.execute(CREATE, (jid, world[who]["tenant"], user or world[who]["user"], title, size, scenes, narr, voice, brand, logo, audio, res or ulid(), amount, secs)).fetchone()[0]
    return jid, r


def row(app, jid):
    return app.execute("SELECT status, title, size_key, narration, voice, audio_path, amount, charged, seconds, file_path, bytes, error, started_at IS NOT NULL, finished_at IS NOT NULL FROM video_jobs WHERE id=%s", (jid,)).fetchone()


def claim(admin, n=1):
    as_admin(admin)
    return admin.execute("SELECT job_id, tenant_id, user_id FROM video_job_claim(%s)", (n,)).fetchall()


def test_create_normalises_the_title_and_starts_queued(app, world):
    jid, r = make(app, world, title="  ঈদ   ভিডিও ", narr="বলার লেখা", voice="alloy", audio="video-src/x.mp3")
    assert r == "ok"
    assert row(app, jid) == ("queued", "ঈদ ভিডিও", "post", "বলার লেখা", "alloy", "video-src/x.mp3", 3, None, 6, None, None, None, False, False)


@pytest.mark.parametrize("kw", [{"title": ""}, {"title": "  "}, {"title": None}, {"title": "ক" * 121}, {"size": "cover"}, {"size": None}, {"scenes": "[]"}, {"scenes": "{}"}, {"scenes": "[" + ",".join(["{}"] * 11) + "]"},
                                {"brand": "[]"}, {"brand": None}, {"narr": "x" * 2001}, {"voice": "v" * 21}, {"logo": "x" * 900001}, {"audio": "p" * 301}, {"amount": "0"}, {"amount": "-1"}, {"amount": None},
                                {"secs": "1.9"}, {"secs": "60.1"}, {"secs": None}])
def test_bad_input(app, world, kw):
    assert make(app, world, **kw)[1] == "bad_input"
    assert app.execute("SELECT count(*) FROM video_jobs").fetchone()[0] == 0


def test_exact_edges(app, world):
    assert make(app, world, title="ক" * 120, scenes="[" + ",".join(["{}"] * 10) + "]", narr="x" * 2000, voice="v" * 20, logo="x" * 900000, audio="p" * 300, secs="2")[1] == "ok"
    assert make(app, world, secs="60", size="ad")[1] == "ok"


def test_three_active_jobs_per_person_and_thirty_in_all(app, world, admin):
    for i in range(3):
        assert make(app, world)[1] == "ok"
    assert make(app, world)[1] == "limit"
    assert make(app, world, who="b")[1] == "ok"                    # the limit is per person
    assert len(claim(admin, 5)) == 4                              # all four are now rendering: still "active"
    assert make(app, world)[1] == "limit"
    as_admin(admin)
    ctx(app, world)
    for (jid,) in app.execute("SELECT id FROM video_jobs WHERE user_id=%s", (world["a"]["user"],)).fetchall():
        admin.execute("SELECT video_job_fail(%s,%s,'x')", (jid, world["a"]["tenant"]))
    for i in range(27):
        assert make(app, world)[1] == "ok"
        ctx(app, world)
        jid = app.execute("SELECT id FROM video_jobs WHERE user_id=%s ORDER BY created_at DESC LIMIT 1", (world["a"]["user"],)).fetchone()[0]
        assert app.execute("SELECT video_job_cancel(%s,%s,%s)", (jid, world["a"]["tenant"], world["a"]["user"])).fetchone()[0] == "ok"
    assert make(app, world)[1] == "limit"                          # 30 kept


def test_same_id_never_overwrites(app, world):
    jid, _ = make(app, world, title="first")
    make(app, world, jid=jid, title="second")
    assert row(app, jid)[1] == "first"


def test_person_must_be_given(app, world):
    ctx(app, world)
    for sql, args in ((CREATE, (ulid(), world["a"]["tenant"], None, "t", "post", SCENES, None, None, BRAND, None, None, ulid(), "3", "6")),
                      ("SELECT video_job_cancel(%s,%s,NULL)", (ulid(), world["a"]["tenant"])), ("SELECT video_job_delete(%s,%s,NULL)", (ulid(), world["a"]["tenant"]))):
        with pytest.raises(psycopg.Error) as e:
            app.execute(sql, args)
        assert e.value.sqlstate == "22023"
        app.rollback()


def test_cancel_only_before_the_job_starts_and_only_by_the_owner(app, world, admin):
    jid, _ = make(app, world)
    me = world["a"]["user"]
    assert app.execute("SELECT video_job_cancel(%s,%s,%s)", (jid, world["a"]["tenant"], ulid())).fetchone()[0] == "not_found"
    assert app.execute("SELECT video_job_cancel(%s,%s,%s)", (jid, world["a"]["tenant"], me)).fetchone()[0] == "ok"
    assert row(app, jid)[0] == "cancelled" and row(app, jid)[13] is True
    assert app.execute("SELECT video_job_cancel(%s,%s,%s)", (jid, world["a"]["tenant"], me)).fetchone()[0] == "not_found"
    j2, _ = make(app, world)
    claim(admin)
    ctx(app, world)
    assert app.execute("SELECT video_job_cancel(%s,%s,%s)", (j2, world["a"]["tenant"], me)).fetchone()[0] == "not_found"      # already rendering
    assert row(app, j2)[0] == "rendering"


def test_delete_only_finished_jobs_by_the_owner(app, world, admin):
    jid, _ = make(app, world)
    me = world["a"]["user"]
    t = world["a"]["tenant"]
    assert app.execute("SELECT video_job_delete(%s,%s,%s)", (jid, t, me)).fetchone()[0] == "not_found"       # waiting
    claim(admin)
    ctx(app, world)
    assert app.execute("SELECT video_job_delete(%s,%s,%s)", (jid, t, me)).fetchone()[0] == "not_found"       # rendering
    admin.execute("SELECT video_job_fail(%s,%s,'boom')", (jid, t))
    assert app.execute("SELECT video_job_delete(%s,%s,%s)", (jid, t, ulid())).fetchone()[0] == "not_found"
    assert app.execute("SELECT video_job_delete(%s,%s,%s)", (jid, t, me)).fetchone()[0] == "ok"
    assert app.execute("SELECT video_job_delete(%s,%s,%s)", (jid, t, me)).fetchone()[0] == "not_found"


def test_claim_takes_each_job_once_oldest_first_and_only_for_admin(app, world, admin, owner):
    a, _ = make(app, world)
    b, _ = make(app, world, who="b")
    c, _ = make(app, world)
    got = claim(admin, 2)
    assert [g[0] for g in got] == [a, b]
    assert (got[0][1], got[0][2]) == (world["a"]["tenant"], world["a"]["user"])
    assert [g[0] for g in claim(admin, 5)] == [c]
    assert claim(admin) == []
    ctx(app, world)
    with pytest.raises(psycopg.Error) as e:
        app.execute("SELECT * FROM video_job_claim(1)")
    assert e.value.sqlstate == "42501"
    app.rollback()


def test_claim_limit_is_clamped(app, world, admin):
    for i in range(3):
        make(app, world)
    assert len(claim(admin, 0)) == 1
    assert len(claim(admin, None)) == 1
    assert len(claim(admin, 99)) == 1


def test_done_needs_a_rendering_job_and_a_real_file(app, world, admin):
    jid, _ = make(app, world)
    t = world["a"]["tenant"]
    as_admin(admin)
    assert admin.execute("SELECT video_job_done(%s,%s,'f.mp4',100,3)", (jid, t)).fetchone()[0] == "not_found"      # still queued
    claim(admin)
    for args in (("", 100), ("  ", 100), (None, 100), ("f" * 301, 100), ("f.mp4", 0), ("f.mp4", None)):
        assert admin.execute("SELECT video_job_done(%s,%s,%s,%s,3)", (jid, t) + args).fetchone()[0] == "not_found"
    assert admin.execute("SELECT video_job_done(%s,%s,'videos/f.mp4',1234,2.5)", (jid, world["b"]["tenant"])).fetchone()[0] == "not_found"      # wrong workspace
    assert admin.execute("SELECT video_job_done(%s,%s,'videos/f.mp4',1234,2.5)", (jid, t)).fetchone()[0] == "ok"
    ctx(app, world)
    s = row(app, jid)
    assert (s[0], s[7], s[9], s[10], s[11], s[13]) == ("done", 2.5, "videos/f.mp4", 1234, None, True)
    assert admin.execute("SELECT video_job_done(%s,%s,'g.mp4',9,1)", (jid, t)).fetchone()[0] == "not_found"        # only once
    assert admin.execute("SELECT video_job_fail(%s,%s,'late')", (jid, t)).fetchone()[0] == "not_found"            # a done job cannot fail


def test_fail_records_a_short_reason_once(app, world, admin):
    jid, _ = make(app, world)
    t = world["a"]["tenant"]
    as_admin(admin)
    assert admin.execute("SELECT video_job_fail(%s,%s,'x')", (jid, t)).fetchone()[0] == "not_found"        # not rendering yet
    claim(admin)
    assert admin.execute("SELECT video_job_fail(%s,%s,%s)", (jid, world["b"]["tenant"], "x")).fetchone()[0] == "not_found"
    assert admin.execute("SELECT video_job_fail(%s,%s,%s)", (jid, t, "ঘ" * 300)).fetchone()[0] == "ok"
    ctx(app, world)
    assert row(app, jid)[11] == "ঘ" * 200 and row(app, jid)[0] == "failed"
    assert admin.execute("SELECT video_job_fail(%s,%s,'again')", (jid, t)).fetchone()[0] == "not_found"
    j2, _ = make(app, world)
    claim(admin)
    admin.execute("SELECT video_job_fail(%s,%s,NULL)", (j2, t))
    ctx(app, world)
    assert row(app, j2)[11] == "ব্যর্থ"


def test_a_job_stuck_rendering_is_failed_by_the_next_claim(app, world, admin, owner):
    jid, _ = make(app, world)
    claim(admin)
    as_admin(owner)
    owner.execute("UPDATE video_jobs SET started_at = now() - interval '21 minutes' WHERE id=%s", (jid,))
    assert claim(admin) == []
    ctx(app, world)
    assert row(app, jid)[0] == "failed" and "আটকে" in row(app, jid)[11]


def test_other_workspaces_cannot_see_or_change_jobs(app, world):
    jid, _ = make(app, world)
    ctx(app, world, "b")
    assert app.execute("SELECT count(*) FROM video_jobs").fetchone()[0] == 0
    for sql in ("SELECT video_job_cancel(%s,%s,%s)", "SELECT video_job_delete(%s,%s,%s)"):
        with pytest.raises(psycopg.Error) as e:
            app.execute(sql, (jid, world["a"]["tenant"], world["a"]["user"]))
        assert e.value.sqlstate == "42501"
        app.rollback()


def test_the_done_flag_and_file_must_agree(app, world, owner):
    jid, _ = make(app, world)
    as_admin(owner)
    with pytest.raises(psycopg.Error):
        owner.execute("UPDATE video_jobs SET status='done' WHERE id=%s", (jid,))
    owner.rollback()
    as_admin(owner)
    with pytest.raises(psycopg.Error):
        owner.execute("UPDATE video_jobs SET file_path='x' WHERE id=%s", (jid,))
    owner.rollback()


def test_app_role_cannot_write_the_table_directly(app, world):
    ctx(app, world)
    with pytest.raises(psycopg.Error):
        app.execute("INSERT INTO video_jobs (id,tenant_id,user_id,title,size_key,scenes,brand,amount,seconds) VALUES (%s,%s,%s,'t','post','[{}]','{}',1,3)", (ulid(), world["a"]["tenant"], world["a"]["user"]))
    app.rollback()


def test_video_is_an_allowed_usage_source(owner):
    as_admin(owner)
    assert "'video'" in owner.execute("SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conname='usage_records_source_check'").fetchone()[0]
