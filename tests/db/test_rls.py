import psycopg
import pytest

from conftest import as_admin, set_tenant, ulid

TENANT_TABLES = ["tenant_settings", "invitations"]


def count(conn, table):
    return conn.execute(f"SELECT count(*) FROM {table}").fetchone()[0]


# ---- the application role itself must be harmless --------------------------------
def test_app_role_cannot_bypass(app):
    sup, byp = app.execute(
        "SELECT rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user"
    ).fetchone()
    assert (sup, byp) == (False, False)


def test_default_deny_without_context(app, world):
    for t in TENANT_TABLES + ["audit_logs"]:
        assert count(app, t) == 0, t


@pytest.mark.parametrize("table", TENANT_TABLES)
def test_context_sees_only_own_rows(app, world, table):
    set_tenant(app, world["a"]["tenant"])
    rows = app.execute(f"SELECT tenant_id FROM {table}").fetchall()
    assert rows and {r[0] for r in rows} == {world["a"]["tenant"]}
    set_tenant(app, world["b"]["tenant"])
    assert {r[0] for r in app.execute(f"SELECT tenant_id FROM {table}").fetchall()} == {
        world["b"]["tenant"]
    }


def test_audit_logs_scoped(app, world):
    set_tenant(app, world["a"]["tenant"])
    assert [r[0] for r in app.execute("SELECT action FROM audit_logs").fetchall()] == ["a.created"]


def test_cannot_read_by_guessing_other_tenant_row(app, world):
    set_tenant(app, world["a"]["tenant"])
    n = app.execute(
        "SELECT count(*) FROM tenant_settings WHERE tenant_id = %s", (world["b"]["tenant"],)
    ).fetchone()[0]
    assert n == 0


def test_update_and_delete_other_tenant_affect_zero_rows(app, world):
    set_tenant(app, world["a"]["tenant"])
    cur = app.execute("UPDATE tenant_settings SET value='{}' WHERE tenant_id=%s", (world["b"]["tenant"],))
    assert cur.rowcount == 0
    cur = app.execute("DELETE FROM tenant_settings WHERE tenant_id=%s", (world["b"]["tenant"],))
    assert cur.rowcount == 0
    cur = app.execute("DELETE FROM invitations")  # no WHERE: only own rows
    assert cur.rowcount == 1
    set_tenant(app, world["b"]["tenant"])
    assert count(app, "invitations") == 1


def test_insert_for_other_tenant_rejected(app, world):
    set_tenant(app, world["a"]["tenant"])
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute(
            "INSERT INTO tenant_settings (tenant_id,key,value) VALUES (%s,'x','1')",
            (world["b"]["tenant"],),
        )


def test_moving_row_to_other_tenant_rejected(app, world):
    set_tenant(app, world["a"]["tenant"])
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("UPDATE tenant_settings SET tenant_id=%s", (world["b"]["tenant"],))


def test_insert_without_context_rejected(app, world):
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute(
            "INSERT INTO tenant_settings (tenant_id,key,value) VALUES (%s,'x','1')",
            (world["a"]["tenant"],),
        )


@pytest.mark.parametrize(
    "bad",
    [
        "",
        "   ",
        "not-a-tenant",
        "' OR '1'='1",
        "%",
        None,
    ],
)
def test_malformed_context_denies(app, world, bad):
    app.execute("SELECT set_config('app.tenant_id', %s, false)", (bad,))
    assert count(app, "tenant_settings") == 0


def test_overlong_value_is_not_truncated_to_match(app, world):
    """char(26) casts truncate silently; a 30-char value starting with a real id must NOT match."""
    app.execute("SELECT set_config('app.tenant_id', %s, false)", (world["a"]["tenant"] + "zzzz",))
    assert count(app, "tenant_settings") == 0


def test_set_local_does_not_leak_after_commit(app, world):
    with app.transaction():
        set_tenant(app, world["a"]["tenant"], local=True)
        assert count(app, "tenant_settings") == 1
    assert count(app, "tenant_settings") == 0


def test_rollback_does_not_leak_context(app, world):
    with pytest.raises(RuntimeError):
        with app.transaction():
            set_tenant(app, world["a"]["tenant"], local=True)
            raise RuntimeError
    assert count(app, "tenant_settings") == 0


