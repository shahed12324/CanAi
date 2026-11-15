import datetime
import json

import psycopg
import pytest
from hypothesis import HealthCheck, given, settings, strategies as st

from conftest import as_admin, ulid
from test_accounting import acc, ctx, seed  # noqa: F401
from test_accounting_docs import D, line, make, party, setup, wipe_all

TABLES = ("tax_withholdings", "tax_deposits", "tax_advances", "tax_rules")
TODAY = datetime.date.today().isoformat()


def wipe(owner):
    as_admin(owner)
    for t in TABLES:
        owner.execute(f"DELETE FROM {t}")
    wipe_all(owner)


@pytest.fixture(autouse=True)
def clean(owner, world):
    yield
    wipe(owner)


def item(kind="vds", amount=1500, base=10000, bp=1500, code="STD", cert=None):
    return {"kind": kind, "rule_code": code, "base_minor": base, "rate_bp": bp, "amount_minor": amount, "cert_no": cert}


def settle(app, world, doc, gross, account, items, *, date=D, who="a"):
    pid = ulid()
    ctx(app, world, who)
    r = app.execute("SELECT tax_settle(%s,%s,%s,%s,%s::date,%s,%s,%s,%s::jsonb)", (pid, ulid(), doc, world[who]["user"], date, gross, account, "note", json.dumps(items))).fetchone()[0]
    return pid, r


def bal(app, key):
    return app.execute("SELECT coalesce(sum(l.debit_minor - l.credit_minor), 0) FROM journal_lines l JOIN accounts a ON a.id = l.account_id WHERE a.system_key = %s", (key,)).fetchone()[0]


def bill(app, world, amount=100000, vat=0):
    s = setup(app, world)
    p = party(app, world, "supplier")
    did, r = make(app, world, "bill", p, [line(s["rent"], amount)], vat=vat)
    assert r[0] == "ok"
    return did, s


def test_paying_a_bill_with_tax_withheld(app, world):
    did, s = bill(app, world)                                              # 1,000.00 owed
    pid, r = settle(app, world, did, 100000, s["bank"], [item("vds", 15000, 100000), item("tds", 5000, 100000, 500, "TDS5")])
    assert r == "ok"
    assert bal(app, "payable") == 0                                        # the whole bill is settled
    assert bal(app, "bank") == -80000                                      # cash paid = gross - withheld
    assert bal(app, "tax_payable") == -20000                               # we now owe the treasury 200
    assert app.execute("SELECT status, paid_minor FROM acct_docs WHERE id=%s", (did,)).fetchone() == ("paid", 100000)
    assert app.execute("SELECT amount_minor, account_id FROM acct_payments WHERE id=%s", (pid,)).fetchone() == (100000, s["bank"])
    rows = app.execute("SELECT direction, kind, amount_minor, rule_code, deposit_id FROM tax_withholdings ORDER BY kind").fetchall()
    assert rows == [("deducted", "tds", 5000, "TDS5", None), ("deducted", "vds", 15000, "STD", None)]
    assert app.execute("SELECT count(*) FROM journal_entries").fetchone()[0] == 2        # the bill and the payment


def test_a_part_payment_leaves_the_rest_open(app, world):
    did, s = bill(app, world)
    assert settle(app, world, did, 40000, s["bank"], [item(amount=6000)])[1] == "ok"
    assert app.execute("SELECT status, paid_minor FROM acct_docs WHERE id=%s", (did,)).fetchone() == ("open", 40000)
    assert settle(app, world, did, 60001, s["bank"], [item(amount=1)])[1] == "overpay"


def test_a_customer_withholding_from_us_becomes_an_advance_tax_asset(app, world):
    s = setup(app, world)
    c = party(app, world, "customer")
    inv, _ = make(app, world, "invoice", c, [line(s["sales"], 100000)])
    assert app.execute("SELECT count(*) FROM accounts WHERE system_key='tax_advance'").fetchone()[0] == 0
    pid, r = settle(app, world, inv, 100000, s["bank"], [item("tds", 3000, 100000, 300, "TDS3")])
    assert r == "ok"
    assert bal(app, "bank") == 97000 and bal(app, "tax_advance") == 3000 and bal(app, "receivable") == 0
    assert app.execute("SELECT code, type, parent_id IS NOT NULL FROM accounts WHERE system_key='tax_advance'").fetchone() == ("1410", "asset", True)
    assert app.execute("SELECT direction, amount_minor FROM tax_withholdings").fetchall() == [("suffered", 3000)]
    assert settle(app, world, inv, 1, s["bank"], [item(amount=1)])[1] == "not_payable"      # already paid


