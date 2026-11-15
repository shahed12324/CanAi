import json

import psycopg
import pytest

from conftest import as_admin, ulid
from test_accounting import acc, cr, ctx, dr, post, seed  # noqa: F401
from test_accounting_docs import setup, wipe_all

D = "2026-11-02"


def wipe(owner):
    as_admin(owner)
    owner.execute("DELETE FROM bank_lines")
    owner.execute("DELETE FROM bank_imports")
    wipe_all(owner)


@pytest.fixture(autouse=True)
def clean(owner, world):
    yield
    wipe(owner)


def row(amount, date=D, desc="x", ref=None, bal=None):
    return {"date": date, "description": desc, "reference": ref, "amount_minor": amount, "balance_minor": bal}


def imp(app, world, account, rows, who="a", name="s.csv"):
    return app.execute("SELECT * FROM bank_import(%s,%s,%s,%s,%s,%s::jsonb)", (ulid(), world[who]["tenant"], world[who]["user"], account, name, json.dumps(rows))).fetchone()


def lines(app, account):
    return app.execute("SELECT id, txn_date, amount_minor, status, journal_line_seq FROM bank_lines WHERE account_id=%s ORDER BY txn_date, amount_minor, id", (account,)).fetchall()


def count(app):
    return app.execute("SELECT (SELECT count(*) FROM bank_lines), (SELECT count(*) FROM bank_imports)").fetchone()


def book(app, world, account, amount, date=D, memo="t"):
    other = acc(app, world, "sales") if amount > 0 else acc(app, world, "rent")
    lines_ = [dr(account, amount), cr(other, amount)] if amount > 0 else [dr(other, -amount), cr(account, -amount)]
    e, r = post(app, world, lines_, date=date, memo=memo)
    assert r[0] == "ok", r
    return e


def jseq(app, entry, account):
    return app.execute("SELECT seq FROM journal_lines WHERE entry_id=%s AND account_id=%s", (entry, account)).fetchone()[0]


def test_import_is_idempotent_and_keeps_genuine_repeats(app, world):
    A = setup(app, world)
    rows = [row(1000, desc="bKash in"), row(1000, desc="bKash in"), row(-300, desc="fee")]       # two identical lines are two real transactions
    assert tuple(imp(app, world, A["bank"], rows)) == ("ok", 3, 0)
    assert tuple(imp(app, world, A["bank"], rows)) == ("ok", 0, 3)                                # the same file again adds nothing
    assert len(lines(app, A["bank"])) == 3
    r = imp(app, world, A["bank"], rows + [row(1000, desc="bKash in"), row(777, desc="new")])      # overlapping file: a third identical line IS new
    assert tuple(r) == ("ok", 2, 3)
    assert len(lines(app, A["bank"])) == 5
    assert app.execute("SELECT rows_total, rows_new, rows_dup FROM bank_imports ORDER BY created_at DESC LIMIT 1").fetchone() == (5, 2, 3)
    assert tuple(imp(app, world, A["bank"], [row(1000, desc="BKASH IN ")])) == ("ok", 0, 1)        # case/space differences are the same line
    assert tuple(imp(app, world, A["cash"], [row(1000, desc="bKash in")])) == ("ok", 1, 0)         # another account has its own keys
    assert tuple(imp(app, world, A["bank"], [row(55, desc="same", ref="TX1"), row(55, desc="same", ref="TX2")])) == ("ok", 2, 0)   # the reference tells lines apart
    assert tuple(imp(app, world, A["bank"], [row(55, desc="same", ref="tx1")])) == ("ok", 0, 1)


def test_a_bad_file_writes_nothing(app, world):
    A = setup(app, world)
    assert imp(app, world, A["bank"], [row(10)])[0] == "ok"
    before = count(app)
    for rows, exp in (([row(1), row(0)], ("bad_row", 2)), ([row(5, date="2026-13-45")], ("bad_row", 1)), ([row(5), row(5, date="2030-01-01")], ("bad_row", 2)),
                      ([row(5, date="1999-12-31")], ("bad_row", 1)), ([row(10**13)], ("bad_row", 1)), ([row(5, desc="x" * 301)], ("bad_row", 1)),
                      ([row(5, ref="r" * 81)], ("bad_row", 1)), ([{"date": D}], ("bad_row", 1)), ([row(7, bal=10**16)], ("bad_row", 1))):
        r = imp(app, world, A["bank"], rows)
        assert (r[0], r[1]) == exp, rows
    assert imp(app, world, A["bank"], [])[0] == "bad_rows"
    assert imp(app, world, A["bank"], [row(i + 1, desc=str(i)) for i in range(5001)])[0] == "bad_rows"
    assert count(app) == before
    assert tuple(imp(app, world, A["bank"], [row(i + 1, desc=str(i)) for i in range(5000)])) == ("ok", 5000, 0)    # the limit itself is fine


