"""The SQL strings behind the chat-order flow (PgOrderDraftRepo, PgAgentRepo::byVariants/related), run against real PG."""
import json

import pytest

from conftest import as_admin, set_tenant, ulid
from test_catalog import ctx, go_live, product
from test_inbox import channel, contact
from test_lead_php_sql import sql_of
from test_orders import live

DRAFT = "app/Inbox/Agent/Orders/PgOrderDraftRepo.php"
AGENT = "app/Inbox/Agent/PgAgentRepo.php"


@pytest.fixture(autouse=True)
def clean(owner, world):
    yield
    as_admin(owner)
    owner.execute("ALTER TABLE stock_movements DISABLE TRIGGER stock_movements_no_change")
    owner.execute("ALTER TABLE product_events DISABLE TRIGGER product_events_no_change")
    for t in ("inbox_order_drafts", "inbox_messages", "inbox_conversations", "inbox_contacts", "inbox_channels", "stock_movements", "product_events", "product_variants", "products", "store_connections"):
        owner.execute(f"DELETE FROM {t}")
    owner.execute("ALTER TABLE stock_movements ENABLE TRIGGER stock_movements_no_change")
    owner.execute("ALTER TABLE product_events ENABLE TRIGGER product_events_no_change")


def test_draft_upsert_get_delete_and_expiry(app, world, owner):
    ctx(app, world)
    t = world["a"]["tenant"]
    ch, _ = channel(app, world)
    conv = contact(app, world, ch)
    save = sql_of(DRAFT, "insert into inbox_order_drafts")
    app.execute(save, (conv, t, "collecting", json.dumps([{"variant_id": "x", "qty": 1}]), None, None, None, None, None, "false", 1))
    app.execute(save, (conv, t, "awaiting_confirm", json.dumps([{"variant_id": "x", "qty": 2}]), "করিম", "+8801711223344", "House 12, Road 5, Dhaka", "inside", "abcdef0123456789", "true", 2))
    get = sql_of(DRAFT, "from inbox_order_drafts where conversation_id")
    r = app.execute(get, (conv,)).fetchone()
    assert r[0] == "awaiting_confirm" and r[1] == [{"variant_id": "x", "qty": 2}] and r[2] == "করিম" and r[5] == "inside" and r[7] is True and r[8] == 2
    app.execute("UPDATE inbox_order_drafts SET updated_at = now() - interval '25 hours'")
    assert app.execute(get, (conv,)).fetchone() is None                      # stale drafts are ignored
    app.execute(save, (conv, t, "collecting", "[]", None, None, None, None, None, "false", 1))
    assert app.execute(get, (conv,)).fetchone() is not None                  # and an upsert revives the row
    app.execute(sql_of(DRAFT, "delete from inbox_order_drafts"), (conv,))
    assert app.execute(get, (conv,)).fetchone() is None


def test_by_variants_and_related(app, world):
    ctx(app, world)
    p1, v1 = live(app, world, title="Tee", stock=5)
    p2, v2 = live(app, world, title="Cap", stock=3)
    by = sql_of(AGENT, "w.id = any(?::char(26)[])")
    rows = app.execute(by, ("{" + v1 + "," + v2 + "}",)).fetchall()
    assert sorted(r[0].strip() for r in rows) == sorted([p1, p2])
    assert app.execute(by, ("{" + ulid() + "}",)).fetchall() == []
    # related: same category, in stock, not excluded
    made = []
    for title, stock in (("Shirt", 4), ("Hat", 2), ("Sold-out scarf", 0), ("Mug", 9)):
        p, v = product(app, world, title=title, stock=stock or None)
        app.execute("UPDATE products SET category=%s WHERE id=%s", ("wear" if title != "Mug" else "kitchen", p))
        go_live(app, world, p)
        made.append((title, p))
    rel = sql_of(AGENT, "p.live->>'category' = ?")
    got = app.execute(rel, ("wear", "{" + made[0][1] + "}", 3)).fetchall()
    assert [json.loads(json.dumps(r[1]))["title"] for r in got] == ["Hat"]            # not Shirt (excluded), not the sold-out scarf, not the mug
    assert app.execute(rel, ("nothing", "{}", 1)).fetchall() == []
    set_tenant(app, world["b"]["tenant"])
    assert app.execute(by, ("{" + v1 + "}",)).fetchall() == []              # another workspace cannot see them
    assert app.execute(rel, ("wear", "{}", 3)).fetchall() == []
