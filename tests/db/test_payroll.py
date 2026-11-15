import datetime
import json

import psycopg
import pytest
from hypothesis import HealthCheck, given, settings, strategies as st

from conftest import as_admin, ulid
from test_accounting import acc, ctx, seed  # noqa: F401
from test_accounting_docs import setup, wipe_all

TODAY = datetime.date.today()
PERIOD = TODAY.strftime("%Y-%m")
PREV = (TODAY.replace(day=1) - datetime.timedelta(days=1)).strftime("%Y-%m")
TABLES = ("payroll_lines", "payroll_runs", "payroll_components", "payroll_employees")


def wipe(owner):
    as_admin(owner)
    for t in TABLES:
        owner.execute(f"DELETE FROM {t}")
    wipe_all(owner)


@pytest.fixture(autouse=True)
def clean(owner, world):
    yield
    wipe(owner)


def emp(app, world, code="E1", name="করিম", join="2024-01-01", end=None, who="a"):
    ctx(app, world, who)
    eid = ulid()
    r = app.execute("SELECT payroll_employee_save(%s,%s,%s,%s,%s,'ম্যানেজার',%s::date,%s::date)", (eid, world[who]["tenant"], world[who]["user"], code, name, join, end)).fetchone()[0]
    assert r == "ok", r
    return eid


def comp(app, world, e, kind, label, amount, frm="2024-01-01", tax=False, who="a"):
    ctx(app, world, who)
    cid = ulid()
    return cid, app.execute("SELECT payroll_component_save(%s,%s,%s,%s,%s,%s,%s,%s,%s::date)", (cid, world[who]["tenant"], world[who]["user"], e, kind, tax, label, amount, frm)).fetchone()[0]


def standard(app, world, code="E1", basic=3000000, rent=1000000, tax=100000, pf=50000, **kw):
    e = emp(app, world, code=code, **kw)
    assert comp(app, world, e, "earning", "মূল বেতন", basic)[1] == "ok"
    if rent:
        assert comp(app, world, e, "earning", "বাড়ি ভাড়া", rent)[1] == "ok"
    if tax:
        assert comp(app, world, e, "deduction", "আয়কর", tax, tax=True)[1] == "ok"
    if pf:
        assert comp(app, world, e, "deduction", "প্রভিডেন্ট ফান্ড", pf)[1] == "ok"
    return e


def create(app, world, period=PERIOD, who="a"):
    ctx(app, world, who)
    rid = ulid()
    return rid, app.execute("SELECT payroll_run_create(%s,%s,%s,%s)", (rid, world[who]["tenant"], world[who]["user"], period)).fetchone()[0]


def adj(app, world, rid, e, kind, label, amount, tax=False, who="a"):
    ctx(app, world, who)
    return app.execute("SELECT payroll_adjust_add(%s,%s,%s,%s,%s,%s,%s)", (rid, world[who]["tenant"], e, kind, label, amount, tax)).fetchone()[0]


def post(app, world, rid, date=None, who="a"):
    ctx(app, world, who)
    return app.execute("SELECT payroll_run_post(%s,%s,%s,%s,%s::date)", (rid, world[who]["tenant"], world[who]["user"], ulid(), date or TODAY.isoformat())).fetchone()[0]


def payout(app, world, rid, account, date=None, who="a"):
    ctx(app, world, who)
    return app.execute("SELECT payroll_run_pay(%s,%s,%s,%s,%s::date,%s)", (rid, world[who]["tenant"], world[who]["user"], ulid(), date or TODAY.isoformat(), account)).fetchone()[0]


def void(app, world, rid, date=None, who="a"):
    ctx(app, world, who)
    return app.execute("SELECT payroll_run_void(%s,%s,%s,%s,%s::date)", (rid, world[who]["tenant"], world[who]["user"], ulid(), date or TODAY.isoformat())).fetchone()[0]


def run_row(app, rid):
    return app.execute("SELECT status, gross_minor, deduction_minor, tax_minor, net_minor FROM payroll_runs WHERE id=%s", (rid,)).fetchone()


def bal(app, key):
    return app.execute("SELECT coalesce(sum(l.debit_minor - l.credit_minor), 0) FROM journal_lines l JOIN accounts a ON a.id = l.account_id WHERE a.system_key = %s", (key,)).fetchone()[0]


