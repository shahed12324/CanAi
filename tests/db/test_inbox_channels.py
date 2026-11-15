import psycopg
import pytest

from conftest import as_admin, set_tenant, ulid
from test_inbox import channel as widget_channel, contact


@pytest.fixture(autouse=True)
def clean(owner, world):
    yield
    as_admin(owner)
    owner.execute("ALTER TABLE emergency_events DISABLE TRIGGER emergency_events_no_change")
    for t in ("inbox_messages", "inbox_conversations", "inbox_contacts", "inbox_channels", "emergency_events"):
        owner.execute(f"DELETE FROM {t}")
    owner.execute("ALTER TABLE emergency_events ENABLE TRIGGER emergency_events_no_change")


def ext(app, w, kind="messenger", ref="PAGE1", who="a", key=None):
    i = ulid()
    k = key or ("c" * 31 + str(len(ref) % 10))
    app.execute("INSERT INTO inbox_channels (id,tenant_id,kind,name,public_key,external_ref,credentials_ct,key_version) VALUES (%s,%s,%s,'Page',%s,%s,'CIPHER',1)", (i, w[who]["tenant"], kind, k, ref))
    return i, k


def test_external_channel_needs_account_and_secret_and_secret_is_function_only(app, world):
    set_tenant(app, world["a"]["tenant"])
    for cols in ("(id,tenant_id,kind,name,public_key,external_ref)", "(id,tenant_id,kind,name,public_key,credentials_ct,key_version)"):
        vals = "(%s,%s,'whatsapp','x',%s,'REF')" if "external_ref" in cols else "(%s,%s,'whatsapp','x',%s,'C',1)"
        with pytest.raises(psycopg.errors.CheckViolation):
            app.execute(f"INSERT INTO inbox_channels {cols} VALUES {vals}", (ulid(), world["a"]["tenant"], "d" * 32))
        app.rollback()
        set_tenant(app, world["a"]["tenant"])
    ch, _ = ext(app, world)
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("SELECT credentials_ct FROM inbox_channels")
    app.rollback()
    set_tenant(app, world["a"]["tenant"])
    r = app.execute("SELECT credentials_ct, key_version, kind, external_ref FROM inbox_credentials(%s)", (ch,)).fetchone()
    assert r == ("CIPHER", 1, "messenger", "PAGE1")


def test_other_workspace_cannot_read_secret_and_account_cannot_be_claimed_twice(app, world):
    set_tenant(app, world["b"]["tenant"])
    chb, _ = ext(app, world, who="b", ref="PAGEX", key="e" * 32)
    set_tenant(app, world["a"]["tenant"])
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("SELECT * FROM inbox_credentials(%s)", (chb,))
    app.rollback()
    set_tenant(app, world["a"]["tenant"])
    with pytest.raises(psycopg.errors.UniqueViolation):                           # same page id cannot be attached by another workspace
        ext(app, world, ref="PAGEX", key="f" * 32)
    app.rollback()
    set_tenant(app, world["a"]["tenant"])
    ext(app, world, kind="whatsapp", ref="PAGEX", key="9" * 32)                   # different platform is a different account


def test_lookup_by_key_returns_account_ref(app, world):
    set_tenant(app, world["a"]["tenant"])
    ch, k = ext(app, world, kind="telegram", ref="BOT1")
    r = app.execute("SELECT id, kind, external_ref FROM inbox_channel_by_key(%s)", (k,)).fetchone()
    assert (r[0].strip(), r[1], r[2]) == (ch, "telegram", "BOT1")


def queued(app, world, kind="telegram"):
    u = world["a"]["user"]
    ch, _ = ext(app, world, kind=kind, ref="R" + kind)
    conv = contact(app, world, ch, ext="user1")
    app.execute("SELECT * FROM inbox_receive(%s,'hello',NULL)", (conv,))
    s = app.execute("SELECT seq FROM inbox_send(%s,%s,'staff','reply')", (conv, u)).fetchone()[0]
    return ch, conv, s


def test_dispatch_context_has_what_the_sender_needs(app, world):
    set_tenant(app, world["a"]["tenant"])
    ch, conv, s = queued(app, world, "whatsapp")
    r = app.execute("SELECT kind, external_ref, to_external, body, status, attempts, window_ok, emergency, channel_active FROM inbox_dispatch_ctx(%s)", (s,)).fetchone()
    assert r == ("whatsapp", "Rwhatsapp", "user1", "reply", "queued", 0, True, False, True)


def test_window_is_rechecked_at_send_time(app, world, owner):
    set_tenant(app, world["a"]["tenant"])
    ch, conv, s = queued(app, world, "messenger")
    as_admin(owner)
    owner.execute("UPDATE inbox_conversations SET last_inbound_at = now() - interval '30 hours' WHERE id=%s", (conv,))
    set_tenant(app, world["a"]["tenant"])
    assert app.execute("SELECT window_ok FROM inbox_dispatch_ctx(%s)", (s,)).fetchone()[0] is False
    ch2, conv2, s2 = queued(app, world, "telegram")
    as_admin(owner)
    owner.execute("UPDATE inbox_conversations SET last_inbound_at = now() - interval '300 hours' WHERE id=%s", (conv2,))
    set_tenant(app, world["a"]["tenant"])
    assert app.execute("SELECT window_ok FROM inbox_dispatch_ctx(%s)", (s2,)).fetchone()[0] is True      # telegram has no window


def test_retry_is_bounded_and_status_cannot_go_back(app, world):
    set_tenant(app, world["a"]["tenant"])
    ch, conv, s = queued(app, world)
    res = [app.execute("SELECT inbox_message_retry(%s,%s)", (s, "timeout " * 50)).fetchone()[0] for _ in range(5)]
    assert res == ["retry"] * 4 + ["failed"]
    assert app.execute("SELECT status, attempts, length(error) FROM inbox_messages WHERE seq=%s", (s,)).fetchone() == ("failed", 5, 200)
    assert app.execute("SELECT inbox_message_retry(%s,'x')", (s,)).fetchone()[0] == "not_queued"
    assert app.execute("SELECT inbox_message_status(%s,'sent',NULL)", (s,)).fetchone()[0] is False


def test_dispatch_functions_are_closed_to_other_workspaces(app, world):
    set_tenant(app, world["a"]["tenant"])
    ch, conv, s = queued(app, world)
    set_tenant(app, world["b"]["tenant"])
    for sql in ("SELECT * FROM inbox_dispatch_ctx(%s)", "SELECT inbox_message_retry(%s,'x')"):
        with pytest.raises(psycopg.errors.InsufficientPrivilege):
            app.execute(sql, (s,))
        app.rollback()
        set_tenant(app, world["b"]["tenant"])


def test_dispatch_context_reports_emergency_and_paused(app, world, owner):
    set_tenant(app, world["a"]["tenant"])
    ch, conv, s = queued(app, world)
    app.execute("UPDATE inbox_channels SET status='paused' WHERE id=%s", (ch,))
    as_admin(owner)
    owner.execute("INSERT INTO emergency_events (tenant_id, action, by_user, reason) VALUES (%s,'stop',%s,'t')", (world["a"]["tenant"], world["a"]["user"]))
    set_tenant(app, world["a"]["tenant"])
    r = app.execute("SELECT emergency, channel_active FROM inbox_dispatch_ctx(%s)", (s,)).fetchone()
    assert r == (True, False)
