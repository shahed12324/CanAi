import hashlib

import psycopg
import pytest

from conftest import as_admin, set_tenant, ulid


def h(s: str) -> str:
    return hashlib.sha256(s.encode()).hexdigest()


@pytest.fixture
def acct(owner):
    as_admin(owner)
    aid = ulid()
    owner.execute(
        "INSERT INTO channel_accounts (id,channel,name,token_encrypted,token_key_version,webhook_secret_hash,status) "
        "VALUES (%s,'telegram','bot','CIPHER',1,%s,'active')", (aid, h("s3cret")))
    yield aid
    as_admin(owner)
    for t in ("channel_events", "channel_links", "channel_link_codes", "channel_accounts"):
        owner.execute(f"DELETE FROM {t}")


def code(app, w, who="a", plain="CODE1234", ttl="10 minutes"):
    set_tenant(app, w[who]["tenant"])
    app.execute("INSERT INTO channel_link_codes (id,tenant_id,user_id,channel,code_hash,expires_at) VALUES (%s,%s,%s,'telegram',%s,now()+%s::interval)",
                (ulid(), w[who]["tenant"], w[who]["user"], h(plain), ttl))


def redeem(app, acct, plain="CODE1234", ext="111", chat="111"):
    app.execute("SELECT set_config('app.tenant_id','',false)")   # a webhook has no tenant context
    return app.execute("SELECT * FROM channel_link_redeem(%s,%s,%s,%s,'bob',%s)", (acct, h(plain), ext, chat, ulid())).fetchone()


def test_webhook_secret_checked(app, acct):
    assert app.execute("SELECT channel_account_authenticate(%s,%s)", (acct, h("s3cret"))).fetchone()[0] is True
    assert app.execute("SELECT channel_account_authenticate(%s,%s)", (acct, h("wrong"))).fetchone()[0] is False
    assert app.execute("SELECT channel_account_authenticate(%s,%s)", (ulid(), h("s3cret"))).fetchone()[0] is False


def test_disabled_account_never_authenticates_or_sends(app, owner, acct):
    owner.execute("UPDATE channel_accounts SET status='disabled' WHERE id=%s", (acct,))
    assert app.execute("SELECT channel_account_authenticate(%s,%s)", (acct, h("s3cret"))).fetchone()[0] is False
    assert app.execute("SELECT count(*) FROM channel_account_for_send(%s)", (acct,)).fetchone()[0] == 0


def test_app_and_admin_cannot_read_bot_token(app, admin, acct):
    for c in (app, admin):
        with pytest.raises(psycopg.errors.InsufficientPrivilege):
            c.execute("SELECT token_encrypted FROM channel_accounts")
        with pytest.raises(psycopg.errors.InsufficientPrivilege):
            c.execute("SELECT webhook_secret_hash FROM channel_accounts")
    assert app.execute("SELECT token_encrypted FROM channel_account_for_send(%s)", (acct,)).fetchone()[0] == "CIPHER"


def test_duplicate_update_detected(app, acct):
    assert app.execute("SELECT channel_event_claim(%s,100,'message')", (acct,)).fetchone()[0] == "new"
    assert app.execute("SELECT channel_event_claim(%s,100,'message')", (acct,)).fetchone()[0] == "duplicate"
    app.execute("SELECT channel_event_finish(%s,100,'processed',NULL,NULL,NULL)", (acct,))
    assert app.execute("SELECT channel_event_claim(%s,100,'message')", (acct,)).fetchone()[0] == "duplicate"
    assert app.execute("SELECT channel_event_claim(%s,101,'message')", (acct,)).fetchone()[0] == "new"


def test_crashed_run_can_be_retried_but_not_immediately(app, owner, acct):
    assert app.execute("SELECT channel_event_claim(%s,200,'message')", (acct,)).fetchone()[0] == "new"
    assert app.execute("SELECT channel_event_claim(%s,200,'message')", (acct,)).fetchone()[0] == "duplicate"   # still being processed
    owner.execute("UPDATE channel_events SET received_at = now() - interval '5 minutes' WHERE external_update_id=200")
    assert app.execute("SELECT channel_event_claim(%s,200,'message')", (acct,)).fetchone()[0] == "new"       # presumed crashed
    assert owner.execute("SELECT attempts FROM channel_events WHERE external_update_id=200").fetchone()[0] == 2


def test_event_finish_cannot_reopen(app, owner, acct):
    app.execute("SELECT channel_event_claim(%s,300,'message')", (acct,))
    app.execute("SELECT channel_event_finish(%s,300,'processing',NULL,NULL,NULL)", (acct,))
    assert owner.execute("SELECT outcome FROM channel_events WHERE external_update_id=300").fetchone()[0] == "processing"
    app.execute("SELECT channel_event_finish(%s,300,'ignored','unknown_sender',NULL,NULL)", (acct,))
    assert owner.execute("SELECT outcome,error_code FROM channel_events WHERE external_update_id=300").fetchone() == ("ignored", "unknown_sender")


def test_unknown_identity_resolves_to_nothing(app, acct):
    app.execute("SELECT set_config('app.tenant_id','',false)")
    assert app.execute("SELECT count(*) FROM channel_resolve(%s,'999')", (acct,)).fetchone()[0] == 0