def test_only_own_cash_accounts_take_statements(app, world):
    A = setup(app, world)
    assert imp(app, world, A["sales"], [row(5)])[0] == "bad_account"
    assert imp(app, world, A["ar"], [row(5)])[0] == "bad_account"                                  # not a cash account
    ctx(app, world, "b")
    seed(app, world, "b")
    assert imp(app, world, A["bank"], [row(5)], who="b")[0] == "bad_account"                       # a's account, b's workspace
    ctx(app, world, "a")
    assert count(app) == (0, 0)
    assert imp(app, world, "z" * 26, [row(5)])[0] == "bad_account"                                  # no such account
    for sql, args in (("SELECT bank_unmatch(%s)", ("n" * 26,)), ("SELECT bank_auto_match(%s,%s,3)", ("n" * 26, world["a"]["user"]))):
        with pytest.raises(psycopg.errors.InsufficientPrivilege):
            app.execute(sql, args)


def test_match_rules(app, world):
    A = setup(app, world)
    e1, e2 = book(app, world, A["bank"], 1000), book(app, world, A["bank"], 1000, memo="second")
    imp(app, world, A["bank"], [row(1000), row(1000, desc="y")])
    l1, l2 = [r[0] for r in lines(app, A["bank"])]
    s1, s2 = jseq(app, e1, A["bank"]), jseq(app, e2, A["bank"])
    sug = app.execute("SELECT line_id, journal_line_seq, day_diff FROM bank_suggestions(%s, 3)", (A["bank"],)).fetchall()
    assert len(sug) == 4 and {x[1] for x in sug} == {s1, s2}
    assert app.execute("SELECT bank_match(%s,%s,%s)", (l1, s1, world["a"]["user"])).fetchone()[0] == "ok"
    assert app.execute("SELECT bank_match(%s,%s,%s)", (l1, s2, world["a"]["user"])).fetchone()[0] == "not_open"      # already matched
    assert app.execute("SELECT bank_match(%s,%s,%s)", (l2, s1, world["a"]["user"])).fetchone()[0] == "taken"         # journal line used once only
    assert app.execute("SELECT line_id FROM bank_suggestions(%s, 3)", (A["bank"],)).fetchall() == [(l2,)]
    wrong_amount = jseq(app, book(app, world, A["bank"], 999), A["bank"])
    wrong_account = jseq(app, book(app, world, A["cash"], 1000), A["cash"])
    wrong_side = jseq(app, book(app, world, A["bank"], -1000), A["bank"])
    for s in (wrong_amount, wrong_account, wrong_side, 123456789):
        assert app.execute("SELECT bank_match(%s,%s,%s)", (l2, s, world["a"]["user"])).fetchone()[0] == "bad_journal_line", s
    assert app.execute("SELECT bank_unmatch(%s)", (l1,)).fetchone()[0] == "ok"
    assert app.execute("SELECT bank_unmatch(%s)", (l1,)).fetchone()[0] == "not_matched"
    assert app.execute("SELECT bank_match(%s,%s,%s)", (l2, s1, world["a"]["user"])).fetchone()[0] == "ok"           # freed line can be reused
    assert app.execute("SELECT bank_ignore(%s, true)", (l2,)).fetchone()[0] == "not_open"                           # matched lines cannot be set aside
    assert app.execute("SELECT bank_ignore(%s, true)", (l1,)).fetchone()[0] == "ok"
    assert app.execute("SELECT bank_ignore(%s, true)", (l1,)).fetchone()[0] == "not_open"
    assert app.execute("SELECT bank_match(%s,%s,%s)", (l1, s2, world["a"]["user"])).fetchone()[0] == "not_open"      # ignored lines are not matched either
    assert app.execute("SELECT bank_ignore(%s, false)", (l1,)).fetchone()[0] == "ok"
    assert app.execute("SELECT bank_ignore(%s, false)", (l1,)).fetchone()[0] == "not_ignored"
    assert [r[3] for r in lines(app, A["bank"])] == ["unmatched", "matched"]


