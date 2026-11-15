import psycopg
import pytest

from conftest import as_admin, set_tenant, ulid


def make_invite(owner, world, *, email, expires="1 day", tenant="a", role="Manager", accepted=False):
    token = ulid() + ulid() + "ab"  # any hex-like digest is fine for the test
    digest = ("%064x" % int.from_bytes(token.encode(), "big"))[-64:]
    as_admin(owner)
    owner.execute(
        "INSERT INTO invitations (id,tenant_id,email,role,token_hash,invited_by,expires_at,accepted_at) "
        f"VALUES (%s,%s,%s,%s,%s,%s,now()+interval '{expires}',{'now()' if accepted else 'NULL'})",
        (ulid(), world[tenant]["tenant"], email, role, digest, world[tenant]["user"]),
    )
    owner.execute("SELECT set_config('app.bypass_rls','',false)")
    return digest


def make_user(owner, email, *, verified=True, status="active"):
    uid = ulid()
    as_admin(owner)
    owner.execute(
        "INSERT INTO users (id,name,email,password,email_verified_at,status) "
        "VALUES (%s,'n',%s,'x',%s,%s)",
        (uid, email, "2026-01-01" if verified else None, status),
    )
    owner.execute("SELECT set_config('app.bypass_rls','',false)")
    return uid


def accept(app, digest, uid):
    return app.execute("SELECT * FROM invitation_accept(%s,%s)", (digest, uid)).fetchone()


def test_preview_works_without_context_and_returns_only_that_invite(app, owner, world):
    d = make_invite(owner, world, email="x1@e.com")
    rows = app.execute("SELECT * FROM invitation_preview(%s)", (d,)).fetchall()
    assert len(rows) == 1 and rows[0][0] == world["a"]["tenant"] and rows[0][2] == "x1@e.com"
    assert app.execute("SELECT count(*) FROM invitation_preview(%s)", ("0" * 64,)).fetchone()[0] == 0


def test_direct_table_access_still_blocked(app, owner, world):
    make_invite(owner, world, email="x2@e.com")
    assert app.execute("SELECT count(*) FROM invitations").fetchone()[0] == 0


def test_accept_happy_path(app, owner, world):
    email = f"{ulid()}@e.com"
    d = make_invite(owner, world, email=email, role="Manager")
    uid = make_user(owner, email.upper())  # case-insensitive match
    tid, role = accept(app, d, uid)
    assert (tid, role) == (world["a"]["tenant"], "Manager")
    as_admin(owner)
    st = owner.execute(
        "SELECT status FROM tenant_users WHERE tenant_id=%s AND user_id=%s", (tid, uid)
    ).fetchone()
    assert st == ("active",)


def test_bypass_flag_restored_after_accept(app, owner, world):
    email = f"{ulid()}@e.com"
    d = make_invite(owner, world, email=email)
    uid = make_user(owner, email)
    with app.transaction():
        accept(app, d, uid)
        assert app.execute("SELECT current_setting('app.bypass_rls', true)").fetchone()[0] in ("", None)
        assert app.execute("SELECT count(*) FROM tenant_settings").fetchone()[0] == 0
        assert app.execute("SELECT count(*) FROM invitations").fetchone()[0] == 0


def test_cannot_accept_twice(app, owner, world):
    email = f"{ulid()}@e.com"
    d = make_invite(owner, world, email=email)
    uid = make_user(owner, email)
    accept(app, d, uid)
    with pytest.raises(psycopg.errors.RaiseException, match="invitation_used"):
        accept(app, d, uid)


@pytest.mark.parametrize(
    "case,msg",
    [
        ("expired", "invitation_expired"),
        ("mismatch", "invitation_email_mismatch"),
        ("unverified", "user_email_unverified"),
        ("suspended_user", "user_unavailable"),
        ("unknown", "invitation_invalid"),
    ],
)
def test_rejections(app, owner, world, case, msg):
    email = f"{ulid()}@e.com"
    d = make_invite(owner, world, email=email, expires="-1 hour" if case == "expired" else "1 day")
    uemail = f"{ulid()}@e.com" if case == "mismatch" else email
    uid = make_user(owner, uemail, verified=case != "unverified",
                    status="suspended" if case == "suspended_user" else "active")
    if case == "unknown":
        d = "f" * 64
    with pytest.raises(psycopg.errors.RaiseException, match=msg):
        accept(app, d, uid)
    # nothing was granted
    as_admin(owner)
    assert owner.execute("SELECT count(*) FROM tenant_users WHERE user_id=%s", (uid,)).fetchone()[0] == 0


def test_suspended_tenant_cannot_be_joined(app, owner, world):
    email = f"{ulid()}@e.com"
    d = make_invite(owner, world, email=email, tenant="b")
    uid = make_user(owner, email)
    as_admin(owner)
    owner.execute("UPDATE tenants SET status='suspended' WHERE id=%s", (world["b"]["tenant"],))
    owner.execute("SELECT set_config('app.bypass_rls','',false)")
    with pytest.raises(psycopg.errors.RaiseException, match="tenant_unavailable"):
        accept(app, d, uid)
    as_admin(owner)
    owner.execute("UPDATE tenants SET status='active' WHERE id=%s", (world["b"]["tenant"],))


def test_removed_member_reactivated_by_new_invite(app, owner, world):
    email = f"{ulid()}@e.com"
    uid = make_user(owner, email)
    as_admin(owner)
    owner.execute(
        "INSERT INTO tenant_users (id,tenant_id,user_id,status) VALUES (%s,%s,%s,'removed')",
        (ulid(), world["a"]["tenant"], uid),
    )
    owner.execute("SELECT set_config('app.bypass_rls','',false)")
    d = make_invite(owner, world, email=email)
    accept(app, d, uid)
    as_admin(owner)
    assert owner.execute(
        "SELECT status FROM tenant_users WHERE tenant_id=%s AND user_id=%s", (world["a"]["tenant"], uid)
    ).fetchone() == ("active",)


def test_functions_not_callable_by_public(dbname):
    from conftest import ADMIN_DSN, connect

    with psycopg.connect(ADMIN_DSN, autocommit=True) as su:
        su.execute("DO $$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname='jarvis_nobody') THEN CREATE ROLE jarvis_nobody LOGIN; END IF; END $$")
    with connect(dbname, "jarvis_nobody") as c:
        with pytest.raises(psycopg.errors.InsufficientPrivilege):
            c.execute("SELECT * FROM invitation_preview(%s)", ("0" * 64,))
