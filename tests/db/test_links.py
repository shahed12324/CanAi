import psycopg
import pytest

from conftest import as_admin, ulid
from test_accounting import ctx


@pytest.fixture(autouse=True)
def clean(owner, world):
    yield
    as_admin(owner)
    owner.execute("DELETE FROM user_links")


def add(app, world, *, kind="ics", name="কাজের ক্যালেন্ডার", ct="CIPHER", ver=1, host="calendar.google.com", who="a", user=None, lid=None):
    ctx(app, world, who)
    lid = lid or ulid()
    r = app.execute("SELECT link_add(%s,%s,%s,%s,%s,%s,%s,%s)", (lid, world[who]["tenant"], user or world[who]["user"], kind, name, ct, ver, host)).fetchone()[0]
    return lid, r


def rows(app, world, who="a"):
    ctx(app, world, who)
    return app.execute("SELECT kind, name, secret_ct, secret_ver, host, last_used_at, last_error FROM user_links ORDER BY created_at, name").fetchall()


def test_add_normalises_name_and_stores_only_what_it_is_given(app, world):
    lid, r = add(app, world, name="  কাজের   ক্যালেন্ডার ")
    assert r == "ok"
    assert rows(app, world) == [("ics", "কাজের ক্যালেন্ডার", "CIPHER", 1, "calendar.google.com", None, None)]


@pytest.mark.parametrize("kw", [{"kind": "email"}, {"kind": None}, {"name": ""}, {"name": "  "}, {"name": None}, {"name": "ক" * 61}, {"ct": ""}, {"ct": None}, {"ver": 0}, {"ver": None}, {"host": "h" * 101}])
def test_bad_input(app, world, kw):
    assert add(app, world, **kw)[1] == "bad_input"
    assert rows(app, world) == []


def test_exact_limits(app, world):
    assert add(app, world, name="ক" * 60, host="h" * 100)[1] == "ok"
    for i in range(4):
        assert add(app, world, name=f"n{i}")[1] == "ok"
    assert add(app, world, name="sixth")[1] == "limit"
    assert add(app, world, kind="slack", name="n0")[1] == "ok"        # the limit is per kind
    assert add(app, world, kind="discord", name="n0")[1] == "ok"


def test_name_is_unique_per_person_and_kind_ignoring_case(app, world):
    assert add(app, world, name="Work")[1] == "ok"
    assert add(app, world, name=" work ")[1] == "duplicate"
    assert add(app, world, kind="slack", name="Work")[1] == "ok"
    lid = ulid()
    assert add(app, world, name="Other", lid=lid)[1] == "ok"
    assert add(app, world, name="Other2", lid=lid)[1] == "duplicate"      # same id: nothing overwritten
    assert [r[1] for r in rows(app, world) if r[0] == "ics"] == ["Work", "Other"]


def test_person_must_be_given(app, world):
    ctx(app, world)
    for sql in ("SELECT link_add(%s,%s,NULL,'ics','n','c',1,'h')", "SELECT link_delete(%s,%s,NULL)", "SELECT link_touch(%s,%s,NULL,NULL)"):
        with pytest.raises(psycopg.Error) as e:
            app.execute(sql, (ulid(), world["a"]["tenant"]))
        assert e.value.sqlstate == "22023"
        app.rollback()


def test_delete_and_touch_only_for_the_owner(app, world):
    lid, _ = add(app, world)
    ctx(app, world)
    me = world["a"]["user"]
    assert app.execute("SELECT link_touch(%s,%s,%s,%s)", (lid, world["a"]["tenant"], me, "x" * 300)).fetchone()[0] == "ok"
    r = rows(app, world)[0]
    assert r[5] is not None and r[6] == "x" * 200
    assert app.execute("SELECT link_touch(%s,%s,%s,NULL)", (lid, world["a"]["tenant"], me)).fetchone()[0] == "ok"
    assert rows(app, world)[0][6] is None
    assert app.execute("SELECT link_delete(%s,%s,%s)", (lid, world["a"]["tenant"], ulid())).fetchone()[0] == "not_found"
    assert app.execute("SELECT link_touch(%s,%s,%s,NULL)", (lid, world["a"]["tenant"], ulid())).fetchone()[0] == "not_found"
    assert len(rows(app, world)) == 1
    assert app.execute("SELECT link_delete(%s,%s,%s)", (lid, world["a"]["tenant"], me)).fetchone()[0] == "ok"
    assert app.execute("SELECT link_delete(%s,%s,%s)", (lid, world["a"]["tenant"], me)).fetchone()[0] == "not_found"


