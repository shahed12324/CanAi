import datetime as dt

import psycopg
import pytest

from conftest import as_admin, ulid
from test_accounting import ctx
from test_memory import second_user  # noqa: F401  (fixture)


@pytest.fixture(autouse=True)
def clean(owner, world):
    yield
    as_admin(owner)
    for t in ("tasks", "notes"):
        owner.execute(f"DELETE FROM {t}")
    owner.execute("DELETE FROM inbox_notifications WHERE kind = 'task_reminder'")


def ago(**kw):
    return dt.datetime.now(dt.timezone.utc) - dt.timedelta(**kw)


def add(app, world, title="বিল দিতে হবে", *, details="", prio=2, due=None, remind=None, who="a", user=None, tid=None):
    ctx(app, world, who)
    tid = tid or ulid()
    r = app.execute("SELECT task_add(%s,%s,%s,%s,%s,%s,%s,%s)", (tid, world[who]["tenant"], user or world[who]["user"], title, details, prio, due, remind)).fetchone()[0]
    return tid, r


def edit(app, world, tid, title="নতুন", *, details="", prio=2, due=None, remind=None, who="a", user=None):
    ctx(app, world, who)
    return app.execute("SELECT task_edit(%s,%s,%s,%s,%s,%s,%s,%s)", (tid, world[who]["tenant"], user or world[who]["user"], title, details, prio, due, remind)).fetchone()[0]


def done(app, world, tid, flag=True, who="a", user=None):
    ctx(app, world, who)
    return app.execute("SELECT task_set_done(%s,%s,%s,%s)", (tid, world[who]["tenant"], user or world[who]["user"], flag)).fetchone()[0]


def trows(app, world, who="a"):
    ctx(app, world, who)
    return app.execute("SELECT title, details, priority, due_on, status, done_at IS NOT NULL FROM tasks ORDER BY title").fetchall()


def test_add_normalises_and_stores(app, world):
    assert add(app, world, "  বিল   দিতে\n হবে ", details="  বিদ্যুৎ  ", prio=1, due="2026-11-30")[1] == "ok"
    assert trows(app, world) == [("বিল দিতে হবে", "বিদ্যুৎ", 1, __import__("datetime").date(2026, 11, 30), "open", False)]


@pytest.mark.parametrize("title,details,prio", [("", "", 2), ("   ", "", 2), (None, "", 2), ("ক" * 201, "", 2), ("ok", "ক" * 2001, 2), ("ok", "", 0), ("ok", "", 4)])
def test_bad_input(app, world, title, details, prio):
    assert add(app, world, title, details=details, prio=prio)[1] == "bad_input"
    assert trows(app, world) == []


def test_limits_are_exact(app, world):
    assert add(app, world, "ক" * 200, details="খ" * 2000)[1] == "ok"


def test_open_task_limit_ignores_finished_ones(app, world, owner):
    as_admin(owner)
    t, u = world["a"]["tenant"], world["a"]["user"]
    owner.execute("INSERT INTO tasks (id,tenant_id,user_id,title) SELECT lpad(g::text,26,'t'),%s,%s,'t '||g FROM generate_series(1,500) g", (t, u))
    assert add(app, world, "একটি বেশি")[1] == "limit"
    tid = "0" * 25 + "1"
    as_admin(owner)
    owner.execute("UPDATE tasks SET status='done', done_at=now() WHERE id = lpad('1',26,'t')")
    assert add(app, world, "এখন জায়গা আছে")[1] == "ok"


def test_user_required_and_tenant_check(app, world):
    ctx(app, world, "a")
    with pytest.raises(psycopg.errors.InvalidParameterValue):
        app.execute("SELECT task_add(%s,%s,NULL,'x',NULL,2,NULL,NULL)", (ulid(), world["a"]["tenant"]))
    ctx(app, world, "b")
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("SELECT task_add(%s,%s,%s,'x',NULL,2,NULL,NULL)", (ulid(), world["a"]["tenant"], world["b"]["user"]))


def test_edit_replaces_fields_and_rearms_reminder_only_when_time_changes(app, world, owner):
    when = ago(days=10)
    tid, _ = add(app, world, "পুরনো", remind=when)
    as_admin(owner)
    owner.execute("UPDATE tasks SET reminded_at = now() WHERE id = %s", (tid,))
    assert edit(app, world, tid, "নতুন শিরোনাম", prio=3, remind=when) == "ok"
    as_admin(owner)
    assert owner.execute("SELECT title, priority, reminded_at IS NOT NULL FROM tasks WHERE id=%s", (tid,)).fetchone() == ("নতুন শিরোনাম", 3, True)
    assert edit(app, world, tid, "নতুন শিরোনাম", remind="2026-02-02 10:00+06") == "ok"
    as_admin(owner)
    assert owner.execute("SELECT reminded_at IS NULL FROM tasks WHERE id=%s", (tid,)).fetchone()[0] is True
    assert edit(app, world, tid, "নতুন শিরোনাম", remind=None) == "ok"
    as_admin(owner)
    assert owner.execute("SELECT remind_at IS NULL FROM tasks WHERE id=%s", (tid,)).fetchone()[0] is True