@pytest.mark.parametrize("items", [[], None, [item(amount=100000)], [item(amount=100001)], [item(kind="gst")], [item(amount=0)], [item(base=0)], [item(bp=10001)], [{"kind": "vds"}], [item(amount="abc")], [item(base="x")], [item(bp="y")], [item(code="x" * 21)], [item(cert="c" * 41)],
                                    [item(amount=1)] * 6])
def test_bad_items_are_refused_and_nothing_is_written(app, world, items):
    did, s = bill(app, world)
    assert settle(app, world, did, 100000, s["bank"], items)[1] == "bad_items"
    assert app.execute("SELECT count(*) FROM tax_withholdings").fetchone()[0] == 0
    assert app.execute("SELECT count(*) FROM acct_payments").fetchone()[0] == 0
    assert bal(app, "payable") == -100000


def test_other_refusals(app, world):
    did, s = bill(app, world)
    assert settle(app, world, did, 0, s["bank"], [item()])[1] == "bad_amount"
    assert settle(app, world, did, 100000, s["rent"], [item()])[1] == "bad_account"           # not a cash account
    assert settle(app, world, did, 100000, s["bank"], [item()], date="2026-11-01")[1] == "ok"
    p2 = party(app, world, "supplier", name="অন্য")
    exp, _ = make(app, world, "expense", p2, [line(s["rent"], 1000)], pay=s["bank"])
    assert settle(app, world, exp, 500, s["bank"], [item(amount=1)])[1] == "not_payable"


def test_a_missing_tax_payable_account_is_reported(app, world, owner):
    did, s = bill(app, world)
    as_admin(owner)
    owner.execute("UPDATE accounts SET active = false WHERE system_key='tax_payable'")
    assert settle(app, world, did, 100000, s["bank"], [item()])[1] == "no_system_account"


def test_a_locked_period_refuses_and_leaves_no_trace(app, world):
    did, s = bill(app, world)
    ctx(app, world, "a")
    assert app.execute("SELECT acct_lock(%s,'2026-10-31',%s)", (world["a"]["tenant"], world["a"]["user"])).fetchone()[0] == "ok"
    assert settle(app, world, did, 100000, s["bank"], [item()], date="2026-10-15")[1] == "locked"
    assert app.execute("SELECT count(*) FROM tax_withholdings").fetchone()[0] == 0


def test_blank_certificate_in_a_settlement_is_stored_as_none(app, world):
    did, s = bill(app, world)
    assert settle(app, world, did, 100000, s["bank"], [item(cert="   ")])[1] == "ok"
    assert app.execute("SELECT cert_no FROM tax_withholdings").fetchone()[0] is None


def test_tax_a_customer_withheld_from_us_is_never_deposited(app, world):
    s = setup(app, world)
    c = party(app, world, "customer")
    inv, _ = make(app, world, "invoice", c, [line(s["sales"], 100000)])
    settle(app, world, inv, 100000, s["bank"], [item("vds", 3000)])
    assert deposit(app, world, "vds") == ("nothing", 0)
    assert bal(app, "tax_payable") == 0


def test_certificate_number(app, world):
    did, s = bill(app, world)
    settle(app, world, did, 100000, s["bank"], [item()])
    wid = app.execute("SELECT id FROM tax_withholdings").fetchone()[0]
    assert app.execute("SELECT tax_cert_set(%s,%s,%s)", (wid, world["a"]["user"], "6.6-0001")).fetchone()[0] == "ok"
    assert app.execute("SELECT cert_no FROM tax_withholdings").fetchone()[0] == "6.6-0001"
    assert app.execute("SELECT tax_cert_set(%s,%s,%s)", (wid, world["a"]["user"], "c" * 41)).fetchone()[0] == "bad_cert"
    assert app.execute("SELECT tax_cert_set(%s,%s,'  ')", (wid, world["a"]["user"])).fetchone()[0] == "ok"
    assert app.execute("SELECT cert_no FROM tax_withholdings").fetchone()[0] is None


def deposit(app, world, kind="vds", date=D, upto="2026-12-31", account=None, challan="CH-1", who="a"):
    ctx(app, world, who)
    acct = account or app.execute("SELECT id FROM accounts WHERE system_key='bank'").fetchone()[0]
    return tuple(app.execute("SELECT * FROM tax_deposit(%s,%s,%s,%s,%s,%s::date,%s::date,%s,%s)", (ulid(), ulid(), world[who]["tenant"], world[who]["user"], kind, date, upto, acct, challan)).fetchone())


