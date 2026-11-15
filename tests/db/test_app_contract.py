"""Runs, as the restricted app role, the exact SQL shapes the Laravel actions issue
(CreateWorkspace, ManageInvitations, CreateNewUser). Guards against schema/code drift."""

import hashlib

import psycopg
import pytest

from conftest import set_tenant, ulid


def new_user(app, email):
    uid = ulid()
    app.execute("INSERT INTO users (id,name,email,password,locale,timezone) VALUES (%s,'N',%s,'h','bn','Asia/Dhaka')", (uid, email))
    return uid


def create_workspace(app, owner, name, type_):
    tid = ulid()
    with app.transaction():
        app.execute(
            "INSERT INTO tenants (id,name,slug,type,owner_id,locale,timezone) VALUES (%s,%s,%s,%s,%s,'bn','Asia/Dhaka')",
            (tid, name, f"{name.lower()}-{ulid()[:6]}", type_, owner),
        )
        app.execute("INSERT INTO tenant_users (id,tenant_id,user_id,status,joined_at) VALUES (%s,%s,%s,'active',now())", (ulid(), tid, owner))
    return tid


def test_registration_flow_as_app_role(app):
    uid = new_user(app, f"{ulid()}@e.com")
    tid = create_workspace(app, uid, "Personal", "personal")
    assert app.execute("SELECT count(*) FROM tenant_users WHERE tenant_id=%s AND status='active'", (tid,)).fetchone()[0] == 1
    with pytest.raises(psycopg.errors.UniqueViolation, match="tenants_one_personal_per_owner"):
        create_workspace(app, uid, "Personal2", "personal")


def test_invite_replace_and_accept_as_app_role(app):
    owner = new_user(app, f"{ulid()}@e.com")
    tid = create_workspace(app, owner, "Org", "organization")
    guest_email = f"{ulid()}@e.com"
    guest = new_user(app, guest_email)
    app.execute("UPDATE users SET email_verified_at=now() WHERE id=%s", (guest,))
    set_tenant(app, tid)

    def invite(token):
        with app.transaction():
            app.execute("DELETE FROM invitations WHERE tenant_id=%s AND email=%s AND accepted_at IS NULL", (tid, guest_email))
            app.execute(
                "INSERT INTO invitations (id,tenant_id,email,role,token_hash,invited_by,expires_at) "
                "VALUES (%s,%s,%s,'Manager',%s,%s,now()+interval '7 days')",
                (ulid(), tid, guest_email, hashlib.sha256(token.encode()).hexdigest(), owner),
            )

    invite("first")
    invite("second")  # replaces the pending one instead of violating the unique index
    assert app.execute("SELECT count(*) FROM invitations").fetchone()[0] == 1
    app.execute("SELECT set_config('app.tenant_id','',false)")  # accept needs no context

    old = hashlib.sha256(b"first").hexdigest()
    with pytest.raises(psycopg.errors.RaiseException, match="invitation_invalid"):
        app.execute("SELECT * FROM invitation_accept(%s,%s)", (old, guest))
    row = app.execute("SELECT * FROM invitation_accept(%s,%s)", (hashlib.sha256(b"second").hexdigest(), guest)).fetchone()
    assert row == (tid, "Manager")


def test_login_log_and_session_statements_as_app_role(app):
    uid = new_user(app, f"{ulid()}@e.com")
    # LoginLog::record
    app.execute(
        "INSERT INTO login_logs (user_id,email,ip,user_agent,result) VALUES (%s,%s,%s,%s,'success')",
        (uid, "a@e.com", "::1", "UA/1.0"),
    )
    app.execute(
        "INSERT INTO login_logs (user_id,email,ip,user_agent,result) VALUES (NULL,%s,NULL,NULL,'failed')",
        ("nobody@e.com",),
    )
    # last_login_at update (users trigger must not interfere)
    assert app.execute("UPDATE users SET last_login_at=now() WHERE id=%s", (uid,)).rowcount == 1
    # SecurityController: list / delete other sessions
    for sid in ("s1", "s2", "s3"):
        app.execute(
            "INSERT INTO sessions (id,user_id,ip_address,user_agent,payload,last_activity) VALUES (%s,%s,'::1','ua','p',%s)",
            (f"{sid}-{uid}", uid, 1700000000),
        )
    cur = app.execute("DELETE FROM sessions WHERE user_id=%s AND id <> %s", (uid, f"s1-{uid}"))
    assert cur.rowcount == 2
    assert app.execute("SELECT count(*) FROM sessions WHERE user_id=%s", (uid,)).fetchone()[0] == 1
    # logs are append-only for the app role
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("DELETE FROM login_logs WHERE user_id=%s", (uid,))


def test_tables_created_later_by_owner_are_usable_by_app(owner, app):
    """Laravel-created tables (spatie roles, sanctum tokens, ...) must work for the app role."""
    owner.execute("CREATE TABLE zz_later (id bigserial PRIMARY KEY, name text)")
    try:
        app.execute("INSERT INTO zz_later (name) VALUES ('a')")  # needs sequence USAGE too
        assert app.execute("UPDATE zz_later SET name='b'").rowcount == 1
        assert app.execute("SELECT count(*) FROM zz_later").fetchone()[0] == 1
        assert app.execute("DELETE FROM zz_later").rowcount == 1
        with pytest.raises(psycopg.errors.InsufficientPrivilege):
            app.execute("DROP TABLE zz_later")
    finally:
        owner.execute("DROP TABLE zz_later")


def test_default_privileges_did_not_widen_append_only_tables(app, world):
    for stmt in ("UPDATE audit_logs SET ip=NULL", "DELETE FROM audit_logs", "UPDATE login_logs SET ip=NULL"):
        with pytest.raises(psycopg.errors.InsufficientPrivilege):
            app.execute(stmt)
