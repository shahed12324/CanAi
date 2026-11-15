import pytest
import psycopg

from conftest import as_admin, set_tenant, ulid


@pytest.fixture(autouse=True)
def clean(owner, world):
    yield
    as_admin(owner)
    owner.execute("ALTER TABLE messages DISABLE TRIGGER messages_guard_trg")
    for t in ("chat_attachments", "messages", "conversations"):
        owner.execute(f"DELETE FROM {t}")
    owner.execute("ALTER TABLE messages ENABLE TRIGGER messages_guard_trg")


def conv(conn, w, who="a", title="hello"):
    cid = ulid()
    conn.execute("INSERT INTO conversations (id,tenant_id,user_id,title) VALUES (%s,%s,%s,%s)", (cid, w[who]["tenant"], w[who]["user"], title))
    return cid


def msg(conn, w, cid, who="a", role="user", content="hi", status="complete"):
    mid = ulid()
    conn.execute("INSERT INTO messages (id,tenant_id,conversation_id,role,content,status) VALUES (%s,%s,%s,%s,%s,%s)",
                 (mid, w[who]["tenant"], cid, role, content, status))
    return mid


def test_tenant_cannot_see_other_tenants_chats(app, owner, world):
    as_admin(owner)
    ca, cb = conv(owner, world, "a"), conv(owner, world, "b")
    msg(owner, world, ca, "a", content="secret-A")
    msg(owner, world, cb, "b", content="secret-B")
    owner.execute("SELECT set_config('app.bypass_rls','',false)")

    set_tenant(app, world["a"]["tenant"])
    assert [r[0] for r in app.execute("SELECT content FROM messages").fetchall()] == ["secret-A"]
    assert app.execute("SELECT count(*) FROM conversations WHERE id=%s", (cb,)).fetchone()[0] == 0
    # updating / deleting the other tenant's rows touches nothing
    assert app.execute("UPDATE conversations SET title='x' WHERE id=%s", (cb,)).rowcount == 0


def test_cannot_insert_into_other_tenants_conversation(app, owner, world):
    as_admin(owner)
    cb = conv(owner, world, "b")
    owner.execute("SELECT set_config('app.bypass_rls','',false)")
    set_tenant(app, world["a"]["tenant"])
    # tenant A tries to attach a message to B's conversation, labelled as tenant A: blocked by the same-tenant trigger
    with pytest.raises(psycopg.Error):
        app.execute("INSERT INTO messages (id,tenant_id,conversation_id,role,content,status) VALUES (%s,%s,%s,'user','x','complete')",
                    (ulid(), world["a"]["tenant"], cb))


def test_cannot_insert_row_labelled_with_other_tenant(app, world):
    set_tenant(app, world["a"]["tenant"])
    with pytest.raises(psycopg.Error):
        app.execute("INSERT INTO conversations (id,tenant_id,user_id,title) VALUES (%s,%s,%s,'x')", (ulid(), world["b"]["tenant"], world["b"]["user"]))


def test_finished_messages_are_immutable_but_can_be_hidden(app, world):
    set_tenant(app, world["a"]["tenant"])
    cid = conv(app, world)
    mid = msg(app, world, cid, role="assistant", content="answer")
    with pytest.raises(psycopg.errors.RaiseException):
        app.execute("UPDATE messages SET content='tampered' WHERE id=%s", (mid,))
    app.rollback()
    set_tenant(app, world["a"]["tenant"])
    with pytest.raises(psycopg.errors.RaiseException):
        app.execute("UPDATE messages SET credits_charged=0, status='complete', input_tokens=1 WHERE id=%s", (mid,))
    app.rollback()
    set_tenant(app, world["a"]["tenant"])
    assert app.execute("UPDATE messages SET hidden_at=now() WHERE id=%s", (mid,)).rowcount == 1
    with pytest.raises(psycopg.errors.RaiseException):
        app.execute("DELETE FROM messages WHERE id=%s", (mid,))


def test_streaming_message_can_be_finalized_once(app, world):
    set_tenant(app, world["a"]["tenant"])
    cid = conv(app, world)
    mid = msg(app, world, cid, role="assistant", content="", status="streaming")
    app.execute("UPDATE messages SET content='partial text', updated_at=now() WHERE id=%s", (mid,))
    app.execute("UPDATE messages SET status='complete', output_tokens=5, credits_charged=1.5 WHERE id=%s", (mid,))
    with pytest.raises(psycopg.errors.RaiseException):
        app.execute("UPDATE messages SET content='again' WHERE id=%s", (mid,))


def test_stale_streams_are_finalized(app, owner, world):
    as_admin(owner)
    cid = conv(owner, world)
    a = msg(owner, world, cid, role="assistant", content="some words", status="streaming")
    b = msg(owner, world, cid, role="assistant", content="", status="streaming")
    c = msg(owner, world, cid, role="assistant", content="fresh", status="streaming")
    owner.execute("UPDATE messages SET updated_at = now() - interval '1 hour' WHERE id IN (%s,%s)", (a, b))
    owner.execute("SELECT set_config('app.bypass_rls','',false)")

    n = owner.execute("SELECT chat_finalize_stale(1200)").fetchone()[0]
    assert n == 2
    as_admin(owner)
    rows = dict((r[0], (r[1], r[2])) for r in owner.execute("SELECT id,status,error_code FROM messages WHERE id IN (%s,%s,%s)", (a, b, c)).fetchall())
    assert rows[a] == ("partial", "interrupted") and rows[b] == ("error", "interrupted") and rows[c] == ("streaming", None)