def test_edit_validates_and_missing(app, world):
    tid, _ = add(app, world)
    assert edit(app, world, tid, "") == "bad_input"
    assert edit(app, world, tid, "x", prio=9) == "bad_input"
    assert edit(app, world, ulid()) == "not_found"


def test_done_and_undone(app, world):
    tid, _ = add(app, world)
    assert done(app, world, tid) == "ok"
    assert trows(app, world)[0][4:] == ("done", True)
    assert done(app, world, tid, False) == "ok"
    assert trows(app, world)[0][4:] == ("open", False)
    assert done(app, world, ulid()) == "not_found"


def test_people_cannot_touch_each_others_tasks(app, world, second_user, owner):
    tid, _ = add(app, world, "আমার")
    assert edit(app, world, tid, "হ্যাক", user=second_user) == "not_found"
    assert done(app, world, tid, user=second_user) == "not_found"
    ctx(app, world, "a")
    assert app.execute("SELECT task_delete(%s,%s,%s)", (tid, world["a"]["tenant"], second_user)).fetchone()[0] == "not_found"
    assert app.execute("SELECT task_clear_done(%s,%s)", (world["a"]["tenant"], second_user)).fetchone()[0] == 0
    assert trows(app, world) == [("আমার", "", 2, None, "open", False)]


def test_other_workspace_sees_and_changes_nothing(app, world):
    tid, _ = add(app, world)
    assert trows(app, world, "b") == []
    ctx(app, world, "b")
    assert app.execute("SELECT task_delete(%s,%s,%s)", (tid, world["b"]["tenant"], world["b"]["user"])).fetchone()[0] == "not_found"
    assert len(trows(app, world, "a")) == 1


def test_delete_and_clear_done(app, world):
    t1, _ = add(app, world, "এক")
    t2, _ = add(app, world, "দুই")
    t3, _ = add(app, world, "তিন")
    done(app, world, t1)
    done(app, world, t2)
    ctx(app, world, "a")
    assert app.execute("SELECT task_clear_done(%s,%s)", (world["a"]["tenant"], world["a"]["user"])).fetchone()[0] == 2
    assert [r[0] for r in trows(app, world)] == ["তিন"]
    assert app.execute("SELECT task_delete(%s,%s,%s)", (t3, world["a"]["tenant"], world["a"]["user"])).fetchone()[0] == "ok"
    assert app.execute("SELECT task_delete(%s,%s,%s)", (t3, world["a"]["tenant"], world["a"]["user"])).fetchone()[0] == "not_found"


def test_app_cannot_write_tables_directly(app, world):
    ctx(app, world, "a")
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("INSERT INTO tasks (id,tenant_id,user_id,title) VALUES (%s,%s,%s,'x')", (ulid(), world["a"]["tenant"], world["a"]["user"]))
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("UPDATE tasks SET title='x'")
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("DELETE FROM notes")


def test_done_status_and_time_must_agree(owner, world):
    as_admin(owner)
    with pytest.raises(psycopg.errors.CheckViolation):
        owner.execute("INSERT INTO tasks (id,tenant_id,user_id,title,status) VALUES (%s,%s,%s,'x','done')", (ulid(), world["a"]["tenant"], world["a"]["user"]))


# ---- notes ----

def note(app, world, title="নোট", body="বিস্তারিত", *, pinned=False, new=True, nid=None, who="a", user=None):
    ctx(app, world, who)
    nid = nid or ulid()
    r = app.execute("SELECT note_save(%s,%s,%s,%s,%s,%s,%s)", (nid, world[who]["tenant"], user or world[who]["user"], title, body, pinned, new)).fetchone()[0]
    return nid, r


def nrows(app, world, who="a"):
    ctx(app, world, who)
    return app.execute("SELECT title, body, pinned FROM notes ORDER BY title").fetchall()


def test_note_add_edit_delete(app, world):
    nid, r = note(app, world, " শিরোনাম ", " বডি ", pinned=True)
    assert r == "ok" and nrows(app, world) == [("শিরোনাম", "বডি", True)]
    assert note(app, world, "বদলানো", "নতুন", new=False, nid=nid, pinned=None)[1] == "ok"
    assert nrows(app, world) == [("বদলানো", "নতুন", True)]          # pinned unchanged when NULL
    ctx(app, world, "a")
    assert app.execute("SELECT note_delete(%s,%s,%s)", (nid, world["a"]["tenant"], world["a"]["user"])).fetchone()[0] == "ok"
    assert nrows(app, world) == []


