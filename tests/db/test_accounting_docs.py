import json

import psycopg
import pytest

from conftest import as_admin, set_tenant, ulid
from test_accounting import acc, ctx, seed

D = "2026-11-01"


@pytest.fixture(autouse=True)
def clean(owner, world):
    yield
    as_admin(owner)
    for t in ("journal_entries", "journal_lines", "acct_lock_log", "acct_doc_lines"):
        owner.execute(f"ALTER TABLE {t} DISABLE TRIGGER {t}_no_change")
    for t in ("acct_payments", "acct_doc_lines", "acct_docs", "acct_parties", "acct_lock_log", "journal_lines", "journal_entries", "accounts", "acct_settings"):
        owner.execute(f"DELETE FROM {t}")
    for t in ("journal_entries", "journal_lines", "acct_lock_log", "acct_doc_lines"):
        owner.execute(f"ALTER TABLE {t} ENABLE TRIGGER {t}_no_change")


def party(app, world, kind="customer", who="a", name="রহিম স্টোর"):
    pid = ulid()
    assert app.execute("SELECT acct_party_add(%s,%s,%s,%s,NULL,NULL)", (pid, world[who]["tenant"], kind, name)).fetchone()[0] == "ok"
    return pid


def line(account, amount, desc="item"):
    return {"account_id": account, "description": desc, "amount_minor": amount}


def make(app, world, kind, party_id, lines, *, date=D, due=None, vat=0, pay=None, who="a"):
    did = ulid()
    r = app.execute("SELECT * FROM acct_doc_create(%s,%s,%s,%s,%s,%s,%s::date,%s::date,NULL,%s::jsonb,%s,%s)",
                    (did, ulid(), world[who]["tenant"], world[who]["user"], kind, party_id, date, due, json.dumps(lines), vat, pay)).fetchone()
    return did, r


def pay(app, world, doc, amount, account, *, date=D, who="a"):
    pid = ulid()
    return pid, app.execute("SELECT acct_payment_add(%s,%s,%s,%s,%s::date,%s,%s,NULL)", (pid, ulid(), doc, world[who]["user"], date, amount, account)).fetchone()[0]


def bal(app, key, who="a", sign=1):
    a = acc(app, None, key, who) if False else app.execute("SELECT id FROM accounts WHERE system_key=%s", (key,)).fetchone()[0]
    r = app.execute("SELECT coalesce(sum(debit_minor-credit_minor),0) FROM journal_lines WHERE account_id=%s", (a,)).fetchone()[0]
    return r * sign


def setup(app, world):
    seed(app, world)
    return dict(cash=acc(app, world, "cash"), bank=acc(app, world, "bank"), sales=acc(app, world, "sales"), rent=acc(app, world, "rent"), ar=acc(app, world, "receivable"))


def entry_lines(app, doc):
    return app.execute("SELECT a.system_key, l.debit_minor, l.credit_minor FROM acct_docs d JOIN journal_lines l ON l.entry_id=d.entry_id JOIN accounts a ON a.id=l.account_id WHERE d.id=%s ORDER BY l.seq", (doc,)).fetchall()


def test_invoice_posts_receivable_income_and_vat(app, world):
    A = setup(app, world)
    c = party(app, world)
    did, r = make(app, world, "invoice", c, [line(A["sales"], 100000), line(A["sales"], 50000)], vat=15000)
    assert r[0] == "ok" and r[2] == "INV-0001" and r[3] == 165000
    assert entry_lines(app, did) == [("receivable", 165000, 0), ("sales", 0, 150000), ("vat_output", 0, 15000)]
    assert app.execute("SELECT status, paid_minor, subtotal_minor, vat_minor, due_date FROM acct_docs WHERE id=%s", (did,)).fetchone() == ("open", 0, 150000, 15000, __import__("datetime").date(2026, 11, 1))
    assert make(app, world, "invoice", c, [line(A["sales"], 1)])[1][2] == "INV-0002"
    assert app.execute("SELECT source, source_ref FROM acct_docs d JOIN journal_entries e ON e.id=d.entry_id WHERE d.id=%s", (did,)).fetchone() == ("invoice", "INV-0001")


