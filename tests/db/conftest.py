"""DB-level tests. Need a PostgreSQL superuser DSN in JARVIS_TEST_PG_ADMIN_DSN, e.g.
postgresql://postgres@127.0.0.1:5432/postgres . The suite creates and drops its own database."""

import os
import random
import string
from pathlib import Path

import psycopg
import pytest

SQL = Path(__file__).parents[2] / "apps" / "web-overlay" / "database" / "sql"
ADMIN_DSN = os.environ.get("JARVIS_TEST_PG_ADMIN_DSN")

B32 = "0123456789abcdefghjkmnpqrstvwxyz"


def ulid() -> str:
    return "".join(random.choices(B32, k=26))


def _dsn(base: str, user: str, db: str) -> str:
    p = psycopg.conninfo.conninfo_to_dict(base)
    p.update(user=user, dbname=db)
    return psycopg.conninfo.make_conninfo(**p)


def pytest_collection_modifyitems(config, items):
    if not ADMIN_DSN:
        skip = pytest.mark.skip(reason="JARVIS_TEST_PG_ADMIN_DSN not set")
        for i in items:
            i.add_marker(skip)


def _exec_file(conn, name):
    conn.execute((SQL / name).read_text())


@pytest.fixture(scope="session")
def dbname():
    name = "jarvis_t_" + "".join(random.choices(string.ascii_lowercase, k=8))
    with psycopg.connect(ADMIN_DSN, autocommit=True) as su:
        for role, opts in [
            ("jarvis_owner", "LOGIN"),
            ("jarvis_app", "LOGIN"),
            ("jarvis_admin_user", "LOGIN"),
        ]:
            su.execute(
                f"DO $$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname='{role}') "
                f"THEN CREATE ROLE {role} {opts}; END IF; END $$"
            )
        su.execute((SQL / "roles.sql").read_text())
        su.execute("GRANT jarvis_admin TO jarvis_owner")
        su.execute("GRANT jarvis_app_rw TO jarvis_app")
        su.execute("GRANT jarvis_app_rw, jarvis_admin TO jarvis_admin_user")
        su.execute(f"CREATE DATABASE {name} OWNER jarvis_owner")
    with psycopg.connect(_dsn(ADMIN_DSN, "jarvis_owner", name), autocommit=True) as c:
        _exec_file(c, "phase2.up.sql")
        _exec_file(c, "phase3.up.sql")
        _exec_file(c, "phase3.seed.sql")
        _exec_file(c, "phase4.up.sql")
        _exec_file(c, "phase5.up.sql")
        _exec_file(c, "phase6.up.sql")
        _exec_file(c, "phase7.up.sql")
        _exec_file(c, "phase8.up.sql")
        _exec_file(c, "phase8b.up.sql")
        _exec_file(c, "phase9a.up.sql")
        _exec_file(c, "phase9b.up.sql")
        _exec_file(c, "phase9c.up.sql")
        _exec_file(c, "phase10.up.sql")
        _exec_file(c, "phase11.up.sql")
        _exec_file(c, "phase12.up.sql")
        _exec_file(c, "phase12b.up.sql")
        _exec_file(c, "phase13.up.sql")
        _exec_file(c, "phase13b.up.sql")
        _exec_file(c, "phase13c.up.sql")
        _exec_file(c, "phase14.up.sql")
        _exec_file(c, "phase14b.up.sql")
        _exec_file(c, "phase14c.up.sql")
        _exec_file(c, "phase15.up.sql")
        _exec_file(c, "phase15b.up.sql")
        _exec_file(c, "phase15c.up.sql")
        _exec_file(c, "phase16a.up.sql")
        _exec_file(c, "phase16b.up.sql")
        _exec_file(c, "phase16c.up.sql")
        _exec_file(c, "phase16d.up.sql")
        _exec_file(c, "phase17a.up.sql")
        _exec_file(c, "phase17b.up.sql")
        _exec_file(c, "phase17c.up.sql")
        _exec_file(c, "phase18a.up.sql")
        _exec_file(c, "phase18b.up.sql")
        _exec_file(c, "phase18c.up.sql")
        _exec_file(c, "phase18d.up.sql")
        _exec_file(c, "phase19a.up.sql")
        _exec_file(c, "phase19b.up.sql")
        _exec_file(c, "phase19c.up.sql")
        _exec_file(c, "phase19d.up.sql")
        _exec_file(c, "phase192a.up.sql")
        _exec_file(c, "phase192b.up.sql")
        _exec_file(c, "phase192c.up.sql")
        _exec_file(c, "phase21a.up.sql")
        _exec_file(c, "phase192d.up.sql")
        _exec_file(c, "phase22a.up.sql")
        _exec_file(c, "phase22b.up.sql")
        _exec_file(c, "phase23a.up.sql")
        _exec_file(c, "phase23b.up.sql")
        _exec_file(c, "phase23c.up.sql")
        _exec_file(c, "phase232a.up.sql")
        _exec_file(c, "phase232c.up.sql")
        _exec_file(c, "phase232d.up.sql")
        _exec_file(c, "phase241a.up.sql")
        _exec_file(c, "phase241b.up.sql")
        _exec_file(c, "phase241c.up.sql")
        _exec_file(c, "phase241d.up.sql")
        _exec_file(c, "phase242a.up.sql")
        _exec_file(c, "phase242b.up.sql")
        _exec_file(c, "phase242c.up.sql")
        _exec_file(c, "phase242d.up.sql")
        _exec_file(c, "phase243b.up.sql")
        _exec_file(c, "phase243c.up.sql")
        _exec_file(c, "phase192e.up.sql")
        _exec_file(c, "phase21b.up.sql")
        _exec_file(c, "phase22c.up.sql")
        _exec_file(c, "phase22d.up.sql")
        _exec_file(c, "phase27a.up.sql")
        _exec_file(c, "phase27b.up.sql")
        _exec_file(c, "phase27c.up.sql")
        _exec_file(c, "phase27d.up.sql")
        _exec_file(c, "phase27e.up.sql")
        _exec_file(c, "phase27f.up.sql")
        _exec_file(c, "phase27g.up.sql")
        _exec_file(c, "phase28a.up.sql")
        _exec_file(c, "phase28b.up.sql")
        _exec_file(c, "phase28c.up.sql")
        _exec_file(c, "phase26a.up.sql")
        _exec_file(c, "phase26c.up.sql")
        _exec_file(c, "phase26d.up.sql")
        _exec_file(c, "phase26e.up.sql")
        _exec_file(c, "phase26f.up.sql")
        _exec_file(c, "phase26g.up.sql")
        _exec_file(c, "phase32a.up.sql")
        _exec_file(c, "phase32b.up.sql")
        _exec_file(c, "phase32c.up.sql")
        _exec_file(c, "phase28d.up.sql")
        _exec_file(c, "phase28e.up.sql")
        _exec_file(c, "phase28f.up.sql")
        _exec_file(c, "phase28g.up.sql")
        _exec_file(c, "phase33a.up.sql")
        _exec_file(c, "phase33b.up.sql")
        _exec_file(c, "phase34a.up.sql")
        _exec_file(c, "phase35a.up.sql")
        _exec_file(c, "phase36a.up.sql")
        _exec_file(c, "phase37a.up.sql")
    yield name
    with psycopg.connect(ADMIN_DSN, autocommit=True) as su:
        su.execute(f"DROP DATABASE {name} WITH (FORCE)")


