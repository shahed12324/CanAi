import psycopg
import pytest

from conftest import as_admin, set_tenant, ulid

CAT = ["ai_providers", "ai_models", "model_capabilities", "model_pricing", "model_access_rules", "routing_rules"]


def mk_provider(admin, name, status="active", fallback=None, adapter="openai"):
    pid = ulid()
    as_admin(admin)
    admin.execute(
        "INSERT INTO ai_providers (id,name,adapter,status,fallback_provider_id) VALUES (%s,%s,%s,%s,%s)",
        (pid, name, adapter, status, fallback),
    )
    return pid


def mk_model(admin, provider, key="m1", free=False, status="active"):
    mid = ulid()
    as_admin(admin)
    admin.execute(
        "INSERT INTO ai_models (id,provider_id,model_key,display_name,is_free_tier,status) VALUES (%s,%s,%s,%s,%s,%s)",
        (mid, provider, key, key, free, status),
    )
    return mid


def plan(conn, code):
    as_admin(conn)
    return conn.execute("SELECT id FROM plans WHERE code=%s", (code,)).fetchone()[0]


def avail(conn, tenant):
    return {r[0] for r in conn.execute("SELECT model_id FROM available_models(%s)", (tenant,)).fetchall()}


@pytest.fixture(autouse=True)
def clean(owner, world):
    yield
    as_admin(owner)
    t = (world["a"]["tenant"], world["b"]["tenant"])
    owner.execute("DELETE FROM subscriptions WHERE tenant_id IN (%s,%s)", t)
    owner.execute("DELETE FROM tenant_model_preferences WHERE tenant_id IN (%s,%s)", t)
    # pricing is append-only; remove it by dropping the trigger temporarily as the table owner
    owner.execute("ALTER TABLE model_pricing DISABLE TRIGGER model_pricing_append_only")
    owner.execute("DELETE FROM model_pricing")
    owner.execute("ALTER TABLE model_pricing ENABLE TRIGGER model_pricing_append_only")
    owner.execute("DELETE FROM provider_health_checks")
    owner.execute("DELETE FROM provider_credentials")
    owner.execute("DELETE FROM model_access_rules")
    owner.execute("DELETE FROM ai_models")
    owner.execute("UPDATE ai_providers SET fallback_provider_id = NULL")
    owner.execute("DELETE FROM ai_providers")


# ---- privileges ----------------------------------------------------------------------
def test_app_role_cannot_write_catalog(app, admin):
    p = mk_provider(admin, "p-priv")
    m = mk_model(admin, p)
    for sql in (
        "UPDATE ai_providers SET name='x'",
        "DELETE FROM ai_models",
        "INSERT INTO routing_rules (id,task_type,mode) VALUES ('" + ulid() + "','chat','manual')",
        f"INSERT INTO model_access_rules VALUES ('{m}', '{plan(app, 'free')}', true)",
        f"INSERT INTO model_pricing (model_id) VALUES ('{m}')",
    ):
        with pytest.raises(psycopg.errors.InsufficientPrivilege):
            app.execute(sql)
    assert app.execute("SELECT count(*) FROM ai_models").fetchone()[0] == 1   # readable


def test_admin_group_can_manage_catalog(admin):
    p = mk_provider(admin, "p-adm")
    m = mk_model(admin, p)
    admin.execute("UPDATE ai_models SET display_name='n' WHERE id=%s", (m,))
    admin.execute("INSERT INTO model_capabilities VALUES (%s,'text')", (m,))
    admin.execute("INSERT INTO model_pricing (model_id, input_per_1m, output_per_1m, credit_multiplier) VALUES (%s,1,2,1.5)", (m,))
    assert admin.execute("SELECT credit_multiplier FROM model_current_pricing WHERE model_id=%s", (m,)).fetchone()[0] == 1.5


# ---- credential vault ----------------------------------------------------------------
def add_cred(admin, p, secret="CIPHERTEXT-abc", label="main", status="active"):
    as_admin(admin)
    admin.execute(
        "INSERT INTO provider_credentials (id,provider_id,label,secret_encrypted,status) VALUES (%s,%s,%s,%s,%s)",
        (ulid(), p, label, secret, status),
    )


def test_app_role_cannot_read_secret_column_any_way(app, admin):
    p = mk_provider(admin, "p-vault")
    add_cred(admin, p)
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("SELECT secret_encrypted FROM provider_credentials")
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("SELECT * FROM provider_credentials")
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("SELECT to_jsonb(c) FROM provider_credentials c")
    # non-secret columns are fine
    assert app.execute("SELECT label, status FROM provider_credentials").fetchall() == [("main", "active")]


def test_admin_group_can_write_but_not_read_back_the_secret(admin):
    p = mk_provider(admin, "p-vault2")
    add_cred(admin, p)
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        admin.execute("SELECT secret_encrypted FROM provider_credentials")
    admin.execute("UPDATE provider_credentials SET secret_encrypted='NEW', key_version=2 WHERE provider_id=%s", (p,))


