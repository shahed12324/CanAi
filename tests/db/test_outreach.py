import psycopg
import pytest

from conftest import as_admin, set_tenant, ulid


@pytest.fixture(autouse=True)
def clean(owner, world):
    yield
    as_admin(owner)
    for t in ("outreach_messages", "outreach_suppressions", "outreach_campaigns", "email_accounts", "contacts", "leads", "companies"):
        owner.execute(f"DELETE FROM {t}")


def account(conn, w, who="a", email="me@agency.test"):
    i = ulid()
    conn.execute("INSERT INTO email_accounts (id,tenant_id,name,from_email,host,port,username,secret_ct) VALUES (%s,%s,'smtp',%s,'smtp.agency.test',465,'u','ct')", (i, w[who]["tenant"], email))
    return i


def campaign(conn, w, acc, who="a"):
    i = ulid()
    conn.execute("INSERT INTO outreach_campaigns (id,tenant_id,account_id,name,subject_tpl,body_tpl) VALUES (%s,%s,%s,'c','hi','body')", (i, w[who]["tenant"], acc))
    return i


def message(conn, w, camp, to="x@client.test", who="a", **kw):
    cols = {"id": ulid(), "tenant_id": w[who]["tenant"], "campaign_id": camp, "to_email": to, "subject": "s", "body": "b"} | kw
    conn.execute(f"INSERT INTO outreach_messages ({','.join(cols)}) VALUES ({','.join(['%s'] * len(cols))})", list(cols.values()))
    return cols["id"]


def test_a_message_starts_pending_and_cannot_skip_approval(app, world):
    set_tenant(app, world["a"]["tenant"])
    camp = campaign(app, world, account(app, world))
    m = message(app, world, camp)
    assert app.execute("SELECT status FROM outreach_messages WHERE id=%s", (m,)).fetchone()[0] == "pending_approval"
    for status in ("approved", "sending", "sent"):
        with pytest.raises(psycopg.errors.CheckViolation):
            app.execute("UPDATE outreach_messages SET status=%s, sent_at=now() WHERE id=%s", (status, m))
        app.rollback()
        set_tenant(app, world["a"]["tenant"])
    with pytest.raises(psycopg.errors.CheckViolation):                       # an approver without a timestamp is not enough
        message(app, world, camp, to="y@client.test", status="approved", approved_by=world["a"]["user"])


def test_approved_message_can_go_through_sending_to_sent(app, world):
    set_tenant(app, world["a"]["tenant"])
    m = message(app, world, campaign(app, world, account(app, world)))
    app.execute("UPDATE outreach_messages SET status='approved', approved_by=%s, approved_at=now() WHERE id=%s", (world["a"]["user"], m))
    app.execute("UPDATE outreach_messages SET status='sending' WHERE id=%s", (m,))
    app.execute("UPDATE outreach_messages SET status='sent', sent_at=now() WHERE id=%s", (m,))


def test_suppressed_address_cannot_be_queued_or_approved(app, world):
    t = world["a"]["tenant"]
    set_tenant(app, t)
    camp = campaign(app, world, account(app, world))
    m = message(app, world, camp, to="ok@client.test")
    app.execute("INSERT INTO outreach_suppressions (tenant_id,email,reason) VALUES (%s,'ok@client.test','manual')", (t,))
    with pytest.raises(psycopg.errors.RaiseException, match="suppressed"):
        app.execute("UPDATE outreach_messages SET status='approved', approved_by=%s, approved_at=now() WHERE id=%s", (world["a"]["user"], m))
    app.rollback(); set_tenant(app, t)
    with pytest.raises(psycopg.errors.RaiseException, match="suppressed"):
        message(app, world, camp, to="ok@client.test", step=2)
    assert message(app, world, camp, to="other@client.test")                  # others unaffected


def test_suppression_is_per_workspace_and_cannot_be_edited_or_deleted(app, world):
    set_tenant(app, world["a"]["tenant"])
    app.execute("INSERT INTO outreach_suppressions (tenant_id,email,reason) VALUES (%s,'z@client.test','manual')", (world["a"]["tenant"],))
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("DELETE FROM outreach_suppressions")
    app.rollback()
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("UPDATE outreach_suppressions SET reason='manual'")
    app.rollback()
    set_tenant(app, world["b"]["tenant"])
    assert app.execute("SELECT count(*) FROM outreach_suppressions").fetchone()[0] == 0
    camp = campaign(app, world, account(app, world, "b"), "b")
    assert message(app, world, camp, to="z@client.test", who="b")             # B never suppressed it