def test_a_month_is_built_from_the_structure(app, world):
    standard(app, world, "E1")                                              # earnings 40,000.00  deductions 1,500.00 (tax 1,000)
    standard(app, world, "E2", basic=2000000, rent=0, tax=0, pf=0)          # rent 0 is refused below, so only basic
    rid, r = create(app, world)
    assert r == "ok"
    assert run_row(app, rid) == ("draft", 4000000 + 2000000, 150000, 100000, 5850000)
    ls = app.execute("SELECT e.code, l.gross_minor, l.deduction_minor, l.tax_minor, l.net_minor, jsonb_array_length(l.items) FROM payroll_lines l JOIN payroll_employees e ON e.id = l.employee_id ORDER BY e.code").fetchall()
    assert ls == [("E1", 4000000, 150000, 100000, 3850000, 4), ("E2", 2000000, 0, 0, 2000000, 1)]
    assert create(app, world)[1] == "exists"
    assert create(app, world, "2099-01")[1] == "bad_period" and create(app, world, "2026-13")[1] == "bad_period" and create(app, world, "x")[1] == "bad_period" and create(app, world, None)[1] == "bad_period"


def test_who_is_in_the_run(app, world):
    standard(app, world, "IN")
    standard(app, world, "FUTURE", join=(TODAY + datetime.timedelta(days=40)).isoformat())          # joins after the month
    standard(app, world, "LEFT", join="2020-01-01", end=(TODAY.replace(day=1) - datetime.timedelta(days=1)).isoformat())   # left before the month
    standard(app, world, "LASTDAY", join="2020-01-01", end=TODAY.replace(day=1).isoformat())      # left on day 1 of the month: still paid this month
    off = standard(app, world, "OFF")
    ctx(app, world, "a")
    assert app.execute("SELECT payroll_employee_active(%s,%s,false)", (off, world["a"]["tenant"])).fetchone()[0] == "ok"
    emp(app, world, "NOPAY")                                                                       # no earning at all
    rid, r = create(app, world)
    assert r == "ok"
    assert sorted(c for (c,) in app.execute("SELECT e.code FROM payroll_lines l JOIN payroll_employees e ON e.id = l.employee_id").fetchall()) == ["IN", "LASTDAY"]


def test_nothing_to_pay_and_over_deducted_leave_no_run(app, world):
    assert create(app, world)[1] == "nothing"
    assert app.execute("SELECT count(*) FROM payroll_runs").fetchone()[0] == 0
    standard(app, world, "BIG", basic=100000, rent=0, tax=0, pf=150000)                          # deductions exceed earnings
    assert create(app, world)[1] == "negative_net"
    assert app.execute("SELECT count(*), (SELECT count(*) FROM payroll_lines) FROM payroll_runs").fetchone() == (0, 0)


def test_deductions_equal_to_earnings_leave_nothing_and_one_more_is_refused(app, world):
    s = setup(app, world)
    standard(app, world, "EQ", basic=100000, rent=0, tax=0, pf=100000)
    rid, r = create(app, world)
    assert r == "ok" and run_row(app, rid) == ("draft", 100000, 100000, 0, 0)
    assert post(app, world, rid) == "ok"                                          # a zero-net month still books (no payable line)
    assert bal(app, "salaries") == 100000 and bal(app, "salary_payable") == 0 and bal(app, "salary_deductions") == -100000
    assert payout(app, world, rid, s["bank"]) == "nothing_to_pay"
    assert run_row(app, rid)[0] == "posted"
    assert void(app, world, rid) == "ok"
    ctx(app, world, "a")
    e2 = standard(app, world, "OVER", basic=100000, rent=0, tax=0, pf=100001)
    assert create(app, world)[1] == "negative_net"


