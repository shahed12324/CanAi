import psycopg
import pytest

from conftest import as_admin, set_tenant, ulid


@pytest.fixture(autouse=True)
def clean(owner, world):
    yield
    as_admin(owner)
    for t in ("lead_research_items", "leads", "contacts", "companies", "lead_research_jobs"):
        owner.execute(f"DELETE FROM {t}")


def company(conn, w, who="a", domain="alo.test"):
    cid = ulid()
    conn.execute("INSERT INTO companies (id,tenant_id,name,domain) VALUES (%s,%s,%s,%s)", (cid, w[who]["tenant"], domain, domain))
    return cid


def job(conn, w, who="a"):
    jid = ulid()
    conn.execute("INSERT INTO lead_research_jobs (id,tenant_id,user_id,name,input_type) VALUES (%s,%s,%s,'j','url')", (jid, w[who]["tenant"], w[who]["user"]))
    return jid


def test_tenants_cannot_see_each_others_leads(app, owner, world):
    as_admin(owner)
    ca, cb = company(owner, world, "a", "a.test"), company(owner, world, "b", "b.test")
    owner.execute("INSERT INTO leads (id,tenant_id,company_id) VALUES (%s,%s,%s)", (ulid(), world["a"]["tenant"], ca))
    owner.execute("INSERT INTO leads (id,tenant_id,company_id) VALUES (%s,%s,%s)", (ulid(), world["b"]["tenant"], cb))
    owner.execute("SELECT set_config('app.bypass_rls','',false)")
    set_tenant(app, world["a"]["tenant"])
    assert [r[0] for r in app.execute("SELECT domain FROM companies").fetchall()] == ["a.test"]
    assert app.execute("SELECT count(*) FROM leads").fetchone()[0] == 1
    assert app.execute("UPDATE companies SET name='x' WHERE id=%s", (cb,)).rowcount == 0


def test_same_domain_twice_is_blocked_but_other_tenant_may_have_it(app, owner, world):
    set_tenant(app, world["a"]["tenant"])
    company(app, world)
    with pytest.raises(psycopg.errors.UniqueViolation):
        company(app, world)
    set_tenant(app, world["b"]["tenant"])
    company(app, world, "b")                                    # different tenant: same domain is fine


def test_soft_deleted_company_frees_the_domain(app, world):
    set_tenant(app, world["a"]["tenant"])
    c = company(app, world)
    app.execute("UPDATE companies SET deleted_at=now() WHERE id=%s", (c,))
    company(app, world)


def test_domain_must_be_normalised(app, world):
    set_tenant(app, world["a"]["tenant"])
    for bad in ("WWW.Alo.test", "www.alo.test", "alo test"):
        with pytest.raises(psycopg.errors.CheckViolation):
            company(app, world, domain=bad)


def test_one_live_lead_per_company(app, world):
    set_tenant(app, world["a"]["tenant"])
    c = company(app, world)
    app.execute("INSERT INTO leads (id,tenant_id,company_id) VALUES (%s,%s,%s)", (ulid(), world["a"]["tenant"], c))
    with pytest.raises(psycopg.errors.UniqueViolation):
        app.execute("INSERT INTO leads (id,tenant_id,company_id) VALUES (%s,%s,%s)", (ulid(), world["a"]["tenant"], c))


def test_contact_dedupe_rules(app, world):
    set_tenant(app, world["a"]["tenant"])
    t = world["a"]["tenant"]
    c1, c2 = company(app, world, domain="one.test"), company(app, world, domain="two.test")

    def contact(company_id, email=None, phone=None):
        app.execute("INSERT INTO contacts (id,tenant_id,company_id,email,phone_e164) VALUES (%s,%s,%s,%s,%s)", (ulid(), t, company_id, email, phone))

    contact(c1, email="a@one.test")
    with pytest.raises(psycopg.errors.UniqueViolation):
        contact(c2, email="a@one.test")                          # one email = one contact per tenant
    contact(c1, phone="+8801712345678")
    contact(c2, phone="+8801712345678")                          # a shared switchboard number may appear at two companies
    with pytest.raises(psycopg.errors.UniqueViolation):
        contact(c1, phone="+8801712345678")                      # but not twice at the same company


