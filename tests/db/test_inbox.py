import hashlib

import psycopg
import pytest

from conftest import as_admin, set_tenant, ulid


@pytest.fixture(autouse=True)
def clean(owner, world):
    yield
    as_admin(owner)
    owner.execute("ALTER TABLE emergency_events DISABLE TRIGGER emergency_events_no_change")
    for t in ("inbox_messages", "inbox_conversations", "inbox_contacts", "inbox_channels", "emergency_events"):
        owner.execute(f"DELETE FROM {t}")
    owner.execute("ALTER TABLE emergency_events ENABLE TRIGGER emergency_events_no_change")


def key():
    return ulid().replace("g", "a").replace("h", "b").replace("j", "c").replace("k", "d").replace("m", "e").replace("n", "f").replace("p", "1").replace("q", "2").replace("r", "3").replace("s", "4").replace("t", "5").replace("v", "6").replace("w", "7").replace("x", "8").replace("y", "9").replace("z", "0") + "abcdef"


def channel(app, w, who="a", kind="web_widget", origins=("https://shop.example.com",)):
    i, k = ulid(), key()[:32]
    if kind == "web_widget":
        app.execute("INSERT INTO inbox_channels (id,tenant_id,kind,name,public_key,allowed_origins) VALUES (%s,%s,%s,'Site',%s,%s)", (i, w[who]["tenant"], kind, k, list(origins)))
    else:
        app.execute("INSERT INTO inbox_channels (id,tenant_id,kind,name,public_key,external_ref,credentials_ct,key_version) VALUES (%s,%s,%s,'Site',%s,%s,'C',1)", (i, w[who]["tenant"], kind, k, "ref-" + i))
    return i, k


def tok(s="secret-token"):
    return hashlib.sha256(s.encode()).hexdigest()


def contact(app, w, ch, who="a", ext="v1", token="secret-token"):
    r = app.execute("SELECT * FROM inbox_contact_ensure(%s,%s,'Visitor',%s,%s,%s)", (ch, ext, tok(token), ulid(), ulid())).fetchone()
    assert r[0] == "ok", r
    return r[2]


def test_public_key_lookup_works_without_a_tenant_and_returns_no_secret(app, world):
    set_tenant(app, world["a"]["tenant"])
    ch, k = channel(app, world)
    app.commit()
    app.execute("SELECT set_config('app.tenant_id', '', false)")
    r = app.execute("SELECT id, tenant_id, kind, status FROM inbox_channel_by_key(%s)", (k,)).fetchone()
    assert r[0] == ch and r[1] == world["a"]["tenant"] and r[2] == "web_widget"
    assert app.execute("SELECT count(*) FROM inbox_channel_by_key(%s)", ("0" * 32,)).fetchone()[0] == 0
    app.commit()
    # but the table itself stays closed without a tenant
    assert app.execute("SELECT count(*) FROM inbox_channels").fetchone()[0] == 0


def test_secret_column_is_not_readable_and_widget_cannot_hold_one(app, world):
    set_tenant(app, world["a"]["tenant"])
    channel(app, world)
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("SELECT credentials_ct FROM inbox_channels")
    app.rollback()
    set_tenant(app, world["a"]["tenant"])
    with pytest.raises(psycopg.errors.CheckViolation):
        app.execute("INSERT INTO inbox_channels (id,tenant_id,kind,name,public_key,credentials_ct,key_version) VALUES (%s,%s,'web_widget','x',%s,'c',1)", (ulid(), world["a"]["tenant"], "a" * 32))


def test_allowed_origins_must_be_https_or_localhost(app, world):
    set_tenant(app, world["a"]["tenant"])
    for bad in ("http://shop.example.com", "https://shop.example.com/path", "*", "javascript:1", "https://a b.com"):
        with pytest.raises(psycopg.errors.CheckViolation):
            channel(app, world, origins=(bad,))
        app.rollback()
        set_tenant(app, world["a"]["tenant"])
    channel(app, world, origins=("http://localhost:3000", "https://shop.example.com:8443"))


