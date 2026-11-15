import psycopg
import pytest

from conftest import as_admin, set_tenant, ulid
from test_inbox import channel, contact


@pytest.fixture(autouse=True)
def clean(owner, world):
    yield
    as_admin(owner)
    for t in ("inbox_agent_drafts", "inbox_agent_settings", "inbox_messages", "inbox_conversations", "inbox_contacts", "inbox_channels"):
        owner.execute(f"DELETE FROM {t}")


def conv_of(app, world, mode="off"):
    ch, _ = channel(app, world)
    app.execute("UPDATE inbox_channels SET agent_mode=%s WHERE id=%s", (mode, ch))
    return ch, contact(app, world, ch)


def test_agent_cannot_speak_unless_the_channel_is_in_auto_mode(app, world):
    set_tenant(app, world["a"]["tenant"])
    u = world["a"]["user"]
    for mode in ("off", "draft"):
        ch, conv = conv_of(app, world, mode)
        assert app.execute("SELECT result FROM inbox_send(%s,NULL,'agent','hi')", (conv,)).fetchone()[0] == "agent_off"
        assert app.execute("SELECT count(*) FROM inbox_messages WHERE conversation_id=%s", (conv,)).fetchone()[0] == 0
        assert app.execute("SELECT result FROM inbox_send(%s,%s,'staff','people can')", (conv, u)).fetchone()[0] == "sent"       # staff are unaffected
    ch, conv = conv_of(app, world, "auto")
    assert app.execute("SELECT result FROM inbox_send(%s,NULL,'agent','hi')", (conv,)).fetchone()[0] == "sent"


def test_agent_is_capped_per_conversation_per_hour(app, world):
    set_tenant(app, world["a"]["tenant"])
    ch, conv = conv_of(app, world, "auto")
    res = [app.execute("SELECT result FROM inbox_send(%s,NULL,'agent','x')", (conv,)).fetchone()[0] for _ in range(14)]
    assert res.count("sent") == 12 and res[12:] == ["agent_limit", "agent_limit"]
    assert app.execute("SELECT result FROM inbox_send(%s,%s,'staff','a person can still reply')", (conv, world["a"]["user"])).fetchone()[0] == "sent"


def test_only_valid_modes_and_secret_free_default(app, world):
    set_tenant(app, world["a"]["tenant"])
    ch, _ = channel(app, world)
    assert app.execute("SELECT agent_mode FROM inbox_channels WHERE id=%s", (ch,)).fetchone()[0] == "off"
    with pytest.raises(psycopg.errors.CheckViolation):
        app.execute("UPDATE inbox_channels SET agent_mode='yolo' WHERE id=%s", (ch,))


def test_settings_and_drafts_are_per_workspace(app, world):
    ta, tb = world["a"]["tenant"], world["b"]["tenant"]
    set_tenant(app, tb)
    app.execute("INSERT INTO inbox_agent_settings (tenant_id, instructions, business_info) VALUES (%s,'secret b','delivery 60')", (tb,))
    set_tenant(app, ta)
    assert app.execute("SELECT count(*) FROM inbox_agent_settings").fetchone()[0] == 0
    with pytest.raises(psycopg.errors.InsufficientPrivilege):                       # cannot write another workspace's row
        app.execute("INSERT INTO inbox_agent_settings (tenant_id) VALUES (%s)", (tb,))
    app.rollback()
    set_tenant(app, ta)
    app.execute("INSERT INTO inbox_agent_settings (tenant_id, instructions) VALUES (%s,'hi')", (ta,))
    app.execute("UPDATE inbox_agent_settings SET business_info='x', updated_at=now() WHERE tenant_id=%s", (ta,))
    with pytest.raises(psycopg.errors.StringDataRightTruncation):
        app.execute("UPDATE inbox_agent_settings SET business_info=%s", ("z" * 3001,))


def test_draft_upsert_and_length_rule(app, world):
    set_tenant(app, world["a"]["tenant"])
    ch, conv = conv_of(app, world, "draft")
    t = world["a"]["tenant"]
    app.execute("INSERT INTO inbox_agent_drafts (conversation_id, tenant_id, body, in_seq) VALUES (%s,%s,'first',1) ON CONFLICT (conversation_id) DO UPDATE SET body=excluded.body, in_seq=excluded.in_seq, created_at=now()", (conv, t))
    app.execute("INSERT INTO inbox_agent_drafts (conversation_id, tenant_id, body, in_seq) VALUES (%s,%s,'second',2) ON CONFLICT (conversation_id) DO UPDATE SET body=excluded.body, in_seq=excluded.in_seq, created_at=now()", (conv, t))
    assert app.execute("SELECT body, in_seq FROM inbox_agent_drafts").fetchall() == [("second", 2)]
    with pytest.raises(psycopg.errors.CheckViolation):
        app.execute("UPDATE inbox_agent_drafts SET body='   '")
    app.execute("DELETE FROM inbox_agent_drafts")
    assert app.execute("SELECT count(*) FROM inbox_agent_drafts").fetchone()[0] == 0
