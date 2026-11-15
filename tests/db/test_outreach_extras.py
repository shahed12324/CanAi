"""Phase 28e: IMAP columns, WhatsApp / proposal-attachment campaign flags, and the proposal-belongs-to-the-lead rule."""
import psycopg
import pytest

from conftest import as_admin, set_tenant, ulid
from test_crm import lead
from test_outreach import account, campaign, message
from test_proposals import proposal


@pytest.fixture(autouse=True)
def clean(owner, world):
    yield
    as_admin(owner)
    for t in ("outreach_messages", "outreach_campaigns", "email_accounts", "proposals", "contacts", "leads", "companies"):
        owner.execute(f"DELETE FROM {t}")


def acc_with(conn, w, who="a", **cols):
    i = ulid()
    base = {"id": i, "tenant_id": w[who]["tenant"], "name": "s", "from_email": f"{i[:6].lower()}@agency.test", "host": "smtp.agency.test", "port": 465, "username": "u", "secret_ct": "ct"} | cols
    conn.execute(f"INSERT INTO email_accounts ({','.join(base)}) VALUES ({','.join(['%s'] * len(base))})", list(base.values()))
    return i


def refuses(app, w, fn, who="a"):
    with pytest.raises((psycopg.errors.CheckViolation, psycopg.errors.RaiseException)):
        fn()
    app.rollback()
    set_tenant(app, w[who]["tenant"])


# ------------------------------------------------------------------ IMAP columns
def test_account_may_have_no_imap_or_a_complete_imap(app, world):
    set_tenant(app, world["a"]["tenant"])
    acc_with(app, world)
    acc_with(app, world, imap_host="imap.agency.test", imap_port=993, imap_security="tls")
    acc_with(app, world, imap_host="imap.agency.test", imap_port=143, imap_security="starttls")


@pytest.mark.parametrize("cols", [
    {"imap_host": "imap.agency.test"},
    {"imap_host": "imap.agency.test", "imap_port": 993},
    {"imap_port": 993, "imap_security": "tls"},
    {"imap_host": "imap.agency.test", "imap_port": 0, "imap_security": "tls"},
    {"imap_host": "imap.agency.test", "imap_port": 65536, "imap_security": "tls"},
    {"imap_host": "imap.agency.test", "imap_port": 993, "imap_security": "plain"},
    {"imap_host": "IMAP.Agency.test", "imap_port": 993, "imap_security": "tls"},
    {"imap_host": "imap agency.test", "imap_port": 993, "imap_security": "tls"},
    {"imap_host": " ", "imap_port": 993, "imap_security": "tls"},
    {"imap_host": "", "imap_port": 993, "imap_security": "tls"},
])
def test_incomplete_or_bad_imap_settings_are_refused(app, world, cols):
    set_tenant(app, world["a"]["tenant"])
    refuses(app, world, lambda: acc_with(app, world, **cols))


def test_oauth_accounts_cannot_carry_imap_settings(app, world):
    set_tenant(app, world["a"]["tenant"])
    refuses(app, world, lambda: acc_with(app, world, provider="gmail", host=None, port=None, username=None, secret_ct=None, refresh_ct="r",
                                          imap_host="imap.agency.test", imap_port=993, imap_security="tls"))


def test_port_boundaries_are_accepted(app, world):
    set_tenant(app, world["a"]["tenant"])
    acc_with(app, world, imap_host="i.agency.test", imap_port=1, imap_security="tls")
    acc_with(app, world, imap_host="i.agency.test", imap_port=65535, imap_security="tls")


# ------------------------------------------------------------------ campaign flags
def test_campaign_flags_default_off(app, world, owner):
    set_tenant(app, world["a"]["tenant"])
    camp = campaign(app, world, account(app, world))
    as_admin(owner)
    assert owner.execute("SELECT whatsapp_button, attach_proposal FROM outreach_campaigns WHERE id=%s", (camp,)).fetchone() == (False, False)


# ------------------------------------------------------------------ proposal attachment rule
def setup(app, world, who="a"):
    set_tenant(app, world[who]["tenant"])
    camp = campaign(app, world, account(app, world, who), who)
    l = lead(app, world, who)
    return camp, l


def test_a_mail_may_carry_its_own_leads_proposal(app, world):
    camp, l = setup(app, world)
    p = proposal(app, world, lead_id=l)
    message(app, world, camp, lead_id=l, proposal_id=p, proposal_stamp="2026-01-01T00:00:00Z")
    message(app, world, camp, to="y@client.test")                              # no proposal: unchanged behaviour


def test_another_leads_proposal_is_refused(app, world):
    camp, l = setup(app, world)
    other = lead(app, world, domain="other.test")
    p = proposal(app, world, lead_id=other)
    refuses(app, world, lambda: message(app, world, camp, lead_id=l, proposal_id=p, proposal_stamp="2026-01-01T00:00:00Z"))


def test_a_proposal_without_a_lead_or_a_mail_without_a_lead_is_refused(app, world):
    camp, l = setup(app, world)
    p_nolead = proposal(app, world)
    refuses(app, world, lambda: message(app, world, camp, lead_id=l, proposal_id=p_nolead, proposal_stamp="2026-01-01T00:00:00Z"))
    p = proposal(app, world, lead_id=l)
    refuses(app, world, lambda: message(app, world, camp, proposal_id=p, proposal_stamp="2026-01-01T00:00:00Z"))


def test_a_deleted_proposal_is_refused(app, world):
    camp, l = setup(app, world)
    p = proposal(app, world, lead_id=l, deleted_at="2026-01-01T00:00:00Z")
    refuses(app, world, lambda: message(app, world, camp, lead_id=l, proposal_id=p, proposal_stamp="2026-01-01T00:00:00Z"))


def test_another_workspaces_proposal_is_refused(app, world, owner):
    camp, l = setup(app, world)
    set_tenant(app, world["b"]["tenant"])
    lb = lead(app, world, "b")
    pb = proposal(app, world, "b", lead_id=lb)
    set_tenant(app, world["a"]["tenant"])
    with pytest.raises((psycopg.errors.CheckViolation, psycopg.errors.RaiseException, psycopg.errors.ForeignKeyViolation)):
        message(app, world, camp, lead_id=l, proposal_id=pb, proposal_stamp="2026-01-01T00:00:00Z")


@pytest.mark.parametrize("cols", [{"proposal_stamp": "2026-01-01T00:00:00Z"}, {"proposal_id": "PID"}])
def test_proposal_and_stamp_come_together(app, world, cols):
    camp, l = setup(app, world)
    p = proposal(app, world, lead_id=l)
    cols = {k: (p if v == "PID" else v) for k, v in cols.items()}
    refuses(app, world, lambda: message(app, world, camp, lead_id=l, **cols))


def test_moving_an_attached_mail_to_another_lead_is_refused(app, world):
    camp, l = setup(app, world)
    p = proposal(app, world, lead_id=l)
    m = message(app, world, camp, lead_id=l, proposal_id=p, proposal_stamp="2026-01-01T00:00:00Z")
    other = lead(app, world, domain="other.test")
    refuses(app, world, lambda: app.execute("UPDATE outreach_messages SET lead_id=%s WHERE id=%s", (other, m)))