def test_bill_and_expense_postings(app, world):
    A = setup(app, world)
    s = party(app, world, "supplier")
    did, r = make(app, world, "bill", s, [line(A["rent"], 30000)], vat=4500, due="2026-11-30")
    assert r[0] == "ok" and r[2] == "BILL-0001"
    assert entry_lines(app, did) == [("rent", 30000, 0), ("vat_input", 4500, 0), ("payable", 0, 34500)]
    eid, r2 = make(app, world, "expense", None, [line(A["rent"], 20000)], pay=A["cash"])
    assert r2[0] == "ok" and r2[2] == "EXP-0001"
    assert entry_lines(app, eid) == [("rent", 20000, 0), ("cash", 0, 20000)]
    assert app.execute("SELECT status, paid_minor FROM acct_docs WHERE id=%s", (eid,)).fetchone() == ("paid", 20000)


def test_document_rules_are_enforced(app, world):
    A = setup(app, world)
    cust, sup = party(app, world), party(app, world, "supplier")
    ok = [line(A["sales"], 100)]
    cases = [
        (("invoice", sup, ok), "bad_party"),                               # a supplier cannot be invoiced
        (("bill", cust, [line(A["rent"], 100)]), "bad_party"),             # a customer cannot be billed
        (("invoice", None, ok), "bad_party"),
        (("invoice", cust, [line(A["rent"], 100)]), "bad_line_account"),   # invoice lines are income
        (("bill", sup, [line(A["sales"], 100)]), "bad_line_account"),      # bill lines are expense / asset
        (("bill", sup, [line(A["cash"], 100)]), "bad_line_account"),       # not a cash account
        (("bill", sup, [line(A["ar"], 100)]), "bad_line_account"),         # not a control account
        (("invoice", cust, [line(A["sales"], 0)]), "bad_line"),
        (("invoice", cust, [line(A["sales"], -5)]), "bad_line"),
        (("invoice", cust, []), "bad_lines"),
        (("invoice", cust, [line(ulid(), 100)]), "bad_line_account"),
        (("loan", cust, ok), "bad_kind"),
    ]
    for (kind, p, lines), want in cases:
        assert make(app, world, kind, p, lines)[1][0] == want, (kind, want)
    assert make(app, world, "invoice", cust, ok, vat=-1)[1][0] == "bad_vat"
    assert make(app, world, "invoice", cust, ok, date="2026-11-05", due="2026-11-01")[1][0] == "bad_due"
    assert make(app, world, "invoice", cust, ok, pay=A["cash"])[1][0] == "bad_pay_account"
    assert make(app, world, "expense", None, [line(A["rent"], 100)])[1][0] == "bad_pay_account"                 # an expense needs a cash/bank account
    assert make(app, world, "expense", None, [line(A["rent"], 100)], pay=A["sales"])[1][0] == "bad_pay_account"
    assert make(app, world, "invoice", cust, ok, date="1999-01-01")[1][0] == "bad_date"
    app.execute("UPDATE acct_parties SET active=false WHERE id=%s", (cust,))
    assert make(app, world, "invoice", cust, ok)[1][0] == "bad_party"
    assert app.execute("SELECT count(*) FROM acct_docs").fetchone()[0] == 0 and app.execute("SELECT count(*) FROM journal_entries").fetchone()[0] == 0
    app.execute("UPDATE acct_parties SET active=true WHERE id=%s", (cust,))
    assert make(app, world, "invoice", cust, ok)[1][2] == "INV-0001"                                                 # refused attempts used no numbers


def test_missing_chart_is_reported_not_crashed(app, world):
    set_tenant(app, world["a"]["tenant"])
    c = party(app, world)
    assert make(app, world, "invoice", c, [line(ulid(), 100)])[1][0] in ("no_system_account", "bad_line_account")