def test_app_cannot_write_conversations_or_messages_directly(app, world):
    set_tenant(app, world["a"]["tenant"])
    ch, _ = channel(app, world)
    conv = contact(app, world, ch)
    for sql in ("UPDATE inbox_conversations SET mode='bot'", "UPDATE inbox_messages SET body='x'", "DELETE FROM inbox_messages",
                "INSERT INTO inbox_messages (tenant_id,conversation_id,direction,sender,body,status) VALUES ('%s','%s','in','customer','x','received')" % (world["a"]["tenant"], conv),
                "UPDATE inbox_contacts SET token_hash=NULL"):
        with pytest.raises(psycopg.errors.InsufficientPrivilege):
            app.execute(sql)
        app.rollback()
        set_tenant(app, world["a"]["tenant"])


def test_contact_is_created_once_with_one_rolling_conversation(app, world):
    set_tenant(app, world["a"]["tenant"])
    ch, _ = channel(app, world)
    c1 = contact(app, world, ch)
    c2 = contact(app, world, ch)                                   # same visitor again -> same conversation
    assert c1 == c2
    assert app.execute("SELECT count(*) FROM inbox_contacts").fetchone()[0] == 1
    assert app.execute("SELECT mode, status, unread FROM inbox_conversations").fetchone() == ("bot", "open", 0)


def test_visitor_token_verification(app, world):
    set_tenant(app, world["a"]["tenant"])
    ch, _ = channel(app, world)
    conv = contact(app, world, ch)
    assert app.execute("SELECT inbox_contact_verify(%s,'v1',%s)", (ch, tok())).fetchone()[0] == conv
    assert app.execute("SELECT inbox_contact_verify(%s,'v1',%s)", (ch, tok("wrong"))).fetchone()[0] is None
    assert app.execute("SELECT inbox_contact_verify(%s,'other',%s)", (ch, tok())).fetchone()[0] is None


def test_receive_updates_conversation_and_dedupes_retries(app, world):
    set_tenant(app, world["a"]["tenant"])
    ch, _ = channel(app, world)
    conv = contact(app, world, ch)
    r = app.execute("SELECT * FROM inbox_receive(%s,'  hello  ','m1')", (conv,)).fetchone()
    assert r[0] == "received" and r[1] is not None
    assert app.execute("SELECT * FROM inbox_receive(%s,'hello','m1')", (conv,)).fetchone()[0] == "duplicate"
    assert app.execute("SELECT unread, preview, status FROM inbox_conversations").fetchone() == (1, "hello", "open")
    assert app.execute("SELECT count(*) FROM inbox_messages").fetchone()[0] == 1
    assert app.execute("SELECT body FROM inbox_messages").fetchone()[0] == "hello"
    assert app.execute("SELECT result FROM inbox_receive(%s,'   ',NULL)", (conv,)).fetchone()[0] == "empty"
    assert app.execute("SELECT result FROM inbox_receive(%s,%s,NULL)", (conv, "x" * 4001)).fetchone()[0] == "too_long"


def test_flood_limits(app, world):
    set_tenant(app, world["a"]["tenant"])
    ch, _ = channel(app, world)
    conv = contact(app, world, ch)
    res = [app.execute("SELECT result FROM inbox_receive(%s,'hi',NULL)", (conv,)).fetchone()[0] for _ in range(22)]
    assert res.count("received") == 20 and res[-1] == "rate_limited"
    u = world["a"]["user"]
    out = [app.execute("SELECT result FROM inbox_send(%s,%s,'staff','ok')", (conv, u)).fetchone()[0] for _ in range(32)]
    assert out.count("sent") == 30 and out[-1] == "rate_limited"


