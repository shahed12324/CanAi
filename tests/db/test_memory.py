import json

import psycopg
import pytest

from conftest import as_admin, ulid
from test_accounting import ctx


@pytest.fixture(autouse=True)
def clean(owner, world):
    yield
    as_admin(owner)
    for t in ("memory_suggestions", "memory_settings", "memories"):
        owner.execute(f"DELETE FROM {t}")


@pytest.fixture
def second_user(owner, world):
    """A second person in tenant A."""
    as_admin(owner)
    uid = ulid()
    owner.execute("INSERT INTO users (id,name,email,password,email_verified_at) VALUES (%s,'u2',%s,'x',now())", (uid, f"u2-{uid}@example.com"))
    owner.execute("INSERT INTO tenant_users (id,tenant_id,user_id,joined_at) VALUES (%s,%s,%s,now())", (ulid(), world["a"]["tenant"], uid))
    return uid


def add(app, world, text="আমি ঢাকায় থাকি", *, scope="user", cat="fact", pinned=False, source="user", who="a", user=None, mid=None, conv=None):
    ctx(app, world, who)
    mid = mid or ulid()
    r = app.execute("SELECT memory_add(%s,%s,%s,%s,%s,%s,%s,%s,%s)", (mid, world[who]["tenant"], user or world[who]["user"], scope, cat, text, pinned, source, conv)).fetchone()[0]
    return mid, r


def rows(app, world, who="a"):
    ctx(app, world, who)
    return app.execute("SELECT content, scope, category, pinned, active, source FROM memories ORDER BY content").fetchall()


def test_add_and_normalise(app, world):
    assert add(app, world, "  আমি   ঢাকায়\n থাকি ")[1] == "ok"
    assert rows(app, world) == [("আমি ঢাকায় থাকি", "user", "fact", False, True, "user")]


@pytest.mark.parametrize("scope,cat,source", [("team", "fact", "user"), ("user", "mood", "user"), ("user", "fact", "robot"), (None, "fact", "user"), ("user", None, "user"), ("user", "fact", None)])
def test_bad_choices(app, world, scope, cat, source):
    assert add(app, world, scope=scope, cat=cat, source=source)[1] == "bad_input"
    assert rows(app, world) == []


@pytest.mark.parametrize("text", ["", "   ", None, "ক" * 501])
def test_empty_and_too_long(app, world, text):
    assert add(app, world, text)[1] == "bad_input"


def test_500_chars_is_fine(app, world):
    assert add(app, world, "ক" * 500)[1] == "ok"


@pytest.mark.parametrize(
    "text",
    [
        "my password is hunter2", "Password: abc", "wifi passcode 12345", "the OTP was 482913", "my pin is 4821", "cvv 123", "API key abc", "api_key=zzz", "private key here", "access token xyz", "client secret",
        "আমার পাসওয়ার্ড abc", "ওটিপি ৪৮২৯১৩", "পিন নম্বর ১২৩৪", "সিক্রেট কোড", "টোকেন আছে",
        "card 4111 1111 1111 1111", "card 4111-1111-1111-1111", "NID 1234567890123", "কার্ড ৪১১১১১১১১১১১১১১১", "sk-abcdefghijklmnopqrstuvwxyz123456", "AKIAABCDEFGHIJKLMNOP1", "ghp_abcdefghijklmnopqrstuvwxyz0123",
        "a" * 32, "Xk3-" + "ab9" * 12,
    ],
)
def test_sensitive_things_are_never_stored(app, world, text):
    assert add(app, world, text)[1] == "sensitive"
    assert rows(app, world) == []


@pytest.mark.parametrize(
    "text",
    ["আমার ফোন 01712345678", "আমি পিন্টুর সাথে কাজ করি", "prefers short answers", "Shop is in Bashundhara R/A", "ব্যবসার নাম গোল্ডভেস্ট, ঢাকা", "order 12345 was late", "I like pinterest", "we tokenize the text first", "২০২৬ সালে ১২ লক্ষ টাকা"],
)
def test_ordinary_things_are_stored(app, world, text):
    assert add(app, world, text)[1] == "ok", text


def test_duplicates_ignore_case_and_spacing_per_person(app, world, second_user):
    assert add(app, world, "Prefers Bengali")[1] == "ok"
    assert add(app, world, "  prefers   bengali ")[1] == "duplicate"
    assert add(app, world, "prefers bengali", user=second_user)[1] == "ok"          # another person may remember the same thing
    assert add(app, world, "prefers bengali", scope="business")[1] == "ok"
    assert add(app, world, "Prefers bengali", scope="business", user=second_user)[1] == "duplicate"      # business memories are shared: one copy