def test_payments_partial_full_overpay(app, world):
    A = setup(app, world)
    c = party(app, world)
    did, _ = make(app, world, "invoice", c, [line(A["sales"], 100000)])
    assert pay(app, world, did, 0, A["cash"])[1] == "bad_amount"
    assert pay(app, world, did, 40000, A["sales"])[1] == "bad_account"
    assert pay(app, world, did, 100001, A["cash"])[1] == "overpay"
    assert pay(app, world, did, 40000, A["cash"])[1] == "ok"
    assert app.execute("SELECT status, paid_minor FROM acct_docs WHERE id=%s", (did,)).fetchone() == ("open", 40000)
    assert pay(app, world, did, 60001, A["bank"])[1] == "overpay"
    assert pay(app, world, did, 60000, A["bank"])[1] == "ok"
    assert app.execute("SELECT status, paid_minor FROM acct_docs WHERE id=%s", (did,)).fetchone() == ("paid", 100000)
    assert pay(app, world, did, 1, A["cash"])[1] == "not_payable"
    assert bal(app, "receivable") == 0 and bal(app, "cash") == 40000 and bal(app, "bank") == 60000
    eid, _ = make(app, world, "expense", None, [line(A["rent"], 500)], pay=A["cash"])
    assert pay(app, world, eid, 500, A["cash"])[1] == "not_payable"


def test_bill_payment_direction(app, world):
    A = setup(app, world)
    s = party(app, world, "supplier")
    did, _ = make(app, world, "bill", s, [line(A["rent"], 30000)])
    assert bal(app, "payable") == -30000
    assert pay(app, world, did, 10000, A["bank"])[1] == "ok"
    assert bal(app, "payable") == -20000 and bal(app, "bank") == -10000


def test_cancelling_a_payment_and_voiding_a_document(app, world):
    A = setup(app, world)
    c = party(app, world)
    did, _ = make(app, world, "invoice", c, [line(A["sales"], 100000)])
    pid, _ = pay(app, world, did, 100000, A["cash"])
    u = world["a"]["user"]
    assert app.execute("SELECT acct_doc_void(%s,%s,%s,'2026-11-02')", (ulid(), did, u)).fetchone()[0] == "has_payments"
    assert app.execute("SELECT acct_payment_void(%s,%s,%s,'2026-11-02')", (ulid(), pid, u)).fetchone()[0] == "ok"
    assert app.execute("SELECT status, paid_minor FROM acct_docs WHERE id=%s", (did,)).fetchone() == ("open", 0)
    assert app.execute("SELECT acct_payment_void(%s,%s,%s,'2026-11-02')", (ulid(), pid, u)).fetchone()[0] == "already_void"
    assert bal(app, "cash") == 0 and bal(app, "receivable") == 100000
    assert app.execute("SELECT acct_doc_void(%s,%s,%s,'2026-11-02')", (ulid(), did, u)).fetchone()[0] == "ok"
    assert app.execute("SELECT status FROM acct_docs WHERE id=%s", (did,)).fetchone()[0] == "void"
    assert app.execute("SELECT acct_doc_void(%s,%s,%s,'2026-11-02')", (ulid(), did, u)).fetchone()[0] == "already_void"
    assert pay(app, world, did, 1, A["cash"])[1] == "not_payable"
    assert bal(app, "receivable") == 0 and bal(app, "sales") == 0
    eid, _ = make(app, world, "expense", None, [line(A["rent"], 500)], pay=A["cash"])
    assert app.execute("SELECT acct_doc_void(%s,%s,%s,'2026-11-02')", (ulid(), eid, u)).fetchone()[0] == "ok"
    assert bal(app, "cash") == 0