def test_attachment_limits_and_isolation(app, owner, world):
    set_tenant(app, world["a"]["tenant"])
    cid = conv(app, world)
    app.execute("INSERT INTO chat_attachments (id,tenant_id,conversation_id,user_id,filename,mime,size_bytes,extracted_text) VALUES (%s,%s,%s,%s,'a.txt','text/plain',3,'abc')",
                (ulid(), world["a"]["tenant"], cid, world["a"]["user"]))
    with pytest.raises(psycopg.errors.CheckViolation):
        app.execute("INSERT INTO chat_attachments (id,tenant_id,conversation_id,user_id,filename,mime,size_bytes,extracted_text) VALUES (%s,%s,%s,%s,'big.txt','text/plain',3,%s)",
                    (ulid(), world["a"]["tenant"], cid, world["a"]["user"], "x" * 200001))
    app.rollback()
    set_tenant(app, world["b"]["tenant"])
    assert app.execute("SELECT count(*) FROM chat_attachments").fetchone()[0] == 0


def test_admin_analytics_expressions_run(owner, world):
    """The aggregate expressions used by Admin\\ChatAnalyticsController (kept in sync by hand; syntax and semantics)."""
    as_admin(owner)
    cid = conv(owner, world)
    msg(owner, world, cid, role="assistant", content="ok", status="complete")
    owner.execute("INSERT INTO messages (id,tenant_id,conversation_id,role,content,status,error_code) VALUES (%s,%s,%s,'assistant','','error','timeout')",
                  (ulid(), world["a"]["tenant"], cid))
    t = owner.execute(
        "select count(*) as answers, count(*) filter (where status = 'complete') as ok, count(*) filter (where status in ('error','partial')) as failed,"
        " coalesce(sum(credits_charged),0), coalesce(sum(input_tokens),0), coalesce(sum(output_tokens),0), count(distinct tenant_id)"
        " from messages where role = 'assistant' and created_at >= now() - interval '30 days'"
    ).fetchone()
    assert (t[0], t[1], t[2], t[6]) == (2, 1, 1, 1)
    assert owner.execute(
        "select created_at::date as day, count(*), count(*) filter (where status in ('error','partial')), coalesce(sum(credits_charged),0)"
        " from messages where role='assistant' group by 1 order by 1 desc limit 31"
    ).fetchall()
    rows = owner.execute(
        "select coalesce(p.name,'—') as provider, coalesce(m.error_code,'unknown') as code, count(*) as n, max(m.created_at)"
        " from messages m left join ai_providers p on p.id = m.provider_id where m.role='assistant' and m.status in ('error','partial')"
        " group by 1, 2 order by count(*) desc limit 30"
    ).fetchall()
    assert [(r[0], r[1], r[2]) for r in rows] == [("—", "timeout", 1)]
    # dashboard: questions this month for one user
    n = owner.execute(
        "select count(*) from messages m join conversations c on c.id = m.conversation_id where c.user_id = %s and m.role='user' and m.created_at >= date_trunc('month', now())",
        (world["a"]["user"],),
    ).fetchone()[0]
    assert n == 0


def test_conversation_mode_defaults_to_general_and_only_accepts_known_modes(app, owner, world):
    set_tenant(app, world["a"]["tenant"])
    cid = conv(app, world)
    assert app.execute("SELECT mode FROM conversations WHERE id=%s", (cid,)).fetchone()[0] == "general"
    c2 = ulid()
    app.execute("INSERT INTO conversations (id,tenant_id,user_id,title,mode) VALUES (%s,%s,%s,'c','code')", (c2, world["a"]["tenant"], world["a"]["user"]))
    assert app.execute("SELECT mode FROM conversations WHERE id=%s", (c2,)).fetchone()[0] == "code"
    with pytest.raises(psycopg.errors.CheckViolation):
        app.execute("INSERT INTO conversations (id,tenant_id,user_id,title,mode) VALUES (%s,%s,%s,'c','hacker')", (ulid(), world["a"]["tenant"], world["a"]["user"]))


def test_conversation_mode_cannot_be_changed_afterwards(app, owner, world):
    set_tenant(app, world["a"]["tenant"])
    cid = conv(app, world)
    with pytest.raises(psycopg.errors.InvalidParameterValue):
        app.execute("UPDATE conversations SET mode='code' WHERE id=%s", (cid,))
    app.rollback()
    set_tenant(app, world["a"]["tenant"])
    app.execute("UPDATE conversations SET title='renamed' WHERE id=%s", (cid,))     # other updates still fine
    assert app.execute("SELECT title,mode FROM conversations WHERE id=%s", (cid,)).fetchone() == ("renamed", "general")