def test_limits(app, world, owner):
    as_admin(owner)
    t, u = world["a"]["tenant"], world["a"]["user"]
    owner.execute("INSERT INTO memories (id,tenant_id,user_id,scope,category,content) SELECT lpad(g::text,26,'m'),%s,%s,'user','fact','m '||g FROM generate_series(1,200) g", (t, u))
    assert add(app, world, "one more")[1] == "limit"
    as_admin(owner)
    owner.execute("INSERT INTO memories (id,tenant_id,user_id,scope,category,content) SELECT lpad(g::text,26,'b'),%s,%s,'business','fact','b '||g FROM generate_series(1,100) g", (t, u))
    assert add(app, world, "one more business", scope="business")[1] == "limit"


def test_user_required_and_tenant_check(app, world):
    ctx(app, world, "a")
    with pytest.raises(psycopg.errors.InvalidParameterValue):
        app.execute("SELECT memory_add(%s,%s,NULL,'user','fact','x y',false,'user',NULL)", (ulid(), world["a"]["tenant"]))
    ctx(app, world, "b")
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("SELECT memory_add(%s,%s,%s,'user','fact','x y',false,'user',NULL)", (ulid(), world["a"]["tenant"], world["b"]["user"]))


def upd(app, world, mid, *, text=None, cat=None, pinned=None, active=None, manager=False, who="a", user=None):
    ctx(app, world, who)
    return app.execute("SELECT memory_update(%s,%s,%s,%s,%s,%s,%s,%s)", (mid, world[who]["tenant"], user or world[who]["user"], manager, text, cat, pinned, active)).fetchone()[0]


def test_update_changes_only_what_is_given(app, world):
    mid, _ = add(app, world, "likes tea")
    assert upd(app, world, mid, pinned=True) == "ok"
    assert rows(app, world) == [("likes tea", "user", "fact", True, True, "user")]
    assert upd(app, world, mid, active=False, cat="preference") == "ok"
    assert rows(app, world) == [("likes tea", "user", "preference", True, False, "user")]
    assert upd(app, world, mid, text="  likes   green tea ") == "ok"
    assert rows(app, world)[0][0] == "likes green tea"


def test_update_validates(app, world):
    mid, _ = add(app, world, "likes tea")
    assert upd(app, world, mid, text="my password is x") == "sensitive"
    assert upd(app, world, mid, text="  ") == "bad_input"
    assert upd(app, world, mid, cat="mood") == "bad_input"
    assert upd(app, world, ulid()) == "not_found"
    other, _ = add(app, world, "likes coffee")
    assert upd(app, world, other, text="LIKES TEA") == "duplicate"
    assert rows(app, world)[0][0] == "likes coffee"


def test_people_cannot_touch_each_others_memories(app, world, second_user):
    mine, _ = add(app, world, "private to me")
    assert upd(app, world, mine, text="hijacked", user=second_user) == "not_found"
    ctx(app, world, "a")
    assert app.execute("SELECT memory_delete(%s,%s,%s,true)", (mine, world["a"]["tenant"], second_user)).fetchone()[0] == "not_found"      # even a manager cannot reach a personal memory
    assert rows(app, world) == [("private to me", "user", "fact", False, True, "user")]


def test_business_memories_need_a_manager(app, world, second_user):
    mid, _ = add(app, world, "We close on Fridays", scope="business", cat="business")
    assert upd(app, world, mid, text="We close on Saturdays", manager=False, user=second_user) == "forbidden"
    assert upd(app, world, mid, text="We close on Saturdays", manager=True, user=second_user) == "ok"
    ctx(app, world, "a")
    assert app.execute("SELECT memory_delete(%s,%s,%s,false)", (mid, world["a"]["tenant"], second_user)).fetchone()[0] == "forbidden"
    assert app.execute("SELECT memory_delete(%s,%s,%s,true)", (mid, world["a"]["tenant"], second_user)).fetchone()[0] == "ok"
    assert rows(app, world) == []


def test_delete_and_foreign_tenant(app, world):
    mid, _ = add(app, world, "x y z")
    ctx(app, world, "b")
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("SELECT memory_delete(%s,%s,%s,true)", (mid, world["a"]["tenant"], world["b"]["user"]))
    assert rows(app, world, "b") == []
    ctx(app, world, "a")
    assert app.execute("SELECT memory_delete(%s,%s,%s,false)", (mid, world["a"]["tenant"], world["a"]["user"])).fetchone()[0] == "ok"
    assert app.execute("SELECT memory_delete(%s,%s,%s,false)", (mid, world["a"]["tenant"], world["a"]["user"])).fetchone()[0] == "not_found"