def test_a_raise_keeps_history_and_the_month_uses_the_structure_at_its_end(app, world):
    e = emp(app, world, "E1")
    comp(app, world, e, "earning", "মূল বেতন", 3000000, "2024-01-01")
    first = TODAY.replace(day=1)
    mid = first + datetime.timedelta(days=min(14, (TODAY - first).days))
    cid, r = comp(app, world, e, "earning", "মূল বেতন", 3500000, mid.isoformat())
    assert r == "ok"
    assert [tuple(x) for x in app.execute("SELECT amount_minor, effective_from, effective_to FROM payroll_components ORDER BY effective_from").fetchall()] == [(3000000, datetime.date(2024, 1, 1), mid - datetime.timedelta(days=1)), (3500000, mid, None)]
    assert comp(app, world, e, "earning", "মূল বেতন", 1, mid.isoformat())[1] == "overlap"
    assert comp(app, world, e, "earning", "মূল বেতন", 1, "2020-01-01")[1] == "overlap"
    rid, _ = create(app, world)
    assert run_row(app, rid)[1] == 3500000
    old, _ = create(app, world, PREV)
    assert run_row(app, old)[1] == 3000000


def test_ending_a_component(app, world):
    e = emp(app, world, "E1")
    c1, _ = comp(app, world, e, "earning", "মূল বেতন", 3000000, "2024-01-01")
    c2, _ = comp(app, world, e, "earning", "ভাতা", 500000, "2024-01-01")
    ctx(app, world, "a")
    t = world["a"]["tenant"]
    assert app.execute("SELECT payroll_component_end(%s,%s,'2023-12-31')", (c2, t)).fetchone()[0] == "bad_date"
    assert app.execute("SELECT payroll_component_end(%s,%s,'2025-06-30')", (c2, t)).fetchone()[0] == "ok"
    assert app.execute("SELECT payroll_component_end(%s,%s,'2025-07-31')", (c2, t)).fetchone()[0] == "already_ended"
    rid, _ = create(app, world)
    assert run_row(app, rid)[1] == 3000000                                       # the ended allowance is gone


@pytest.mark.parametrize("kw", [dict(code="bad code"), dict(code=""), dict(code="x" * 21), dict(name="  "), dict(name=None), dict(join="1980-01-01"), dict(join="2024-05-01", end="2024-04-30"), dict(join=None), dict(join=(TODAY + datetime.timedelta(days=400)).isoformat())])
def test_employee_validation(app, world, kw):
    ctx(app, world, "a")
    v = dict(code="E1", name="ক", join="2024-01-01", end=None) | kw
    r = app.execute("SELECT payroll_employee_save(%s,%s,%s,%s,%s,NULL,%s::date,%s::date)", (ulid(), world["a"]["tenant"], world["a"]["user"], v["code"], v["name"], v["join"], v["end"])).fetchone()[0]
    assert r in ("bad_input", "bad_date")
    assert app.execute("SELECT count(*) FROM payroll_employees").fetchone()[0] == 0


def test_employee_codes_are_unique_and_edits_keep_one_row(app, world):
    e = emp(app, world, "E1")
    ctx(app, world, "a")
    t, u = world["a"]["tenant"], world["a"]["user"]
    assert app.execute("SELECT payroll_employee_save(%s,%s,%s,'E1','অন্য',NULL,'2024-01-01',NULL)", (ulid(), t, u)).fetchone()[0] == "code_taken"
    assert app.execute("SELECT payroll_employee_save(%s,%s,%s,'E1','নতুন নাম',' ডেভ ','2024-02-01',NULL)", (e, t, u)).fetchone()[0] == "ok"
    assert app.execute("SELECT count(*), max(name), max(designation) FROM payroll_employees").fetchone() == (1, "নতুন নাম", "ডেভ")


@pytest.mark.parametrize("args", [("pension", False, "x", 100), ("earning", True, "x", 100), ("earning", False, "  ", 100), ("earning", False, "x", 0), ("earning", False, "x", -5), ("earning", False, "x", None), ("deduction", False, "x", 100000000000)])
def test_component_validation(app, world, args):
    e = emp(app, world, "E1")
    kind, tax, label, amount = args
    assert comp(app, world, e, kind, label, amount, tax=tax)[1] == "bad_input"
    assert app.execute("SELECT count(*) FROM payroll_components").fetchone()[0] == 0