def test_link_flow_and_resolve(app, world, acct):
    code(app, world)
    r = redeem(app, acct)
    assert r[0] is True and r[2] == world["a"]["tenant"] and r[3] == world["a"]["user"]
    app.execute("SELECT set_config('app.tenant_id','',false)")
    row = app.execute("SELECT tenant_id,user_id FROM channel_resolve(%s,'111')", (acct,)).fetchone()
    assert row == (world["a"]["tenant"], world["a"]["user"])


def test_code_is_single_use_and_expires(app, world, acct):
    code(app, world, plain="ONCE0001")
    assert redeem(app, acct, "ONCE0001", "1", "1")[0] is True
    assert redeem(app, acct, "ONCE0001", "2", "2")[:2] == (False, "invalid_code")
    code(app, world, "b", plain="OLD00001", ttl="-1 minute")
    assert redeem(app, acct, "OLD00001", "3", "3")[:2] == (False, "invalid_code")
    assert redeem(app, acct, "NOPE0000", "4", "4")[:2] == (False, "invalid_code")


def test_one_identity_one_user(app, world, acct):
    code(app, world, "a", "AAAA0001")
    code(app, world, "b", "BBBB0001")
    r = redeem(app, acct, "AAAA0001", "555", "555"); assert r[0] is True, r
    # the same Telegram account cannot also attach to tenant B's user
    assert redeem(app, acct, "BBBB0001", "555", "555")[:2] == (False, "identity_already_linked")
    # and user A cannot link a second Telegram account
    code(app, world, "a", "AAAA0002")
    assert redeem(app, acct, "AAAA0002", "556", "556")[:2] == (False, "user_already_linked")


def test_removed_member_or_suspended_tenant_stops_resolving(app, owner, world, acct):
    code(app, world)
    redeem(app, acct)
    app.execute("SELECT set_config('app.tenant_id','',false)")
    as_admin(owner)
    owner.execute("UPDATE tenant_users SET status='removed' WHERE tenant_id=%s", (world["a"]["tenant"],))
    assert app.execute("SELECT count(*) FROM channel_resolve(%s,'111')", (acct,)).fetchone()[0] == 0
    owner.execute("UPDATE tenant_users SET status='active' WHERE tenant_id=%s", (world["a"]["tenant"],))
    assert app.execute("SELECT count(*) FROM channel_resolve(%s,'111')", (acct,)).fetchone()[0] == 1
    owner.execute("UPDATE tenants SET status='suspended' WHERE id=%s", (world["a"]["tenant"],))
    assert app.execute("SELECT count(*) FROM channel_resolve(%s,'111')", (acct,)).fetchone()[0] == 0
    owner.execute("UPDATE tenants SET status='active' WHERE id=%s", (world["a"]["tenant"],))


def test_unlink_then_relink(app, world, acct):
    code(app, world, plain="LINK0001")
    redeem(app, acct, "LINK0001")
    set_tenant(app, world["a"]["tenant"])
    assert app.execute("UPDATE channel_links SET revoked_at=now() WHERE user_id=%s", (world["a"]["user"],)).rowcount == 1
    assert app.execute("SELECT count(*) FROM channel_resolve(%s,'111')", (acct,)).fetchone()[0] == 0
    code(app, world, plain="LINK0002")
    assert redeem(app, acct, "LINK0002")[0] is True


def test_links_are_tenant_isolated_and_identity_not_editable(app, world, acct):
    code(app, world, "a", "ISO00001")
    redeem(app, acct, "ISO00001", "777", "777")
    set_tenant(app, world["b"]["tenant"])
    assert app.execute("SELECT count(*) FROM channel_links").fetchone()[0] == 0
    assert app.execute("SELECT count(*) FROM channel_link_codes").fetchone()[0] == 0
    set_tenant(app, world["a"]["tenant"])
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("UPDATE channel_links SET external_user_id='1' WHERE user_id=%s", (world["a"]["user"],))
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("DELETE FROM channel_links")
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("INSERT INTO channel_links (id,tenant_id,user_id,account_id,external_user_id,external_chat_id) VALUES (%s,%s,%s,%s,'9','9')",
                    (ulid(), world["a"]["tenant"], world["a"]["user"], acct))


def test_events_are_admin_readable_not_app_readable_and_purge(app, admin, owner, acct):
    app.execute("SELECT channel_event_claim(%s,400,'message')", (acct,))
    app.execute("SELECT channel_event_finish(%s,400,'processed',NULL,NULL,NULL)", (acct,))
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("SELECT * FROM channel_events")
    as_admin(admin)
    assert admin.execute("SELECT count(*) FROM channel_events").fetchone()[0] == 1
    owner.execute("UPDATE channel_events SET received_at = now() - interval '40 days'")
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("SELECT channel_events_purge(30)")                      # housekeeping is the scheduler's (admin) job, not the app role's
    app.rollback()
    as_admin(admin)
    assert admin.execute("SELECT channel_events_purge(30)").fetchone()[0] == 1


def test_only_admin_group_can_fetch_token_for_admin(app, admin, acct):
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("SELECT * FROM channel_account_token_for_admin(%s)", (acct,))
    assert admin.execute("SELECT token_encrypted FROM channel_account_token_for_admin(%s)", (acct,)).fetchone()[0] == "CIPHER"
