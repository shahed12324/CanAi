import psycopg
import pytest

from conftest import as_admin, set_tenant, ulid

CATALOG = ["plans", "plan_prices", "features", "plan_features", "feature_flags", "system_settings"]


def plan_id(conn, code):
    as_admin(conn)
    return conn.execute("SELECT id FROM plans WHERE code=%s", (code,)).fetchone()[0]


def eff(conn, tenant, key):
    return conn.execute("SELECT enabled, limit_value, source FROM effective_feature(%s,%s)", (tenant, key)).fetchone()


def subscribe(owner, tenant, code, status="active", period_end="now() + interval '30 days'"):
    as_admin(owner)
    owner.execute(
        "INSERT INTO subscriptions (id,tenant_id,plan_id,status,interval,current_period_start,current_period_end) "
        f"VALUES (%s,%s,%s,%s,'month',now(),{period_end})",
        (ulid(), tenant, plan_id(owner, code), status),
    )
    owner.execute("SELECT set_config('app.bypass_rls','',false)")


@pytest.fixture(autouse=True)
def clean(owner, world):
    yield
    as_admin(owner)
    t = (world["a"]["tenant"], world["b"]["tenant"])
    owner.execute("DELETE FROM subscriptions WHERE tenant_id IN (%s,%s)", t)
    owner.execute("DELETE FROM tenant_feature_overrides WHERE tenant_id IN (%s,%s)", t)
    owner.execute("DELETE FROM feature_flags WHERE key LIKE 'zz.%'")
    owner.execute("DELETE FROM system_settings WHERE key LIKE 'zz.%'")
    owner.execute("DELETE FROM features WHERE key LIKE 'zz.%'")
    owner.execute("DELETE FROM plans WHERE code LIKE 'zz_%'")


# ---- seed ------------------------------------------------------------------------------
def test_seed_catalog_present(app):
    codes = [r[0] for r in app.execute("SELECT code FROM plans ORDER BY sort").fetchall()]
    assert codes == ["free", "pro", "ultra", "business", "enterprise"]
    assert app.execute("SELECT is_lifetime_free FROM plans WHERE code='free'").fetchone()[0] is True
    assert app.execute("SELECT count(*) FROM plan_prices").fetchone()[0] == 0  # prices are the owner's call


def test_seed_is_idempotent(owner):
    from conftest import SQL

    before = [owner.execute(f"SELECT count(*) FROM {t}").fetchone()[0] for t in ("plans", "features", "plan_features", "system_settings")]
    as_admin(owner)
    owner.execute((SQL / "phase3.seed.sql").read_text())
    after = [owner.execute(f"SELECT count(*) FROM {t}").fetchone()[0] for t in ("plans", "features", "plan_features", "system_settings")]
    assert before == after


# ---- privileges ------------------------------------------------------------------------
WRITES = {
    "plans": "UPDATE plans SET name = name",
    "plan_prices": "DELETE FROM plan_prices",
    "features": "UPDATE features SET description = description",
    "plan_features": "UPDATE plan_features SET enabled = enabled",
    "feature_flags": "DELETE FROM feature_flags",
    "system_settings": "UPDATE system_settings SET value = value",
}


@pytest.mark.parametrize("table", CATALOG)
def test_app_role_is_read_only_on_catalog(app, table):
    assert app.execute(f"SELECT count(*) FROM {table}").fetchone()[0] >= 0
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute(WRITES[table])
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute(f"INSERT INTO {table} DEFAULT VALUES")


def test_app_cannot_write_overrides_but_admin_can(app, admin, world):
    set_tenant(app, world["a"]["tenant"])
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute(
            "INSERT INTO tenant_feature_overrides (tenant_id,feature_key,enabled,reason,set_by) VALUES (%s,'module.voice',true,'x',%s)",
            (world["a"]["tenant"], world["a"]["user"]),
        )
    as_admin(admin)
    admin.execute(
        "INSERT INTO tenant_feature_overrides (tenant_id,feature_key,enabled,reason,set_by) VALUES (%s,'module.voice',true,'pilot',%s)",
        (world["a"]["tenant"], world["a"]["user"]),
    )
    assert admin.execute("SELECT count(*) FROM tenant_feature_overrides").fetchone()[0] == 1


