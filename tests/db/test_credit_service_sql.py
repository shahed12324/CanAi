"""The SQL strings inside app/Credits/CreditService.php, executed for real.

PHP cannot run in this repo's CI-less environment, so the statements are extracted from the PHP source and run
through psycopg with the same parameter shapes the service passes. If someone edits a statement in the PHP file
(wrong cast, wrong column, wrong argument order) this test fails instead of production.
"""
import json
import re
import uuid
from pathlib import Path

import pytest

from conftest import as_admin, set_tenant

SERVICE = Path(__file__).resolve().parents[2] / "apps/web-overlay/app/Credits/CreditService.php"


def sql_of(marker: str) -> str:
    """The first single/double-quoted PHP string that contains `marker`, with ? turned into %s."""
    src = SERVICE.read_text()
    for m in re.finditer(r"""(?s)(?<![\w$])(?:'((?:[^'\\]|\\.)*)'|"((?:[^"\\]|\\.)*)")""", src):
        s = m.group(1) if m.group(1) is not None else m.group(2)
        if marker in s and s.lstrip().lower().startswith("select"):
            return s.replace("%", "%%").replace("?", "%s")
    raise AssertionError(f"no SQL containing {marker!r} in CreditService.php")


def k() -> str:
    return "svc-" + uuid.uuid4().hex


@pytest.fixture(autouse=True)
def clean(owner, world):
    yield
    as_admin(owner)
    for t in ("credit_transactions", "usage_records"):
        owner.execute(f"ALTER TABLE {t} DISABLE TRIGGER USER")
    for t in ("usage_records", "credit_alerts", "credit_lots", "credit_reservations", "credit_transactions", "wallets"):
        owner.execute(f"DELETE FROM {t}")
    for t in ("credit_transactions", "usage_records"):
        owner.execute(f"ALTER TABLE {t} ENABLE TRIGGER USER")


def test_service_statements_work_end_to_end(app, world):
    tenant = world["a"]["tenant"]
    set_tenant(app, tenant)

    tx, replayed = app.execute(sql_of("credit_grant("), (tenant, "grant_free", "100.0000", "signup:" + tenant, "signup", tenant, None, None, "Welcome credits")).fetchone()
    assert replayed is False
    # same key again -> replay, not a second grant
    tx2, replayed2 = app.execute(sql_of("credit_grant("), (tenant, "grant_free", "100.0000", "signup:" + tenant, "signup", tenant, None, None, "Welcome credits")).fetchone()
    assert (tx2, replayed2) == (tx, True)

    rid, _ = app.execute(sql_of("credit_reserve("), (tenant, "30.0000", k(), "req-1", 900)).fetchone()
    assert len(rid) == 26

    usage = json.dumps({"source": "chat", "request_id": "req-1", "input_tokens": 120, "output_tokens": 80, "department": "সেলস"}, ensure_ascii=False)
    _, charged, shortfall, replayed = app.execute(sql_of("credit_settle("), (rid, "12.5000", k(), usage)).fetchone()
    assert (str(charged), str(shortfall), replayed) == ("12.5000", "0.0000", False)

    w = app.execute(sql_of("from wallets w"), (tenant,)).fetchone()
    balance, reserved, available, usable, thr, lim = w
    assert (str(balance), str(reserved), str(available), str(usable)) == ("87.5000", "0.0000", "87.5000", "87.5000")
    assert thr is None and lim is None

    spent = app.execute(sql_of("from usage_records"), (tenant,)).fetchone()[0]
    assert str(spent) == "12.5000"

    app.execute(sql_of("credit_wallet_configure("), (tenant, "10.0000", "500.0000"))
    w = app.execute(sql_of("from wallets w"), (tenant,)).fetchone()
    assert (str(w[4]), str(w[5])) == ("10.0000", "500.0000")

    rid2, _ = app.execute(sql_of("credit_reserve("), (tenant, "5.0000", k(), None, 900)).fetchone()
    tx3, replayed3 = app.execute(sql_of("credit_release("), (rid2, k())).fetchone()
    assert replayed3 is False and tx3 > 0
    row = app.execute("SELECT department FROM usage_records WHERE tenant_id=%s", (tenant,)).fetchone()
    assert row[0] == "সেলস"


CONTROLLER = SERVICE.parents[1] / "Http/Controllers/CreditController.php"


def controller_sql(marker: str) -> str:
    src = CONTROLLER.read_text()
    for m in re.finditer(r'"((?:[^"\\]|\\.)*)"', src, re.S):
        s = m.group(1)
        if marker in s:
            return s.replace("%", "%%").replace("?", "%s")
    raise AssertionError(marker)


def test_customer_usage_queries_run_and_aggregate(app, world, owner):
    tenant = world["a"]["tenant"]
    set_tenant(app, tenant)
    app.execute(sql_of("credit_grant("), (tenant, "grant_plan", "50.0000", k(), None, None, None, None, None))
    for dept, amt in (("সেলস", "3.0000"), ("সেলস", "2.0000"), (None, "1.0000")):
        rid, _ = app.execute(sql_of("credit_reserve("), (tenant, amt, k(), None, 900)).fetchone()
        app.execute(sql_of("credit_settle("), (rid, amt, k(), json.dumps({"department": dept})))

    group = controller_sql("group by 1 order by sum").replace("{$expr}", "coalesce(u.department, '—')")
    rows = app.execute(group, (tenant, 30)).fetchall()
    assert [(r[0], str(r[1])) for r in rows] == [("সেলস", "5.0000"), ("—", "1.0000")]

    for expr in ("coalesce(m.display_name, '—')", "coalesce(u.agent_id::text, '—')"):
        app.execute(group.replace("coalesce(u.department, '—')", expr), (tenant, 30)).fetchall()

    daily = controller_sql("at time zone")
    rows = app.execute(daily, ("Asia/Dhaka", tenant, 7)).fetchall()
    assert len(rows) == 1 and str(rows[0][1]) == "6.0000"

    total = app.execute("select coalesce(sum(credits_charged), 0) from usage_records where tenant_id = %s and created_at >= now() - make_interval(days => %s)", (tenant, 30)).fetchone()[0]
    assert str(total) == "6.0000"
