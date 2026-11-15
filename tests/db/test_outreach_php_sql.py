"""The SQL strings in PgOutreachRepo.php, executed for real with the parameter shapes PHP passes."""
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
    owner.execute("ALTER TABLE emergency_events DISABLE TRIGGER emergency_events_no_change")
    owner.execute("DELETE FROM emergency_events")
    owner.execute("ALTER TABLE emergency_events ENABLE TRIGGER emergency_events_no_change")
    for t in ("outreach_messages", "outreach_suppressions", "outreach_campaigns", "proposals", "email_accounts", "brand_kits", "contacts", "leads", "companies"):
        owner.execute(f"DELETE FROM {t}")


def setup(app, w, who="a", email="Boss@Alo.test"):
    t, u = w[who]["tenant"], w[who]["user"]
    set_tenant(app, t)
    acc, camp, comp, lead, ct = ulid(), ulid(), ulid(), ulid(), ulid()
    app.execute("INSERT INTO email_accounts (id,tenant_id,name,from_email,host,port,username,secret_ct,daily_limit) VALUES (%s,%s,'s','me@agency.test','smtp.agency.test',465,'u','ct',50)", (acc, t))
    app.execute("INSERT INTO outreach_campaigns (id,tenant_id,account_id,name,subject_tpl,body_tpl,status,followup_after_days,followup_body_tpl) VALUES (%s,%s,%s,'c','s','b','active',3,'again')", (camp, t, acc))
    app.execute("INSERT INTO companies (id,tenant_id,name,domain) VALUES (%s,%s,'আলো','alo.test')", (comp, t))
    app.execute("INSERT INTO leads (id,tenant_id,company_id) VALUES (%s,%s,%s)", (lead, t, comp))
    app.execute("INSERT INTO contacts (id,tenant_id,company_id,email,email_status) VALUES (%s,%s,%s,%s,'mx_ok')", (ct, t, comp, email.lower()))
    return dict(t=t, u=u, acc=acc, camp=camp, lead=lead, ct=ct)


def enqueue(app, s, email="boss@alo.test", step=1):
    mid = ulid()
    n = app.execute(q("insert into outreach_messages"), (mid, s["t"], s["camp"], s["lead"], s["ct"], email, step, "বিষয়", "বডি", None, None)).rowcount
    return mid, n


def status(app, mid):
    return app.execute("SELECT status FROM outreach_messages WHERE id=%s", (mid,)).fetchone()[0]


def test_reads(app, world):
    s = setup(app, world)
    a = app.execute(q("from email_accounts where id"), (s["acc"],)).fetchone()
    assert a[1] == "smtp" and a[4] == "smtp.agency.test" and a[11] == 50 and isinstance(a[12], str)           # warmup date as text
    assert app.execute(q("from outreach_campaigns where id"), (s["camp"],)).fetchone()[4] == 3
    lead = app.execute(q("left join lateral"), (s["lead"],)).fetchone()
    assert lead[5] == "boss@alo.test" and lead[4].strip() == s["ct"] and lead[1] == "আলো"
    assert app.execute(q("from brand_kits where is_default")).fetchone() is None
    assert app.execute(q("from outreach_suppressions where email"), ("x@y.test",)).fetchone() is None


def test_bad_contacts_are_never_picked(app, world):
    s = setup(app, world)
    for st in ("bounced", "unsubscribed", "no_mx"):
        app.execute("UPDATE contacts SET email_status=%s WHERE id=%s", (st, s["ct"]))
        assert app.execute(q("left join lateral"), (s["lead"],)).fetchone()[5] is None


