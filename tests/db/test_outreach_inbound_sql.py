"""The 9c statements in PgOutreachRepo.php, executed for real."""
import psycopg
import pytest

from conftest import as_admin, set_tenant, ulid
from test_lead_php_sql import sql_of

REPO = "app/Outreach/PgOutreachRepo.php"


def q(marker):
    return sql_of(REPO, marker)


@pytest.fixture(autouse=True)
def clean(owner, world):
    yield
    as_admin(owner)
    for t in ("outreach_inbound", "outreach_messages", "outreach_campaigns", "email_accounts"):
        owner.execute(f"DELETE FROM {t}")


def setup(app, w, who="a", provider="gmail"):
    t, u = w[who]["tenant"], w[who]["user"]
    set_tenant(app, t)
    acc, camp = ulid(), ulid()
    app.execute("INSERT INTO email_accounts (id,tenant_id,name,from_email,provider,refresh_ct) VALUES (%s,%s,'g',%s,%s,'ct')", (acc, t, f"{acc[:6]}@gmail.test", provider))
    app.execute("INSERT INTO outreach_campaigns (id,tenant_id,account_id,name,subject_tpl,body_tpl,status) VALUES (%s,%s,%s,'c','s','b','active')", (camp, t, acc))
    return dict(t=t, u=u, acc=acc, camp=camp)


def sent(app, s, to="boss@alo.test", thread="th1", rfc="rfc1@x", days_ago=0, step=1):
    mid = ulid()
    app.execute("INSERT INTO outreach_messages (id,tenant_id,campaign_id,to_email,subject,body,step) VALUES (%s,%s,%s,%s,'s','b',%s)", (mid, s["t"], s["camp"], to, step))
    app.execute("UPDATE outreach_messages SET status='approved', approved_by=%s, approved_at=now() WHERE id=%s", (s["u"], mid))
    app.execute("UPDATE outreach_messages SET status='sending' WHERE id=%s", (mid,))
    app.execute("UPDATE outreach_messages SET status='sent', sent_at=now() - make_interval(days => %s) WHERE id=%s", (days_ago, mid))
    app.execute("UPDATE outreach_messages SET rfc_message_id=%s, thread_id=%s WHERE id=%s", (rfc, thread, mid))
    return mid


def test_mark_sent_keeps_provider_ids(app, world):
    s = setup(app, world)
    mid = ulid()
    app.execute("INSERT INTO outreach_messages (id,tenant_id,campaign_id,to_email,subject,body) VALUES (%s,%s,%s,'x@c.test','s','b')", (mid, s["t"], s["camp"]))
    app.execute("UPDATE outreach_messages SET status='approved', approved_by=%s, approved_at=now() WHERE id=%s", (s["u"], mid))
    app.execute("UPDATE outreach_messages SET status='sending' WHERE id=%s", (mid,))
    app.execute(q("set status = 'sent', sent_at"), ("rfc@x", "g-1", "t-1", mid))
    assert app.execute("SELECT status, provider_message_id, thread_id, rfc_message_id FROM outreach_messages WHERE id=%s", (mid,)).fetchone() == ("sent", "g-1", "t-1", "rfc@x")


def test_account_error_is_set_and_cleared(app, world):
    s = setup(app, world)
    sql = q("set last_error = ?")
    app.execute(sql, ("oauth_revoked", "oauth_revoked", s["acc"], "oauth_revoked"))
    assert app.execute("SELECT last_error, last_error_at IS NOT NULL FROM email_accounts WHERE id=%s", (s["acc"],)).fetchone() == ("oauth_revoked", True)
    assert app.execute(sql, ("oauth_revoked", "oauth_revoked", s["acc"], "oauth_revoked")).rowcount == 0         # same error again: untouched
    app.execute(sql, (None, None, s["acc"], None))
    assert app.execute("SELECT last_error, last_error_at FROM email_accounts WHERE id=%s", (s["acc"],)).fetchone() == (None, None)


