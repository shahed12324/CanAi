import psycopg
import pytest

from conftest import as_admin, set_tenant, ulid


@pytest.fixture(autouse=True)
def clean(owner, world):
    yield
    as_admin(owner)
    for t in ("outreach_inbound", "outreach_messages", "outreach_campaigns", "email_accounts"):
        owner.execute(f"DELETE FROM {t}")


def oauth_account(conn, w, who="a", provider="gmail", email="me@gmail.test"):
    i = ulid()
    conn.execute("INSERT INTO email_accounts (id,tenant_id,name,from_email,provider,refresh_ct) VALUES (%s,%s,'g',%s,%s,'ct')", (i, w[who]["tenant"], email, provider))
    return i


def test_oauth_account_needs_a_refresh_token_and_no_smtp_fields(app, world):
    set_tenant(app, world["a"]["tenant"])
    assert oauth_account(app, world)
    with pytest.raises(psycopg.errors.CheckViolation):
        app.execute("INSERT INTO email_accounts (id,tenant_id,name,from_email,provider) VALUES (%s,%s,'g','x@y.test','gmail')", (ulid(), world["a"]["tenant"]))
    app.rollback(); set_tenant(app, world["a"]["tenant"])
    with pytest.raises(psycopg.errors.CheckViolation):                       # an smtp account still needs its connection details
        app.execute("INSERT INTO email_accounts (id,tenant_id,name,from_email,provider,refresh_ct) VALUES (%s,%s,'g','z@y.test','smtp','ct')", (ulid(), world["a"]["tenant"]))
    app.rollback(); set_tenant(app, world["a"]["tenant"])
    with pytest.raises(psycopg.errors.CheckViolation):
        app.execute("INSERT INTO email_accounts (id,tenant_id,name,from_email,provider,refresh_ct) VALUES (%s,%s,'g','w@y.test','imap','ct')", (ulid(), world["a"]["tenant"]))


def test_existing_smtp_rows_still_work(app, world):
    set_tenant(app, world["a"]["tenant"])
    app.execute("INSERT INTO email_accounts (id,tenant_id,name,from_email,host,port,username,secret_ct) VALUES (%s,%s,'s','s@y.test','smtp.y.test',465,'u','c')", (ulid(), world["a"]["tenant"]))
    assert app.execute("SELECT provider FROM email_accounts").fetchone()[0] == "smtp"


def test_inbound_is_recorded_once_per_provider_message(app, world):
    t = world["a"]["tenant"]
    set_tenant(app, t)
    acc = oauth_account(app, world)
    sql = "INSERT INTO outreach_inbound (account_id,provider_msg_id,tenant_id,kind) VALUES (%s,%s,%s,%s)"
    app.execute(sql, (acc, "m1", t, "reply"))
    with pytest.raises(psycopg.errors.UniqueViolation):
        app.execute(sql, (acc, "m1", t, "bounce"))
    app.rollback(); set_tenant(app, t)
    with pytest.raises(psycopg.errors.CheckViolation):
        app.execute(sql, (acc, "m2", t, "spam"))


def test_inbound_cannot_point_at_another_workspace(app, world):
    set_tenant(app, world["b"]["tenant"])
    acc_b = oauth_account(app, world, "b", email="b@gmail.test")
    app.commit()
    set_tenant(app, world["a"]["tenant"])
    with pytest.raises((psycopg.errors.RaiseException, psycopg.errors.ForeignKeyViolation, psycopg.errors.InsufficientPrivilege)):
        app.execute("INSERT INTO outreach_inbound (account_id,provider_msg_id,tenant_id,kind) VALUES (%s,'m',%s,'reply')", (acc_b, world["a"]["tenant"]))
    app.rollback()
    set_tenant(app, world["a"]["tenant"])
    assert app.execute("SELECT count(*) FROM email_accounts").fetchone()[0] == 0          # RLS hides B's account


def test_inbound_log_is_append_only_and_private(app, world):
    t = world["a"]["tenant"]
    set_tenant(app, t)
    acc = oauth_account(app, world)
    app.execute("INSERT INTO outreach_inbound (account_id,provider_msg_id,tenant_id,kind) VALUES (%s,'m1',%s,'reply')", (acc, t))
    for stmt in ("UPDATE outreach_inbound SET kind='other'", "DELETE FROM outreach_inbound"):
        with pytest.raises(psycopg.errors.InsufficientPrivilege):
            app.execute(stmt)
        app.rollback(); set_tenant(app, t)
    app.commit()
    set_tenant(app, world["b"]["tenant"])
    assert app.execute("SELECT count(*) FROM outreach_inbound").fetchone()[0] == 0


def test_provider_ids_are_stored_on_messages(app, world):
    t = world["a"]["tenant"]
    set_tenant(app, t)
    acc = oauth_account(app, world)
    camp = ulid()
    app.execute("INSERT INTO outreach_campaigns (id,tenant_id,account_id,name,subject_tpl,body_tpl) VALUES (%s,%s,%s,'c','s','b')", (camp, t, acc))
    mid = ulid()
    app.execute("INSERT INTO outreach_messages (id,tenant_id,campaign_id,to_email,subject,body,provider_message_id,thread_id) VALUES (%s,%s,%s,'x@c.test','s','b','g1','t1')", (mid, t, camp))
    assert app.execute("SELECT provider_message_id, thread_id FROM outreach_messages WHERE id=%s", (mid,)).fetchone() == ("g1", "t1")