def test_unsubscribe_by_token_suppresses_cancels_and_needs_no_tenant(app, world, owner):
    t = world["a"]["tenant"]
    set_tenant(app, t)
    camp = campaign(app, world, account(app, world))
    sent = message(app, world, camp, to="gone@client.test")
    app.execute("UPDATE outreach_messages SET status='approved', approved_by=%s, approved_at=now() WHERE id=%s", (world["a"]["user"], sent))
    app.execute("UPDATE outreach_messages SET status='sent', sent_at=now() WHERE id=%s", (sent,))
    later = message(app, world, camp, to="gone@client.test", step=2)
    token = app.execute("SELECT unsub_token FROM outreach_messages WHERE id=%s", (sent,)).fetchone()[0]
    app.commit()
    app.execute("SELECT set_config('app.tenant_id', '', false)")             # the public page has no workspace
    assert app.execute("SELECT outreach_unsubscribe(%s)", (token,)).fetchone()[0] is True
    assert app.execute("SELECT outreach_unsubscribe(%s)", (token,)).fetchone()[0] is True      # idempotent
    assert app.execute("SELECT outreach_unsubscribe(%s)", ("0" * 32,)).fetchone()[0] is False
    assert app.execute("SELECT outreach_unsubscribe(%s)", ("x' OR '1'='1",)).fetchone()[0] is False
    app.commit()
    set_tenant(app, t)
    assert app.execute("SELECT reason FROM outreach_suppressions WHERE email='gone@client.test'").fetchone()[0] == "unsubscribed"
    assert app.execute("SELECT status FROM outreach_messages WHERE id=%s", (later,)).fetchone()[0] == "cancelled"
    assert app.execute("SELECT status FROM outreach_messages WHERE id=%s", (sent,)).fetchone()[0] == "sent"   # history is kept


def test_unsubscribe_cannot_reach_another_workspace(app, world):
    set_tenant(app, world["b"]["tenant"])
    camp = campaign(app, world, account(app, world, "b"), "b")
    mb = message(app, world, camp, to="same@client.test", who="b")
    token_b = app.execute("SELECT unsub_token FROM outreach_messages WHERE id=%s", (mb,)).fetchone()[0]
    set_tenant(app, world["a"]["tenant"])
    camp_a = campaign(app, world, account(app, world), "a")
    ma = message(app, world, camp_a, to="same@client.test")
    app.execute("SELECT outreach_unsubscribe(%s)", (token_b,))
    assert app.execute("SELECT status FROM outreach_messages WHERE id=%s", (ma,)).fetchone()[0] == "pending_approval"
    assert app.execute("SELECT count(*) FROM outreach_suppressions").fetchone()[0] == 0


def test_bounce_suppresses_the_address(app, world):
    t = world["a"]["tenant"]
    set_tenant(app, t)
    m = message(app, world, campaign(app, world, account(app, world)), to="dead@client.test")
    app.execute("UPDATE outreach_messages SET status='approved', approved_by=%s, approved_at=now() WHERE id=%s", (world["a"]["user"], m))
    app.execute("UPDATE outreach_messages SET status='sent', sent_at=now() WHERE id=%s", (m,))
    app.execute("UPDATE outreach_messages SET status='bounced' WHERE id=%s", (m,))
    assert app.execute("SELECT reason FROM outreach_suppressions WHERE email='dead@client.test'").fetchone()[0] == "bounced"


def test_same_step_never_twice_and_no_cross_tenant_parents(app, world):
    set_tenant(app, world["a"]["tenant"])
    camp = campaign(app, world, account(app, world))
    message(app, world, camp, to="p@client.test")
    with pytest.raises(psycopg.errors.UniqueViolation):
        message(app, world, camp, to="p@client.test")
    app.rollback(); set_tenant(app, world["a"]["tenant"])
    acc_b = ulid()
    set_tenant(app, world["b"]["tenant"])
    acc_b = account(app, world, "b")
    set_tenant(app, world["a"]["tenant"])
    with pytest.raises((psycopg.errors.RaiseException, psycopg.errors.InsufficientPrivilege, psycopg.errors.ForeignKeyViolation), match=".*") as e:
        campaign(app, world, acc_b, "a")
    assert e.value is not None


def test_rls_hides_other_workspace_accounts_and_messages(app, world):
    set_tenant(app, world["a"]["tenant"])
    camp = campaign(app, world, account(app, world))
    message(app, world, camp)
    app.commit()
    set_tenant(app, world["b"]["tenant"])
    for t in ("email_accounts", "outreach_campaigns", "outreach_messages"):
        assert app.execute(f"SELECT count(*) FROM {t}").fetchone()[0] == 0


def test_account_rules(app, world):
    set_tenant(app, world["a"]["tenant"])
    for col, val in (("security", "none"), ("daily_limit", 0), ("daily_limit", 501), ("port", 0)):
        with pytest.raises(psycopg.errors.CheckViolation):
            app.execute(f"INSERT INTO email_accounts (id,tenant_id,name,from_email,host,port,username,secret_ct,{col}) VALUES (%s,%s,'n','a@b.test','h.test',25,'u','c',%s)" if col != "port" else
                        "INSERT INTO email_accounts (id,tenant_id,name,from_email,host,port,username,secret_ct) VALUES (%s,%s,'n','a@b.test','h.test',%s,'u','c')",
                        (ulid(), world["a"]["tenant"], val))
        app.rollback(); set_tenant(app, world["a"]["tenant"])