def test_auto_match_pairs_nearest_first_one_to_one_within_the_window(app, world):
    A = setup(app, world)
    eA = book(app, world, A["bank"], 500, date="2026-11-01")
    eB = book(app, world, A["bank"], 500, date="2026-11-04")
    far = book(app, world, A["bank"], 800, date="2026-10-20")
    imp(app, world, A["bank"], [row(500, date="2026-11-03", desc="p"), row(500, date="2026-11-04", desc="q"), row(800, date="2026-11-02", desc="far"), row(61, desc="nothing")])
    assert app.execute("SELECT bank_auto_match(%s,%s,3)", (A["bank"], world["a"]["user"])).fetchone()[0] == 2
    got = {r[1:3]: r[4] for r in lines(app, A["bank"])}
    assert got[(__import__("datetime").date(2026, 11, 4), 500)] == jseq(app, eB, A["bank"])        # exact day wins for the 11-04 line
    assert got[(__import__("datetime").date(2026, 11, 3), 500)] == jseq(app, eA, A["bank"])        # the other line takes what is left
    assert got[(__import__("datetime").date(2026, 11, 2), 800)] is None                            # 13 days away
    assert app.execute("SELECT bank_auto_match(%s,%s,30)", (A["bank"], world["a"]["user"])).fetchone()[0] == 1   # a wider window now catches it
    assert app.execute("SELECT bank_auto_match(%s,%s,3)", (A["bank"], world["a"]["user"])).fetchone()[0] == 0
    assert len({r[4] for r in lines(app, A["bank"]) if r[4]}) == 3
    assert app.execute("SELECT bank_auto_match(%s,%s,-5)", (A["bank"], world["a"]["user"])).fetchone()[0] == 0   # silly window falls back to 3, no error
    book(app, world, A["bank"], 321, date="2026-10-13")                                                           # 20 days away
    book(app, world, A["bank"], 322, date="2026-09-17")                                                           # 46 days away
    imp(app, world, A["bank"], [row(321, date="2026-11-02", desc="late"), row(322, date="2026-11-02", desc="later")])
    assert app.execute("SELECT bank_auto_match(%s,%s,999)", (A["bank"], world["a"]["user"])).fetchone()[0] == 0    # 999 is not allowed -> default 3, not "anything goes"
    assert app.execute("SELECT count(*) FROM bank_suggestions(%s, 999)", (A["bank"],)).fetchone()[0] == 1          # suggestions cap at 30 days: the 20-day one, not the 46-day one


def test_create_entry_posts_the_missing_line_and_pairs_it(app, world):
    A = setup(app, world)
    u = world["a"]["user"]
    fee, income = acc(app, world, "bank_charges"), acc(app, world, "other_income")
    imp(app, world, A["bank"], [row(-250, date="2026-11-03", desc="SMS charge"), row(4000, date="2026-11-02", desc="interest")])
    out_id, in_id = [r[0] for r in sorted(lines(app, A["bank"]), key=lambda r: r[2])]
    assert app.execute("SELECT bank_create_entry(%s,%s,%s,%s,NULL)", (out_id, ulid(), u, fee)).fetchone()[0] == "ok"
    assert app.execute("SELECT bank_create_entry(%s,%s,%s,%s,%s)", (in_id, ulid(), u, income, "Interest Oct")).fetchone()[0] == "ok"
    e = app.execute("SELECT e.entry_date, e.memo, e.source, e.source_ref, a.system_key, l.debit_minor, l.credit_minor FROM bank_lines b JOIN journal_lines bl ON bl.seq=b.journal_line_seq "
                    "JOIN journal_entries e ON e.id=bl.entry_id JOIN journal_lines l ON l.entry_id=e.id JOIN accounts a ON a.id=l.account_id WHERE b.id=%s ORDER BY l.seq", (out_id,)).fetchall()
    assert [(x[4], x[5], x[6]) for x in e] == [("bank_charges", 250, 0), ("bank", 0, 250)]
    assert str(e[0][0]) == "2026-11-03" and e[0][1] == "SMS charge" and e[0][2] == "system" and e[0][3] == "bank:" + out_id
    e2 = app.execute("SELECT e.memo, a.system_key, l.debit_minor, l.credit_minor FROM bank_lines b JOIN journal_lines bl ON bl.seq=b.journal_line_seq JOIN journal_lines l ON l.entry_id=bl.entry_id "
                     "JOIN journal_entries e ON e.id=l.entry_id JOIN accounts a ON a.id=l.account_id WHERE b.id=%s ORDER BY l.seq", (in_id,)).fetchall()
    assert [(x[1], x[2], x[3]) for x in e2] == [("bank", 4000, 0), ("other_income", 0, 4000)] and e2[0][0] == "Interest Oct"
    assert {r[3] for r in lines(app, A["bank"])} == {"matched"}
    assert app.execute("SELECT bank_create_entry(%s,%s,%s,%s,NULL)", (out_id, ulid(), u, fee)).fetchone()[0] == "not_open"      # already handled


