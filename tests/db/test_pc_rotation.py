"""Phase 33b: device-token rotation."""
import hashlib

from conftest import as_admin
from test_pc_control import ROOTS, clean, device, h, pair, policy, req  # noqa: F401
from test_memory import second_user  # noqa: F401


def look(app, tok):
    return app.execute("SELECT pc_agent_lookup(%s)", (h(tok),)).fetchone()[0]


def rotate(app, old, new):
    return app.execute("SELECT pc_agent_rotate(%s,%s)", (h(old), h(new))).fetchone()[0]


NEW = "ab" * 32
NEW2 = "cd" * 32


def test_rotation_swaps_the_token_and_the_old_one_dies(app, owner, world):
    dev, tok = device(app, world)
    before = look(app, tok)
    assert before["device_id"] == dev
    r = rotate(app, tok, NEW)
    assert r["device_id"] == dev and r["tenant_id"] == before["tenant_id"] and r["user_id"] == before["user_id"]
    assert look(app, tok) is None                    # old token: dead at once
    assert look(app, NEW)["device_id"] == dev        # new token: works
    assert rotate(app, tok, NEW2) is None            # the old token cannot rotate again
    as_admin(owner)
    assert owner.execute("SELECT token_rotated_at IS NOT NULL FROM pc_devices WHERE id=%s", (dev,)).fetchone()[0]


def test_rotation_refuses_bad_input_and_collisions(app, owner, world):
    dev, tok = device(app, world)
    assert rotate(app, tok, tok) is None
    assert app.execute("SELECT pc_agent_rotate(%s,%s)", (h(tok), "short")).fetchone()[0] is None
    assert app.execute("SELECT pc_agent_rotate(%s,%s)", (h(tok), "G" * 64)).fetchone()[0] is None
    assert app.execute("SELECT pc_agent_rotate(%s,%s)", (None, h(NEW))).fetchone()[0] is None
    assert look(app, tok)["device_id"] == dev        # nothing changed
    dev2, tok2 = device(app, world, who="a")
    assert rotate(app, tok2, tok) is None            # a hash already in use cannot be taken
    assert look(app, tok2)["device_id"] == dev2


def test_a_revoked_device_cannot_rotate(app, owner, world):
    dev, tok = device(app, world)
    a = world["a"]
    from test_pc_control import ctx
    u = ctx(app, world, "a")
    assert app.execute("SELECT pc_device_revoke(%s,%s,%s)", (a["tenant"], u, dev)).fetchone()[0] == "ok"
    assert look(app, tok) is None and rotate(app, tok, NEW) is None
    assert look(app, NEW) is None


def test_rotation_keeps_the_device_policy_and_pending_work(app, owner, world):
    dev, tok = device(app, world)
    policy(app, world, dev)
    r = req(app, world, dev, "read_file", {"path": "/home/me/work/a.txt"})
    assert r["status"] in ("pending", "approved")
    rotate(app, tok, NEW)
    as_admin(owner)
    assert owner.execute("SELECT count(*) FROM pc_actions WHERE device_id=%s", (dev,)).fetchone()[0] == 1
    assert owner.execute("SELECT count(*) FROM pc_policies WHERE device_id=%s", (dev,)).fetchone()[0] == 1