def test_secret_function_is_the_only_read_path_and_is_gated(app, admin):
    p = mk_provider(admin, "p-fn")
    add_cred(admin, p, secret="CIPHER-1")
    assert app.execute("SELECT secret_encrypted, key_version FROM provider_secret_for_call(%s)", (p,)).fetchone() == ("CIPHER-1", 1)
    # disabled provider -> nothing
    as_admin(admin)
    admin.execute("UPDATE ai_providers SET status='disabled' WHERE id=%s", (p,))
    assert app.execute("SELECT * FROM provider_secret_for_call(%s)", (p,)).fetchall() == []
    # revoked credential -> nothing
    admin.execute("UPDATE ai_providers SET status='active' WHERE id=%s", (p,))
    admin.execute("UPDATE provider_credentials SET status='revoked' WHERE provider_id=%s", (p,))
    assert app.execute("SELECT * FROM provider_secret_for_call(%s)", (p,)).fetchall() == []


def test_secret_function_not_executable_by_public(dbname):
    from conftest import ADMIN_DSN, _dsn

    with psycopg.connect(ADMIN_DSN, autocommit=True) as su:
        su.execute("DO $$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname='zz_nobody') THEN CREATE ROLE zz_nobody LOGIN; END IF; END $$")
    with psycopg.connect(_dsn(ADMIN_DSN, "zz_nobody", dbname), autocommit=True) as c:
        with pytest.raises(psycopg.errors.InsufficientPrivilege):
            c.execute("SELECT * FROM provider_secret_for_call('00000000000000000000000000')")
    with psycopg.connect(ADMIN_DSN, autocommit=True) as su:
        su.execute("DROP ROLE zz_nobody")


def test_test_function_is_admin_only_and_ignores_provider_status(app, admin):
    p = mk_provider(admin, "p-test", status="disabled")
    add_cred(admin, p, secret="CIPHER-T")
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("SELECT * FROM provider_secret_for_test(%s)", (p,))
    assert admin.execute("SELECT secret_encrypted FROM provider_secret_for_test(%s)", (p,)).fetchone()[0] == "CIPHER-T"
    # the normal path still refuses a disabled provider
    assert app.execute("SELECT * FROM provider_secret_for_call(%s)", (p,)).fetchall() == []


def test_only_one_active_credential_per_provider(admin):
    p = mk_provider(admin, "p-one")
    add_cred(admin, p, label="a")
    with pytest.raises(psycopg.errors.UniqueViolation):
        add_cred(admin, p, label="b")
    add_cred(admin, p, label="c", status="revoked")   # revoked ones may pile up (rotation history)


# ---- fallback chain ------------------------------------------------------------------
def test_fallback_cycle_and_self_reference_rejected(admin):
    a = mk_provider(admin, "p-a")
    b = mk_provider(admin, "p-b", fallback=a)
    c = mk_provider(admin, "p-c", fallback=b)
    with pytest.raises(psycopg.errors.CheckViolation):
        admin.execute("UPDATE ai_providers SET fallback_provider_id=%s WHERE id=%s", (c, a))   # a->c->b->a
    with pytest.raises(psycopg.errors.CheckViolation):
        admin.execute("UPDATE ai_providers SET fallback_provider_id=%s WHERE id=%s", (a, a))
    admin.execute("UPDATE ai_providers SET fallback_provider_id=NULL WHERE id=%s", (a,))   # legal


# ---- pricing is insert-only ----------------------------------------------------------
def test_pricing_history_is_append_only(admin, owner):
    p = mk_provider(admin, "p-price")
    m = mk_model(admin, p)
    admin.execute("INSERT INTO model_pricing (model_id,effective_from,input_per_1m,credit_multiplier) VALUES (%s,'2026-01-01',1,1)", (m,))
    admin.execute("INSERT INTO model_pricing (model_id,effective_from,input_per_1m,credit_multiplier) VALUES (%s,'2026-06-01',2,1)", (m,))
    admin.execute("INSERT INTO model_pricing (model_id,effective_from,input_per_1m,credit_multiplier) VALUES (%s,now() + interval '1 day',99,1)", (m,))
    # current = latest not-in-the-future
    assert admin.execute("SELECT input_per_1m FROM model_current_pricing WHERE model_id=%s", (m,)).fetchone()[0] == 2
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        admin.execute("UPDATE model_pricing SET input_per_1m=0")
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        owner.execute("DELETE FROM model_pricing")   # trigger blocks even the owner
    with pytest.raises(psycopg.errors.CheckViolation):
        admin.execute("INSERT INTO model_pricing (model_id,credit_multiplier) VALUES (%s,0)", (m,))


def test_model_with_pricing_cannot_be_deleted(admin):
    p = mk_provider(admin, "p-del")
    m = mk_model(admin, p)
    admin.execute("INSERT INTO model_pricing (model_id) VALUES (%s)", (m,))
    with pytest.raises(psycopg.errors.ForeignKeyViolation):
        admin.execute("DELETE FROM ai_models WHERE id=%s", (m,))
    with pytest.raises(psycopg.errors.ForeignKeyViolation):
        admin.execute("DELETE FROM ai_providers WHERE id=%s", (p,))