def test_queue_approve_send_cycle(app, world):
    s = setup(app, world)
    mid, n = enqueue(app, s)
    assert n == 1 and enqueue(app, s)[1] == 0                                              # same person, same step: nothing
    assert app.execute(q("set status = 'approved', approved_by")  , (s["u"], mid)).rowcount == 1
    assert app.execute(q("set status = 'approved', approved_by"), (s["u"], mid)).rowcount == 0   # approving twice is a no-op
    assert app.execute(q("m.sent_at >= (date_trunc"), (s["acc"],)).fetchone()[0] == 0
    rows = app.execute(q("returning id, tenant_id"), (s["acc"], 10)).fetchall()
    assert len(rows) == 1 and rows[0][7] == 1 and rows[0][0].strip() == mid                # attempts became 1
    assert app.execute(q("returning id, tenant_id"), (s["acc"], 10)).fetchall() == []      # claimed exactly once
    assert app.execute(q("m.sent_at >= (date_trunc"), (s["acc"],)).fetchone()[0] == 1      # 'sending' already uses a slot
    app.execute(q("set status = 'sent', sent_at"), ("abc@agency.test", None, None, mid))
    assert status(app, mid) == "sent" and app.execute(q("m.sent_at >= (date_trunc"), (s["acc"],)).fetchone()[0] == 1


def test_claim_respects_the_number_asked_for_and_only_active_campaigns(app, world):
    s = setup(app, world)
    ids = []
    for e in ("a1@x.test", "a2@x.test", "a3@x.test"):
        mid, _ = enqueue(app, s, e)
        app.execute(q("set status = 'approved', approved_by"), (s["u"], mid))
        ids.append(mid)
    assert len(app.execute(q("returning id, tenant_id"), (s["acc"], 2)).fetchall()) == 2
    app.execute("UPDATE outreach_campaigns SET status='paused' WHERE id=%s", (s["camp"],))
    assert app.execute(q("returning id, tenant_id"), (s["acc"], 5)).fetchall() == []


def test_release_fail_and_suppressed_in_flight(app, world):
    s = setup(app, world)
    mid, _ = enqueue(app, s)
    app.execute(q("set status = 'approved', approved_by"), (s["u"], mid))
    app.execute(q("returning id, tenant_id"), (s["acc"], 5))
    app.execute(q("attempts = case when"), ("smtp_auth", 1, mid))                          # refund
    assert app.execute("SELECT status, attempts, error_code FROM outreach_messages WHERE id=%s", (mid,)).fetchone() == ("approved", 0, "smtp_auth")
    app.execute(q("returning id, tenant_id"), (s["acc"], 5))
    app.execute(q("attempts = case when"), ("smtp_unreachable", 0, mid))                   # no refund
    assert app.execute("SELECT attempts FROM outreach_messages WHERE id=%s", (mid,)).fetchone()[0] == 1
    app.execute(q("returning id, tenant_id"), (s["acc"], 5))
    app.execute(q("set status = 'failed'"), ("smtp_rejected", mid))
    assert status(app, mid) == "failed"


def test_suppressed_while_approved_is_cancelled_before_sending(app, world):
    s = setup(app, world)
    mid, _ = enqueue(app, s)
    app.execute(q("set status = 'approved', approved_by"), (s["u"], mid))
    app.execute("INSERT INTO outreach_suppressions (tenant_id,email,reason) VALUES (%s,'boss@alo.test','manual')", (s["t"],))
    assert app.execute(q("set status = 'cancelled', error_code = 'suppressed' from"), (s["acc"],)).rowcount == 1
    assert status(app, mid) == "cancelled"
    assert app.execute(q("returning id, tenant_id"), (s["acc"], 5)).fetchall() == []


def test_approving_a_suppressed_address_is_refused_by_the_database(app, world):
    s = setup(app, world)
    mid, _ = enqueue(app, s)
    app.execute("INSERT INTO outreach_suppressions (tenant_id,email,reason) VALUES (%s,'boss@alo.test','manual')", (s["t"],))
    with pytest.raises(psycopg.errors.RaiseException, match="suppressed"):                  # PgOutreachRepo catches exactly this message
        app.execute(q("set status = 'approved', approved_by"), (s["u"], mid))