def test_create_entry_refusals_leave_everything_as_it_was(app, world):
    A = setup(app, world)
    u = world["a"]["user"]
    imp(app, world, A["bank"], [row(-100, date="2026-10-20", desc="old"), row(-100, date="2026-11-03", desc="new")])
    old_id, new_id = [r[0] for r in lines(app, A["bank"])]
    heading = app.execute("SELECT id FROM accounts WHERE code='6000'").fetchone()[0].strip()
    rent = A["rent"]
    app.execute("SELECT acct_lock(%s,%s::date,%s)", (world["a"]["tenant"], "2026-10-31", u))
    entries = app.execute("SELECT count(*) FROM journal_entries").fetchone()[0]
    for counter, line_id, exp in ((A["bank"], new_id, "bad_account"), (heading, new_id, "bad_account"), ("x" * 26, new_id, "bad_account"), (rent, old_id, "locked")):
        assert app.execute("SELECT bank_create_entry(%s,%s,%s,%s,NULL)", (line_id, ulid(), u, counter)).fetchone()[0] == exp, (counter, exp)
    assert app.execute("SELECT count(*) FROM journal_entries").fetchone()[0] == entries
    assert {r[3] for r in lines(app, A["bank"])} == {"unmatched"}
    assert app.execute("SELECT bank_create_entry(%s,%s,%s,%s,NULL)", (new_id, ulid(), u, rent)).fetchone()[0] == "ok"                # unlocked month still works


def test_reconciliation_summary(app, world):
    A = setup(app, world)
    u = world["a"]["user"]
    book(app, world, A["bank"], 9000, date="2026-09-30", memo="opening")                              # before the statement starts -> never "missing from statement"
    e1 = book(app, world, A["bank"], 2000, date="2026-11-01")
    book(app, world, A["bank"], -500, date="2026-11-02", memo="cheque not yet cleared")
    imp(app, world, A["bank"], [row(2000, date="2026-11-01", bal=11000), row(-120, date="2026-11-03", bal=10880, desc="charge")])
    r = app.execute("SELECT * FROM bank_reconciliation(%s,'2026-11-30')", (A["bank"],)).fetchone()
    assert (r[0], r[1], str(r[2]), r[3], r[4], r[5], r[6], r[7], r[8]) == (10500, 10880, "2026-11-01", 0, 2, 1880, 0, 2, 1500)
    app.execute("SELECT bank_auto_match(%s,%s,3)", (A["bank"], u))
    charge = [x for x in lines(app, A["bank"]) if x[2] == -120][0][0]
    app.execute("SELECT bank_create_entry(%s,%s,%s,%s,NULL)", (charge, ulid(), u, acc(app, world, "bank_charges")))
    r = app.execute("SELECT * FROM bank_reconciliation(%s,'2026-11-30')", (A["bank"],)).fetchone()
    assert (r[0], r[1], r[3], r[4], r[5], r[7], r[8]) == (10380, 10880, 2, 0, 0, 1, -500)            # book 10380 vs bank 10880: the only gap is the uncleared -500... and 500 = 10880-10380
    assert r[1] - r[0] == -r[8]                                                                         # statement - books = - (books not on the statement)
    r = app.execute("SELECT * FROM bank_reconciliation(%s,'2026-11-01')", (A["bank"],)).fetchone()     # as-of really filters
    assert (r[0], r[1], r[3], r[4]) == (11000, 11000, 1, 0)
    imp(app, world, A["bank"], [row(-77, date="2026-11-04", desc="bank error", bal=10803)])
    odd = [x for x in lines(app, A["bank"]) if x[2] == -77][0][0]
    assert app.execute("SELECT bank_ignore(%s, true)", (odd,)).fetchone()[0] == "ok"
    r = app.execute("SELECT * FROM bank_reconciliation(%s,'2026-11-30')", (A["bank"],)).fetchone()
    assert (r[3], r[4], r[5], r[6]) == (2, 0, 0, 1)                                                      # an ignored line is neither open nor matched
    assert app.execute("SELECT * FROM bank_reconciliation(%s,'2026-11-30')", (A["cash"],)).fetchone()[1] is None     # no statement -> no statement balance