def test_bonuses_and_one_off_deductions(app, world):
    e = standard(app, world, "E1")
    rid, _ = create(app, world)
    assert run_row(app, rid)[1:] == (4000000, 150000, 100000, 3850000)
    assert adj(app, world, rid, e, "bonus", "ঈদ বোনাস", 2000000) == "ok"
    assert adj(app, world, rid, e, "deduction", "অগ্রিম ফেরত", 300000) == "ok"
    assert adj(app, world, rid, e, "deduction", "বোনাসের কর", 50000, tax=True) == "ok"
    assert run_row(app, rid)[1:] == (6000000, 500000, 150000, 5500000)
    assert app.execute("SELECT gross_minor, deduction_minor, tax_minor, net_minor FROM payroll_lines").fetchone() == (6000000, 500000, 150000, 5500000)
    assert adj(app, world, rid, e, "deduction", "অতিরিক্ত", 5500001) == "negative_net"
    assert adj(app, world, rid, e, "deduction", "ঠিক শেষ", 5500000) == "ok"                         # net exactly 0 is allowed
    assert run_row(app, rid)[4] == 0
    for bad in [("bonus", "x", 0), ("bonus", "x", -1), ("bonus", " ", 5), ("gift", "x", 5), ("bonus", "x", None)]:
        assert adj(app, world, rid, e, *bad) == "bad_input"
    assert adj(app, world, rid, e, "bonus", "x", 5, tax=True) == "bad_input"
    assert adj(app, world, rid, ulid(), "bonus", "x", 5) == "no_line"


def test_removing_a_one_off_item(app, world):
    e = standard(app, world, "E1")
    rid, _ = create(app, world)
    adj(app, world, rid, e, "bonus", "বোনাস", 1000000)
    items = app.execute("SELECT items FROM payroll_lines").fetchone()[0]
    bonus = next(i for i in items if i["src"] == "bonus")["id"]
    structure = next(i for i in items if i["src"] == "structure" and i["kind"] == "earning")["id"]
    ctx(app, world, "a")
    t = world["a"]["tenant"]
    assert app.execute("SELECT payroll_adjust_remove(%s,%s,%s,%s)", (rid, t, e, structure)).fetchone()[0] == "no_item"          # structure lines are not removable here
    assert app.execute("SELECT payroll_adjust_remove(%s,%s,%s,'nope')", (rid, t, e)).fetchone()[0] == "no_item"
    assert app.execute("SELECT payroll_adjust_remove(%s,%s,%s,%s)", (rid, t, e, bonus)).fetchone()[0] == "ok"
    assert run_row(app, rid)[1:] == (4000000, 150000, 100000, 3850000)
    assert app.execute("SELECT payroll_adjust_remove(%s,%s,%s,%s)", (rid, t, e, bonus)).fetchone()[0] == "no_item"
    # taking away a bonus that a deduction depends on must not leave a negative net
    adj(app, world, rid, e, "bonus", "বোনাস", 1000000)
    adj(app, world, rid, e, "deduction", "বড় কর্তন", 4850000)
    bonus = next(i for i in app.execute("SELECT items FROM payroll_lines").fetchone()[0] if i["src"] == "bonus")["id"]
    ctx(app, world, "a")
    assert app.execute("SELECT payroll_adjust_remove(%s,%s,%s,%s)", (rid, t, e, bonus)).fetchone()[0] == "negative_net"
    assert run_row(app, rid)[1] == 5000000


def test_posting_books_the_month(app, world):
    s = setup(app, world)
    standard(app, world, "E1")
    standard(app, world, "E2", basic=2000000, rent=0, tax=0, pf=100000)
    rid, _ = create(app, world)
    assert post(app, world, rid) == "ok"
    st = run_row(app, rid)
    assert st[0] == "posted" and st[1:] == (6000000, 250000, 100000, 5750000)
    assert bal(app, "salaries") == 6000000                                      # gross expense
    assert bal(app, "salary_payable") == -5750000                               # owed to staff
    assert bal(app, "salary_tax_payable") == -100000
    assert bal(app, "salary_deductions") == -150000
    assert app.execute("SELECT sum(debit_minor) - sum(credit_minor) FROM journal_lines").fetchone()[0] == 0
    assert post(app, world, rid) == "not_draft"
    assert adj(app, world, rid, app.execute("SELECT employee_id FROM payroll_lines LIMIT 1").fetchone()[0], "bonus", "x", 5) == "not_draft"
    e = app.execute("SELECT employee_id FROM payroll_lines LIMIT 1").fetchone()[0]
    ctx(app, world, "a")
    assert app.execute("SELECT payroll_adjust_remove(%s,%s,%s,'x')", (rid, world["a"]["tenant"], e)).fetchone()[0] == "not_draft"
    # the created accounts are real liabilities under 2000, with distinct codes
    rows = app.execute("SELECT system_key, type, code FROM accounts WHERE system_key IN ('salary_tax_payable','salary_deductions') ORDER BY system_key").fetchall()
    assert [r[:2] for r in rows] == [("salary_deductions", "liability"), ("salary_tax_payable", "liability")] and len({r[2] for r in rows}) == 2


