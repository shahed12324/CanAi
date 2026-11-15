import json

import psycopg
import pytest

from conftest import as_admin, ulid
from test_accounting import ctx
from test_chat import conv, msg


@pytest.fixture(autouse=True)
def clean(owner, world):
    yield
    as_admin(owner)
    owner.execute("DELETE FROM chat_message_sources")
    owner.execute("ALTER TABLE messages DISABLE TRIGGER messages_guard_trg")
    for t in ("chat_attachments", "messages", "conversations"):
        owner.execute(f"DELETE FROM {t}")
    owner.execute("ALTER TABLE messages ENABLE TRIGGER messages_guard_trg")


def answer(owner, world, who="a", role="assistant"):
    as_admin(owner)
    return msg(owner, world, conv(owner, world, who), who, role)


def item(n=1, **kw):
    d = {"n": n, "document_id": "D" * 26, "title": "দামের তালিকা", "seq": 2, "excerpt": "কাজু ১২০০ টাকা", "cited": True}
    d.update(kw)
    return d


def save(app, world, mid, items, who="a", user=None):
    ctx(app, world, who)
    return app.execute("SELECT chat_sources_save(%s,%s,%s,%s::jsonb)", (world[who]["tenant"], user or world[who]["user"], mid, json.dumps(items))).fetchone()[0]


def rows(app, world, who="a"):
    ctx(app, world, who)
    return app.execute("SELECT n, title, passage_seq, excerpt, cited FROM chat_message_sources ORDER BY message_id, n").fetchall()


def test_sources_are_stored_in_order_with_cited_flag(app, owner, world):
    mid = answer(owner, world)
    assert save(app, world, mid, [item(2, cited=False, title="অন্য"), item(1)]) == 2
    assert rows(app, world) == [(1, "দামের তালিকা", 2, "কাজু ১২০০ টাকা", True), (2, "অন্য", 2, "কাজু ১২০০ টাকা", False)]


def test_saving_twice_does_not_duplicate(app, owner, world):
    mid = answer(owner, world)
    assert save(app, world, mid, [item(1)]) == 1
    assert save(app, world, mid, [item(1), item(2)]) == 1
    assert len(rows(app, world)) == 2


def test_only_the_conversation_owner_can_attach_sources(app, owner, world):
    mid = answer(owner, world)
    other = ulid()
    as_admin(owner)
    owner.execute("INSERT INTO users (id,name,email,password,email_verified_at) VALUES (%s,'u2',%s,'x',now())", (other, f"u2-{other}@example.com"))
    owner.execute("INSERT INTO tenant_users (id,tenant_id,user_id,joined_at) VALUES (%s,%s,%s,now())", (ulid(), world["a"]["tenant"], other))
    assert save(app, world, mid, [item()], user=other) == 0
    assert rows(app, world) == []


def test_only_assistant_answers_that_exist_in_my_tenant(app, owner, world):
    assert save(app, world, answer(owner, world, role="user"), [item()]) == 0
    assert save(app, world, ulid(), [item()]) == 0
    foreign = answer(owner, world, who="b")
    assert save(app, world, foreign, [item()]) == 0                       # my tenant, someone else's message
    assert rows(app, world, "b") == []


def test_foreign_tenant_and_missing_user_are_refused(app, owner, world):
    mid = answer(owner, world)
    ctx(app, world, "a")
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("SELECT chat_sources_save(%s,%s,%s,'[]'::jsonb)", (world["b"]["tenant"], world["a"]["user"], mid))
    app.rollback()
    ctx(app, world, "a")
    with pytest.raises(psycopg.errors.InvalidParameterValue):
        app.execute("SELECT chat_sources_save(%s,NULL,%s,'[]'::jsonb)", (world["a"]["tenant"], mid))


def test_other_tenant_sees_nothing(app, owner, world):
    save(app, world, answer(owner, world), [item()])
    assert rows(app, world, "b") == []


@pytest.mark.parametrize("bad", [item(0), item(11), item(seq=0), item(title="  "), item(excerpt=""), item(cited="yes"), item(n="1"), item(seq=None), item(title=5), item(excerpt=5), {"n": 1}, "text", 5, None])
def test_bad_items_are_skipped(app, owner, world, bad):
    assert save(app, world, answer(owner, world), [bad, item(3)]) == 1
    assert [r[0] for r in rows(app, world)] == [3]


def test_non_array_and_cap_of_ten(app, owner, world):
    mid = answer(owner, world)
    assert save(app, world, mid, {"n": 1}) == 0
    ctx(app, world, "a")
    assert app.execute("SELECT chat_sources_save(%s,%s,%s,NULL)", (world["a"]["tenant"], world["a"]["user"], mid)).fetchone()[0] == 0
    assert save(app, world, mid, [item(n) for n in range(1, 11)] + [item(10, title="x")]) == 10


def test_long_text_is_cut_and_optional_document_id(app, owner, world):
    mid = answer(owner, world)
    assert save(app, world, mid, [item(1, title="ক" * 300, excerpt="খ" * 500, document_id=None)]) == 1
    ctx(app, world, "a")
    assert app.execute("SELECT char_length(title), char_length(excerpt), document_id FROM chat_message_sources").fetchone() == (200, 400, None)


def test_app_role_cannot_write_directly(app, owner, world):
    mid = answer(owner, world)
    ctx(app, world, "a")
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("INSERT INTO chat_message_sources (tenant_id,message_id,n,title,passage_seq,excerpt,cited) VALUES (%s,%s,1,'t',1,'e',true)", (world["a"]["tenant"], mid))


def test_long_document_id_is_cut_to_fit(app, owner, world):
    mid = answer(owner, world)
    assert save(app, world, mid, [item(1, document_id="D" * 40)]) == 1


def test_cannot_attach_to_a_message_of_another_workspace_even_for_a_member_of_both(app, owner, world):
    as_admin(owner)
    owner.execute("INSERT INTO tenant_users (id,tenant_id,user_id,joined_at) VALUES (%s,%s,%s,now())", (ulid(), world["b"]["tenant"], world["a"]["user"]))
    cid = ulid()
    owner.execute("INSERT INTO conversations (id,tenant_id,user_id,title) VALUES (%s,%s,%s,'x')", (cid, world["b"]["tenant"], world["a"]["user"]))
    mid = msg(owner, world, cid, "b", "assistant")
    assert save(app, world, mid, [item()]) == 0                           # tenant A + a message that lives in tenant B
    as_admin(owner)
    owner.execute("DELETE FROM tenant_users WHERE tenant_id=%s AND user_id=%s", (world["b"]["tenant"], world["a"]["user"]))