# ---- admin bypass needs BOTH the setting and DB membership -------------------------
def test_app_role_cannot_self_grant_bypass(app, world):
    as_admin(app)  # sets app.bypass_rls = on
    assert count(app, "tenant_settings") == 0
    assert count(app, "audit_logs") == 0


def test_admin_role_without_setting_sees_nothing(admin, world):
    assert count(admin, "tenant_settings") == 0


def test_admin_role_with_setting_sees_all(admin, world):
    as_admin(admin)
    assert count(admin, "tenant_settings") >= 2
    assert count(admin, "audit_logs") >= 2


def test_owner_is_subject_to_force_rls(owner, world):
    assert count(owner, "tenant_settings") == 0


# ---- audit logs / append-only ------------------------------------------------------
def test_platform_audit_rows_hidden_from_tenants_but_appendable(app, world):
    app.execute("INSERT INTO audit_logs (tenant_id, action) VALUES (NULL, 'platform.event')")
    set_tenant(app, world["a"]["tenant"])
    assert "platform.event" not in [r[0] for r in app.execute("SELECT action FROM audit_logs").fetchall()]


def test_cannot_append_audit_for_other_tenant(app, world):
    set_tenant(app, world["a"]["tenant"])
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("INSERT INTO audit_logs (tenant_id, action) VALUES (%s,'forged')", (world["b"]["tenant"],))


@pytest.mark.parametrize("table", ["audit_logs", "login_logs"])
@pytest.mark.parametrize("who", ["app", "owner", "admin"])
def test_append_only_for_everyone(request, world, table, who):
    """No role can alter history: either the statement is refused or it touches zero rows."""
    conn = request.getfixturevalue(who)
    as_admin(conn)
    if table == "audit_logs":
        conn.execute("INSERT INTO audit_logs (tenant_id, action) VALUES (NULL, 'x')")
    else:
        conn.execute("INSERT INTO login_logs (result) VALUES ('success')")
    before = conn.execute(f"SELECT count(*) FROM {table}").fetchone()[0]
    for stmt in (f"UPDATE {table} SET ip = NULL", f"DELETE FROM {table}"):
        try:
            assert conn.execute(stmt).rowcount == 0
        except psycopg.errors.InsufficientPrivilege:
            pass
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        conn.execute(f"TRUNCATE {table}")
    assert conn.execute(f"SELECT count(*) FROM {table}").fetchone()[0] == before


@pytest.mark.parametrize("table", ["audit_logs", "login_logs"])
def test_triggers_block_even_when_rls_is_bypassed(dbname, table):
    """Defense in depth: a superuser (RLS-exempt) is still stopped by the triggers."""
    from conftest import ADMIN_DSN, _dsn

    with psycopg.connect(_dsn(ADMIN_DSN, "postgres", dbname), autocommit=True) as su:
        if table == "audit_logs":
            su.execute("INSERT INTO audit_logs (tenant_id, action) VALUES (NULL, 'su')")
        else:
            su.execute("INSERT INTO login_logs (result) VALUES ('success')")
        for stmt in (f"UPDATE {table} SET ip = NULL", f"DELETE FROM {table}", f"TRUNCATE {table}"):
            with pytest.raises(psycopg.errors.InsufficientPrivilege):
                su.execute(stmt)


# ---- privilege escalation guard ----------------------------------------------------
def test_app_cannot_create_or_promote_platform_admin(app, owner):
    uid = ulid()
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute(
            "INSERT INTO users (id,name,email,password,is_platform_admin) VALUES (%s,'x',%s,'x',true)",
            (uid, f"{uid}@e.com"),
        )
    app.execute("INSERT INTO users (id,name,email,password) VALUES (%s,'x',%s,'x')", (uid, f"{uid}@e.com"))
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("UPDATE users SET is_platform_admin = true WHERE id=%s", (uid,))
    # ordinary updates are fine
    assert app.execute("UPDATE users SET name='y' WHERE id=%s", (uid,)).rowcount == 1