@pytest.mark.parametrize("email,phone", [("UPPER@x.test", None), (None, "01712345678"), (None, "+0123"), (None, None)])
def test_contact_value_checks(app, world, email, phone):
    set_tenant(app, world["a"]["tenant"])
    with pytest.raises(psycopg.errors.CheckViolation):
        app.execute("INSERT INTO contacts (id,tenant_id,email,phone_e164) VALUES (%s,%s,%s,%s)", (ulid(), world["a"]["tenant"], email, phone))


def test_cannot_attach_rows_to_another_tenants_company_or_job(app, owner, world):
    as_admin(owner)
    cb, jb = company(owner, world, "b", "b.test"), job(owner, world, "b")
    owner.execute("SELECT set_config('app.bypass_rls','',false)")
    set_tenant(app, world["a"]["tenant"])
    t = world["a"]["tenant"]
    with pytest.raises(psycopg.Error):
        app.execute("INSERT INTO leads (id,tenant_id,company_id) VALUES (%s,%s,%s)", (ulid(), t, cb))
    with pytest.raises(psycopg.Error):
        app.execute("INSERT INTO contacts (id,tenant_id,company_id,email) VALUES (%s,%s,%s,'z@z.test')", (ulid(), t, cb))
    with pytest.raises(psycopg.Error):
        app.execute("INSERT INTO lead_research_items (id,tenant_id,job_id,url,domain) VALUES (%s,%s,%s,'http://x.test','x.test')", (ulid(), t, jb))


def test_same_url_twice_in_a_job_is_blocked(app, world):
    set_tenant(app, world["a"]["tenant"])
    j = job(app, world)
    q = "INSERT INTO lead_research_items (id,tenant_id,job_id,url,domain) VALUES (%s,%s,%s,%s,'x.test')"
    app.execute(q, (ulid(), world["a"]["tenant"], j, "http://x.test"))
    with pytest.raises(psycopg.errors.UniqueViolation):
        app.execute(q, (ulid(), world["a"]["tenant"], j, "https://www.x.test/"))


def test_stale_running_items_requeue_then_fail_after_three_attempts(app, owner, world):
    set_tenant(app, world["a"]["tenant"])
    j = job(app, world)
    iid = ulid()
    app.execute("INSERT INTO lead_research_items (id,tenant_id,job_id,url,domain,status,attempts,started_at) VALUES (%s,%s,%s,'http://x.test','x.test','running',1,now()-interval '1 hour')", (iid, world["a"]["tenant"], j))
    assert owner.execute("SELECT lead_items_requeue_stale(900)").fetchone()[0] == 1
    assert app.execute("SELECT status FROM lead_research_items WHERE id=%s", (iid,)).fetchone()[0] == "queued"
    app.execute("UPDATE lead_research_items SET status='running', attempts=3, started_at=now()-interval '1 hour' WHERE id=%s", (iid,))
    owner.execute("SELECT lead_items_requeue_stale(900)")
    assert app.execute("SELECT status, result_code FROM lead_research_items WHERE id=%s", (iid,)).fetchone() == ("failed", "worker_lost")


def test_app_cannot_hard_delete_leads_or_companies(app, world):
    set_tenant(app, world["a"]["tenant"])
    c = company(app, world)
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("DELETE FROM companies WHERE id=%s", (c,))
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("DELETE FROM leads")


def test_requeue_closes_a_job_whose_last_item_died(app, owner, world):
    set_tenant(app, world["a"]["tenant"])
    j = job(app, world)
    app.execute("UPDATE lead_research_jobs SET status='running' WHERE id=%s", (j,))
    app.execute("INSERT INTO lead_research_items (id,tenant_id,job_id,url,domain,status,attempts,started_at) VALUES (%s,%s,%s,'http://x.test','x.test','running',3,now()-interval '1 hour')", (ulid(), world["a"]["tenant"], j))
    owner.execute("SELECT lead_items_requeue_stale(900)")
    assert app.execute("SELECT status FROM lead_research_jobs WHERE id=%s", (j,)).fetchone()[0] == "done"
