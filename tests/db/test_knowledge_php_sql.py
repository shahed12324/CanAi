"""The SQL strings and the array literal behind the Knowledge Base (PgKnowledgeRepo), run against real PG."""
import json
import subprocess

from conftest import as_admin, ulid
from test_accounting import ctx
from test_knowledge import clean, search  # noqa: F401  (fixtures reused)
from test_lead_php_sql import sql_of

REPO = "app/Knowledge/PgKnowledgeRepo.php"
TRICKY = ['plain', 'with "quotes" inside', 'back\\slash and \\"both\\"', "single ' quote", "commas, {braces}, NULL", "বাংলা, \"উদ্ধৃতি\" ও \\", "multi\nline\ttext", " lead and trail ", "NULL"]


def php_literal(items):
    code = 'require "app/Knowledge/KnowledgeRepo.php"; require "app/Knowledge/PgKnowledgeRepo.php"; echo App\\Knowledge\\PgKnowledgeRepo::pgArray(json_decode(stream_get_contents(STDIN), true));'
    r = subprocess.run(["php", "-r", code], input=json.dumps(items), capture_output=True, text=True, cwd="apps/web-overlay")
    assert r.returncode == 0, r.stderr
    return r.stdout


def test_array_literal_round_trips_through_postgres_exactly(app):
    got = app.execute("SELECT %s::text[]", (php_literal(TRICKY),)).fetchone()[0]
    assert got == TRICKY


def test_save_list_search_update_delete_with_the_real_sql(app, world):
    t, u = world["a"]["tenant"], world["a"]["user"]
    ctx(app, world, "a")
    did = ulid()
    r = app.execute(sql_of(REPO, "select kb_document_save("), (did, t, u, "দাম", "p.txt", "text/plain", 10, php_literal(["কাজু ১২০০ টাকা", 'say "hi" \\ there'])))
    assert r.fetchone()[0] == "ok"
    docs = app.execute(sql_of(REPO, "from kb_documents order by")).fetchall()
    assert [(d[1], d[2], d[6]) for d in docs] == [("দাম", "p.txt", 2)]
    hits = app.execute(sql_of(REPO, "from kb_search("), ("কাজু", 5)).fetchall()
    assert [(h[0], h[2], h[3]) for h in hits] == [(did, 1, "কাজু ১২০০ টাকা")]
    assert app.execute(sql_of(REPO, "select kb_document_update("), (did, t, "নতুন", "false")).fetchone()[0] == "ok"
    assert app.execute(sql_of(REPO, "from kb_search("), ("কাজু", 5)).fetchall() == []
    assert app.execute(sql_of(REPO, "select kb_document_update("), (did, t, None, None)).fetchone()[0] == "ok"
    assert app.execute(sql_of(REPO, "select kb_document_delete("), (did, t)).fetchone()[0] == "ok"
    assert app.execute(sql_of(REPO, "from kb_documents order by")).fetchall() == []


def test_a_colleague_workspace_cannot_search_or_list(app, world):
    t, u = world["a"]["tenant"], world["a"]["user"]
    ctx(app, world, "a")
    app.execute(sql_of(REPO, "select kb_document_save("), (ulid(), t, u, "secret", "s.txt", "text/plain", 1, php_literal(["internal salary plan"])))
    ctx(app, world, "b")
    assert app.execute(sql_of(REPO, "from kb_documents order by")).fetchall() == []
    assert app.execute(sql_of(REPO, "from kb_search("), ("salary", 5)).fetchall() == []


def test_sources_are_recorded_and_read_back_with_the_real_sql(app, owner, world):
    from test_chat import conv, msg
    as_admin(owner)
    cid = conv(owner, world, "a")
    mid = msg(owner, world, cid, "a", "assistant", "উত্তর")
    items = [{"n": 1, "document_id": "D" * 26, "title": "দাম", "seq": 2, "excerpt": "কাজু", "cited": True}, {"n": 2, "document_id": "E" * 26, "title": "নীতি", "seq": 1, "excerpt": "ফেরত", "cited": False}]
    ctx(app, world, "a")
    assert app.execute(sql_of(REPO, "select chat_sources_save("), (world["a"]["tenant"], world["a"]["user"], mid, json.dumps(items, ensure_ascii=False))).fetchone()[0] == 2
    got = app.execute(sql_of(REPO, "from chat_message_sources where message_id"), (php_literal([mid]),)).fetchall()
    assert [(g[0].strip(), g[1], g[2], g[3], g[4], g[5]) for g in got] == [(mid, 1, "দাম", 2, "কাজু", True), (mid, 2, "নীতি", 1, "ফেরত", False)]
    ctx(app, world, "b")
    assert app.execute(sql_of(REPO, "from chat_message_sources where message_id"), (php_literal([mid]),)).fetchall() == []
    as_admin(owner)
    owner.execute("DELETE FROM chat_message_sources")
    owner.execute("ALTER TABLE messages DISABLE TRIGGER messages_guard_trg")
    for t in ("messages", "conversations"):
        owner.execute(f"DELETE FROM {t}")
    owner.execute("ALTER TABLE messages ENABLE TRIGGER messages_guard_trg")
