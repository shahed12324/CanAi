import psycopg
import pytest

from conftest import as_admin, ulid
from test_accounting import ctx


@pytest.fixture(autouse=True)
def clean(owner, world):
    yield
    as_admin(owner)
    owner.execute("DELETE FROM kb_documents")        # passages go with them


def save(app, world, chunks=None, *, title="দামের তালিকা", who="a", did=None, size=100, filename="prices.txt", mime="text/plain"):
    ctx(app, world, who)
    did = did or ulid()
    chunks = ["কাজু বাদামের দাম প্রতি কেজি ১২০০ টাকা"] if chunks is None else chunks
    r = app.execute("SELECT kb_document_save(%s,%s,%s,%s,%s,%s,%s,%s)", (did, world[who]["tenant"], world[who]["user"], title, filename, mime, size, chunks)).fetchone()[0]
    return did, r


def search(app, world, q, k=5, who="a"):
    ctx(app, world, who)
    return app.execute("SELECT document_id, title, seq, content, rank FROM kb_search(%s,%s)", (q, k)).fetchall()


def test_save_and_find_in_bengali(app, world):
    did, r = save(app, world, ["আমাদের দোকান বসুন্ধরায়। ডেলিভারি ঢাকায় ২৪ ঘণ্টায়।", "কাজু বাদামের দাম প্রতি কেজি ১২০০ টাকা"])
    assert r == "ok"
    hits = search(app, world, "কাজু বাদামের দাম কত?")
    assert [h[0] for h in hits] == [did] and hits[0][2] == 2 and hits[0][1] == "দামের তালিকা"


def test_prefix_matching_finds_inflected_forms(app, world):
    save(app, world, ["ডেলিভারি ঢাকায় ২৪ ঘণ্টায়।"])
    assert len(search(app, world, "ঢাকা")) == 1                    # ঢাকা finds ঢাকায়
    assert search(app, world, "চট্টগ্রাম") == []


def test_ranking_prefers_better_passage(app, world):
    save(app, world, ["delivery is free", "delivery charge in Dhaka is 60 taka and outside Dhaka is 120 taka delivery"])
    hits = search(app, world, "delivery charge Dhaka")
    assert hits[0][2] == 2


def test_empty_and_symbol_only_queries_find_nothing(app, world):
    save(app, world)
    for q in ("", "   ", "?!", "()|&:*", None):
        assert search(app, world, q) == []


def test_query_with_quotes_and_syntax_chars_is_safe(app, world):
    save(app, world, ["it's a test"])
    assert len(search(app, world, "it's a test")) == 1
    assert search(app, world, "x' | ' :* & !") == []


def test_k_is_clamped(app, world):
    save(app, world, [f"common word number {i}" for i in range(1, 31)])
    assert len(search(app, world, "common", 100)) == 20
    assert len(search(app, world, "common", 0)) == 1
    assert len(search(app, world, "common", None)) == 5


def test_inactive_documents_are_not_searched(app, world):
    did, _ = save(app, world)
    ctx(app, world, "a")
    assert app.execute("SELECT kb_document_update(%s,%s,NULL,false)", (did, world["a"]["tenant"])).fetchone()[0] == "ok"
    assert search(app, world, "কাজু") == []
    app.execute("SELECT kb_document_update(%s,%s,NULL,true)", (did, world["a"]["tenant"]))
    assert len(search(app, world, "কাজু")) == 1


def test_other_tenant_sees_and_finds_nothing(app, world):
    save(app, world)
    assert search(app, world, "কাজু", who="b") == []
    ctx(app, world, "b")
    assert app.execute("SELECT count(*) FROM kb_documents").fetchone()[0] == 0
    assert app.execute("SELECT count(*) FROM kb_chunks").fetchone()[0] == 0


@pytest.mark.parametrize("kw", [dict(title="  "), dict(title="ক" * 201), dict(chunks=[]), dict(chunks=["ok", " "]), dict(chunks=["ক" * 2001]), dict(chunks=["a"] * 601), dict(filename="f" * 201), dict(mime="m" * 101), dict(size=-1)])
def test_bad_input(app, world, kw):
    assert save(app, world, **kw)[1] == "bad_input"
    ctx(app, world, "a")
    assert app.execute("SELECT count(*) FROM kb_documents").fetchone()[0] == 0


def test_null_chunks_and_null_id(app, world):
    ctx(app, world, "a")
    t, u = world["a"]["tenant"], world["a"]["user"]
    assert app.execute("SELECT kb_document_save(%s,%s,%s,'t','f','m',1,NULL)", (ulid(), t, u)).fetchone()[0] == "bad_input"
    assert app.execute("SELECT kb_document_save(NULL,%s,%s,'t','f','m',1,ARRAY['x'])", (t, u)).fetchone()[0] == "bad_input"
    assert app.execute("SELECT kb_document_save(%s,%s,%s,'t','f','m',1,ARRAY['x',NULL])", (ulid(), t, u)).fetchone()[0] == "bad_input"


def test_600_passages_and_2000_chars_are_fine(app, world):
    assert save(app, world, ["a" * 2000] + ["b"] * 599)[1] == "ok"


def test_same_text_is_not_stored_twice_but_other_tenant_can(app, world):
    assert save(app, world)[1] == "ok"
    assert save(app, world, title="অন্য নাম")[1] == "duplicate"
    assert save(app, world, who="b")[1] == "ok"


