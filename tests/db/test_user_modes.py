"""Phase 26e: Companion mode vs Agent mode."""
import pytest
from psycopg.types.json import Jsonb

from conftest import as_admin, ulid
from test_accounting import ctx
from test_memory import second_user  # noqa: F401
from test_pc_control import clean, device, policy, req  # noqa: F401
from test_pc_plans import S, W, clean_plans, decide, mkdev, plan, pull, submit  # noqa: F401


def mode(app, world, who="a", user=None):
    u = ctx(app, world, who)
    return app.execute("SELECT coalesce((SELECT mode FROM user_modes WHERE user_id = %s), 'agent')", (user or u,)).fetchone()[0]


def setmode(app, world, m, char=None, who="a", user=None):
    u = ctx(app, world, who)
    return app.execute("SELECT user_mode_set(%s,%s,%s,%s)", (world[who]["tenant"], user or u, m, char)).fetchone()[0]


@pytest.fixture(autouse=True)
def clean_modes(owner, world):
    yield
    as_admin(owner)
    owner.execute("DELETE FROM user_modes")


def mkchar(owner, world, who="a"):
    as_admin(owner)
    cid = ulid()
    owner.execute("INSERT INTO characters (id,tenant_id,created_by,name,kind,personality,language,avatar_style) VALUES (%s,%s,%s,'Neela','female','kind','bn','none')", (cid, world[who]["tenant"], world[who]["user"]))
    return cid


def test_default_is_agent_so_nothing_changes_until_the_person_chooses(app, owner, world):
    assert mode(app, world) == "agent"
    dev, _ = mkdev(app, world, owner)
    assert req(app, world, dev, "read_file", {"path": W + "/a"})["status"] == "pending"


def test_toggle_roundtrip_and_validation(app, owner, world, second_user):
    assert setmode(app, world, "companion") == "ok" and mode(app, world) == "companion"
    assert setmode(app, world, "agent") == "ok" and mode(app, world) == "agent"
    assert setmode(app, world, "robot") == "bad_input" and setmode(app, world, None) == "bad_input"
    assert mode(app, world, user=second_user) == "agent"                  # modes are per person
    assert setmode(app, world, "companion", user=ulid()) == "forbidden"   # not a member of this workspace


def test_character_must_exist_in_this_workspace(app, owner, world):
    cid = mkchar(owner, world)
    assert setmode(app, world, "companion", cid) == "ok"
    assert setmode(app, world, "companion", ulid()) == "bad_input"
    assert setmode(app, world, "companion", cid, who="b") == "bad_input"   # another workspace's character
    as_admin(owner)
    owner.execute("UPDATE characters SET deleted_at = now() WHERE id = %s", (cid,))
    assert setmode(app, world, "companion", cid) == "bad_input"


def test_companion_mode_blocks_new_jobs_and_plans(app, owner, world):
    dev, _ = mkdev(app, world, owner)
    setmode(app, world, "companion")
    r = req(app, world, dev, "read_file", {"path": W + "/a"})
    assert (r["status"], r["reason"]) == ("blocked", "companion_mode")
    p = submit(app, world, dev, plan(S(1, "read_file", path=W + "/a")))
    assert (p["status"], p["reason"]) == ("blocked", "companion_mode")
    setmode(app, world, "agent")
    assert req(app, world, dev, "read_file", {"path": W + "/a"})["status"] == "pending"
    assert submit(app, world, dev, plan(S(1, "read_file", path=W + "/a")))["status"] == "pending"


def test_switching_to_companion_stops_what_was_waiting(app, owner, world):
    dev, _ = mkdev(app, world, owner)
    a1 = req(app, world, dev, "read_file", {"path": W + "/a"})["id"]
    p1 = submit(app, world, dev, plan(S(1, "read_file", path=W + "/a")))["id"]
    p2 = submit(app, world, dev, plan(S(1, "read_file", path=W + "/b")))["id"]
    decide(app, world, p2)                                                  # approved, not yet pulled
    setmode(app, world, "companion")
    as_admin(owner)
    assert owner.execute("SELECT status, reason FROM pc_actions WHERE id=%s", (a1,)).fetchone() == ("stopped", "companion_mode")
    assert {r[0] for r in owner.execute("SELECT status FROM pc_plans WHERE id IN (%s,%s)", (p1, p2)).fetchall()} == {"stopped"}
    assert pull(app, world, dev) is None


def test_an_approved_plan_is_not_handed_out_while_in_companion_mode(app, owner, world):
    dev, _ = mkdev(app, world, owner)
    pid = submit(app, world, dev, plan(S(1, "read_file", path=W + "/a")))["id"]
    decide(app, world, pid)
    as_admin(owner)
    owner.execute("INSERT INTO user_modes (tenant_id, user_id, mode) VALUES (%s,%s,'companion')", (world["a"]["tenant"], world["a"]["user"]))   # mode flipped without the stop sweep
    assert pull(app, world, dev) is None
    owner.execute("UPDATE user_modes SET mode = 'agent'")
    assert pull(app, world, dev)["id"] == pid


def test_other_peoples_work_is_not_stopped(app, owner, world, second_user):
    dev, _ = mkdev(app, world, owner)
    p = submit(app, world, dev, plan(S(1, "read_file", path=W + "/a")))["id"]
    setmode(app, world, "companion", who="a")
    assert mode(app, world, user=second_user) == "agent"


def test_the_internal_helper_is_not_callable_by_the_web_role(app, owner, world):
    import psycopg
    ctx(app, world)
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("SELECT user_mode(%s,%s)", (world["a"]["tenant"], world["a"]["user"]))
    app.rollback()


def test_web_role_cannot_write_modes_directly(app, owner, world):
    import psycopg
    ctx(app, world)
    for sql in ("INSERT INTO user_modes (tenant_id,user_id,mode) VALUES ('x','y','companion')", "UPDATE user_modes SET mode='agent'", "DELETE FROM user_modes"):
        with pytest.raises(psycopg.errors.InsufficientPrivilege):
            app.execute(sql)
        app.rollback()
        ctx(app, world)


def test_tenant_isolation_of_modes(app, owner, world):
    setmode(app, world, "companion")
    ctx(app, world, "b")
    assert app.execute("SELECT count(*) FROM user_modes").fetchone()[0] == 0
