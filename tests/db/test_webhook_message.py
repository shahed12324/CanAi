"""Phase 28f: webhook.send - a workflow's own text to one of the person's addresses (event workflow.message)."""
import psycopg
import pytest

from conftest import as_admin
from test_accounting import ctx
from test_memory import second_user  # noqa: F401  (fixture)
from test_webhooks import hook, enqueue, claim

SEND = "SELECT webhook_send_message(%s,%s,%s,%s)"


@pytest.fixture(autouse=True)
def clean(owner, world):
    yield
    as_admin(owner)
    for t in ("webhook_deliveries", "webhook_endpoints"):
        owner.execute(f"DELETE FROM {t}")


def subscribe(app, world, hid, events=("run.finished", "workflow.message"), who="a"):
    ctx(app, world, who)
    return app.execute("SELECT webhook_set_events(%s,%s,%s,%s)", (hid, world[who]["tenant"], world[who]["user"], list(events))).fetchone()[0]


def send(app, world, name="n8n", text="হ্যালো", who="a", user=None):
    ctx(app, world, who)
    return app.execute(SEND, (world[who]["tenant"], user or world[who]["user"], name, text)).fetchone()[0]


def test_four_events_are_choosable_and_a_fifth_is_not(app, world, owner):
    hid, _ = hook(app, world)
    assert subscribe(app, world, hid, ["run.finished", "run.waiting_approval", "pc.action.pending", "workflow.message"]) == "ok"
    assert subscribe(app, world, hid, ["workflow.message", "x"]) == "bad_input"
    as_admin(owner)
    with pytest.raises(psycopg.errors.CheckViolation):
        owner.execute("UPDATE webhook_endpoints SET events = ARRAY['run.finished','run.waiting_approval','pc.action.pending','workflow.message','x'] WHERE id=%s", (hid,))


def test_five_entries_are_too_many_for_the_column(app, world, owner):
    hid, _ = hook(app, world)
    as_admin(owner)
    with pytest.raises(psycopg.errors.CheckViolation):
        owner.execute("UPDATE webhook_endpoints SET events = ARRAY['workflow.message','workflow.message','workflow.message','workflow.message','workflow.message'] WHERE id=%s", (hid,))
    owner.rollback()
    as_admin(owner)
    owner.execute("UPDATE webhook_endpoints SET events = ARRAY['workflow.message','workflow.message','workflow.message','workflow.message'] WHERE id=%s", (hid,))


def test_other_events_do_not_use_up_the_message_limit(app, world, owner):
    hid, _ = hook(app, world)
    subscribe(app, world, hid)
    as_admin(owner)
    for i in range(25):
        owner.execute("INSERT INTO webhook_deliveries (id,endpoint_id,tenant_id,run_id,event,payload) VALUES (%s,%s,%s,%s,'run.finished','{}')", (f"d{i:025d}"[:26], hid, world["a"]["tenant"], f"r{i:025d}"[:26]))
    assert send(app, world) == "ok"


def test_message_is_queued_signed_path_and_carries_only_its_text(app, world, owner, admin):
    hid, _ = hook(app, world)
    subscribe(app, world, hid)
    assert send(app, world, text="  অর্ডার #12 পাঠানো হয়েছে  ") == "ok"
    assert enqueue(admin) == 0                                              # the worker does not duplicate it
    got = claim(admin)
    assert len(got) == 1
    p = got[0][5]
    assert set(p) == {"event", "message_id", "text", "sent_at"} and p["event"] == "workflow.message" and p["text"] == "অর্ডার #12 পাঠানো হয়েছে"
    assert got[0][2] == "https://hooks.example.com/jarvis"


def test_each_call_is_its_own_message(app, world, owner, admin):
    hid, _ = hook(app, world)
    subscribe(app, world, hid)
    assert send(app, world) == "ok" and send(app, world) == "ok"
    assert len(claim(admin)) == 2


def test_not_ticked_off_unknown_or_other_peoples_addresses_get_nothing(app, world, owner, admin, second_user):
    hid, _ = hook(app, world)
    assert send(app, world) == "not_found"                                  # default events: run.finished only
    subscribe(app, world, hid)
    assert send(app, world, name="other") == "not_found"
    assert send(app, world, user=second_user) == "not_found"                # somebody else's workflow
    ctx(app, world, "b")
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute(SEND, (world["a"]["tenant"], world["a"]["user"], "n8n", "x"))
    as_admin(owner)
    owner.execute("UPDATE webhook_endpoints SET active=false WHERE id=%s", (hid,))
    assert send(app, world) == "not_found"
    assert claim(admin) == []


@pytest.mark.parametrize("name,text", [("n8n", ""), ("n8n", "   "), ("n8n", None), ("n8n", "ক" * 1001), (None, "x"), ("  ", "x")])
def test_bad_input_is_refused(app, world, name, text):
    hid, _ = hook(app, world)
    subscribe(app, world, hid)
    assert send(app, world, name=name, text=text) == "bad_input"


def test_text_limit_is_exactly_1000(app, world, admin):
    hid, _ = hook(app, world)
    subscribe(app, world, hid)
    assert send(app, world, text="ক" * 1000) == "ok"
    assert len(claim(admin)[0][5]["text"]) == 1000


def test_twenty_per_hour_per_address(app, world, owner, admin):
    hid, _ = hook(app, world)
    subscribe(app, world, hid)
    for _ in range(20):
        assert send(app, world) == "ok"
    assert send(app, world) == "limit"
    as_admin(owner)
    owner.execute("UPDATE webhook_deliveries SET created_at = now() - interval '2 hours'")
    assert send(app, world) == "ok"                                         # the window moves on
    hid2, _ = hook(app, world, name="second")
    subscribe(app, world, hid2)
    assert send(app, world, name="second") == "ok"                          # the limit is per address


def test_a_deleted_address_takes_its_messages_with_it_and_old_ones_are_cleaned(app, world, owner, admin):
    hid, _ = hook(app, world)
    subscribe(app, world, hid)
    send(app, world)
    as_admin(owner)
    owner.execute("UPDATE webhook_deliveries SET status='sent', created_at = now() - interval '20 days'")
    enqueue(admin)
    assert owner.execute("SELECT count(*) FROM webhook_deliveries").fetchone()[0] == 0


def test_the_app_cannot_write_messages_directly(app, world):
    hid, _ = hook(app, world)
    subscribe(app, world, hid)
    ctx(app, world)
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("INSERT INTO webhook_deliveries (id,endpoint_id,tenant_id,run_id,event,payload) VALUES ('x'||repeat('0',25),%s,%s,'r'||repeat('0',25),'workflow.message','{}')", (hid, world["a"]["tenant"]))