def test_clear_forgets_only_my_own(app, world, second_user, owner):
    add(app, world, "mine one")
    add(app, world, "mine two")
    add(app, world, "theirs", user=second_user)
    add(app, world, "shared fact", scope="business")
    as_admin(owner)
    owner.execute("INSERT INTO memory_suggestions (id,tenant_id,user_id,category,content) VALUES (%s,%s,%s,'fact','pending mine')", (ulid(), world["a"]["tenant"], world["a"]["user"]))
    ctx(app, world, "a")
    assert app.execute("SELECT memory_clear(%s,%s)", (world["a"]["tenant"], world["a"]["user"])).fetchone()[0] == 2
    assert [r[0] for r in rows(app, world)] == ["shared fact", "theirs"]
    assert app.execute("SELECT count(*) FROM memory_suggestions").fetchone()[0] == 0


def test_setting(app, world):
    ctx(app, world, "a")
    t, u = world["a"]["tenant"], world["a"]["user"]
    assert app.execute("SELECT memory_setting_save(%s,%s,false)", (t, u)).fetchone()[0] == "ok"
    assert app.execute("SELECT use_in_chat FROM memory_settings").fetchone()[0] is False
    assert app.execute("SELECT memory_setting_save(%s,%s,true)", (t, u)).fetchone()[0] == "ok"
    assert app.execute("SELECT use_in_chat FROM memory_settings").fetchone()[0] is True
    assert app.execute("SELECT memory_setting_save(%s,%s,NULL)", (t, u)).fetchone()[0] == "bad_input"


def suggest(app, world, items, ids=None, who="a", conv=None):
    ctx(app, world, who)
    ids = ids or [ulid() for _ in range(10)]
    return app.execute("SELECT memory_suggest_save(%s,%s,%s,%s::jsonb,%s)", (world[who]["tenant"], world[who]["user"], conv, json.dumps(items), ids)).fetchone()[0]


def test_suggestions_filter_and_cap(app, world):
    add(app, world, "already known")
    items = [
        {"content": "likes short answers", "category": "preference"},
        {"content": "my password is x", "category": "fact"},              # sensitive
        {"content": "Already   KNOWN", "category": "fact"},              # already a memory
        {"content": "likes short answers", "category": "fact"},          # duplicate inside the batch
        {"content": "", "category": "fact"},
        {"content": 42, "category": "fact"},
        "not an object",
        {"content": "bad category", "category": "mood"},
        {"content": "no category given"},
        {"content": "sells dry fruits", "category": "business"},
        {"content": "four", "category": "fact"}, {"content": "five", "category": "fact"}, {"content": "six", "category": "fact"}, {"content": "seven", "category": "fact"},
    ]
    n = suggest(app, world, items)
    ctx(app, world, "a")
    got = [r[0] for r in app.execute("SELECT content FROM memory_suggestions ORDER BY created_at, content").fetchall()]
    assert n == len(got) == 5
    assert "likes short answers" in got and "sells dry fruits" in got and "no category given" in got
    assert "my password is x" not in got and "already known" not in [g.lower() for g in got]
    assert app.execute("SELECT count(*) FROM memories").fetchone()[0] == 1            # nothing became a memory


def test_pending_cap_and_non_array(app, world):
    for i in range(4):
        assert suggest(app, world, [{"content": f"item {i}a", "category": "fact"}, {"content": f"item {i}b", "category": "fact"}, {"content": f"item {i}c", "category": "fact"}, {"content": f"item {i}d", "category": "fact"}, {"content": f"item {i}e", "category": "fact"}]) == 5
    assert suggest(app, world, [{"content": "one more", "category": "fact"}]) == 0                # 20 pending already
    assert suggest(app, world, {"content": "x y", "category": "fact"}) == 0
    ctx(app, world, "a")
    assert app.execute("SELECT count(*) FROM memory_suggestions").fetchone()[0] == 20


def test_ids_are_used_per_stored_item_not_per_input_position(app, world):
    bad = [{"content": "my password is x", "category": "fact"}] * 3
    assert suggest(app, world, bad + [{"content": "ok one", "category": "fact"}, {"content": "ok two", "category": "fact"}], ids=[ulid(), ulid()]) == 2


def test_fewer_ids_than_items_stops_cleanly(app, world):
    assert suggest(app, world, [{"content": "aa bb", "category": "fact"}, {"content": "cc dd", "category": "fact"}], ids=[ulid()]) == 1