def test_new_contact_flood_limit_per_channel(app, world):
    set_tenant(app, world["a"]["tenant"])
    ch, _ = channel(app, world)
    for i in range(300):
        app.execute("SELECT * FROM inbox_contact_ensure(%s,%s,NULL,NULL,%s,%s)", (ch, f"v{i}", ulid(), ulid()))
    assert app.execute("SELECT result FROM inbox_contact_ensure(%s,'one-more',NULL,NULL,%s,%s)", (ch, ulid(), ulid())).fetchone()[0] == "rate_limited"
    assert app.execute("SELECT result FROM inbox_contact_ensure(%s,'v1',NULL,NULL,%s,%s)", (ch, ulid(), ulid())).fetchone()[0] == "ok"   # existing visitors still fine


def test_staff_reply_takes_over_and_agent_goes_silent(app, world):
    set_tenant(app, world["a"]["tenant"])
    u = world["a"]["user"]
    ch, _ = channel(app, world)
    app.execute("UPDATE inbox_channels SET agent_mode='auto' WHERE id=%s", (ch,))
    conv = contact(app, world, ch)
    app.execute("SELECT * FROM inbox_receive(%s,'price?',NULL)", (conv,))
    assert app.execute("SELECT result FROM inbox_send(%s,NULL,'agent','Tk 500')", (conv,)).fetchone()[0] == "sent"
    assert app.execute("SELECT mode FROM inbox_conversations").fetchone()[0] == "bot"
    assert app.execute("SELECT result FROM inbox_send(%s,%s,'staff','I will help')", (conv, u)).fetchone()[0] == "sent"
    assert app.execute("SELECT mode, assigned_to, unread, mode_changed_by FROM inbox_conversations").fetchone() == ("human", u, 0, u)
    assert app.execute("SELECT result FROM inbox_send(%s,NULL,'agent','auto')", (conv,)).fetchone()[0] == "human_mode"
    assert app.execute("SELECT inbox_set_mode(%s,%s,'bot',NULL)", (conv, u)).fetchone()[0] is True
    assert app.execute("SELECT mode, assigned_to FROM inbox_conversations").fetchone() == ("bot", None)
    assert app.execute("SELECT result FROM inbox_send(%s,NULL,'agent','auto')", (conv,)).fetchone()[0] == "sent"
    with pytest.raises(psycopg.errors.RaiseException):
        app.execute("SELECT inbox_send(%s,NULL,'staff','x')", (conv,))


def test_agent_handoff_records_reason(app, world):
    set_tenant(app, world["a"]["tenant"])
    ch, _ = channel(app, world)
    conv = contact(app, world, ch)
    app.execute("SELECT inbox_set_mode(%s,NULL,'human','customer is angry')", (conv,))
    assert app.execute("SELECT mode, handoff_reason, assigned_to FROM inbox_conversations").fetchone() == ("human", "customer is angry", None)
    app.execute("SELECT inbox_set_mode(%s,NULL,'bot',NULL)", (conv,))
    assert app.execute("SELECT handoff_reason FROM inbox_conversations").fetchone()[0] is None
    with pytest.raises(psycopg.errors.RaiseException):
        app.execute("SELECT inbox_set_mode(%s,NULL,'robot',NULL)", (conv,))