def test_rotated_refresh_token_is_saved_only_for_oauth_accounts(app, world):
    s = setup(app, world)
    assert app.execute(q("set refresh_ct = ?"), ("NEW", 2, s["acc"])).rowcount == 1
    assert app.execute("SELECT refresh_ct, key_version FROM email_accounts WHERE id=%s", (s["acc"],)).fetchone() == ("NEW", 2)
    smtp = ulid()
    app.execute("INSERT INTO email_accounts (id,tenant_id,name,from_email,host,port,username,secret_ct) VALUES (%s,%s,'s','s@y.test','h.y.test',465,'u','c')", (smtp, s["t"]))
    assert app.execute(q("set refresh_ct = ?"), ("NEW", 2, smtp)).rowcount == 0


def test_inbound_claim_is_once_only(app, world):
    s = setup(app, world)
    mid = sent(app, s)
    sql = q("insert into outreach_inbound")
    assert app.execute(sql, (s["acc"], "g-100", s["t"], "reply", mid, "2026-11-03T05:00:00+00:00")).rowcount == 1
    assert app.execute(sql, (s["acc"], "g-100", s["t"], "bounce", None, None)).rowcount == 0
    assert app.execute(q("from outreach_inbound where account_id"), (s["acc"], "g-100")).fetchone() is not None
    assert app.execute(q("from outreach_inbound where account_id"), (s["acc"], "g-101")).fetchone() is None
    assert app.execute("SELECT kind FROM outreach_inbound").fetchone()[0] == "reply"


def test_find_sent_by_address_thread_or_message_id_and_never_before_it_was_sent(app, world):
    s = setup(app, world)
    old = sent(app, s, "boss@alo.test", "th-old", "rfc-old@x", days_ago=3)
    new = sent(app, s, "boss@alo.test", "th-new", "rfc-new@x", days_ago=1, step=2)
    other = sent(app, s, "other@beta.test", "th-b", "rfc-b@x")
    sql = q("?::text is not null and m.to_email")
    def f(email=None, thread=None, irt=None, at=None):
        email = email.lower() if email else None                                  # the repository lower-cases it before the query
        r = app.execute(sql, (s["acc"], at, at, email, email, thread, thread, irt, irt)).fetchone()
        return r and r[0].strip()
    assert f(email="Boss@Alo.test") == new                                        # newest of that person
    assert f(thread="th-old") == old and f(irt="rfc-b@x") == other and f(email="nobody@x.test") is None
    assert f() is None                                                            # nothing to go on: nothing matched
    assert f(email="boss@alo.test", at="2026-01-01T00:00:00+00:00") is None       # a mail older than our sending can not be its answer
    app.execute("UPDATE outreach_messages SET status='replied' WHERE id=%s", (new,))
    assert f(email="boss@alo.test") == old                                        # settled mails are not matched again


def test_poll_since_and_checkpoint(app, world):
    s = setup(app, world)
    sql = q("select extract(epoch from greatest")
    assert app.execute(sql, (s["acc"],)).fetchone() is None                       # nothing waiting: no polling
    sent(app, s, days_ago=2)
    since = app.execute(sql, (s["acc"],)).fetchone()[0]
    now = app.execute("SELECT extract(epoch from now())::bigint").fetchone()[0]
    assert 2 * 86400 - 3600 < now - since < 2 * 86400 + 3600                       # from shortly before the first sent mail
    app.execute(q("set inbox_checked_at = to_timestamp"), (now - 600, s["acc"]))
    assert abs(app.execute(sql, (s["acc"],)).fetchone()[0] - (now - 1200)) <= 2    # then from the last check, with 10 minutes of overlap
    sent(app, s, "old@x.test", days_ago=60)                                        # beyond 45 days is ignored
    app.execute("UPDATE email_accounts SET inbox_checked_at = NULL WHERE id=%s", (s["acc"],))
    assert app.execute(sql, (s["acc"],)).fetchone()[0] > now - 3 * 86400


