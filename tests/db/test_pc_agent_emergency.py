"""Phase 26g: the agent can ask whether the emergency stop is on while it runs a plan."""
import psycopg
import pytest

from conftest import as_admin
from test_pc_control import ROOTS, clean, device, policy, req  # noqa: F401
from test_memory import second_user  # noqa: F401


def stop(owner, world, who="a", action="stop"):
    as_admin(owner)
    owner.execute("INSERT INTO emergency_events (tenant_id, action, by_user) VALUES (%s,%s,%s)", (world[who]["tenant"], action, world[who]["user"]))


def ask(app, world, dev, who="a"):
    return app.execute("SELECT pc_agent_emergency(%s,%s)", (world[who]["tenant"], dev)).fetchone()[0]


def test_reports_stop_and_release_for_the_devices_own_workspace(app, owner, world):
    dev, _ = device(app, world)
    assert ask(app, world, dev) is False
    stop(owner, world)
    assert ask(app, world, dev) is True
    stop(owner, world, action="resume")
    assert ask(app, world, dev) is False


def test_refuses_a_foreign_or_unknown_device_and_a_wrong_tenant(app, owner, world):
    dev, _ = device(app, world)
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("SELECT pc_agent_emergency(%s,%s)", (world["b"]["tenant"], dev))
    app.rollback()
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("SELECT pc_agent_emergency(%s,%s)", (world["a"]["tenant"], "X" * 26))
    app.rollback()


def test_another_workspaces_stop_does_not_leak(app, owner, world):
    dev, _ = device(app, world)
    stop(owner, world, who="b")
    assert ask(app, world, dev) is False