def test_isolation_and_no_direct_writes(app, world):
    A = setup(app, world)
    imp(app, world, A["bank"], [row(100)])
    lid = lines(app, A["bank"])[0][0]
    ctx(app, world, "b")
    seed(app, world, "b")
    assert app.execute("SELECT count(*) FROM bank_lines").fetchone()[0] == 0
    for sql in ("SELECT bank_unmatch(%s)", "SELECT bank_ignore(%s, true)"):
        with pytest.raises(psycopg.errors.InsufficientPrivilege):
            app.execute(sql, (lid,))
        app.rollback() if hasattr(app, "rollback") and not app.autocommit else None
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("SELECT bank_match(%s,1,%s)", (lid, world["b"]["user"]))
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("SELECT bank_create_entry(%s,%s,%s,%s,NULL)", (lid, ulid(), world["b"]["user"], acc(app, world, "rent", "b")))
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("SELECT bank_auto_match(%s,%s,3)", (A["bank"], world["b"]["user"]))
    for sql in ("INSERT INTO bank_lines(id) VALUES ('x')", "UPDATE bank_lines SET status='ignored'", "DELETE FROM bank_lines", "UPDATE bank_imports SET rows_new=0"):
        with pytest.raises(psycopg.errors.InsufficientPrivilege):
            app.execute(sql)
    ctx(app, world, "a")
    assert lines(app, A["bank"])[0][3] == "unmatched"
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("UPDATE bank_lines SET status='ignored'")


# ---------------------------------------------------------------------------------------------------------------------
hyp = pytest.importorskip("hypothesis")
from hypothesis import HealthCheck, given, settings, strategies as st  # noqa: E402
import datetime  # noqa: E402

entry = st.tuples(st.integers(min_value=1, max_value=300), st.booleans(), st.integers(min_value=1, max_value=25), st.booleans(), st.integers(min_value=-2, max_value=2))
extra = st.tuples(st.integers(min_value=1, max_value=300), st.booleans(), st.integers(min_value=1, max_value=25))


@settings(max_examples=40, deadline=None, suppress_health_check=[HealthCheck.function_scoped_fixture])
@given(st.lists(entry, min_size=1, max_size=10), st.lists(extra, max_size=4))
def test_property_every_statement_line_ends_up_paired_exactly_once(app, world, owner, books, extras):
    A = setup(app, world)
    u = world["a"]["user"]
    base = datetime.date(2026, 10, 1)
    stmt = []
    for amt, inflow, day, on_statement, shift in books:
        signed = amt if inflow else -amt
        book(app, world, A["bank"], signed, date=str(base + datetime.timedelta(days=day)))
        if on_statement:
            stmt.append(row(signed, date=str(base + datetime.timedelta(days=max(1, min(28, day + shift)))), desc=f"s{len(stmt)}"))
    for amt, inflow, day in extras:
        stmt.append(row(amt if inflow else -amt, date=str(base + datetime.timedelta(days=day)), desc=f"e{len(stmt)}"))
    if stmt:
        assert imp(app, world, A["bank"], stmt)[0] == "ok"
        app.execute("SELECT bank_auto_match(%s,%s,3)", (A["bank"], u))
        for lid, _, amt, status, _ in lines(app, A["bank"]):
            if status == "unmatched":
                counter = acc(app, world, "other_income" if amt > 0 else "misc_expense")
                assert app.execute("SELECT bank_create_entry(%s,%s,%s,%s,NULL)", (lid, ulid(), u, counter)).fetchone()[0] == "ok"
        ls = lines(app, A["bank"])
        assert len(ls) == len(stmt) and all(x[3] == "matched" for x in ls), (books, extras)
        seqs = [x[4] for x in ls]
        assert len(set(seqs)) == len(seqs)                                                                       # a journal line is never used twice
        bad = app.execute("SELECT count(*) FROM bank_lines b JOIN journal_lines l ON l.seq=b.journal_line_seq WHERE b.amount_minor <> l.debit_minor - l.credit_minor OR l.account_id <> b.account_id").fetchone()[0]
        assert bad == 0
        r = app.execute("SELECT * FROM bank_reconciliation(%s,'2026-12-31')", (A["bank"],)).fetchone()
        assert r[4] == 0 and r[3] == len(stmt)
        assert r[0] == sum(x[2] for x in ls) + app.execute("SELECT coalesce(sum(l.debit_minor-l.credit_minor),0) FROM journal_lines l WHERE l.account_id=%s AND NOT EXISTS (SELECT 1 FROM bank_lines b WHERE b.journal_line_seq=l.seq)", (A["bank"],)).fetchone()[0]
    wipe(owner)