def test_rls_hides_other_workspaces_inbound_and_matching(app, world):
    sa = setup(app, world, "a")
    sent(app, sa)
    app.commit()
    set_tenant(app, world["b"]["tenant"])
    assert app.execute(q("from outreach_inbound where account_id"), (sa["acc"], "x")).fetchone() is None
    sql = q("?::text is not null and m.to_email")
    assert app.execute(sql, (sa["acc"], None, None, "boss@alo.test", "boss@alo.test", None, None, None, None)).fetchone() is None
    with pytest.raises(psycopg.errors.RaiseException):                              # cannot log an inbound mail against A's account either
        app.execute(q("insert into outreach_inbound"), (sa["acc"], "z", world["b"]["tenant"], "reply", None, None))


def test_command_queries_pick_only_what_needs_work(app, world, owner):
    cmd = "app/Console/Commands/OutreachRunCommand.php"
    s = setup(app, world)
    app.commit()
    due = lambda: [r[1].strip() for r in owner.execute(sql_of(cmd, "a.provider <> 'smtp'")).fetchall()]
    as_admin(owner)
    assert due() == []                                                              # nothing sent yet: no polling
    set_tenant(app, s["t"])
    mid = sent(app, s)
    app.commit()
    assert due() == [s["acc"]]
    owner.execute("UPDATE email_accounts SET inbox_checked_at = now() WHERE id=%s", (s["acc"],))
    assert due() == []                                                              # checked a moment ago: wait 5 minutes
    owner.execute("UPDATE email_accounts SET inbox_checked_at = now() - interval '6 minutes' WHERE id=%s", (s["acc"],))
    assert due() == [s["acc"]]
    owner.execute("UPDATE outreach_messages SET status='replied' WHERE id=%s", (mid,))
    assert due() == []                                                              # answered: nothing to wait for
    owner.execute("UPDATE outreach_messages SET status='sent' WHERE id=%s", (mid,))
    owner.execute("UPDATE email_accounts SET status='paused' WHERE id=%s", (s["acc"],))
    assert due() == []
    owner.execute("UPDATE email_accounts SET status='active', provider='smtp', host='h.y.test', port=465, username='u', secret_ct='c' WHERE id=%s", (s["acc"],))
    assert due() == []                                                              # SMTP accounts have no API inbox here


def test_oauth_connect_upserts_and_reconnect_clears_the_problem(app, world):
    ctl = "app/Http/Controllers/OutreachOAuthController.php"
    t = world["a"]["tenant"]
    set_tenant(app, t)
    sql = sql_of(ctl, "insert into email_accounts")
    first = ulid()
    assert app.execute(sql, (first, t, "Gmail", "me@gmail.test", "gmail", "CT1", 1)).rowcount == 1
    app.execute("UPDATE email_accounts SET last_error='oauth_revoked', status='paused', inbox_checked_at=now() WHERE id=%s", (first,))
    assert app.execute(sql, (ulid(), t, "Gmail", "me@gmail.test", "gmail", "CT2", 2)).rowcount == 1          # reconnect: same row, not a second account
    rows = app.execute("SELECT id, refresh_ct, key_version, last_error, status, inbox_checked_at FROM email_accounts").fetchall()
    assert len(rows) == 1 and rows[0][0].strip() == first and rows[0][1:] == ("CT2", 2, None, "active", None)
    smtp = ulid()
    app.execute("INSERT INTO email_accounts (id,tenant_id,name,from_email,host,port,username,secret_ct) VALUES (%s,%s,'s','s@y.test','h.y.test',465,'u','c')", (smtp, t))
    app.execute(sql, (ulid(), t, "Outlook", "s@y.test", "graph", "CT3", 1))                                    # an SMTP address can be upgraded to OAuth
    assert app.execute("SELECT provider, secret_ct, refresh_ct FROM email_accounts WHERE id=%s", (smtp,)).fetchone() == ("graph", None, "CT3")


def test_account_list_query_has_what_the_page_shows(app, world):
    set_tenant(app, world["a"]["tenant"])
    setup(app, world)
    row = app.execute(sql_of("app/Http/Controllers/EmailAccountController.php", "from email_accounts order by created_at")).fetchone()
    assert row[1] == "gmail" and row[-1] is None
