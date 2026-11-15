"""The SQL strings behind the Memory Center (PgMemoryRepo), run against real PG - above all that one person never sees a colleague's personal notes."""
import json

from conftest import as_admin, ulid
from test_accounting import ctx
from test_lead_php_sql import sql_of
from test_memory import clean, second_user  # noqa: F401  (fixtures reused)

REPO = "app/Memory/PgMemoryRepo.php"


def add(app, t, u, text, scope="user", pinned=False, cat="fact"):
    return app.execute(sql_of(REPO, "select memory_add("), (ulid(), t, u, scope, cat, text, "true" if pinned else "false", "user", None)).fetchone()[0]


def test_lists_are_private_to_the_person(app, world, second_user):
    t, u = world["a"]["tenant"], world["a"]["user"]
    ctx(app, world, "a")
    assert add(app, t, u, "mine plain") == "ok"
    assert add(app, t, u, "mine pinned", pinned=True) == "ok"
    assert add(app, t, second_user, "colleague private") == "ok"
    assert add(app, t, second_user, "shared by colleague", scope="business") == "ok"
    mine = [r[3] for r in app.execute(sql_of(REPO, "from memories where scope = 'business' or user_id"), (u,)).fetchall()]
    assert "colleague private" not in mine
    assert sorted(mine) == ["mine pinned", "mine plain", "shared by colleague"]
    assert mine[0] == "mine pinned"                                        # own first, pinned first
    theirs = [r[3] for r in app.execute(sql_of(REPO, "from memories where scope = 'business' or user_id"), (second_user,)).fetchall()]
    assert "mine plain" not in theirs and "shared by colleague" in theirs


def test_chat_block_skips_inactive_other_peoples_notes_and_respects_the_switch(app, world, second_user):
    t, u = world["a"]["tenant"], world["a"]["user"]
    ctx(app, world, "a")
    ids = {}
    for text, who, scope, pinned in (("plain", u, "user", False), ("pinned", u, "user", True), ("biz", second_user, "business", False), ("colleague", second_user, "user", False), ("off", u, "user", False)):
        mid = ulid()
        app.execute(sql_of(REPO, "select memory_add("), (mid, t, who, scope, "fact", text, "true" if pinned else "false", "user", None))
        ids[text] = mid
    assert app.execute("SELECT memory_update(%s,%s,%s,false,NULL,NULL,NULL,false)", (ids["off"], t, u)).fetchone()[0] == "ok"
    got = app.execute(sql_of(REPO, "from memories where active and"), (u, 10)).fetchall()
    assert [r[0] for r in got] == ["pinned", "plain", "biz"]               # pinned first, then own newest, then business; no "off", no "colleague"
    assert app.execute(sql_of(REPO, "from memories where active and"), (u, 2)).fetchall().__len__() == 2
    assert app.execute(sql_of(REPO, "select use_in_chat from memory_settings where user_id"), (u,)).fetchone() is None            # default = on
    assert app.execute(sql_of(REPO, "select memory_setting_save("), (t, u, "false")).fetchone()[0] == "ok"
    assert app.execute(sql_of(REPO, "select use_in_chat from memory_settings where user_id"), (u,)).fetchone()[0] is False


def test_write_statements_and_suggestions(app, world, second_user):
    t, u = world["a"]["tenant"], world["a"]["user"]
    ctx(app, world, "a")
    mid = ulid()
    assert app.execute(sql_of(REPO, "select memory_add("), (mid, t, u, "user", "fact", "likes tea", "false", "user", None)).fetchone()[0] == "ok"
    assert app.execute(sql_of(REPO, "select memory_update("), (mid, t, u, "false", "likes green tea", None, "true", None)).fetchone()[0] == "ok"
    assert app.execute(sql_of(REPO, "select memory_update("), (mid, t, second_user, "true", "hijack", None, None, None)).fetchone()[0] == "not_found"
    conv = ulid()
    items = json.dumps([{"content": "sells dry fruits", "category": "business"}, {"content": "my password is x", "category": "fact"}])
    assert app.execute(sql_of(REPO, "select memory_suggest_save("), (t, u, conv, items, "{" + ulid() + "," + ulid() + "}")).fetchone()[0] == 1
    pend = app.execute(sql_of(REPO, "from memory_suggestions where user_id"), (u,)).fetchall()
    assert [(p[1], p[2]) for p in pend] == [("business", "sells dry fruits")]
    assert app.execute(sql_of(REPO, "from memory_suggestions where user_id"), (second_user,)).fetchall() == []
    assert app.execute(sql_of(REPO, "select memory_suggestion_accept("), (pend[0][0], t, u, ulid())).fetchone()[0] == "ok"
    assert app.execute(sql_of(REPO, "from memory_suggestions where user_id"), (u,)).fetchall() == []
    items2 = json.dumps([{"content": "another one", "category": "fact"}])
    app.execute(sql_of(REPO, "select memory_suggest_save("), (t, u, conv, items2, "{" + ulid() + "}"))
    sid = app.execute(sql_of(REPO, "from memory_suggestions where user_id"), (u,)).fetchone()[0]
    assert app.execute(sql_of(REPO, "select memory_suggestion_dismiss("), (sid, t, u)).fetchone()[0] == "ok"
    assert app.execute(sql_of(REPO, "select memory_delete("), (mid, t, u, "false")).fetchone()[0] == "ok"
    assert app.execute(sql_of(REPO, "select memory_clear("), (t, u)).fetchone()[0] == 1                    # "sells dry fruits" (accepted suggestion)


def test_recent_chats_are_mine_only_and_not_deleted(app, world, second_user, owner):
    t, u = world["a"]["tenant"], world["a"]["user"]
    as_admin(owner)
    for who, title, deleted in ((u, "mine", False), (u, "mine deleted", True), (second_user, "colleague", False)):
        owner.execute("INSERT INTO conversations (id,tenant_id,user_id,title,deleted_at) VALUES (%s,%s,%s,%s,%s)", (ulid(), t, who, title, "now()" if False else (None if not deleted else "2026-01-01")))
    ctx(app, world, "a")
    assert [r[1] for r in app.execute(sql_of(REPO, "from conversations where user_id"), (u, 10)).fetchall()] == ["mine"]
    owner.execute("DELETE FROM conversations")