def test_a_locked_period_blocks_documents_payments_and_voids_and_leaves_nothing(app, world):
    A = setup(app, world)
    c = party(app, world)
    did, _ = make(app, world, "invoice", c, [line(A["sales"], 100000)], date="2026-10-10")
    pid, _ = pay(app, world, did, 30000, A["cash"], date="2026-10-12")
    u, t = world["a"]["user"], world["a"]["tenant"]
    assert app.execute("SELECT acct_lock(%s,'2026-10-31',%s)", (t, u)).fetchone()[0] == "ok"
    docs, ents, lines = [app.execute(f"SELECT count(*) FROM {x}").fetchone()[0] for x in ("acct_docs", "journal_entries", "acct_doc_lines")]
    assert make(app, world, "invoice", c, [line(A["sales"], 5)], date="2026-10-20")[1][0] == "locked"
    assert make(app, world, "expense", None, [line(A["rent"], 5)], date="2026-10-20", pay=A["cash"])[1][0] == "locked"
    assert pay(app, world, did, 100, A["cash"], date="2026-10-20")[1] == "locked"
    assert app.execute("SELECT acct_payment_void(%s,%s,%s,'2026-10-25')", (ulid(), pid, u)).fetchone()[0] == "locked"
    assert app.execute("SELECT acct_doc_void(%s,%s,%s,'2026-10-25')", (ulid(), did, u)).fetchone()[0] in ("locked", "has_payments")
    assert [app.execute(f"SELECT count(*) FROM {x}").fetchone()[0] for x in ("acct_docs", "journal_entries", "acct_doc_lines")] == [docs, ents, lines]
    assert make(app, world, "invoice", c, [line(A["sales"], 5)], date="2026-11-01")[1][2] == "INV-0002"            # the refused attempt did not use INV-0002
    assert app.execute("SELECT acct_payment_void(%s,%s,%s,'2026-11-02')", (ulid(), pid, u)).fetchone()[0] == "ok"   # fixing it in the open period is the way


def test_aging_buckets_and_as_of(app, world):
    A = setup(app, world)
    c = party(app, world)
    specs = [("2026-09-01", "2026-09-10", 1000), ("2026-08-01", "2026-08-20", 2000), ("2026-06-01", "2026-06-10", 3000), ("2026-11-01", "2026-11-20", 4000)]
    ids = [make(app, world, "invoice", c, [line(A["sales"], amt)], date=d, due=due)[0] for d, due, amt in specs]
    pay(app, world, ids[0], 400, A["cash"], date="2026-10-01")
    rows = {r[1]: r for r in app.execute("SELECT * FROM acct_aging('invoice','2026-11-03')").fetchall()}
    assert {k: (v[7], v[8], v[9]) for k, v in rows.items()} == {"INV-0001": (600, 54, "31-60"), "INV-0002": (2000, 75, "61-90"), "INV-0003": (3000, 146, "90+"), "INV-0004": (4000, 0, "current")}
    early = {r[1]: r[7] for r in app.execute("SELECT * FROM acct_aging('invoice','2026-09-30')").fetchall()}
    assert early == {"INV-0001": 1000, "INV-0002": 2000, "INV-0003": 3000}                      # invoice 4 not issued yet, the 1 Oct payment not yet received
    pay(app, world, ids[3], 4000, A["cash"], date="2026-11-02")
    assert "INV-0004" not in {r[1] for r in app.execute("SELECT * FROM acct_aging('invoice','2026-11-03')").fetchall()}
    assert app.execute("SELECT count(*) FROM acct_aging('bill','2026-11-03')").fetchone()[0] == 0


