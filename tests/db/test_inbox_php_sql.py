"""The SQL strings in PgInboxRepo.php, executed for real with the parameter shapes PHP passes."""
import hashlib
import re

import pytest

from conftest import as_admin, set_tenant, ulid
from test_lead_php_sql import OVERLAY, sql_of

REPO = "app/Inbox/PgInboxRepo.php"


@pytest.fixture(autouse=True)
def clean(owner, world):
    yield
    as_admin(owner)
    for t in ("inbox_messages", "inbox_conversations", "inbox_contacts", "inbox_channels"):
        owner.execute(f"DELETE FROM {t}")


def test_inbox_repo_statements(app, world):
    t, u = world["a"]["tenant"], world["a"]["user"]
    set_tenant(app, t)
    ch, key = ulid(), "ab" * 16
    app.execute(sql_of(REPO, "insert into inbox_channels"), (ch, t, "web_widget", "Site", key, '{"https://shop.example.com"}', "hello"))
    r = app.execute(sql_of(REPO, "from inbox_channels order by created_at")).fetchall()
    assert len(r) == 1 and r[0][6] == ['https://shop.example.com']
    app.execute(sql_of(REPO, "update inbox_channels set"), ("Site 2", "paused", '{"https://a.example.com"}', None, ch))
    app.execute(sql_of(REPO, "update inbox_channels set"), ("Site 2", "active", '{"https://a.example.com"}', None, ch))
    r = app.execute(sql_of(REPO, "from inbox_channel_by_key"), (key,)).fetchone()
    assert r[0].strip() == ch and r[2] == "web_widget" and r[4] == ["https://a.example.com"]

    h = hashlib.sha256(b"tok").hexdigest()
    e = app.execute(sql_of(REPO, "from inbox_contact_ensure"), (ch, "v1", None, h, ulid(), ulid())).fetchone()
    assert e[0] == "ok"
    conv = e[2].strip()
    assert app.execute(sql_of(REPO, "inbox_contact_verify"), (ch, "v1", h)).fetchone()[0].strip() == conv
    assert app.execute(sql_of(REPO, "from inbox_receive"), (conv, "hi there", "c1")).fetchone()[0] == "received"
    s = app.execute(sql_of(REPO, "from inbox_send"), (conv, u, "staff", "hello!")).fetchone()
    assert s[0] == "sent" and isinstance(s[1], int)
    app.execute(sql_of(REPO, "inbox_set_mode"), (conv, u, "bot", None))
    app.execute(sql_of(REPO, "inbox_set_mode"), (conv, None, "human", "angry"))
    app.execute(sql_of(REPO, "inbox_resolve"), (conv, u, "true"))
    app.execute(sql_of(REPO, "inbox_resolve"), (conv, u, "false"))
    app.execute(sql_of(REPO, "inbox_mark_read"), (conv,))

    msgs = app.execute(sql_of(REPO, "from inbox_messages where conversation_id"), (conv, 0, "n", 50)).fetchall()
    assert [m[1] for m in msgs] == ["in", "out"]
    out = app.execute(sql_of(REPO, "from inbox_messages where conversation_id"), (conv, 0, "y", 50)).fetchall()
    assert [m[1] for m in out] == ["out"]

    one = app.execute(sql_of(REPO, "where v.id = ?"), (conv,)).fetchone()
    assert one[2] == "open" and one[3] == "human" and one[12] == "web_widget"

    src = (OVERLAY / REPO).read_text()
    base = sql_of(REPO, "from inbox_conversations v join").replace("$where", "{W}")
    for clause, args in (("v.status = 'resolved'", ()), ("v.status = 'open' and v.mode = 'human'", ()), ("v.unread > 0", ()), ("v.status = 'open' and v.assigned_to = %s", (u,)), ("v.status = 'open'", ())):
        assert clause.replace("%s", "?") in src                           # the clause under test is really what PHP uses
        rows = app.execute(base.replace("{W}", clause), (*args, 50)).fetchall()
        assert isinstance(rows, list)
    assert len(app.execute(base.replace("{W}", "v.status = 'open'"), (50,)).fetchall()) == 1
    assert re.search(r"min\(200, max\(1, \$limit\)\)", src)


def test_inbox_repo_channel_statements(app, world):
    t, u = world["a"]["tenant"], world["a"]["user"]
    set_tenant(app, t)
    ch, key = ulid(), "cd" * 16
    app.execute(sql_of(REPO, "insert into inbox_channels (id, tenant_id, kind, name, public_key, external_ref"), (ch, t, "telegram", "Bot", key, "BOT9", "CIPHER", 3))
    assert app.execute(sql_of(REPO, "from inbox_credentials"), (ch,)).fetchone() == ("CIPHER", 3, "telegram", "BOT9")
    assert app.execute(sql_of(REPO, "from inbox_channel_by_key"), (key,)).fetchone()[7] == "BOT9"
    e = app.execute(sql_of(REPO, "from inbox_contact_ensure"), (ch, "777", "Ana", None, ulid(), ulid())).fetchone()
    conv = e[2].strip()
    app.execute(sql_of(REPO, "from inbox_receive"), (conv, "hi", "777:1"))
    seq = app.execute(sql_of(REPO, "from inbox_send"), (conv, u, "staff", "reply")).fetchone()[1]
    r = app.execute(sql_of(REPO, "from inbox_dispatch_ctx"), (seq,)).fetchone()
    assert r[0] == seq and r[2] == "telegram" and r[3] == "BOT9" and r[4] == "777" and r[6] == "queued" and r[8] is True and r[9] is False and r[10] is True
    assert app.execute(sql_of(REPO, "inbox_message_retry"), (seq, "boom")).fetchone()[0] == "retry"
    assert app.execute(sql_of(REPO, "inbox_message_status"), (seq, "sent", None)).fetchone()[0] is True
