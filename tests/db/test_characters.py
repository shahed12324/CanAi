import psycopg
import pytest

from conftest import as_admin, set_tenant, ulid


@pytest.fixture(autouse=True)
def clean(owner, world):
    yield
    as_admin(owner)
    owner.execute("ALTER TABLE messages DISABLE TRIGGER messages_guard_trg")
    for t in ("chat_attachments", "messages", "conversations", "characters"):
        owner.execute(f"DELETE FROM {t}")
    owner.execute("ALTER TABLE messages ENABLE TRIGGER messages_guard_trg")


def char(conn, w, who="a", name="রুমি", **kw):
    cid = ulid()
    d = dict(kind="female", personality="হাসিখুশি", language="bn", avatar_style="none")
    d.update(kw)
    conn.execute("INSERT INTO characters (id,tenant_id,created_by,name,kind,personality,language,avatar_style) VALUES (%s,%s,%s,%s,%s,%s,%s,%s)",
                 (cid, w[who]["tenant"], w[who]["user"], name, d["kind"], d["personality"], d["language"], d["avatar_style"]))
    return cid


def conv(conn, w, who="a", character=None):
    cid = ulid()
    conn.execute("INSERT INTO conversations (id,tenant_id,user_id,title,character_id) VALUES (%s,%s,%s,'t',%s)", (cid, w[who]["tenant"], w[who]["user"], character))
    return cid


def test_tenant_isolation(app, world):
    set_tenant(app, world["a"]["tenant"])
    c = char(app, world)
    set_tenant(app, world["b"]["tenant"])
    assert app.execute("SELECT count(*) FROM characters WHERE id=%s", (c,)).fetchone()[0] == 0
    assert app.execute("UPDATE characters SET name='x' WHERE id=%s", (c,)).rowcount == 0
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        char(app, world, who="a")      # cannot write a row for another tenant


@pytest.mark.parametrize("kw", [dict(kind="robot"), dict(language="fr"), dict(avatar_style="4d"), dict(name="   "), dict(personality=" ")])
def test_check_constraints(app, world, kw):
    set_tenant(app, world["a"]["tenant"])
    with pytest.raises(psycopg.errors.CheckViolation):
        char(app, world, **kw)


def test_length_limits(app, world):
    set_tenant(app, world["a"]["tenant"])
    with pytest.raises(psycopg.errors.StringDataRightTruncation):
        char(app, world, personality="x" * 1001)
    app.rollback()
    set_tenant(app, world["a"]["tenant"])
    char(app, world, personality="x" * 1000)


def test_max_20_live_characters_and_deleted_ones_do_not_count(app, world):
    set_tenant(app, world["a"]["tenant"])
    ids = [char(app, world, name=f"c{i}") for i in range(20)]
    with pytest.raises(psycopg.errors.ProgramLimitExceeded):
        char(app, world, name="21st")
    app.rollback()
    set_tenant(app, world["a"]["tenant"])
    app.execute("UPDATE characters SET deleted_at=now() WHERE id=%s", (ids[0],))
    char(app, world, name="again")
    set_tenant(app, world["b"]["tenant"])
    char(app, world, who="b", name="other tenant is separate")


def test_conversation_may_use_own_tenant_character_only(app, world):
    set_tenant(app, world["a"]["tenant"])
    mine = char(app, world)
    cid = conv(app, world, character=mine)
    assert app.execute("SELECT character_id FROM conversations WHERE id=%s", (cid,)).fetchone()[0].strip() == mine
    set_tenant(app, world["b"]["tenant"])
    with pytest.raises(psycopg.errors.InvalidParameterValue):
        conv(app, world, who="b", character=mine)       # other tenant's character
    app.rollback()
    set_tenant(app, world["a"]["tenant"])
    with pytest.raises(psycopg.errors.InvalidParameterValue):
        conv(app, world, character=ulid())              # nonexistent


def test_deleted_character_cannot_start_new_conversation_and_old_ones_survive(app, world):
    set_tenant(app, world["a"]["tenant"])
    c = char(app, world)
    cid = conv(app, world, character=c)
    app.execute("UPDATE characters SET deleted_at=now() WHERE id=%s", (c,))
    with pytest.raises(psycopg.errors.InvalidParameterValue):
        conv(app, world, character=c)
    app.rollback()
    set_tenant(app, world["a"]["tenant"])
    app.execute("UPDATE conversations SET title='still fine' WHERE id=%s", (cid,))


def test_conversation_character_is_fixed(app, world):
    set_tenant(app, world["a"]["tenant"])
    c1, c2 = char(app, world, name="a"), char(app, world, name="b")
    cid = conv(app, world, character=c1)
    for new in (c2, None):
        with pytest.raises(psycopg.errors.InvalidParameterValue):
            app.execute("UPDATE conversations SET character_id=%s WHERE id=%s", (new, cid))
        app.rollback()
        set_tenant(app, world["a"]["tenant"])
    plain = conv(app, world)
    with pytest.raises(psycopg.errors.InvalidParameterValue):
        app.execute("UPDATE conversations SET character_id=%s WHERE id=%s", (c1, plain))


def test_even_with_rls_bypassed_a_conversation_cannot_use_another_tenants_character(app, owner, world):
    set_tenant(app, world["a"]["tenant"])
    mine = char(app, world)
    app.commit()
    as_admin(owner)
    with pytest.raises(psycopg.errors.InvalidParameterValue):
        conv(owner, world, who="b", character=mine)
    owner.rollback()