def test_aging_bucket_edges_and_voided_documents(app, world):
    A = setup(app, world)
    c = party(app, world)
    for d, due in (("2026-09-01", "2026-10-04"), ("2026-09-01", "2026-10-03")):                       # as of 3 Nov: 30 and 31 days late
        make(app, world, "invoice", c, [line(A["sales"], 100)], date=d, due=due)
    b = {r[1]: r[9] for r in app.execute("SELECT * FROM acct_aging('invoice','2026-11-03')").fetchall()}
    assert b == {"INV-0001": "1-30", "INV-0002": "31-60"}
    gone, _ = make(app, world, "invoice", c, [line(A["sales"], 700)], date="2026-10-01")
    assert app.execute("SELECT acct_doc_void(%s,%s,%s,'2026-11-02')", (ulid(), gone, world["a"]["user"])).fetchone()[0] == "ok"
    assert "INV-0003" not in {r[1] for r in app.execute("SELECT * FROM acct_aging('invoice','2099-01-01')").fetchall()}      # voided: not owed now
    assert "INV-0003" in {r[1] for r in app.execute("SELECT * FROM acct_aging('invoice','2026-10-31')").fetchall()}          # but it was owed then


def test_cash_balances(app, world):
    A = setup(app, world)
    c = party(app, world)
    did, _ = make(app, world, "invoice", c, [line(A["sales"], 100000)])
    pay(app, world, did, 70000, A["cash"], date="2026-11-01")
    make(app, world, "expense", None, [line(A["rent"], 20000)], pay=A["bank"], date="2026-11-02")
    rows = {r[1]: r[4] for r in app.execute("SELECT * FROM acct_cash_balances('2026-11-03')").fetchall()}
    assert rows["1110"] == 70000 and rows["1120"] == -20000 and rows["1130"] == 0
    assert {r[1]: r[4] for r in app.execute("SELECT * FROM acct_cash_balances('2026-11-01')").fetchall()}["1120"] == 0
    assert app.execute("SELECT acct_account_cash(%s,true)", (A["rent"],)).fetchone()[0] == "not_asset"
    assert app.execute("SELECT acct_account_cash(%s,true)", (A["ar"],)).fetchone()[0] == "not_asset"
    t = world["a"]["tenant"]
    exp = app.execute("SELECT id FROM accounts WHERE code='1100'").fetchone()[0]
    assert app.execute("SELECT acct_account_add(%s,%s,'1140','DBBL Bank','ডাচ-বাংলা','asset',%s)", (ulid(), t, exp)).fetchone()[0] == "ok"
    new = app.execute("SELECT id FROM accounts WHERE code='1140'").fetchone()[0]
    assert app.execute("SELECT cash_account FROM accounts WHERE id=%s", (new,)).fetchone()[0] is False
    assert pay(app, world, did, 1000, new)[1] == "bad_account"
    assert app.execute("SELECT acct_account_cash(%s,true)", (new,)).fetchone()[0] == "ok"
    assert pay(app, world, did, 1000, new)[1] == "ok"


def test_isolation_and_no_direct_writes(app, world):
    A = setup(app, world)
    seed(app, world, "b")
    ctx(app, world, "a")
    c = party(app, world)
    did, _ = make(app, world, "invoice", c, [line(A["sales"], 100)])
    for sql in ("UPDATE acct_docs SET paid_minor=0", "DELETE FROM acct_docs", "INSERT INTO acct_docs (id) VALUES ('x')", "UPDATE acct_payments SET amount_minor=1", "DELETE FROM acct_payments",
                "INSERT INTO acct_payments (id) VALUES ('x')", "UPDATE acct_doc_lines SET amount_minor=1", "DELETE FROM acct_doc_lines", "INSERT INTO acct_parties (id) VALUES ('x')", "UPDATE accounts SET cash_account=true"):
        with pytest.raises(psycopg.errors.InsufficientPrivilege):
            app.execute(sql)
    ctx(app, world, "b")
    assert app.execute("SELECT count(*) FROM acct_docs").fetchone()[0] == 0 and app.execute("SELECT count(*) FROM acct_parties").fetchone()[0] == 0
    b_cash = acc(app, world, "cash", "b")
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        pay(app, world, did, 10, b_cash, who="b")                                                      # another workspace's document
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("SELECT acct_doc_void(%s,%s,%s,'2026-11-02')", (ulid(), did, world["b"]["user"]))
    b_sales = acc(app, world, "sales", "b")
    assert make(app, world, "invoice", c, [line(b_sales, 100)], who="b")[1][0] == "bad_party"           # A's customer
    assert app.execute("SELECT count(*) FROM acct_aging('invoice','2026-12-31')").fetchone()[0] == 0
    ctx(app, world, "a")
    assert app.execute("SELECT count(*) FROM acct_aging('invoice','2026-12-31')").fetchone()[0] == 1


