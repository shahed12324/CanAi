import random
import string

import psycopg

from conftest import ADMIN_DSN, SQL, _dsn


def test_up_down_up_is_clean():
    name = "jarvis_m_" + "".join(random.choices(string.ascii_lowercase, k=8))
    with psycopg.connect(ADMIN_DSN, autocommit=True) as su:
        su.execute(f"CREATE DATABASE {name} OWNER jarvis_owner")
    try:
        with psycopg.connect(_dsn(ADMIN_DSN, "jarvis_owner", name), autocommit=True) as c:
            for f in (
                "phase2.up.sql", "phase3.up.sql", "phase3.seed.sql", "phase4.up.sql", "phase5.up.sql", "phase6.up.sql", "phase7.up.sql", "phase8.up.sql", "phase8b.up.sql", "phase9a.up.sql", "phase9b.up.sql", "phase9c.up.sql",
                "phase9c.down.sql", "phase9b.down.sql", "phase9a.down.sql", "phase8b.down.sql", "phase8.down.sql", "phase7.down.sql", "phase6.down.sql", "phase5.down.sql", "phase4.down.sql", "phase3.down.sql", "phase2.down.sql",
                "phase2.up.sql", "phase3.up.sql", "phase3.seed.sql", "phase4.up.sql", "phase5.up.sql", "phase6.up.sql", "phase7.up.sql", "phase8.up.sql", "phase8b.up.sql", "phase9a.up.sql", "phase9b.up.sql", "phase9c.up.sql",
                "phase9c.down.sql", "phase9b.down.sql", "phase9a.down.sql", "phase8b.down.sql", "phase8.down.sql", "phase7.down.sql", "phase6.down.sql", "phase5.down.sql", "phase4.down.sql", "phase3.down.sql", "phase2.down.sql",
            ):
                c.execute((SQL / f).read_text())
            left = c.execute(
                "SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace "
                "WHERE n.nspname='public' AND c.relkind IN ('r','S')"
            ).fetchone()[0]
            funcs = c.execute(
                "SELECT count(*) FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace "
                "WHERE n.nspname='public' AND p.prokind='f' AND NOT EXISTS "
                "(SELECT 1 FROM pg_depend d WHERE d.objid=p.oid AND d.deptype='e')"
            ).fetchone()[0]
        assert (left, funcs) == (0, 0)
    finally:
        with psycopg.connect(ADMIN_DSN, autocommit=True) as su:
            su.execute(f"DROP DATABASE {name} WITH (FORCE)")