def test_accept_makes_a_suggested_memory_once(app, world):
    suggest(app, world, [{"content": "likes short answers", "category": "preference"}])
    ctx(app, world, "a")
    sid = app.execute("SELECT id FROM memory_suggestions").fetchone()[0]
    t, u = world["a"]["tenant"], world["a"]["user"]
    assert app.execute("SELECT memory_suggestion_accept(%s,%s,%s,%s)", (sid, t, u, ulid())).fetchone()[0] == "ok"
    assert rows(app, world) == [("likes short answers", "user", "preference", False, True, "suggested")]
    assert app.execute("SELECT status FROM memory_suggestions").fetchone()[0] == "accepted"
    assert app.execute("SELECT memory_suggestion_accept(%s,%s,%s,%s)", (sid, t, u, ulid())).fetchone()[0] == "not_found"


def test_accept_when_memory_already_exists_closes_the_suggestion(app, world):
    suggest(app, world, [{"content": "likes tea", "category": "fact"}])
    add(app, world, "Likes Tea")
    ctx(app, world, "a")
    sid = app.execute("SELECT id FROM memory_suggestions").fetchone()[0]
    assert app.execute("SELECT memory_suggestion_accept(%s,%s,%s,%s)", (sid, world["a"]["tenant"], world["a"]["user"], ulid())).fetchone()[0] == "duplicate"
    assert app.execute("SELECT status FROM memory_suggestions").fetchone()[0] == "accepted"


def test_dismiss_and_ownership(app, world, second_user):
    suggest(app, world, [{"content": "likes tea", "category": "fact"}])
    ctx(app, world, "a")
    sid = app.execute("SELECT id FROM memory_suggestions").fetchone()[0]
    t = world["a"]["tenant"]
    assert app.execute("SELECT memory_suggestion_dismiss(%s,%s,%s)", (sid, t, second_user)).fetchone()[0] == "not_found"
    assert app.execute("SELECT memory_suggestion_accept(%s,%s,%s,%s)", (sid, t, second_user, ulid())).fetchone()[0] == "not_found"
    assert app.execute("SELECT memory_suggestion_dismiss(%s,%s,%s)", (sid, t, world["a"]["user"])).fetchone()[0] == "ok"
    assert app.execute("SELECT memory_suggestion_dismiss(%s,%s,%s)", (sid, t, world["a"]["user"])).fetchone()[0] == "not_found"
    assert app.execute("SELECT memory_suggestion_accept(%s,%s,%s,%s)", (sid, t, world["a"]["user"], ulid())).fetchone()[0] == "not_found"


def test_app_role_cannot_write_and_other_tenant_sees_nothing(app, world):
    add(app, world, "likes tea")
    ctx(app, world, "a")
    for sql in ("DELETE FROM memories", "UPDATE memories SET active=false", "INSERT INTO memory_settings (tenant_id,user_id) VALUES ('x','y')", "DELETE FROM memory_suggestions"):
        with pytest.raises(psycopg.errors.InsufficientPrivilege):
            app.execute(sql)
    assert rows(app, world, "b") == []


def test_limit_counts_only_my_own_memories(app, world, owner, second_user):
    as_admin(owner)
    t = world["a"]["tenant"]
    owner.execute("INSERT INTO memories (id,tenant_id,user_id,scope,category,content) SELECT lpad(g::text,26,'o'),%s,%s,'user','fact','o '||g FROM generate_series(1,200) g", (t, second_user))
    assert add(app, world, "mine is fine")[1] == "ok"                   # a colleague's 200 do not use up my quota


def test_suggestion_dedupe_is_per_person_and_covers_pending(app, world, second_user):
    add(app, world, "colleague note", user=second_user)                 # a colleague's private memory
    assert suggest(app, world, [{"content": "colleague note", "category": "fact"}]) == 1
    assert suggest(app, world, [{"content": "COLLEAGUE  note", "category": "fact"}]) == 0      # already pending for me
    add(app, world, "shared fact", scope="business", user=second_user)
    assert suggest(app, world, [{"content": "shared fact", "category": "fact"}]) == 0           # business memory is visible to all


@pytest.mark.parametrize("source", ["chat", "agent", "workflow", "image", "video", "voice", "email", "storage", "render", "catalog", "bill_scan", "inbox", "analyst", "memory", "vision", "docgen", "assist"])
def test_every_ai_source_is_an_allowed_usage_source(app, world, owner, source):
    as_admin(owner)
    owner.execute("INSERT INTO usage_records (tenant_id,source,credits_charged) VALUES (%s,%s,1)", (world["a"]["tenant"], source))


def test_unknown_usage_source_is_refused(app, world, owner):
    as_admin(owner)
    with pytest.raises(psycopg.errors.CheckViolation):
        owner.execute("INSERT INTO usage_records (tenant_id,source,credits_charged) VALUES (%s,'nonsense',1)", (world["a"]["tenant"],))