def test_a_month_without_tax_or_other_deductions_posts_only_what_it_needs(app, world):
    setup(app, world)
    standard(app, world, "E1", tax=0, pf=0)
    rid, _ = create(app, world)
    assert post(app, world, rid) == "ok"
    assert app.execute("SELECT count(*) FROM accounts WHERE system_key IN ('salary_tax_payable','salary_deductions')").fetchone()[0] == 0
    assert app.execute("SELECT count(*) FROM journal_lines l JOIN journal_entries e ON e.id=l.entry_id WHERE e.source_ref = %s", ("payroll:" + rid,)).fetchone()[0] == 2


def test_posting_refusals(app, world):
    standard(app, world, "E1")
    rid, _ = create(app, world)
    assert post(app, world, rid) == "no_system_account"                          # chart not set up
    assert run_row(app, rid)[0] == "draft"
    s = setup(app, world)
    first = TODAY.replace(day=1)
    assert post(app, world, rid, (TODAY + datetime.timedelta(days=1)).isoformat()) == "bad_date"
    assert post(app, world, rid, (first - datetime.timedelta(days=1)).isoformat()) == "bad_date"
    ctx(app, world, "a")
    assert app.execute("SELECT acct_lock(%s,%s::date,%s)", (world["a"]["tenant"], (TODAY - datetime.timedelta(days=1)).isoformat(), world["a"]["user"])).fetchone()[0] is not None
    if TODAY.day > 1:
        assert post(app, world, rid, first.isoformat()) == "locked"
        assert run_row(app, rid)[0] == "draft" and app.execute("SELECT count(*) FROM journal_entries WHERE source_ref LIKE 'payroll:%'").fetchone()[0] == 0


def test_paying_the_month(app, world):
    s = setup(app, world)
    standard(app, world, "E1")
    rid, _ = create(app, world)
    assert payout(app, world, rid, s["bank"]) == "not_posted"
    post(app, world, rid)
    assert payout(app, world, rid, s["rent"]) == "bad_account"                    # not a cash account
    assert payout(app, world, rid, ulid()) == "bad_account"
    first = TODAY.replace(day=1)
    assert payout(app, world, rid, s["bank"], (TODAY + datetime.timedelta(days=1)).isoformat()) == "bad_date"
    assert payout(app, world, rid, s["bank"]) == "ok"
    assert run_row(app, rid)[0] == "paid"
    assert bal(app, "salary_payable") == 0                                        # nothing owed any more
    assert bal(app, "bank") == -3850000
    assert payout(app, world, rid, s["bank"]) == "not_posted"
    assert void(app, world, rid) == "paid"
    assert run_row(app, rid)[0] == "paid"
    assert app.execute("SELECT pay_account_id FROM payroll_runs").fetchone()[0] == s["bank"]


def test_the_payout_cannot_precede_the_posting(app, world):
    s = setup(app, world)
    standard(app, world, "E1")
    rid, _ = create(app, world)
    first = TODAY.replace(day=1)
    if TODAY.day < 3:
        pytest.skip("needs a posting date a day before today inside the same month")
    post(app, world, rid, TODAY.isoformat())
    assert payout(app, world, rid, s["bank"], (TODAY - datetime.timedelta(days=1)).isoformat()) == "bad_date"