def test_followups_reply_and_bounce(app, world, owner):
    s = setup(app, world)
    mid, _ = enqueue(app, s)
    app.execute(q("set status = 'approved', approved_by"), (s["u"], mid))
    app.execute(q("returning id, tenant_id"), (s["acc"], 5))
    app.execute(q("set status = 'sent', sent_at"), ("m@agency.test", None, None, mid))
    assert app.execute(q("make_interval"), (10,)).fetchall() == []                         # sent just now: not due
    app.execute("UPDATE outreach_messages SET sent_at = now() - interval '4 days' WHERE id=%s", (mid,))
    due = app.execute(q("make_interval"), (10,)).fetchall()
    assert [d[3] for d in due] == ["boss@alo.test"]
    f, n = enqueue(app, s, step=2)
    assert n == 1 and app.execute(q("make_interval"), (10,)).fetchall() == []             # follow-up exists: not due again
    assert app.execute(q("set status = 'replied' where"), (mid,)).rowcount == 1
    assert app.execute(q("error_code = 'replied' from"), (mid,)).rowcount == 1 and status(app, f) == "cancelled"
    assert app.execute(q("set status = 'replied' where"), (mid,)).rowcount == 0


def test_bounce_suppresses(app, world):
    s = setup(app, world)
    mid, _ = enqueue(app, s)
    app.execute(q("set status = 'approved', approved_by"), (s["u"], mid))
    app.execute(q("returning id, tenant_id"), (s["acc"], 5))
    app.execute(q("set status = 'sent', sent_at"), ("m@agency.test", None, None, mid))
    assert app.execute(q("set status = 'bounced' where"), (mid,)).rowcount == 1
    assert app.execute(q("from outreach_suppressions where email"), ("boss@alo.test",)).fetchone() is not None


def test_emergency_stop_blocks_approving_and_claiming(app, world, owner):
    s = setup(app, world)
    pending, _ = enqueue(app, s, "p@x.test")
    ready, _ = enqueue(app, s, "r@x.test")
    assert app.execute(q("set status = 'approved', approved_by"), (s["u"], ready)).rowcount == 1
    as_admin(owner)
    owner.execute("INSERT INTO emergency_events (tenant_id,action,by_user) VALUES (%s,'stop',%s)", (s["t"], s["u"]))
    owner.execute("SELECT set_config('app.bypass_rls','',false)")
    assert app.execute(q("set status = 'approved', approved_by"), (s["u"], pending)).rowcount == 0   # cannot approve while stopped
    assert status(app, pending) == "pending_approval"
    assert app.execute(q("returning id, tenant_id"), (s["acc"], 10)).fetchall() == []               # an approved mail waits, untouched
    assert status(app, ready) == "approved"
    as_admin(owner)
    owner.execute("INSERT INTO emergency_events (tenant_id,action,by_user) VALUES (%s,'resume',%s)", (s["t"], s["u"]))
    owner.execute("SELECT set_config('app.bypass_rls','',false)")
    assert len(app.execute(q("returning id, tenant_id"), (s["acc"], 10)).fetchall()) == 1           # after resume it is sent