# ---- who may use which model ---------------------------------------------------------
def test_available_models_rules(app, admin, owner, world):
    ta = world["a"]["tenant"]
    p = mk_provider(admin, "p-av")
    free_m = mk_model(admin, p, "free-m", free=True)
    paid_m = mk_model(admin, p, "paid-m")
    hidden = mk_model(admin, p, "hidden-m", free=True, status="hidden")
    set_tenant(app, ta)

    # default: only free-tier model, no rule needed; paid is denied (fail closed); hidden never.
    assert avail(app, ta) == {free_m}

    # allow paid for the pro plan only: tenant still on free -> still denied
    admin.execute("INSERT INTO model_access_rules VALUES (%s,%s,true)", (paid_m, plan(admin, "pro")))
    assert avail(app, ta) == {free_m}

    # subscribe to pro -> paid appears
    as_admin(owner)
    owner.execute("INSERT INTO subscriptions (id,tenant_id,plan_id,status) VALUES (%s,%s,%s,'active')", (ulid(), ta, plan(owner, "pro")))
    assert avail(app, ta) == {free_m, paid_m}

    # explicit deny beats is_free_tier
    admin.execute("INSERT INTO model_access_rules VALUES (%s,%s,false)", (free_m, plan(admin, "pro")))
    assert avail(app, ta) == {paid_m}
    assert hidden not in avail(app, ta)


def test_disabled_provider_hides_its_models(app, admin, world):
    ta = world["a"]["tenant"]
    p = mk_provider(admin, "p-off", status="disabled")
    mk_model(admin, p, "x", free=True)
    set_tenant(app, ta)
    assert avail(app, ta) == set()
    admin.execute("UPDATE ai_providers SET status='degraded' WHERE id=%s", (p,))
    assert len(avail(app, ta)) == 1      # degraded still serves (fallback logic decides)


def test_other_tenants_subscription_does_not_leak_into_availability(app, admin, owner, world):
    ta, tb = world["a"]["tenant"], world["b"]["tenant"]
    p = mk_provider(admin, "p-iso")
    paid = mk_model(admin, p, "paid")
    admin.execute("INSERT INTO model_access_rules VALUES (%s,%s,true)", (paid, plan(admin, "pro")))
    as_admin(owner)
    owner.execute("INSERT INTO subscriptions (id,tenant_id,plan_id,status) VALUES (%s,%s,%s,'active')", (ulid(), tb, plan(owner, "pro")))
    set_tenant(app, ta)
    assert paid not in avail(app, ta)     # A is on free although B is on pro
    # asking about B while in A's context must not reveal B's plan (RLS hides B's subscription)
    assert paid not in avail(app, tb)


# ---- tenant preferences (RLS) --------------------------------------------------------
def test_tenant_preferences_are_isolated(app, world):
    ta, tb = world["a"]["tenant"], world["b"]["tenant"]
    set_tenant(app, ta)
    app.execute("INSERT INTO tenant_model_preferences (tenant_id, routing_mode) VALUES (%s,'cheapest')", (ta,))
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("INSERT INTO tenant_model_preferences (tenant_id) VALUES (%s)", (tb,))
    set_tenant(app, tb)
    assert app.execute("SELECT count(*) FROM tenant_model_preferences").fetchone()[0] == 0
    assert app.execute("UPDATE tenant_model_preferences SET routing_mode='fastest' WHERE tenant_id=%s", (ta,)).rowcount == 0


def test_preferences_default_deny_without_context(app, world):
    app.execute("SELECT set_config('app.tenant_id','',false)")
    assert app.execute("SELECT count(*) FROM tenant_model_preferences").fetchone()[0] == 0


# ---- health telemetry ----------------------------------------------------------------
def test_health_checks_insert_only_for_app_and_admin_can_purge(app, admin):
    p = mk_provider(admin, "p-h")
    app.execute("INSERT INTO provider_health_checks (provider_id,status,latency_ms) VALUES (%s,'ok',120)", (p,))
    app.execute("INSERT INTO provider_health_checks (provider_id,status,error_code) VALUES (%s,'failed','timeout')", (p,))
    for sql in ("UPDATE provider_health_checks SET status='ok'", "DELETE FROM provider_health_checks"):
        with pytest.raises(psycopg.errors.InsufficientPrivilege):
            app.execute(sql)
    assert admin.execute("DELETE FROM provider_health_checks WHERE checked_at < now() + interval '1 hour'").rowcount == 2


def test_constraints(admin):
    with pytest.raises(psycopg.errors.CheckViolation):
        mk_provider(admin, "p-bad", adapter="custom_rest")
    p = mk_provider(admin, "p-ok")
    for bad in ("../x", "a b", "", "x;y"):
        with pytest.raises(psycopg.errors.CheckViolation):
            mk_model(admin, p, bad)
    mk_model(admin, p, "meta-llama/llama-3:70b")     # slashes/colons are fine (OpenRouter, Ollama)
    with pytest.raises(psycopg.errors.UniqueViolation):
        mk_model(admin, p, "meta-llama/llama-3:70b")