def test_24h_window_applies_to_meta_channels_only(app, world, owner):
    set_tenant(app, world["a"]["tenant"])
    u = world["a"]["user"]
    for kind in ("messenger", "instagram", "whatsapp"):
        ch, _ = channel(app, world, kind=kind)
        conv = contact(app, world, ch, ext="c-" + kind)
        assert app.execute("SELECT result FROM inbox_send(%s,%s,'staff','hi')", (conv, u)).fetchone()[0] == "window_closed"      # they never wrote
        app.execute("SELECT * FROM inbox_receive(%s,'hello',NULL)", (conv,))
        r = app.execute("SELECT * FROM inbox_send(%s,%s,'staff','hi')", (conv, u)).fetchone()
        assert r[0] == "queued" and r[1] is not None
        app.commit()
        as_admin(owner)
        owner.execute("UPDATE inbox_conversations SET last_inbound_at = now() - interval '25 hours' WHERE id = %s", (conv,))
        set_tenant(app, world["a"]["tenant"])
        assert app.execute("SELECT result FROM inbox_send(%s,%s,'staff','late')", (conv, u)).fetchone()[0] == "window_closed"
        owner.execute("UPDATE inbox_conversations SET last_inbound_at = now() - interval '23 hours' WHERE id = %s", (conv,))
        set_tenant(app, world["a"]["tenant"])
        assert app.execute("SELECT result FROM inbox_send(%s,%s,'staff','still ok')", (conv, u)).fetchone()[0] == "queued"
    ch, _ = channel(app, world, kind="telegram")
    conv = contact(app, world, ch, ext="tg")
    assert app.execute("SELECT result FROM inbox_send(%s,%s,'staff','no window here')", (conv, u)).fetchone()[0] == "queued"
    ch, _ = channel(app, world)
    conv = contact(app, world, ch, ext="w")
    assert app.execute("SELECT result FROM inbox_send(%s,%s,'staff','widget')", (conv, u)).fetchone()[0] == "sent"


def test_emergency_stop_blocks_every_outgoing_message_but_not_receiving(app, world, owner):
    set_tenant(app, world["a"]["tenant"])
    u = world["a"]["user"]
    ch, _ = channel(app, world)
    conv = contact(app, world, ch)
    app.commit()
    as_admin(owner)
    owner.execute("INSERT INTO emergency_events (tenant_id, action, by_user, reason) VALUES (%s,'stop',%s,'t')", (world["a"]["tenant"], u))
    set_tenant(app, world["a"]["tenant"])
    for sender, usr in (("staff", u), ("agent", None), ("system", None)):
        assert app.execute("SELECT result FROM inbox_send(%s,%s,%s,'x')", (conv, usr, sender)).fetchone()[0] == "emergency"
    assert app.execute("SELECT result FROM inbox_receive(%s,'still arrives',NULL)", (conv,)).fetchone()[0] == "received"
    assert app.execute("SELECT count(*) FROM inbox_messages WHERE direction='out'").fetchone()[0] == 0


def test_paused_channel_blocks_in_and_out(app, world):
    set_tenant(app, world["a"]["tenant"])
    u = world["a"]["user"]
    ch, _ = channel(app, world)
    conv = contact(app, world, ch)
    app.execute("UPDATE inbox_channels SET status='paused' WHERE id=%s", (ch,))
    assert app.execute("SELECT result FROM inbox_receive(%s,'hi',NULL)", (conv,)).fetchone()[0] == "channel_paused"
    assert app.execute("SELECT result FROM inbox_send(%s,%s,'staff','hi')", (conv, u)).fetchone()[0] == "channel_paused"
    assert app.execute("SELECT result FROM inbox_contact_ensure(%s,'new',NULL,NULL,%s,%s)", (ch, ulid(), ulid())).fetchone()[0] == "channel_paused"


def test_resolve_and_new_message_reopens(app, world):
    set_tenant(app, world["a"]["tenant"])
    ch, _ = channel(app, world)
    conv = contact(app, world, ch)
    app.execute("SELECT * FROM inbox_receive(%s,'hi',NULL)", (conv,))
    app.execute("SELECT inbox_resolve(%s,%s,true)", (conv, world["a"]["user"]))
    assert app.execute("SELECT status, unread FROM inbox_conversations").fetchone() == ("resolved", 0)
    app.execute("SELECT * FROM inbox_receive(%s,'one more thing',NULL)", (conv,))
    assert app.execute("SELECT status, unread FROM inbox_conversations").fetchone() == ("open", 1)
    app.execute("SELECT inbox_mark_read(%s)", (conv,))
    assert app.execute("SELECT unread FROM inbox_conversations").fetchone()[0] == 0


