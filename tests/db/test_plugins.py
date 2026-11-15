"""Phase 27.2e: plugin store."""
import json

import psycopg
import pytest
from psycopg.types.json import Jsonb

from conftest import as_admin, ulid
from test_accounting import ctx

MAN = {"workflows": [{"name": "w1", "description": "", "category": "personal", "definition": {"start": "n1", "nodes": {"n1": {"type": "output", "text": "hi"}}}}]}
SUBMIT = "SELECT plugin_submit(%s,%s,%s,%s,%s,%s,%s,%s::text[])"


@pytest.fixture(autouse=True)
def clean(owner, world):
    yield
    as_admin(owner)
    for t in ("plugin_installs", "plugin_versions"):
        owner.execute(f"DELETE FROM {t}")


def submit(app, world, *, who="a", slug="daily-brief", name="Daily brief", desc="d", manifest=None, tools=(), pid=None, user=None):
    ctx(app, world, who)
    pid = pid or ulid()
    r = app.execute(SUBMIT, (pid, world[who]["tenant"], user or world[who]["user"], slug, name, desc, Jsonb(MAN if manifest is None else manifest), list(tools))).fetchone()[0]
    return pid, r


def review(admin, pid, decision="published", note=None):
    as_admin(admin)
    return admin.execute("SELECT plugin_review(%s,%s,%s)", (pid, decision, note)).fetchone()[0]


def record(app, world, slug="daily-brief", version=1, ids=("w" * 26,), who="a"):
    ctx(app, world, who)
    return app.execute("SELECT plugin_install_record(%s,%s,%s,%s,%s,%s::text[])", (ulid(), world[who]["tenant"], world[who]["user"], slug, version, list(ids))).fetchone()[0]


def test_submit_numbers_versions_by_itself_and_starts_pending(app, world, owner):
    a, r1 = submit(app, world)
    b, r2 = submit(app, world)
    assert (r1, r2) == ("ok", "ok")
    as_admin(owner)
    assert owner.execute("SELECT version, status FROM plugin_versions ORDER BY version").fetchall() == [(1, "pending"), (2, "pending")]


@pytest.mark.parametrize("kw", [
    dict(slug="ab"), dict(slug="Bad_Slug"), dict(slug="1abc"), dict(slug="x" * 41), dict(name="  "), dict(name="x" * 81), dict(desc="x" * 401),
    dict(manifest={"workflows": []}), dict(manifest={"workflows": [MAN["workflows"][0]] * 6}), dict(manifest={"nothing": 1}), dict(manifest={"workflows": "x"}),
    dict(manifest={"workflows": [{"pad": "x" * 61000}]}), dict(tools=["t"] * 41),
])
def test_bad_input_is_refused(app, world, kw):
    assert submit(app, world, **kw)[1] == "bad_input"


def test_exact_limits_pass(app, world):
    assert submit(app, world, slug="abc", name="x" * 80, desc="x" * 400, manifest={"workflows": [MAN["workflows"][0]] * 5}, tools=["t"] * 40)[1] == "ok"
    assert submit(app, world, slug="x" + "a" * 39)[1] == "ok"


def test_a_name_belongs_to_the_workspace_that_first_used_it_and_five_waiting_at_most(app, world):
    submit(app, world, slug="shared")
    assert submit(app, world, who="b", slug="shared")[1] == "slug_taken"
    for i in range(3):
        assert submit(app, world, slug=f"plug-{i}")[1] == "ok"
    assert submit(app, world, slug="plug-x")[1] == "ok"          # 5th waiting
    assert submit(app, world, slug="plug-y")[1] == "limit"
    assert submit(app, world, who="b", slug="other-b")[1] == "ok"   # another workspace has its own five


def test_duplicate_id_is_reported(app, world):
    pid, _ = submit(app, world)
    assert submit(app, world, pid=pid, slug="other-slug")[1] == "duplicate"


def test_only_a_published_version_is_visible_to_others_and_authors_see_their_own(app, world, admin):
    pid, _ = submit(app, world)
    ctx(app, world, "b")
    assert app.execute("SELECT count(*) FROM plugin_versions").fetchone()[0] == 0          # pending: hidden from others
    ctx(app, world)
    assert app.execute("SELECT count(*) FROM plugin_versions").fetchone()[0] == 1          # the author sees it
    assert review(admin, pid) == "ok"
    ctx(app, world, "b")
    assert app.execute("SELECT count(*), min(status) FROM plugin_versions").fetchone() == (1, "published")
    pid2, _ = submit(app, world)
    assert review(admin, pid2, "rejected", "unsafe") == "ok"
    ctx(app, world, "b")
    assert app.execute("SELECT count(*) FROM plugin_versions").fetchone()[0] == 1          # rejected: hidden from others
    ctx(app, world)
    assert app.execute("SELECT status, review_note FROM plugin_versions WHERE id=%s", (pid2,)).fetchone() == ("rejected", "unsafe")