def test_cancelling(app, world):
    s = setup(app, world)
    standard(app, world, "E1")
    d, _ = create(app, world)
    assert void(app, world, d) == "ok"                                            # a draft: no entry
    assert run_row(app, d)[0] == "void" and app.execute("SELECT count(*) FROM journal_entries WHERE source_ref LIKE 'payroll%'").fetchone()[0] == 0
    assert void(app, world, d) == "already_void"
    rid, r = create(app, world)                                                   # the month can be built again
    assert r == "ok"
    post(app, world, rid)
    assert bal(app, "salary_payable") == -3850000
    assert void(app, world, rid) == "ok"                                          # a posted run: reversed
    assert bal(app, "salaries") == 0 and bal(app, "salary_payable") == 0 and bal(app, "salary_tax_payable") == 0 and bal(app, "salary_deductions") == 0
    assert app.execute("SELECT status, void_entry_id IS NOT NULL FROM payroll_runs WHERE id=%s", (rid,)).fetchone() == ("void", True)
    assert payout(app, world, rid, s["bank"]) == "not_posted"
    assert create(app, world)[1] == "ok"


def test_workspaces_are_isolated(app, world):
    s = setup(app, world)
    e = standard(app, world, "E1")
    rid, _ = create(app, world)
    ctx(app, world, "b")
    tb, ub = world["b"]["tenant"], world["b"]["user"]
    for t in TABLES:
        assert app.execute(f"SELECT count(*) FROM {t}").fetchone()[0] == 0
    cases = [("SELECT payroll_employee_save(%s,%s,%s,'Z','ক',NULL,'2024-01-01',NULL)", (e, tb, ub)), ("SELECT payroll_employee_active(%s,%s,false)", (e, tb)),
             ("SELECT payroll_component_save(%s,%s,%s,%s,'earning',false,'x',5,'2024-01-01')", (ulid(), tb, ub, e)),
             ("SELECT payroll_adjust_add(%s,%s,%s,'bonus','x',5,false)", (rid, tb, e)), ("SELECT payroll_adjust_remove(%s,%s,%s,'x')", (rid, tb, e)),
             ("SELECT payroll_run_post(%s,%s,%s,%s,%s::date)", (rid, tb, ub, ulid(), TODAY.isoformat())), ("SELECT payroll_run_pay(%s,%s,%s,%s,%s::date,%s)", (rid, tb, ub, ulid(), TODAY.isoformat(), s["bank"])),
             ("SELECT payroll_run_void(%s,%s,%s,%s,%s::date)", (rid, tb, ub, ulid(), TODAY.isoformat()))]
    for sql, args in cases:
        with pytest.raises(psycopg.errors.InsufficientPrivilege):
            app.execute(sql, args)
        ctx(app, world, "b")
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("SELECT payroll_run_create(%s,%s,%s,%s)", (ulid(), world["a"]["tenant"], ub, PERIOD))
    ctx(app, world, "a")
    assert run_row(app, rid)[0] == "draft"


@pytest.mark.parametrize("sql", ["INSERT INTO payroll_employees (id, tenant_id, code, name, join_date, created_by) VALUES ('x','x','c','n','2024-01-01','x')", "UPDATE payroll_employees SET name = 'z'", "DELETE FROM payroll_employees",
                                 "UPDATE payroll_components SET amount_minor = 1", "DELETE FROM payroll_components", "UPDATE payroll_runs SET status = 'paid'", "DELETE FROM payroll_runs", "UPDATE payroll_lines SET net_minor = 1", "DELETE FROM payroll_lines"])
def test_the_app_role_cannot_write_directly(app, world, sql):
    standard(app, world, "E1")
    create(app, world)
    ctx(app, world, "a")
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute(sql)