def test_document_limit(app, world, owner):
    as_admin(owner)
    t, u = world["a"]["tenant"], world["a"]["user"]
    owner.execute("INSERT INTO kb_documents (id,tenant_id,created_by,title,filename,mime,size_bytes,text_hash,char_count,chunk_count) SELECT lpad(g::text,26,'d'),%s,%s,'d','f','m',1,md5(g::text),1,1 FROM generate_series(1,50) g", (t, u))
    assert save(app, world)[1] == "limit"


def test_user_required_and_tenant_check(app, world):
    ctx(app, world, "a")
    with pytest.raises(psycopg.errors.InvalidParameterValue):
        app.execute("SELECT kb_document_save(%s,%s,NULL,'t','f','m',1,ARRAY['x'])", (ulid(), world["a"]["tenant"]))
    app.rollback()
    ctx(app, world, "a")
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("SELECT kb_document_save(%s,%s,%s,'t','f','m',1,ARRAY['x'])", (ulid(), world["b"]["tenant"], world["a"]["user"]))
    app.rollback()
    ctx(app, world, "a")
    for sql in ("SELECT kb_document_update(%s,%s,'x',NULL)", "SELECT kb_document_delete(%s,%s)"):
        with pytest.raises(psycopg.errors.InsufficientPrivilege):
            app.execute(sql, (ulid(), world["b"]["tenant"]))
        app.rollback()
        ctx(app, world, "a")


def test_update_title_and_validation(app, world):
    did, _ = save(app, world)
    t = world["a"]["tenant"]
    ctx(app, world, "a")
    assert app.execute("SELECT kb_document_update(%s,%s,'  নতুন নাম ',NULL)", (did, t)).fetchone()[0] == "ok"
    assert app.execute("SELECT title, active FROM kb_documents WHERE id=%s", (did,)).fetchone() == ("নতুন নাম", True)
    for bad in ("  ", "ক" * 201):
        assert app.execute("SELECT kb_document_update(%s,%s,%s,NULL)", (did, t, bad)).fetchone()[0] == "bad_input"
    assert app.execute("SELECT kb_document_update(%s,%s,NULL,NULL)", (did, t)).fetchone()[0] == "ok"        # nothing to change
    assert app.execute("SELECT kb_document_update(%s,%s,'x',NULL)", (ulid(), t)).fetchone()[0] == "not_found"
    assert app.execute("SELECT title FROM kb_documents WHERE id=%s", (did,)).fetchone()[0] == "নতুন নাম"


def test_update_cannot_touch_another_tenants_document(app, world):
    did, _ = save(app, world)
    ctx(app, world, "b")
    assert app.execute("SELECT kb_document_update(%s,%s,'hijack',false)", (did, world["b"]["tenant"])).fetchone()[0] == "not_found"
    assert app.execute("SELECT kb_document_delete(%s,%s)", (did, world["b"]["tenant"])).fetchone()[0] == "not_found"
    assert len(search(app, world, "কাজু")) == 1


def test_delete_removes_passages(app, world, owner):
    did, _ = save(app, world, ["এক", "দুই", "তিন"])
    ctx(app, world, "a")
    assert app.execute("SELECT kb_document_delete(%s,%s)", (did, world["a"]["tenant"])).fetchone()[0] == "ok"
    assert app.execute("SELECT kb_document_delete(%s,%s)", (did, world["a"]["tenant"])).fetchone()[0] == "not_found"
    as_admin(owner)
    assert owner.execute("SELECT count(*) FROM kb_chunks WHERE document_id=%s", (did,)).fetchone()[0] == 0


def test_app_role_cannot_write_directly(app, world):
    ctx(app, world, "a")
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("INSERT INTO kb_chunks (tenant_id,document_id,seq,content) VALUES (%s,%s,1,'x')", (world["a"]["tenant"], "x" * 26))


def test_other_tenants_documents_do_not_use_up_my_limit(app, world, owner):
    as_admin(owner)
    owner.execute("INSERT INTO kb_documents (id,tenant_id,created_by,title,filename,mime,size_bytes,text_hash,char_count,chunk_count) SELECT lpad(g::text,26,'d'),%s,%s,'d','f','m',1,md5(g::text),1,1 FROM generate_series(1,50) g", (world["b"]["tenant"], world["b"]["user"]))
    assert save(app, world)[1] == "ok"


def test_short_words_match_exactly_and_longer_words_by_prefix(app, world):
    save(app, world, ["abc def"])
    assert search(app, world, "ab") == []                    # two letters: whole word only
    assert len(search(app, world, "abc")) == 1
    assert len(search(app, world, "abc")) == 1 and len(search(app, world, "ab ab abc")) == 1
    save(app, world, ["xy"], title="second")
    assert len(search(app, world, "xy")) == 1
    assert len(search(app, world, "def")) == 1
    assert len(search(app, world, "de")) == 0


def test_only_the_first_1000_characters_of_a_question_count(app, world):
    save(app, world, ["cashew"])
    assert search(app, world, " " * 1005 + "cashew") == []
    assert len(search(app, world, " " * 900 + "cashew")) == 1


def test_only_12_words_of_a_question_are_used(app, world):
    save(app, world, ["needle aa13"])
    words = " ".join(f"aa{i:02d}" for i in range(1, 14))
    # 13 distinct words, all single occurrences: the 12 alphabetically first are kept, so aa13 is dropped
    hits = search(app, world, words)
    assert hits == []
    assert len(search(app, world, words.replace("aa01", "needle"))) == 1