def test_admin_group_can_write_catalog(admin):
    as_admin(admin)
    admin.execute("INSERT INTO plans (id,code,name) VALUES (%s,'zz_test','Test')", (ulid(),))
    admin.execute("INSERT INTO features (key,category,type) VALUES ('zz.thing','x','boolean')")
    admin.execute("INSERT INTO feature_flags (key,enabled) VALUES ('zz.flag',true)")
    admin.execute("INSERT INTO system_settings (key,value) VALUES ('zz.set','1')")


# ---- RLS on tenant tables --------------------------------------------------------------
def test_subscriptions_and_overrides_isolated(app, owner, world):
    subscribe(owner, world["a"]["tenant"], "pro")
    subscribe(owner, world["b"]["tenant"], "ultra")
    assert app.execute("SELECT count(*) FROM subscriptions").fetchone()[0] == 0
    set_tenant(app, world["a"]["tenant"])
    rows = app.execute("SELECT tenant_id FROM subscriptions").fetchall()
    assert [r[0] for r in rows] == [world["a"]["tenant"]]


# ---- constraints -----------------------------------------------------------------------
def test_one_live_subscription_per_tenant(owner, world):
    t = world["a"]["tenant"]
    subscribe(owner, t, "pro")
    with pytest.raises(psycopg.errors.UniqueViolation):
        subscribe(owner, t, "ultra", status="trialing")
    as_admin(owner)
    owner.execute("UPDATE subscriptions SET status='cancelled' WHERE tenant_id=%s", (t,))
    subscribe(owner, t, "ultra")  # a new one is fine once the old one is cancelled


def test_feature_in_use_cannot_be_deleted_and_plan_delete_cascades(admin):
    as_admin(admin)
    with pytest.raises(psycopg.errors.ForeignKeyViolation):
        admin.execute("DELETE FROM features WHERE key='module.chat'")
    admin.execute("INSERT INTO plans (id,code,name) VALUES (%s,'zz_tmp','T')", (ulid(),))
    pid = admin.execute("SELECT id FROM plans WHERE code='zz_tmp'").fetchone()[0]
    admin.execute("INSERT INTO plan_features (plan_id,feature_key) VALUES (%s,'module.chat')", (pid,))
    admin.execute("DELETE FROM plans WHERE id=%s", (pid,))
    assert admin.execute("SELECT count(*) FROM plan_features WHERE plan_id=%s", (pid,)).fetchone()[0] == 0


@pytest.mark.parametrize(
    "sql",
    [
        "INSERT INTO features (key,category,type) VALUES ('Bad Key','x','boolean')",
        "INSERT INTO features (key,category,type) VALUES ('nodot','x','boolean')",
        "INSERT INTO features (key,category,type) VALUES ('zz.a','x','weird')",
        "INSERT INTO plans (id,code,name) VALUES ('%s','Bad-Code','x')",
        "INSERT INTO plans (id,code,name,status) VALUES ('%s','zz_s','x','nope')",
        "INSERT INTO plan_features (plan_id,feature_key,limit_value) SELECT id,'module.chat',-1 FROM plans LIMIT 1",
        "INSERT INTO feature_flags (key) VALUES ('Bad Flag')",
        "INSERT INTO system_settings (key,value) VALUES ('UPPER','1')",
    ],
)
def test_catalog_check_constraints(admin, sql):
    as_admin(admin)
    with pytest.raises(psycopg.errors.CheckViolation):
        admin.execute(sql % ulid() if "%s" in sql else sql)


def test_override_requires_reason(admin, world):
    as_admin(admin)
    with pytest.raises(psycopg.errors.CheckViolation):
        admin.execute(
            "INSERT INTO tenant_feature_overrides (tenant_id,feature_key,enabled,reason,set_by) VALUES (%s,'module.voice',true,'   ',%s)",
            (world["a"]["tenant"], world["a"]["user"]),
        )