# ------------------------------------------------------------------ Phase 28e: proposal pin, WhatsApp flag, IMAP columns
def test_proposal_lookup_pin_and_change_detection(app, owner, world):
    s = setup(app, world)
    t = s["t"]
    app.execute("INSERT INTO proposals (id,tenant_id,lead_id,title) VALUES (%s,%s,%s,'old')", (ulid(), t, s["lead"]))
    newer = ulid()
    app.execute("INSERT INTO proposals (id,tenant_id,lead_id,title,updated_at) VALUES (%s,%s,%s,'new', now() + interval '1 second')", (newer, t, s["lead"]))
    gone = ulid()
    app.execute("INSERT INTO proposals (id,tenant_id,lead_id,title,updated_at,deleted_at) VALUES (%s,%s,%s,'deleted', now() + interval '1 hour', now())", (gone, t, s["lead"]))
    got = app.execute(q("from proposals where lead_id"), (s["lead"],)).fetchone()
    assert got[0].strip() == newer and isinstance(got[1], str)                         # newest, not deleted
    assert app.execute(q("from proposals where lead_id"), (ulid(),)).fetchone() is None
    mid = ulid()
    assert app.execute(q("insert into outreach_messages"), (mid, t, s["camp"], s["lead"], s["ct"], "boss@alo.test", 1, "s", "b", newer, got[1])).rowcount == 1
    assert app.execute(q("and updated_at = ?::timestamptz"), (newer, got[1])).fetchone() is not None
    app.execute("UPDATE proposals SET title='edited', updated_at = now() + interval '5 seconds' WHERE id=%s", (newer,))
    assert app.execute(q("and updated_at = ?::timestamptz"), (newer, got[1])).fetchone() is None          # edited after approval
    assert app.execute(q("and updated_at = ?::timestamptz"), (gone, got[1])).fetchone() is None
    app.execute("UPDATE proposals SET updated_at = %s::timestamptz, deleted_at = now() WHERE id=%s", (got[1], newer))
    assert app.execute(q("and updated_at = ?::timestamptz"), (newer, got[1])).fetchone() is None          # same version but deleted


def test_claim_returns_the_pin_the_campaign_and_the_approver(app, owner, world):
    s = setup(app, world)
    p = ulid()
    app.execute("INSERT INTO proposals (id,tenant_id,lead_id,title) VALUES (%s,%s,%s,'p')", (p, s["t"], s["lead"]))
    stamp = app.execute("SELECT updated_at::text FROM proposals WHERE id=%s", (p,)).fetchone()[0]
    mid = ulid()
    app.execute(q("insert into outreach_messages"), (mid, s["t"], s["camp"], s["lead"], s["ct"], "boss@alo.test", 1, "s", "b", p, stamp))
    app.execute("UPDATE outreach_messages SET status='approved', approved_by=%s, approved_at=now() WHERE id=%s", (s["u"], mid))
    row = app.execute(q("update outreach_messages set status = 'sending'"), (s["acc"], 5)).fetchone()
    assert row is not None
    assert (row[0].strip(), row[1].strip(), row[9].strip(), row[10].strip(), row[11].strip()) == (mid, s["t"], s["camp"], s["u"], p)
    assert row[12] == stamp


def test_campaign_and_sender_and_account_reads_have_the_new_fields(app, owner, world):
    s = setup(app, world)
    set_tenant(app, s["t"])
    c = app.execute(q("from outreach_campaigns where id"), (s["camp"],)).fetchone()
    assert c[-2:] == (False, False)
    app.execute("UPDATE outreach_campaigns SET whatsapp_button=true WHERE id=%s", (s["camp"],))
    assert app.execute(q("from outreach_campaigns where id"), (s["camp"],)).fetchone()[-2:] == (True, False)
    app.execute("UPDATE outreach_campaigns SET whatsapp_button=false, attach_proposal=true WHERE id=%s", (s["camp"],))
    assert app.execute(q("from outreach_campaigns where id"), (s["camp"],)).fetchone()[-2:] == (False, True)
    app.execute("INSERT INTO brand_kits (id,tenant_id,name,phone,is_default) VALUES (%s,%s,'Agency','01712345678',true)", (ulid(), s["t"]))
    sd = app.execute(q("from brand_kits where is_default")).fetchone()
    assert sd[2] == "01712345678"
    app.execute("UPDATE email_accounts SET imap_host='imap.agency.test', imap_port=993, imap_security='tls' WHERE id=%s", (s["acc"],))
    a = app.execute(q("from email_accounts where id"), (s["acc"],)).fetchone()
    assert a[-3:] == ("imap.agency.test", 993, "tls")