@pytest.mark.parametrize("title,body", [("", ""), ("  ", " "), (None, None), ("ক" * 201, "x"), ("x", "ক" * 20001)])
def test_note_bad_input(app, world, title, body):
    assert note(app, world, title, body)[1] == "bad_input"


def test_note_title_or_body_alone_is_fine_and_size_limits_exact(app, world):
    assert note(app, world, "শুধু শিরোনাম", "")[1] == "ok"
    assert note(app, world, "", "শুধু বডি")[1] == "ok"
    assert note(app, world, "ক" * 200, "খ" * 20000)[1] == "ok"


def test_note_limit(app, world, owner):
    as_admin(owner)
    owner.execute("INSERT INTO notes (id,tenant_id,user_id,title) SELECT lpad(g::text,26,'n'),%s,%s,'n '||g FROM generate_series(1,1000) g", (world["a"]["tenant"], world["a"]["user"]))
    assert note(app, world)[1] == "limit"


def test_note_isolation(app, world, second_user):
    nid, _ = note(app, world)
    assert note(app, world, "হ্যাক", "x", new=False, nid=nid, user=second_user)[1] == "not_found"
    ctx(app, world, "a")
    assert app.execute("SELECT note_delete(%s,%s,%s)", (nid, world["a"]["tenant"], second_user)).fetchone()[0] == "not_found"
    assert nrows(app, world, "b") == []
    assert note(app, world, "x", "y", new=False, nid=ulid())[1] == "not_found"


# ---- reminder dispatch ----

def dispatch(admin, limit=200):
    as_admin(admin)
    return admin.execute("SELECT task_reminders_dispatch(%s)", (limit,)).fetchone()[0]


def notes_for(owner, user):
    as_admin(owner)
    return owner.execute("SELECT title, ref_kind, ref_id, kind FROM inbox_notifications WHERE user_id=%s AND kind='task_reminder'", (user,)).fetchall()


def test_due_reminder_notifies_once(app, world, admin, owner):
    tid, _ = add(app, world, "মিটিং", remind=ago(hours=1))
    assert dispatch(admin) >= 1
    assert notes_for(owner, world["a"]["user"]) == [("রিমাইন্ডার: মিটিং", "task", tid, "task_reminder")]
    assert dispatch(admin) == 0
    assert len(notes_for(owner, world["a"]["user"])) == 1


def test_future_done_and_unset_reminders_do_not_fire(app, world, admin, owner):
    add(app, world, "ভবিষ্যৎ", remind="2999-01-01 00:00+00")
    add(app, world, "কোনোটি নেই")
    t3, _ = add(app, world, "শেষ", remind=ago(hours=1))
    done(app, world, t3)
    assert dispatch(admin) == 0
    assert notes_for(owner, world["a"]["user"]) == []


def test_untick_after_done_still_reminds_once(app, world, admin, owner):
    tid, _ = add(app, world, "ফেরত", remind=ago(hours=1))
    done(app, world, tid)
    done(app, world, tid, False)
    assert dispatch(admin) == 1


def test_rearmed_reminder_fires_again(app, world, admin, owner):
    tid, _ = add(app, world, "আবার", remind=ago(hours=1))
    dispatch(admin)
    edit(app, world, tid, "আবার", remind=ago(minutes=30))
    assert dispatch(admin) == 1
    assert len(notes_for(owner, world["a"]["user"])) == 2


def test_very_old_reminders_are_marked_without_flooding(app, world, admin, owner):
    tid, _ = add(app, world, "পুরনো", remind="2020-01-01 09:00+06")
    assert dispatch(admin) == 0
    assert notes_for(owner, world["a"]["user"]) == []
    as_admin(owner)
    assert owner.execute("SELECT reminded_at IS NOT NULL FROM tasks WHERE id=%s", (tid,)).fetchone()[0] is True


def test_dispatch_limit_and_other_users(app, world, admin, owner, second_user):
    for i in range(3):
        add(app, world, f"টাস্ক {i}", remind=ago(hours=1))
    add(app, world, "অন্যজনের", remind=ago(hours=1), user=second_user)
    assert dispatch(admin, 2) == 2
    assert dispatch(admin, 200) == 2
    assert len(notes_for(owner, second_user)) == 1


def test_only_admin_may_dispatch(app, world):
    ctx(app, world, "a")
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("SELECT task_reminders_dispatch(10)")