# ---- effective_feature -----------------------------------------------------------------
def test_free_plan_is_the_default(app, world):
    t = world["a"]["tenant"]
    set_tenant(app, t)
    assert eff(app, t, "module.chat") == (True, None, "plan")
    assert eff(app, t, "limit.members") == (True, 3, "plan")
    assert eff(app, t, "module.accounting") == (False, 0, "none")  # not on free: fail closed
    assert eff(app, t, "does.not_exist") == (False, 0, "none")


def test_subscription_changes_entitlement(app, owner, world):
    t = world["a"]["tenant"]
    subscribe(owner, t, "pro")
    set_tenant(app, t)
    assert eff(app, t, "module.lead_extractor")[0] is True
    assert eff(app, t, "limit.members")[1] == 10
    assert eff(app, t, "module.accounting")[0] is False


def test_enterprise_limits_are_unlimited(app, owner, world):
    t = world["a"]["tenant"]
    subscribe(owner, t, "enterprise")
    set_tenant(app, t)
    assert eff(app, t, "limit.members") == (True, None, "plan")


def test_override_beats_plan_in_both_directions(app, admin, owner, world):
    t = world["a"]["tenant"]
    subscribe(owner, t, "pro")
    as_admin(admin)
    for key, enabled, limit in (("limit.members", True, 50), ("module.workflows", False, None), ("module.voice", True, None)):
        admin.execute(
            "INSERT INTO tenant_feature_overrides (tenant_id,feature_key,enabled,limit_value,reason,set_by) VALUES (%s,%s,%s,%s,'support',%s)",
            (t, key, enabled, limit, world["a"]["user"]),
        )
    set_tenant(app, t)
    assert eff(app, t, "limit.members") == (True, 50, "override")
    assert eff(app, t, "module.workflows") == (False, None, "override")
    assert eff(app, t, "module.voice") == (True, None, "override")


@pytest.mark.parametrize(
    "status,period_end,expected_plan_limit",
    [
        ("active", "now() + interval '1 day'", 10),
        ("trialing", "now() + interval '1 day'", 10),
        ("past_due", "now() - interval '1 day'", 10),   # keeps access while payment is being retried
        ("grace", "now() - interval '1 day'", 10),
        ("active", "now() - interval '1 day'", 10),     # Phase 10: just ended -> still inside the 7-day grace window
        ("trialing", "now() - interval '1 day'", 10),
        ("active", "now() - interval '30 days'", 3),    # period ended long ago and not renewed -> free
        ("trialing", "now() - interval '30 days'", 3),
        ("cancelled", "now() + interval '1 day'", 3),
        ("expired", "now() + interval '1 day'", 3),
    ],
)
def test_subscription_status_rules(app, owner, world, status, period_end, expected_plan_limit):
    t = world["a"]["tenant"]
    subscribe(owner, t, "pro", status=status, period_end=period_end)
    set_tenant(app, t)
    assert eff(app, t, "limit.members")[1] == expected_plan_limit


def test_cannot_read_another_tenants_entitlements(app, admin, owner, world):
    a, b = world["a"]["tenant"], world["b"]["tenant"]
    subscribe(owner, b, "enterprise")
    as_admin(admin)
    admin.execute(
        "INSERT INTO tenant_feature_overrides (tenant_id,feature_key,enabled,limit_value,reason,set_by) VALUES (%s,'limit.members',true,999,'vip',%s)",
        (b, world["b"]["user"]),
    )
    set_tenant(app, a)
    # Asking about B while in A's context must reveal nothing about B: you get the free-plan answer.
    assert eff(app, b, "limit.members") == (True, 3, "plan")


def test_admin_context_can_compute_for_any_tenant(admin, owner, world):
    subscribe(owner, world["b"]["tenant"], "business")
    as_admin(admin)
    assert eff(admin, world["b"]["tenant"], "module.desktop")[0] is True
