"""The 13a/13b/13c/14a down scripts really undo their up scripts, and the up scripts can be applied again."""
import random
import string

import psycopg
import pytest

from conftest import ADMIN_DSN, SQL, _exec_file


def test_down_then_up_again(dbname):
    clone = "jarvis_rt_" + "".join(random.choices(string.ascii_lowercase, k=8))
    with psycopg.connect(ADMIN_DSN, autocommit=True) as su:
        su.execute(f"CREATE DATABASE {clone} TEMPLATE {dbname}")
    try:
        dsn = psycopg.conninfo.make_conninfo(ADMIN_DSN, dbname=clone)
        with psycopg.connect(dsn, autocommit=True) as c:
            for n in ("phase37a", "phase36a", "phase35a", "phase34a", "phase33b", "phase33a", "phase31a", "phase30a", "phase29a", "phase28g", "phase28f", "phase28e", "phase28d", "phase32a", "phase32b", "phase32c", "phase26g", "phase26f", "phase26e", "phase26d", "phase26c", "phase26a", "phase28c", "phase28b", "phase28a", "phase27g", "phase27f", "phase27e", "phase27d", "phase27c", "phase27b", "phase27a", "phase22d", "phase22c", "phase21b", "phase192e", "phase243c", "phase243b", "phase242d", "phase242c", "phase242b", "phase242a", "phase241d", "phase241c", "phase241b", "phase241a", "phase232d", "phase232c", "phase232a", "phase23c", "phase23b", "phase23a", "phase22b", "phase22a", "phase192d", "phase21a", "phase192c", "phase192b", "phase192a", "phase19d", "phase19c", "phase19b", "phase19a", "phase18d", "phase18c", "phase18b", "phase18a", "phase17c", "phase17b", "phase17a", "phase16d", "phase16c", "phase16b", "phase16a", "phase15c", "phase15b", "phase15", "phase14c", "phase14b", "phase14", "phase13c", "phase13b", "phase13"):
                c.execute((SQL / f"{n}.down.sql").read_text())
            assert c.execute("SELECT to_regclass('store_orders')").fetchone()[0] is None
            assert c.execute("SELECT count(*) FROM pg_proc WHERE proname LIKE 'order\\_%'").fetchone()[0] == 0
            assert c.execute("SELECT to_regclass('inbox_messages')").fetchone()[0] is None
            assert c.execute("SELECT count(*) FROM pg_proc WHERE proname LIKE 'inbox\\_%'").fetchone()[0] == 0
            for n in ("phase13", "phase13b", "phase13c", "phase14", "phase14b", "phase14c", "phase15", "phase15b", "phase15c", "phase16a", "phase16b", "phase16c", "phase16d", "phase17a", "phase17b", "phase17c", "phase18a", "phase18b", "phase18c", "phase18d", "phase19a", "phase19b", "phase19c", "phase19d", "phase192a", "phase192b", "phase192c", "phase21a", "phase192d", "phase22a", "phase22b", "phase23a", "phase23b", "phase23c", "phase232a", "phase232c", "phase232d", "phase241a", "phase241b", "phase241c", "phase241d", "phase242a", "phase242b", "phase242c", "phase242d", "phase243b", "phase243c", "phase192e", "phase21b", "phase22c", "phase22d", "phase27a", "phase27b", "phase27c", "phase27d", "phase27e", "phase27f", "phase27g", "phase28a", "phase28b", "phase28c", "phase26a", "phase26c", "phase26d", "phase26e", "phase26f", "phase26g", "phase32c", "phase32b", "phase32a", "phase28d", "phase28e", "phase28f", "phase28g", "phase29a", "phase30a", "phase31a", "phase33a", "phase33b", "phase34a", "phase35a", "phase36a", "phase37a"):
                c.execute((SQL / f"{n}.up.sql").read_text())
            assert c.execute("SELECT to_regclass('inbox_agent_drafts')").fetchone()[0] is not None
            assert c.execute("SELECT to_regclass('store_orders')").fetchone()[0] is not None
            assert c.execute("SELECT to_regclass('inbox_order_drafts')").fetchone()[0] is not None
            assert c.execute("SELECT to_regclass('order_shipments')").fetchone()[0] is not None
            assert c.execute("SELECT to_regclass('journal_entries')").fetchone()[0] is not None
            assert c.execute("SELECT count(*) FROM pg_proc WHERE proname LIKE 'acct\\_%' OR proname LIKE 'journal\\_%'").fetchone()[0] >= 9
    finally:
        with psycopg.connect(ADMIN_DSN, autocommit=True) as su:
            su.execute(f"DROP DATABASE IF EXISTS {clone} WITH (FORCE)")