def test_dispatcher_status_only_moves_queued(app, world):
    set_tenant(app, world["a"]["tenant"])
    u = world["a"]["user"]
    ch, _ = channel(app, world, kind="telegram")
    conv = contact(app, world, ch, ext="tg")
    s = app.execute("SELECT seq FROM inbox_send(%s,%s,'staff','hi')", (conv, u)).fetchone()[0]
    assert app.execute("SELECT inbox_message_status(%s,'sent',NULL)", (s,)).fetchone()[0] is True
    assert app.execute("SELECT inbox_message_status(%s,'failed','x')", (s,)).fetchone()[0] is False        # already sent
    s2 = app.execute("SELECT seq FROM inbox_send(%s,%s,'staff','again')", (conv, u)).fetchone()[0]
    app.execute("SELECT inbox_message_status(%s,'failed',%s)", (s2, "e" * 300))
    assert app.execute("SELECT status, length(error) FROM inbox_messages WHERE seq=%s", (s2,)).fetchone() == ("failed", 200)
    with pytest.raises(psycopg.errors.RaiseException):
        app.execute("SELECT inbox_message_status(%s,'received',NULL)", (s2,))


def test_other_workspace_cannot_touch_it(app, world):
    set_tenant(app, world["b"]["tenant"])
    chb, _ = channel(app, world, "b")
    convb = contact(app, world, chb, "b")
    app.commit()
    set_tenant(app, world["a"]["tenant"])
    assert app.execute("SELECT count(*) FROM inbox_conversations").fetchone()[0] == 0
    for sql, args in (("SELECT * FROM inbox_receive(%s,'x',NULL)", (convb,)), ("SELECT * FROM inbox_send(%s,%s,'staff','x')", (convb, world["a"]["user"])),
                      ("SELECT inbox_set_mode(%s,NULL,'human',NULL)", (convb,)), ("SELECT inbox_resolve(%s,NULL,true)", (convb,)), ("SELECT inbox_mark_read(%s)", (convb,)),
                      ("SELECT * FROM inbox_contact_ensure(%s,'z',NULL,NULL,%s,%s)", (chb, ulid(), ulid())), ("SELECT inbox_contact_verify(%s,'v1',%s)", (chb, tok()))):
        with pytest.raises(psycopg.errors.InsufficientPrivilege):
            app.execute(sql, args)
        app.rollback()
        set_tenant(app, world["a"]["tenant"])
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("SELECT inbox_guard(%s)", (convb,))


def test_message_shape_rules(app, world, owner):
    as_admin(owner)
    t = world["a"]["tenant"]
    ch, conv = ulid(), ulid()
    cid = ulid()
    owner.execute("INSERT INTO inbox_channels (id,tenant_id,kind,name,public_key) VALUES (%s,%s,'web_widget','x',%s)", (ch, t, "b" * 32))
    owner.execute("INSERT INTO inbox_contacts (id,tenant_id,channel_id,external_id) VALUES (%s,%s,%s,'e')", (cid, t, ch))
    owner.execute("INSERT INTO inbox_conversations (id,tenant_id,channel_id,contact_id) VALUES (%s,%s,%s,%s)", (conv, t, ch, cid))
    for d, s, st in (("in", "staff", "received"), ("out", "customer", "sent"), ("in", "customer", "sent"), ("out", "agent", "received")):
        with pytest.raises(psycopg.errors.CheckViolation):
            owner.execute("INSERT INTO inbox_messages (tenant_id,conversation_id,direction,sender,body,status) VALUES (%s,%s,%s,%s,'x',%s)", (t, conv, d, s, st))
        owner.rollback()
        as_admin(owner)
    with pytest.raises(psycopg.errors.CheckViolation):
        owner.execute("INSERT INTO inbox_messages (tenant_id,conversation_id,direction,sender,body,status) VALUES (%s,%s,'in','customer','   ','received')", (t, conv))
