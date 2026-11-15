import psycopg
import pytest

from conftest import as_admin, set_tenant, ulid
from test_inbox import channel, contact
from test_orders import clean as _orders_clean  # noqa: F401


@pytest.fixture(autouse=True)
def clean(owner, world):
    yield
    as_admin(owner)
    for t in ("inbox_order_drafts", "inbox_messages", "inbox_conversations", "inbox_contacts", "inbox_channels", "order_settings"):
        owner.execute(f"DELETE FROM {t}")


def conv(app, world):
    set_tenant(app, world["a"]["tenant"])
    ch, _ = channel(app, world)
    return contact(app, world, ch)


def test_draft_roundtrip_and_constraints(app, world):
    c, t = conv(app, world), world["a"]["tenant"]
    app.execute("INSERT INTO inbox_order_drafts (conversation_id, tenant_id) VALUES (%s,%s)", (c, t))
    app.execute("UPDATE inbox_order_drafts SET items=%s::jsonb, customer_name='রহিম', phone='017', state='awaiting_confirm', summary_hash='abcdef0123456789', turns=3 WHERE conversation_id=%s",
                ('[{"variant_id":"x","qty":1}]', c))
    assert app.execute("SELECT state, turns FROM inbox_order_drafts").fetchone() == ("awaiting_confirm", 3)
    for bad in ("UPDATE inbox_order_drafts SET state='done'", "UPDATE inbox_order_drafts SET zone='mars'", "UPDATE inbox_order_drafts SET items='{}'::jsonb",
                "UPDATE inbox_order_drafts SET turns=500"):
        with pytest.raises(psycopg.errors.CheckViolation):
            app.execute(bad)
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("UPDATE inbox_order_drafts SET tenant_id=tenant_id")
    app.execute("DELETE FROM inbox_order_drafts WHERE conversation_id=%s", (c,))
    assert app.execute("SELECT count(*) FROM inbox_order_drafts").fetchone()[0] == 0


def test_draft_is_isolated_and_needs_a_real_conversation(app, world):
    c, t = conv(app, world), world["a"]["tenant"]
    app.execute("INSERT INTO inbox_order_drafts (conversation_id, tenant_id) VALUES (%s,%s)", (c, t))
    with pytest.raises(psycopg.errors.ForeignKeyViolation):
        app.execute("INSERT INTO inbox_order_drafts (conversation_id, tenant_id) VALUES (%s,%s)", (ulid(), t))
    set_tenant(app, world["b"]["tenant"])
    assert app.execute("SELECT count(*) FROM inbox_order_drafts").fetchone()[0] == 0
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("INSERT INTO inbox_order_drafts (conversation_id, tenant_id) VALUES (%s,%s)", (c, t))


def test_chat_orders_is_off_by_default(app, world):
    set_tenant(app, world["a"]["tenant"])
    app.execute("INSERT INTO order_settings (tenant_id) VALUES (%s)", (world["a"]["tenant"],))
    assert app.execute("SELECT chat_orders FROM order_settings").fetchone()[0] is False
    app.execute("UPDATE order_settings SET chat_orders=true")
    assert app.execute("SELECT chat_orders FROM order_settings").fetchone()[0] is True