def connect(dbname: str, user: str) -> psycopg.Connection:
    return psycopg.connect(_dsn(ADMIN_DSN, user, dbname), autocommit=True)


@pytest.fixture
def owner(dbname):
    with connect(dbname, "jarvis_owner") as c:
        yield c


@pytest.fixture
def app(dbname):
    with connect(dbname, "jarvis_app") as c:
        yield c


@pytest.fixture
def admin(dbname):
    with connect(dbname, "jarvis_admin_user") as c:
        yield c


def as_admin(conn):
    conn.execute("SELECT set_config('app.bypass_rls','on',false)")


def set_tenant(conn, tenant_id, *, local=False):
    conn.execute("SELECT set_config('app.tenant_id', %s, %s)", (tenant_id, local))


@pytest.fixture
def world(owner):
    """Two tenants A and B, each with an owner, settings, invitation and audit row."""
    as_admin(owner)
    w = {}
    for k in ("a", "b"):
        uid, tid = ulid(), ulid()
        owner.execute(
            "INSERT INTO users (id,name,email,password,email_verified_at) VALUES (%s,%s,%s,'x',now())",
            (uid, f"user {k}", f"{k}-{uid}@example.com"),
        )
        owner.execute(
            "INSERT INTO tenants (id,name,slug,type,owner_id) VALUES (%s,%s,%s,'organization',%s)",
            (tid, f"Tenant {k}", f"slug-{tid}", uid),
        )
        owner.execute(
            "INSERT INTO tenant_users (id,tenant_id,user_id,joined_at) VALUES (%s,%s,%s,now())",
            (ulid(), tid, uid),
        )
        owner.execute(
            "INSERT INTO tenant_settings (tenant_id,key,value) VALUES (%s,'brand','{\"n\":1}')", (tid,)
        )
        owner.execute(
            "INSERT INTO invitations (id,tenant_id,email,role,token_hash,invited_by,expires_at) "
            "VALUES (%s,%s,%s,'Member',%s,%s,now()+interval '1 day')",
            (ulid(), tid, f"inv-{tid}@example.com", "".join(random.choices("0123456789abcdef", k=64)), uid),
        )
        owner.execute(
            "INSERT INTO audit_logs (tenant_id,action) VALUES (%s,%s)", (tid, f"{k}.created")
        )
        w[k] = {"user": uid, "tenant": tid}
    owner.execute("SELECT set_config('app.bypass_rls','',false)")
    yield w
    as_admin(owner)
    # audit_logs is append-only by design; clean by dropping the tenants' other data only.
    for t in ("invitations", "tenant_settings", "tenant_users"):
        owner.execute(f"DELETE FROM {t} WHERE tenant_id IN (%s,%s)", (w["a"]["tenant"], w["b"]["tenant"]))