def test_depositing_withheld_tax(app, world):
    did, s = bill(app, world, 300000)
    settle(app, world, did, 100000, s["bank"], [item("vds", 15000), item("tds", 5000)])
    settle(app, world, did, 100000, s["bank"], [item("vds", 15000)])
    assert deposit(app, world, "vds") == ("ok", 30000)
    assert bal(app, "tax_payable") == -5000                                 # only the TDS is still owed
    assert deposit(app, world, "vds") == ("nothing", 0)                     # not twice
    assert app.execute("SELECT kind, amount_minor, challan_ref FROM tax_deposits").fetchall() == [("vds", 30000, "CH-1")]
    assert app.execute("SELECT count(*) FROM tax_withholdings WHERE deposit_id IS NOT NULL").fetchone()[0] == 2
    assert deposit(app, world, "tds") == ("ok", 5000)
    assert bal(app, "tax_payable") == 0


def test_deposit_refusals(app, world):
    did, s = bill(app, world)
    settle(app, world, did, 100000, s["bank"], [item()], date="2026-11-02")
    assert deposit(app, world, "gst")[0] == "bad_kind"
    assert deposit(app, world, date="2026-11-01")[0] == "bad_date"          # before the tax was withheld
    assert deposit(app, world, date="2999-01-01")[0] == "bad_date"
    assert deposit(app, world, challan="c" * 61, date="2026-11-03")[0] == "bad_date"
    assert deposit(app, world, account=s["rent"], date="2026-11-03")[0] == "bad_account"
    assert deposit(app, world, upto="2026-11-01", date="2026-11-03")[0] == "nothing"      # nothing withheld up to that date
    assert app.execute("SELECT count(*) FROM tax_deposits").fetchone()[0] == 0


def test_a_cancelled_payment_is_not_deposited_and_a_deposited_one_cannot_be_cancelled(app, world):
    did, s = bill(app, world, 300000)
    p1, _ = settle(app, world, did, 100000, s["bank"], [item(amount=1000)], date="2026-11-02")
    p2, _ = settle(app, world, did, 100000, s["bank"], [item(amount=2000)], date="2026-11-02")
    u = world["a"]["user"]
    assert app.execute("SELECT acct_payment_void(%s,%s,%s,'2026-11-03')", (ulid(), p1, u)).fetchone()[0] == "ok"
    assert deposit(app, world, date="2026-11-03") == ("ok", 2000)           # only the live one
    assert app.execute("SELECT amount_minor FROM tax_withholdings WHERE deposit_id IS NULL").fetchall() == [(1000,)]      # the cancelled one stays unmarked
    with pytest.raises(psycopg.errors.CheckViolation):
        app.execute("SELECT acct_payment_void(%s,%s,%s,'2026-11-04')", (ulid(), p2, u))
    app.rollback()


def test_advance_tax(app, world):
    s = setup(app, world)
    ctx(app, world, "a")
    t, u = world["a"]["tenant"], world["a"]["user"]
    aid = ulid()
    assert app.execute("SELECT tax_advance_pay(%s,%s,%s,%s,'ait',%s::date,%s,%s,'CH-9','import')", (aid, ulid(), t, u, D, 25000, s["bank"])).fetchone()[0] == "ok"
    assert bal(app, "tax_advance") == 25000 and bal(app, "bank") == -25000
    assert app.execute("SELECT tax_advance_pay(%s,%s,%s,%s,'ait',%s::date,0,%s,NULL,NULL)", (ulid(), ulid(), t, u, D, s["bank"])).fetchone()[0] == "bad_amount"
    assert app.execute("SELECT tax_advance_pay(%s,%s,%s,%s,'vat',%s::date,10,%s,NULL,NULL)", (ulid(), ulid(), t, u, D, s["bank"])).fetchone()[0] == "bad_kind"
    assert app.execute("SELECT tax_advance_pay(%s,%s,%s,%s,'ait',%s::date,10,%s,NULL,NULL)", (ulid(), ulid(), t, u, D, s["rent"])).fetchone()[0] == "bad_account"
    assert app.execute("SELECT tax_advance_void(%s,%s,%s,%s::date)", (ulid(), aid, u, D)).fetchone()[0] == "ok"
    assert bal(app, "tax_advance") == 0
    assert app.execute("SELECT tax_advance_void(%s,%s,%s,%s::date)", (ulid(), aid, u, D)).fetchone()[0] == "already_void"


def rule(app, world, kind="tds", code="TDS5", bp=500, frm="2026-07-01", who="a"):
    ctx(app, world, who)
    return app.execute("SELECT tax_rule_save(%s,%s,%s,%s,%s,%s,%s,%s::date)", (ulid(), world[who]["tenant"], world[who]["user"], kind, code, "নিয়ম", bp, frm)).fetchone()[0]