# ---------------------------------------------------------------------------------------------------------------------
hyp = pytest.importorskip("hypothesis")
from hypothesis import HealthCheck, given, settings, strategies as st  # noqa: E402

op = st.tuples(st.sampled_from(["inv", "bill", "pay", "payv", "void", "exp"]), st.integers(min_value=1, max_value=500_000), st.integers(min_value=0, max_value=9), st.integers(min_value=0, max_value=100_000))


@settings(max_examples=40, deadline=None, suppress_health_check=[HealthCheck.function_scoped_fixture])
@given(st.lists(op, min_size=1, max_size=14))
def test_property_receivable_and_payable_always_equal_the_open_documents(app, world, owner, ops):
    A = setup(app, world)
    c, s = party(app, world), party(app, world, "supplier")
    u = world["a"]["user"]
    docs: list[str] = []
    pays: list[str] = []
    for kind, amount, pick, vat in ops:
        if kind == "inv":
            d, r = make(app, world, "invoice", c, [line(A["sales"], amount)], vat=vat % 1000)
            docs.append(d) if r[0] == "ok" else None
        elif kind == "bill":
            d, r = make(app, world, "bill", s, [line(A["rent"], amount)], vat=vat % 1000)
            docs.append(d) if r[0] == "ok" else None
        elif kind == "exp":
            make(app, world, "expense", None, [line(A["rent"], amount)], pay=A["cash"])
        elif kind == "pay" and docs:
            p, res = pay(app, world, docs[pick % len(docs)], 1 + vat % 50_000, A["cash"] if pick % 2 else A["bank"])
            pays.append(p) if res == "ok" else None
        elif kind == "payv" and pays:
            app.execute("SELECT acct_payment_void(%s,%s,%s,'2026-11-02')", (ulid(), pays[pick % len(pays)], u))
        elif kind == "void" and docs:
            app.execute("SELECT acct_doc_void(%s,%s,%s,'2026-11-02')", (ulid(), docs[pick % len(docs)], u))
        for k, key, sign in (("invoice", "receivable", 1), ("bill", "payable", -1)):
            owed = app.execute("SELECT coalesce(sum(total_minor - paid_minor),0) FROM acct_docs WHERE kind=%s AND status <> 'void'", (k,)).fetchone()[0]
            assert bal(app, key, sign=sign) == owed, (k, ops)
        tb = app.execute("SELECT debit_minor, credit_minor FROM acct_trial_balance('2099-12-31')").fetchall()
        assert sum(x[0] for x in tb) == sum(x[1] for x in tb)
        paid_ok = app.execute("SELECT count(*) FROM acct_docs d WHERE d.kind <> 'expense' AND d.paid_minor <> coalesce((SELECT sum(amount_minor) FROM acct_payments p WHERE p.doc_id=d.id AND p.voided_at IS NULL),0)").fetchone()[0]
        assert paid_ok == 0
    wipe_all(owner)


def wipe_all(owner):
    as_admin(owner)
    for t in ("journal_entries", "journal_lines", "acct_lock_log", "acct_doc_lines"):
        owner.execute(f"ALTER TABLE {t} DISABLE TRIGGER {t}_no_change")
    for t in ("acct_payments", "acct_doc_lines", "acct_docs", "acct_parties", "acct_lock_log", "journal_lines", "journal_entries", "accounts", "acct_settings"):
        owner.execute(f"DELETE FROM {t}")
    for t in ("journal_entries", "journal_lines", "acct_lock_log", "acct_doc_lines"):
        owner.execute(f"ALTER TABLE {t} ENABLE TRIGGER {t}_no_change")