def test_other_workspaces_cannot_see_or_change_links(app, world):
    lid, _ = add(app, world)
    assert rows(app, world, "b") == []
    ctx(app, world, "b")
    for sql, args in (("SELECT link_delete(%s,%s,%s)", (lid, world["a"]["tenant"], world["a"]["user"])), ("SELECT link_touch(%s,%s,%s,NULL)", (lid, world["a"]["tenant"], world["a"]["user"]))):
        with pytest.raises(psycopg.Error) as e:
            app.execute(sql, args)
        assert e.value.sqlstate == "42501"
        app.rollback()
    assert len(rows(app, world)) == 1


def test_app_role_cannot_write_the_table_directly(app, world):
    ctx(app, world)
    with pytest.raises(psycopg.Error):
        app.execute("INSERT INTO user_links (id, tenant_id, user_id, kind, name, secret_ct, secret_ver) VALUES (%s,%s,%s,'ics','n','c',1)", (ulid(), world["a"]["tenant"], world["a"]["user"]))
    app.rollback()


def test_calendar_kinds_are_accepted_with_their_own_limit(app, world):
    assert add(app, world, kind="gcal", name="me0@x.com", host="google")[1] == "ok"
    assert add(app, world, kind="gcal", name="ME0@x.com")[1] == "duplicate"        # same e-mail, any case
    assert add(app, world, kind="ics", name="me0@x.com")[1] == "ok"                # a different kind
    for k, first in (("gcal", 1), ("mcal", 0)):
        for i in range(first, 5):
            assert add(app, world, kind=k, name=f"me{i}@x.com", host="google")[1] == "ok"
        assert add(app, world, kind=k, name="me6@x.com")[1] == "limit"


def update(app, world, lid, ct="NEW", ver=2, who="a", user=None):
    ctx(app, world, who)
    return app.execute("SELECT link_secret_update(%s,%s,%s,%s,%s)", (lid, world[who]["tenant"], user or world[who]["user"], ct, ver)).fetchone()[0]


def test_secret_update_changes_only_the_secret_of_a_calendar_link(app, world):
    lid, _ = add(app, world, kind="gcal", name="me@x.com", host="google")
    ctx(app, world)
    assert app.execute("SELECT link_touch(%s,%s,%s,'old error')", (lid, world["a"]["tenant"], world["a"]["user"])).fetchone()[0] == "ok"
    assert update(app, world, lid) == "ok"
    assert rows(app, world) == [("gcal", "me@x.com", "NEW", 2, "google", rows(app, world)[0][5], None)]      # secret replaced, stale error cleared, name/host untouched


@pytest.mark.parametrize("kw", [{"ct": ""}, {"ct": None}, {"ver": 0}, {"ver": None}])
def test_secret_update_rejects_empty_values(app, world, kw):
    lid, _ = add(app, world, kind="gcal", name="me@x.com")
    assert update(app, world, lid, **kw) == "bad_input"
    assert rows(app, world)[0][2] == "CIPHER"


def test_secret_update_is_for_the_owner_and_calendar_links_only(app, world):
    ics, _ = add(app, world, kind="ics", name="feed")
    cal, _ = add(app, world, kind="mcal", name="me@x.com")
    assert update(app, world, ics) == "not_found"                  # a Slack webhook / ICS address is never replaced this way
    assert update(app, world, cal, user=ulid()) == "not_found"     # someone else's link
    assert [r[2] for r in rows(app, world)] == ["CIPHER", "CIPHER"]
    ctx(app, world, "a")
    with pytest.raises(psycopg.Error):
        app.execute("SELECT link_secret_update(%s,%s,NULL,'c',1)", (cal, world["a"]["tenant"]))
    app.rollback()
    ctx(app, world, "b")
    with pytest.raises(psycopg.Error) as e:
        app.execute("SELECT link_secret_update(%s,%s,%s,'c',1)", (cal, world["a"]["tenant"], world["a"]["user"]))
    assert e.value.sqlstate == "42501"
    app.rollback()
