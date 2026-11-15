"""The SQL strings in the PHP messaging classes, executed for real (same idea as test_credit_service_sql.py)."""
import hashlib
import re
from pathlib import Path

import pytest

from conftest import as_admin, set_tenant, ulid

OVERLAY = Path(__file__).resolve().parents[2] / "apps/web-overlay"


def sql_of(file: str, marker: str) -> str:
    src = (OVERLAY / file).read_text()
    for m in re.finditer(r"""(?s)(?<![\w$])(?:'((?:[^'\\]|\\.)*)'|"((?:[^"\\]|\\.)*)")""", src):
        s = m.group(1) if m.group(1) is not None else m.group(2)
        if marker in s and s.lstrip().lower().startswith("select"):
            return s.replace("%", "%%").replace("?", "%s")
    raise AssertionError(f"no SQL containing {marker!r} in {file}")


STORE = "app/Messaging/PgChannelStore.php"


@pytest.fixture
def acct(owner):
    as_admin(owner)
    aid = ulid()
    owner.execute("INSERT INTO channel_accounts (id,channel,name,token_encrypted,token_key_version,webhook_secret_hash,status) "
                  "VALUES (%s,'telegram','bot','CIPHER',2,%s,'active')", (aid, hashlib.sha256(b"sec").hexdigest()))
    yield aid
    for t in ("channel_events", "channel_links", "channel_link_codes", "channel_accounts"):
        owner.execute(f"DELETE FROM {t}")


def test_php_statements_run_the_whole_flow(app, admin, world, acct):
    h = lambda s: hashlib.sha256(s.encode()).hexdigest()
    assert app.execute(sql_of(STORE, "channel_account_authenticate"), (acct, h("sec"))).fetchone()[0] is True
    assert app.execute(sql_of(STORE, "channel_event_claim"), (acct, 9001, "message")).fetchone()[0] == "new"
    assert app.execute(sql_of(STORE, "channel_event_claim"), (acct, 9001, "message")).fetchone()[0] == "duplicate"

    # code created exactly as ChannelLinkController does (tenant context, plain insert)
    set_tenant(app, world["a"]["tenant"])
    app.execute("INSERT INTO channel_link_codes (id,tenant_id,user_id,channel,code_hash,expires_at) VALUES (%s,%s,%s,'telegram',%s,now()+interval '10 minutes')",
                (ulid(), world["a"]["tenant"], world["a"]["user"], h("ABCD2345")))
    app.execute("SELECT set_config('app.tenant_id','',false)")
    r = app.execute(sql_of(STORE, "channel_link_redeem"), (acct, h("ABCD2345"), "42", "42", "bob", ulid())).fetchone()
    assert r[0] is True and r[1] == "ok" and r[2] == world["a"]["tenant"] and r[3] == world["a"]["user"]

    row = app.execute(sql_of(STORE, "channel_resolve"), (acct, "42")).fetchone()
    assert row[1] == world["a"]["tenant"] and row[2] == world["a"]["user"] and row[4] is None
    link_id = row[0]

    # setConversation / touch / revoke as PgChannelStore does them (tenant context + column grants)
    set_tenant(app, world["a"]["tenant"])
    assert app.execute("UPDATE channel_links SET conversation_id=NULL WHERE id=%s AND tenant_id=%s", (link_id, world["a"]["tenant"])).rowcount == 1
    assert app.execute("UPDATE channel_links SET last_seen_at=now() WHERE id=%s AND tenant_id=%s", (link_id, world["a"]["tenant"])).rowcount == 1
    app.execute("SELECT set_config('app.tenant_id','',false)")

    app.execute(sql_of(STORE, "channel_event_finish"), (acct, 9001, "processed", None, world["a"]["tenant"], link_id))
    app.execute(sql_of(STORE, "channel_account_health"), (acct, "boom"))
    as_admin(admin)
    assert admin.execute("SELECT outcome, tenant_id FROM channel_events WHERE external_update_id=9001").fetchone() == ("processed", world["a"]["tenant"])
    assert admin.execute("SELECT last_error FROM channel_accounts WHERE id=%s", (acct,)).fetchone()[0] == "boom"

    # sender + admin token statements
    assert app.execute(sql_of("app/Messaging/TelegramClient.php", "channel_account_for_send"), (acct,)).fetchone() == ("CIPHER", 2)
    assert admin.execute(sql_of("app/Http/Controllers/Admin/ChannelController.php", "channel_account_token_for_admin"), (acct,)).fetchone() == ("CIPHER", 2)


def test_php_admin_queries_use_only_granted_columns(admin, acct, world):
    as_admin(admin)
    # same column lists as ChannelController::index()
    admin.execute("SELECT a.id,a.name,a.bot_username,a.status,a.last_webhook_at,a.last_error,a.last_error_at,a.created_at FROM channel_accounts a ORDER BY a.created_at").fetchall()
    admin.execute("SELECT account_id, count(*) AS n FROM channel_links WHERE revoked_at IS NULL GROUP BY account_id").fetchall()
    admin.execute("SELECT e.received_at,a.name,e.kind,e.outcome,e.error_code,e.attempts FROM channel_events e JOIN channel_accounts a ON a.id=e.account_id ORDER BY e.id DESC LIMIT 50").fetchall()
    # ...and the admin can write the token/secret columns it cannot read
    admin.execute("UPDATE channel_accounts SET status='active', webhook_secret_hash=%s, updated_at=now() WHERE id=%s", ("0" * 64, acct))