def test_admin_context_can_promote(admin):
    as_admin(admin)
    uid = ulid()
    admin.execute(
        "INSERT INTO users (id,name,email,password,is_platform_admin) VALUES (%s,'root',%s,'x',true)",
        (uid, f"{uid}@e.com"),
    )
    assert admin.execute("SELECT is_platform_admin FROM users WHERE id=%s", (uid,)).fetchone()[0]


# ---- constraints --------------------------------------------------------------------
def test_email_unique_case_insensitive_but_reusable_after_soft_delete(owner):
    as_admin(owner)
    u1, u2, u3 = ulid(), ulid(), ulid()
    owner.execute("INSERT INTO users (id,name,email,password) VALUES (%s,'a','Dup@Example.com','x')", (u1,))
    with pytest.raises(psycopg.errors.UniqueViolation):
        owner.execute("INSERT INTO users (id,name,email,password) VALUES (%s,'b','dup@example.com','x')", (u2,))
    owner.execute("UPDATE users SET deleted_at=now() WHERE id=%s", (u1,))
    owner.execute("INSERT INTO users (id,name,email,password) VALUES (%s,'c','dup@example.com','x')", (u3,))


def test_slug_unique_case_insensitive_and_one_personal_tenant(owner, world):
    as_admin(owner)
    with pytest.raises(psycopg.errors.UniqueViolation):
        owner.execute(
            "INSERT INTO tenants (id,name,slug,type,owner_id) VALUES (%s,'x',%s,'organization',%s)",
            (ulid(), ("SLUG-" + world["a"]["tenant"]).upper(), world["a"]["user"]),
        )
    owner.execute(
        "INSERT INTO tenants (id,name,slug,type,owner_id) VALUES (%s,'p',%s,'personal',%s)",
        (ulid(), "p-" + ulid(), world["a"]["user"]),
    )
    with pytest.raises(psycopg.errors.UniqueViolation):
        owner.execute(
            "INSERT INTO tenants (id,name,slug,type,owner_id) VALUES (%s,'p2',%s,'personal',%s)",
            (ulid(), "p-" + ulid(), world["a"]["user"]),
        )


def test_check_constraints(owner, world):
    as_admin(owner)
    with pytest.raises(psycopg.errors.CheckViolation):
        owner.execute("UPDATE tenants SET status='weird' WHERE id=%s", (world["a"]["tenant"],))
    with pytest.raises(psycopg.errors.CheckViolation):
        owner.execute(
            "INSERT INTO invitations (id,tenant_id,email,role,token_hash,invited_by,expires_at) "
            "VALUES (%s,%s,'z@e.com','Member','NOT-HEX',%s,now())",
            (ulid(), world["a"]["tenant"], world["a"]["user"]),
        )


def test_app_has_no_ddl(app):
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("DROP TABLE tenant_settings")
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("ALTER TABLE tenant_settings DISABLE ROW LEVEL SECURITY")


RLS_TABLES = ["tenant_settings", "invitations", "audit_logs", "subscriptions", "tenant_feature_overrides"]


@pytest.mark.parametrize("table", RLS_TABLES)
def test_every_rls_table_is_enabled_and_forced(owner, table):
    """Catalog check, so a future tenant table cannot silently ship without enforced RLS."""
    row = owner.execute(
        "SELECT relrowsecurity, relforcerowsecurity FROM pg_class WHERE oid = to_regclass(%s)", (table,)
    ).fetchone()
    assert row == (True, True), table


def test_all_tables_with_tenant_id_have_rls(owner):
    """Any table that has a tenant_id column must be RLS-protected, except the global tenant registry tables."""
    allowed_global = {"tenant_users"}  # resolved before a tenant context exists; scoped by user membership
    rows = owner.execute(
        """SELECT c.relname, c.relrowsecurity AND c.relforcerowsecurity
           FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
           JOIN pg_attribute a ON a.attrelid = c.oid AND a.attname = 'tenant_id' AND NOT a.attisdropped
           WHERE n.nspname = 'public' AND c.relkind = 'r'"""
    ).fetchall()
    assert rows
    unprotected = {name for name, ok in rows if not ok} - allowed_global
    assert unprotected == set()
