"""The SQL strings in PgAgentRepo.php, executed for real with the parameter shapes PHP passes."""
import json

import pytest

from conftest import as_admin, set_tenant, ulid
from test_catalog import ctx, go_live, product
from test_inbox import channel, contact
from test_lead_php_sql import sql_of

REPO = "app/Inbox/Agent/PgAgentRepo.php"
NOPE = "%\x01nomatch\x01%"


@pytest.fixture(autouse=True)
def clean(owner, world):
    yield
    as_admin(owner)
    owner.execute("ALTER TABLE stock_movements DISABLE TRIGGER stock_movements_no_change")
    owner.execute("ALTER TABLE product_events DISABLE TRIGGER product_events_no_change")
    for t in ("inbox_agent_drafts", "inbox_agent_settings", "inbox_messages", "inbox_conversations", "inbox_contacts", "inbox_channels",
              "stock_movements", "product_events", "product_variants", "products", "store_connections"):
        owner.execute(f"DELETE FROM {t}")
    owner.execute("ALTER TABLE stock_movements ENABLE TRIGGER stock_movements_no_change")
    owner.execute("ALTER TABLE product_events ENABLE TRIGGER product_events_no_change")


def pats(*words):
    p = [f"%{w}%" for w in words][:6]
    return p + [NOPE] * (6 - len(p))


def test_settings_and_mode_statements(app, world):
    t = world["a"]["tenant"]
    set_tenant(app, t)
    assert app.execute("select instructions, business_info from inbox_agent_settings").fetchone() is None
    up = sql_of(REPO, "insert into inbox_agent_settings")
    app.execute(up, (t, "বিনয়ী", "ডেলিভারি ৬০ টাকা"))
    app.execute(up, (t, "সংক্ষিপ্ত", "ডেলিভারি ৮০ টাকা"))
    assert app.execute("select instructions, business_info from inbox_agent_settings").fetchall() == [("সংক্ষিপ্ত", "ডেলিভারি ৮০ টাকা")]
    ch, _ = channel(app, world)
    app.execute(sql_of(REPO, "update inbox_channels set agent_mode"), ("draft", ch))
    assert app.execute("select agent_mode from inbox_channels where id=%s", (ch,)).fetchone()[0] == "draft"
    set_tenant(app, world["b"]["tenant"])
    assert app.execute("select 1 from inbox_agent_settings").fetchone() is None          # other workspace sees nothing


def test_draft_statements(app, world):
    t = world["a"]["tenant"]
    set_tenant(app, t)
    ch, _ = channel(app, world)
    conv = contact(app, world, ch)
    save = sql_of(REPO, "insert into inbox_agent_drafts")
    app.execute(save, (conv, t, "প্রথম", 3))
    app.execute(save, (conv, t, "দ্বিতীয়", 4))
    assert app.execute(sql_of(REPO, "select body, in_seq from inbox_agent_drafts"), (conv,)).fetchall() == [("দ্বিতীয়", 4)]
    app.execute(sql_of(REPO, "delete from inbox_agent_drafts"), (conv,))
    assert app.execute(sql_of(REPO, "select body, in_seq from inbox_agent_drafts"), (conv,)).fetchone() is None


def test_search_ranks_by_keyword_hides_unpublished_and_reports_stock(app, world):
    u = ctx(app, world)
    p1, v1 = product(app, world, title="Cotton Tee", desc="soft cotton shirt", stock=5)
    go_live(app, world, p1)
    p2, v2 = product(app, world, title="Leather Wallet", desc="brown wallet", stock=None)
    go_live(app, world, p2)
    p3, _ = product(app, world, title="Cotton Draft Only")                                  # never published
    sql = sql_of(REPO, "from products p cross join lateral")
    rows = app.execute(sql, (*pats("cotton"), 5)).fetchall()
    ids = [r[0].strip() for r in rows]
    assert ids[0] == p1 and p3 not in ids
    top = rows[0]
    assert top[3] == 1 and json.loads(json.dumps(top[2]))[v1] is True
    nomatch = app.execute(sql, (*pats("zzzz"), 5)).fetchall()
    assert nomatch and all(r[3] == 0 for r in nomatch)                                         # fallback sample, flagged by score 0
    wallet = app.execute(sql, (*pats("wallet"), 5)).fetchall()
    assert json.loads(json.dumps(wallet[0][2]))[v2] is False                                    # tracked stock 0 -> not in stock
    lit = app.execute(sql, ("%cotton%tee%".replace("%tee%", "\\%"), *[NOPE] * 5, 5)).fetchall()     # backslash-escaped % (what PHP sends) is a literal
    assert all(r[3] == 0 for r in lit)
    cats = app.execute(sql_of(REPO, "select distinct p.live->>'category'")).fetchall()
    assert isinstance(cats, list)


def test_search_never_crosses_workspaces(app, world):
    ctx(app, world)
    p1, _ = product(app, world, title="Cotton Tee")
    go_live(app, world, p1)
    set_tenant(app, world["b"]["tenant"])
    assert app.execute(sql_of(REPO, "from products p cross join lateral"), (*pats("cotton"), 5)).fetchall() == []