def test_review_rules_and_who_may_review(app, world, admin):
    pid, _ = submit(app, world)
    assert review(admin, pid, "published", "x" * 301) == "bad_input"
    assert review(admin, pid, "rejected", None) == "bad_input"
    assert review(admin, pid, "rejected", "  ") == "bad_input"
    assert review(admin, pid, "maybe") == "bad_input"
    assert review(admin, "N" * 26) == "not_found"
    assert review(admin, pid, "published", "ok " * 100) == "ok"          # exactly <=300 chars fine
    assert review(admin, pid) == "not_pending"                                 # a decision is final
    ctx(app, world)
    with pytest.raises(psycopg.Error):
        app.execute("SELECT plugin_review(%s,'published',NULL)", (pid,))      # authors cannot approve themselves
    app.rollback()


def test_withdraw_is_the_authors_only_and_stops_new_installs(app, world, admin):
    pid, _ = submit(app, world)
    t, u = world["a"]["tenant"], world["a"]["user"]
    ctx(app, world)
    assert app.execute("SELECT plugin_withdraw(%s,%s,%s)", (pid, t, "U" * 26)).fetchone()[0] == "not_found"
    assert app.execute("SELECT plugin_withdraw(%s,%s,%s)", (pid, t, u)).fetchone()[0] == "ok"
    assert app.execute("SELECT plugin_withdraw(%s,%s,%s)", (pid, t, u)).fetchone()[0] == "not_found"      # already withdrawn
    assert review(admin, pid) == "not_pending"
    pid2, _ = submit(app, world)
    review(admin, pid2)
    ctx(app, world)
    assert app.execute("SELECT plugin_withdraw(%s,%s,%s)", (pid2, t, u)).fetchone()[0] == "ok"
    assert record(app, world, version=2) == "not_found"                        # no longer installable


def test_install_needs_a_published_version_and_updates_only_forward(app, world, admin):
    p1, _ = submit(app, world)
    assert record(app, world, version=1) == "not_found"                        # still pending
    review(admin, p1)
    assert record(app, world, version=1) == "ok"
    assert record(app, world, version=1) == "not_newer"                        # same version again
    p2, _ = submit(app, world)
    assert record(app, world, version=2) == "not_found"                        # v2 not published yet
    review(admin, p2)
    assert record(app, world, version=2, ids=("a" * 26, "b" * 26)) == "ok"
    ctx(app, world)
    assert app.execute("SELECT version, cardinality(workflow_ids) FROM plugin_installs").fetchone() == (2, 2)
    assert record(app, world, version=1) == "not_newer"                        # no going back


def test_install_input_checks_privacy_and_limit(app, world, admin, owner):
    p1, _ = submit(app, world)
    review(admin, p1)
    assert record(app, world, ids=()) == "bad_input"
    assert record(app, world, ids=("x" * 26,) * 6) == "bad_input"
    assert record(app, world, who="b") == "ok"                                 # anybody may install a published plugin
    ctx(app, world)
    assert app.execute("SELECT count(*) FROM plugin_installs").fetchone()[0] == 0          # installs are private to their workspace
    as_admin(owner)
    for i in range(19):
        s = f"fill-{i:02d}"
        owner.execute("INSERT INTO plugin_versions (id,slug,version,name,tenant_id,user_id,manifest,tools,status) VALUES (%s,%s,1,'n',%s,%s,%s,'{}','published')",
                      (ulid(), s, world["a"]["tenant"], world["a"]["user"], Jsonb(MAN)))
    for i in range(19):
        assert record(app, world, slug=f"fill-{i:02d}") == "ok"
    assert record(app, world) == "ok"                                          # the 20th
    owner.execute("INSERT INTO plugin_versions (id,slug,version,name,tenant_id,user_id,manifest,tools,status) VALUES (%s,'one-more',1,'n',%s,%s,%s,'{}','published')",
                  (ulid(), world["a"]["tenant"], world["a"]["user"], Jsonb(MAN)))
    assert record(app, world, slug="one-more") == "limit"


def test_uninstall_and_direct_writes_are_refused(app, world, admin):
    p1, _ = submit(app, world)
    review(admin, p1)
    record(app, world)
    t, u = world["a"]["tenant"], world["a"]["user"]
    ctx(app, world)
    assert app.execute("SELECT plugin_uninstall(%s,%s,'daily-brief')", (t, "U" * 26)).fetchone()[0] == "not_found"
    assert app.execute("SELECT plugin_uninstall(%s,%s,'daily-brief')", (t, u)).fetchone()[0] == "ok"
    assert app.execute("SELECT plugin_uninstall(%s,%s,'daily-brief')", (t, u)).fetchone()[0] == "not_found"
    for sql in ("INSERT INTO plugin_versions (id,slug,version,name,tenant_id,user_id,manifest,tools) VALUES ('%s','abc',1,'n','%s','%s','{\"workflows\":[{}]}','{}')" % (ulid(), t, u),
                "UPDATE plugin_versions SET status='published'", "DELETE FROM plugin_versions", "INSERT INTO plugin_installs (id,tenant_id,user_id,slug,version,workflow_ids) VALUES ('%s','%s','%s','x',1,'{a}')" % (ulid(), t, u)):
        with pytest.raises(psycopg.Error):
            app.execute(sql)
        app.rollback()
    with pytest.raises(psycopg.Error):
        app.execute("SELECT plugin_uninstall(%s,NULL,'x')", (t,))
    app.rollback()