def test_table_checks_hold_for_the_owner_too(owner, world):
    as_admin(owner)
    t, u = world["a"]["tenant"], world["a"]["user"]
    e, r = ulid(), ulid()
    owner.execute("INSERT INTO payroll_employees (id, tenant_id, code, name, join_date, created_by) VALUES (%s,%s,'C','n','2024-01-01',%s)", (e, t, u))
    with pytest.raises(psycopg.errors.CheckViolation):
        owner.execute("INSERT INTO payroll_components (id, tenant_id, employee_id, kind, is_tax, label, amount_minor, effective_from, created_by) VALUES (%s,%s,%s,'earning',true,'x',5,'2024-01-01',%s)", (ulid(), t, e, u))
    for status, gross, ded, net in [("draft", 100, 10, 91), ("draft", 100, 200, -100), ("posted", 100, 0, 100), ("paid", 100, 0, 100), ("void", 100, 0, 100)]:
        with pytest.raises((psycopg.errors.CheckViolation, psycopg.errors.NotNullViolation)):
            owner.execute("INSERT INTO payroll_runs (id, tenant_id, period, status, gross_minor, deduction_minor, net_minor, created_by) VALUES (%s,%s,'2026-01',%s,%s,%s,%s,%s)", (ulid(), t, status, gross, ded, net, u))
    owner.execute("INSERT INTO payroll_runs (id, tenant_id, period, created_by) VALUES (%s,%s,'2026-01',%s)", (r, t, u))
    with pytest.raises(psycopg.errors.UniqueViolation):
        owner.execute("INSERT INTO payroll_runs (id, tenant_id, period, created_by) VALUES (%s,%s,'2026-01',%s)", (ulid(), t, u))
    with pytest.raises(psycopg.errors.CheckViolation):
        owner.execute("INSERT INTO payroll_lines (run_id, tenant_id, employee_id, items, gross_minor, deduction_minor, tax_minor, net_minor) VALUES (%s,%s,%s,'[]',100,10,0,91)", (r, t, e))
    with pytest.raises(psycopg.errors.CheckViolation):
        owner.execute("INSERT INTO payroll_lines (run_id, tenant_id, employee_id, items, gross_minor, deduction_minor, tax_minor, net_minor) VALUES (%s,%s,%s,'[]',100,10,20,90)", (r, t, e))


@settings(max_examples=25, deadline=None, suppress_health_check=[HealthCheck.function_scoped_fixture, HealthCheck.too_slow])
@given(staff=st.lists(st.tuples(st.integers(100000, 9000000), st.integers(0, 300000), st.integers(0, 100000), st.lists(st.tuples(st.sampled_from(["bonus", "deduction"]), st.integers(1, 400000), st.booleans()), max_size=3)), min_size=1, max_size=4))
def test_property_lines_runs_and_books_always_agree(app, world, owner, staff):
    try:
        s = setup(app, world)
        ids = []
        for n, (basic, ded, tax, extras) in enumerate(staff):
            e = emp(app, world, f"P{n}")
            comp(app, world, e, "earning", "মূল বেতন", basic)
            if ded:
                comp(app, world, e, "deduction", "কর্তন", ded)
            if tax:
                comp(app, world, e, "deduction", "কর", tax, tax=True)
            ids.append((e, extras))
        rid, r = create(app, world)
        if r != "ok":
            assert r == "negative_net" and any(d + t > b for b, d, t, _ in staff)
            return
        for e, extras in ids:
            for kind, amount, tx in extras:
                adj(app, world, rid, e, kind, "x", amount, tax=tx and kind == "deduction")
        ctx(app, world, "a")
        for items, g, d, x, nn in app.execute("SELECT items, gross_minor, deduction_minor, tax_minor, net_minor FROM payroll_lines").fetchall():
            assert g == sum(i["amount"] for i in items if i["kind"] == "earning")
            assert d == sum(i["amount"] for i in items if i["kind"] == "deduction")
            assert x == sum(i["amount"] for i in items if i["kind"] == "deduction" and i["tax"])
            assert nn == g - d >= 0
        row = run_row(app, rid)
        assert row[1:] == tuple(app.execute("SELECT sum(gross_minor), sum(deduction_minor), sum(tax_minor), sum(net_minor) FROM payroll_lines").fetchone())
        assert post(app, world, rid) == "ok"
        assert bal(app, "salaries") == row[1] and bal(app, "salary_payable") == -row[4] and bal(app, "salary_tax_payable") == -row[3] and bal(app, "salary_deductions") == -(row[2] - row[3])
        assert app.execute("SELECT sum(debit_minor) - sum(credit_minor) FROM journal_lines").fetchone()[0] == 0
        if row[4] > 0:
            assert payout(app, world, rid, s["bank"]) == "ok" and bal(app, "salary_payable") == 0
    finally:
        wipe(owner)