def test_rules_have_a_dated_history(app, world):
    assert rule(app, world) == "ok" and rule(app, world, bp=700, frm="2026-12-01") == "ok"
    assert app.execute("SELECT effective_to FROM tax_rules WHERE rate_bp=500").fetchone()[0].isoformat() == "2026-11-30"
    assert rule(app, world, frm="2026-11-01") == "overlap" and rule(app, world, kind="gst") == "bad_input" and rule(app, world, bp=10001) == "bad_input" and rule(app, world, frm="2012-01-01") == "bad_date"
    assert rule(app, world, kind="vds", code="TDS5") == "ok"                # same code under another kind is separate


def test_another_workspace_cannot_touch_anything(app, world):
    did, s = bill(app, world)
    pid, _ = settle(app, world, did, 100000, s["bank"], [item()])
    wid = app.execute("SELECT id FROM tax_withholdings").fetchone()[0]
    ctx(app, world, "b")
    ub, tb = world["b"]["user"], world["b"]["tenant"]
    for sql, args in (("SELECT tax_settle(%s,%s,%s,%s,%s::date,1,%s,'x',%s::jsonb)", (ulid(), ulid(), did, ub, D, s["bank"], json.dumps([item(amount=0)]))), ("SELECT tax_cert_set(%s,%s,'x')", (wid, ub)),
                      ("SELECT tax_rule_save(%s,%s,%s,'tds','X','x',1,'2026-07-01'::date)", (ulid(), world["a"]["tenant"], ub)), ("SELECT tax_deposit(%s,%s,%s,%s,'vds','2026-11-01'::date,'2026-12-31'::date,%s,NULL)", (ulid(), ulid(), world["a"]["tenant"], ub, s["bank"]))):
        with pytest.raises(psycopg.errors.InsufficientPrivilege):
            app.execute(sql, args)
        app.rollback()
        ctx(app, world, "b")
    for t in TABLES:
        assert app.execute(f"SELECT count(*) FROM {t}").fetchone()[0] == 0


@pytest.mark.parametrize("sql", ["INSERT INTO tax_rules (id, tenant_id, kind, code, label, rate_bp, effective_from, created_by) VALUES ('x','x','tds','S','l',1,'2026-01-01','x')", "UPDATE tax_withholdings SET amount_minor = 1",
                                 "DELETE FROM tax_withholdings", "UPDATE tax_deposits SET amount_minor = 1", "DELETE FROM tax_advances", "UPDATE tax_rules SET rate_bp = 1", "UPDATE tax_advances SET amount_minor = 1", "DELETE FROM tax_deposits"])
def test_the_app_role_cannot_write_directly(app, world, sql):
    did, s = bill(app, world)
    settle(app, world, did, 100000, s["bank"], [item()])
    deposit(app, world)
    ctx(app, world, "a")
    app.execute("SELECT tax_advance_pay(%s,%s,%s,%s,'ait',%s::date,5,%s,NULL,NULL)", (ulid(), ulid(), world["a"]["tenant"], world["a"]["user"], D, s["bank"]))
    rule(app, world)
    ctx(app, world, "a")
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute(sql)


@settings(max_examples=25, deadline=None, suppress_health_check=[HealthCheck.function_scoped_fixture, HealthCheck.too_slow])
@given(st.lists(st.tuples(st.sampled_from(["settle_v", "settle_t", "deposit_v", "deposit_t", "void"]), st.integers(1, 9)), min_size=1, max_size=12))
def test_property_the_tax_payable_account_always_equals_the_live_undeposited_withholdings(app, world, owner, ops):
    try:
        wipe(owner)
        did, s = bill(app, world, 100_000_000)
        pids = []
        for op, n in ops:
            if op.startswith("settle"):
                pid, r = settle(app, world, did, 1000 * n, s["bank"], [item("vds" if op.endswith("v") else "tds", 100 * n)], date="2026-11-02")
                if r == "ok":
                    pids.append(pid)
            elif op.startswith("deposit"):
                deposit(app, world, "vds" if op.endswith("v") else "tds", date="2026-11-03")
            elif pids:
                try:
                    app.execute("SELECT acct_payment_void(%s,%s,%s,'2026-11-03')", (ulid(), pids.pop(), world["a"]["user"])).fetchone()
                except psycopg.errors.CheckViolation:
                    app.rollback()
                    ctx(app, world, "a")
        owed = app.execute("SELECT coalesce(sum(w.amount_minor),0) FROM tax_withholdings w JOIN acct_payments p ON p.id = w.payment_id WHERE w.deposit_id IS NULL AND p.voided_at IS NULL").fetchone()[0]
        assert -bal(app, "tax_payable") == owed
        assert app.execute("SELECT coalesce(sum(debit_minor) - sum(credit_minor), 0) FROM journal_lines").fetchone()[0] == 0
    finally:
        app.rollback()
        wipe(owner)
