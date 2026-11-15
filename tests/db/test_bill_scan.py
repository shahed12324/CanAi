import hashlib
import json

import psycopg
import pytest

from conftest import as_admin, ulid
from test_accounting import acc, ctx, seed  # noqa: F401
from test_accounting_docs import D, line, party, setup, wipe_all


def wipe(owner):
    as_admin(owner)
    owner.execute("DELETE FROM bill_scans")
    wipe_all(owner)


@pytest.fixture(autouse=True)
def clean(owner, world):
    yield
    wipe(owner)


def sha(s="a"):
    return hashlib.sha256(s.encode()).hexdigest()


def start(app, world, content="a", *, who="a", name="bill.jpg", mime="image/jpeg", size=1000, user=True):
    sid = ulid()
    ctx(app, world, who)
    r = app.execute("SELECT * FROM bill_scan_start(%s,%s,%s,%s,%s,%s,%s)", (sid, world[who]["tenant"], world[who]["user"] if user else None, name, mime, size, sha(content))).fetchone()
    return sid, tuple(r)


DRAFT = {"kind": "bill", "supplier_name": "Rahim Traders", "lines": [{"description": "Paper", "amount_minor": 80000, "account_code": "6100"}], "vat_minor": 0, "total_minor": 80000}


def ready(app, sid, draft=None, flags=("total_mismatch",), credits="0.0450"):
    return app.execute("SELECT bill_scan_ready(%s,%s::jsonb,%s,'claude-x',1200,180,%s::numeric)", (sid, json.dumps(draft or DRAFT), list(flags), credits)).fetchone()[0]


def status(app, sid):
    return app.execute("SELECT status, error, doc_id FROM bill_scans WHERE id=%s", (sid,)).fetchone()


def post(app, world, sid, kind, party_id, lines, *, date=D, due=None, vat=0, pay=None, who="a"):
    did = ulid()
    ctx(app, world, who)
    r = app.execute("SELECT * FROM bill_scan_post(%s,%s,%s,%s,%s,%s,%s::date,%s::date,'INV-77',%s::jsonb,%s,%s)",
                    (sid, did, ulid(), world[who]["user"], kind, party_id, date, due, json.dumps(lines), vat, pay)).fetchone()
    return did, tuple(r)


# ----------------------------------------------------------------------------------------------------------------- start
def test_start_registers_a_scan(app, world):
    sid, r = start(app, world)
    assert r == ("ok", sid)
    assert app.execute("SELECT status, file_name, mime, size_bytes, sha256, extracted, flags FROM bill_scans WHERE id=%s", (sid,)).fetchone() == ("scanning", "bill.jpg", "image/jpeg", 1000, sha(), None, [])


def test_the_same_file_is_not_scanned_twice_while_a_scan_is_live(app, world):
    setup(app, world)
    sid, _ = start(app, world)
    assert start(app, world)[1] == ("duplicate", sid)                                          # still scanning
    assert ready(app, sid) == "ok"
    assert start(app, world)[1] == ("duplicate", sid)                                          # ready
    p = party(app, world, "supplier")
    post(app, world, sid, "bill", p, [line(acc(app, world, "rent"), 80000)])
    assert start(app, world)[1] == ("duplicate", sid)                                          # used
    assert app.execute("SELECT count(*) FROM bill_scans").fetchone()[0] == 1
    assert start(app, world, "other file")[1][0] == "ok"                                       # a different file is fine


def test_a_failed_or_discarded_scan_frees_the_file(app, world):
    sid, _ = start(app, world)
    assert app.execute("SELECT bill_scan_fail(%s,'unreadable','m',10,5,0.01)", (sid,)).fetchone()[0] == "ok"
    sid2, r = start(app, world)
    assert r == ("ok", sid2)
    assert ready(app, sid2) == "ok"
    assert app.execute("SELECT bill_scan_discard(%s,%s)", (sid2, world["a"]["user"])).fetchone()[0] == "ok"
    assert start(app, world)[1][0] == "ok"


def test_a_scan_stuck_for_ten_minutes_is_closed_and_frees_the_file(app, world, owner):
    sid, _ = start(app, world)
    as_admin(owner)
    owner.execute("UPDATE bill_scans SET created_at = now() - interval '11 minutes' WHERE id=%s", (sid,))
    sid2, r = start(app, world)
    assert r == ("ok", sid2) and status(app, sid) == ("failed", "timeout", None)


def test_start_refuses_bad_input(app, world):
    for kw in (dict(name="  "), dict(mime="image/gif"), dict(mime="text/html"), dict(size=0), dict(size=10485761)):
        assert start(app, world, **kw)[1][0] == "bad_input", kw
    ctx(app, world)
    for sha_ in ("zz", "A" * 64, "a" * 63, None):
        assert app.execute("SELECT result FROM bill_scan_start(%s,%s,%s,'n','image/png',5,%s)", (ulid(), world["a"]["tenant"], world["a"]["user"], sha_)).fetchone()[0] == "bad_input"
    with pytest.raises(psycopg.errors.Error):
        start(app, world, user=False)
    assert app.execute("SELECT count(*) FROM bill_scans").fetchone()[0] == 0


# ----------------------------------------------------------------------------------------------------------------- ready / fail / discard
def test_ready_stores_the_draft_and_cost(app, world):
    sid, _ = start(app, world)
    assert ready(app, sid) == "ok"
    row = app.execute("SELECT status, model, input_tokens, output_tokens, credits, flags, extracted->>'supplier_name' FROM bill_scans WHERE id=%s", (sid,)).fetchone()
    assert row == ("ready", "claude-x", 1200, 180, __import__("decimal").Decimal("0.0450"), ["total_mismatch"], "Rahim Traders")
    assert ready(app, sid) == "not_scanning"                                                 # once


@pytest.mark.parametrize("draft", [None, [], {"lines": []}, {"lines": "x"}, {"supplier": "no lines"}])
def test_ready_needs_a_draft_with_lines(app, world, draft):
    sid, _ = start(app, world)
    r = app.execute("SELECT bill_scan_ready(%s,%s::jsonb,'{}','m',1,1,0)", (sid, json.dumps(draft) if draft is not None else None)).fetchone()[0]
    assert r == "bad_input" and status(app, sid)[0] == "scanning"


def test_ready_rejects_negative_numbers(app, world):
    sid, _ = start(app, world)
    for a in ("-1,1,0", "1,-1,0", "1,1,-1"):
        i, o, c = a.split(",")
        assert app.execute("SELECT bill_scan_ready(%s,%s::jsonb,'{}','m',%s,%s,%s)", (sid, json.dumps(DRAFT), i, o, c)).fetchone()[0] == "bad_input"


def test_fail_records_what_the_provider_charged(app, world):
    sid, _ = start(app, world)
    assert app.execute("SELECT bill_scan_fail(%s,'unreadable','claude-x',1200,20,0.0123)", (sid,)).fetchone()[0] == "ok"
    assert app.execute("SELECT status, error, credits, input_tokens FROM bill_scans WHERE id=%s", (sid,)).fetchone() == ("failed", "unreadable", __import__("decimal").Decimal("0.0123"), 1200)
    assert app.execute("SELECT bill_scan_fail(%s,'again','m',0,0,0)", (sid,)).fetchone()[0] == "not_scanning"
    assert ready(app, sid) == "not_scanning"


def test_discard_only_open_drafts(app, world):
    setup(app, world)
    u = world["a"]["user"]
    sid, _ = start(app, world)
    assert app.execute("SELECT bill_scan_discard(%s,%s)", (sid, u)).fetchone()[0] == "not_open"      # still scanning
    ready(app, sid)
    assert app.execute("SELECT bill_scan_discard(%s,%s)", (sid, u)).fetchone()[0] == "ok"
    assert app.execute("SELECT bill_scan_discard(%s,%s)", (sid, u)).fetchone()[0] == "not_open"
    sid2, _ = start(app, world, "b")
    ready(app, sid2)
    post(app, world, sid2, "expense", None, [line(acc(app, world, "rent"), 1000)], pay=acc(app, world, "cash"))
    assert app.execute("SELECT bill_scan_discard(%s,%s)", (sid2, u)).fetchone()[0] == "not_open"      # used


def test_a_failed_scan_can_be_discarded_but_never_posted(app, world):
    setup(app, world)
    u = world["a"]["user"]
    sid, _ = start(app, world)
    app.execute("SELECT bill_scan_fail(%s,'unreadable',NULL,0,0,0)", (sid,))
    did, r = post(app, world, sid, "expense", None, [line(acc(app, world, "rent"), 1000)], pay=acc(app, world, "cash"))
    assert r[0] == "not_ready"
    assert app.execute("SELECT bill_scan_discard(%s,%s)", (sid, u)).fetchone()[0] == "ok"


def test_long_names_and_errors_are_trimmed_and_negative_usage_is_clamped(app, world):
    sid, _ = start(app, world, name="  " + "x" * 300 + "  ")
    assert app.execute("SELECT file_name FROM bill_scans WHERE id=%s", (sid,)).fetchone()[0] == "x" * 120
    assert app.execute("SELECT bill_scan_fail(%s,%s,'m',-5,-6,-1)", (sid, "e" * 200)).fetchone()[0] == "ok"
    assert app.execute("SELECT length(error), input_tokens, output_tokens, credits FROM bill_scans WHERE id=%s", (sid,)).fetchone() == (60, 0, 0, 0)


# ----------------------------------------------------------------------------------------------------------------- post
def test_a_reviewed_draft_becomes_a_bill_once(app, world):
    A = setup(app, world)
    p = party(app, world, "supplier")
    sid, _ = start(app, world)
    ready(app, sid)
    rent = acc(app, world, "rent")
    did, r = post(app, world, sid, "bill", p, [line(rent, 80000, "Paper"), line(rent, 20000, "Pens")], vat=15000, due="2026-11-30")
    assert r == ("ok", did, "BILL-0001", 115000)
    assert status(app, sid) == ("used", None, did)
    assert app.execute("SELECT kind, total_minor, party_id, reference, status FROM acct_docs WHERE id=%s", (did,)).fetchone() == ("bill", 115000, p, "INV-77", "open")
    assert app.execute("SELECT count(*) FROM journal_entries").fetchone()[0] == 1
    did2, r2 = post(app, world, sid, "bill", p, [line(rent, 1000)])
    assert r2[0] == "not_ready" and app.execute("SELECT count(*) FROM acct_docs").fetchone()[0] == 1       # never twice


def test_a_paid_expense_without_a_supplier(app, world):
    A = setup(app, world)
    sid, _ = start(app, world)
    ready(app, sid)
    did, r = post(app, world, sid, "expense", None, [line(acc(app, world, "rent"), 5000)], pay=A["bank"])
    assert r[0] == "ok" and app.execute("SELECT status, paid_minor, pay_account_id FROM acct_docs WHERE id=%s", (did,)).fetchone() == ("paid", 5000, A["bank"])


def test_only_bills_and_expenses_can_come_from_a_scan(app, world):
    A = setup(app, world)
    c = party(app, world, "customer")
    sid, _ = start(app, world)
    ready(app, sid)
    for kind in ("invoice", "weird"):
        assert post(app, world, sid, kind, c, [line(A["sales"], 100)])[1][0] == "bad_kind"
    assert status(app, sid)[0] == "ready"


def test_a_refused_document_leaves_the_draft_ready_and_consumes_no_number(app, world):
    A = setup(app, world)
    p = party(app, world, "supplier")
    sid, _ = start(app, world)
    ready(app, sid)
    rent = acc(app, world, "rent")
    u = world["a"]["user"]
    ctx(app, world)
    app.execute("SELECT acct_lock(%s,%s::date,%s)", (world["a"]["tenant"], D, u))
    assert post(app, world, sid, "bill", p, [line(rent, 1000)])[1][0] == "locked"
    assert post(app, world, sid, "bill", p, [line(A["cash"], 1000)], date="2099-01-01")[1][0] in ("bad_line_account", "bad_date", "bad_due")
    assert post(app, world, sid, "bill", "z" * 26, [line(rent, 1000)], date=str(__import__("datetime").date.today()))[1][0] == "bad_party"
    assert status(app, sid)[0] == "ready" and app.execute("SELECT count(*) FROM acct_docs").fetchone()[0] == 0
    did, r = post(app, world, sid, "bill", p, [line(rent, 1000)], date=str(__import__("datetime").date.today()))
    assert r[0] == "ok" and r[2] == "BILL-0001"                                              # the failed tries did not use up a number


def test_only_a_ready_draft_can_be_posted(app, world):
    setup(app, world)
    p = party(app, world, "supplier")
    rent = acc(app, world, "rent")
    sid, _ = start(app, world)
    assert post(app, world, sid, "bill", p, [line(rent, 1000)])[1][0] == "not_ready"          # still scanning
    ready(app, sid)
    app.execute("SELECT bill_scan_discard(%s,%s)", (sid, world["a"]["user"]))
    assert post(app, world, sid, "bill", p, [line(rent, 1000)])[1][0] == "not_ready"          # discarded


# ----------------------------------------------------------------------------------------------------------------- isolation
def test_another_workspace_cannot_touch_a_scan(app, world):
    A = setup(app, world)
    sid, _ = start(app, world)
    ready(app, sid)
    ctx(app, world, "b")
    ub = world["b"]["user"]
    for sql, args in (("SELECT bill_scan_ready(%s,%s::jsonb,'{}','m',1,1,0)", (sid, json.dumps(DRAFT))), ("SELECT bill_scan_fail(%s,'x','m',0,0,0)", (sid,)), ("SELECT bill_scan_discard(%s,%s)", (sid, ub)),
                      ("SELECT * FROM bill_scan_post(%s,%s,%s,%s,'expense',NULL,CURRENT_DATE,NULL,NULL,'[]'::jsonb,0,NULL)", (sid, ulid(), ulid(), ub)),
                      ("SELECT * FROM bill_scan_start(%s,%s,%s,'n','image/png',5,%s)", (ulid(), world["a"]["tenant"], ub, sha("zz")))):
        with pytest.raises(psycopg.errors.InsufficientPrivilege):
            app.execute(sql, args)
        ctx(app, world, "b")
    assert app.execute("SELECT count(*) FROM bill_scans").fetchone()[0] == 0
    sid_b, r = start(app, world, "a", who="b")                                                # the same file in another workspace is its own scan
    assert r == ("ok", sid_b)
    ctx(app, world)
    assert status(app, sid)[0] == "ready"


def test_the_app_role_cannot_write_scans_directly(app, world):
    sid, _ = start(app, world)
    ctx(app, world)
    for sql in ("UPDATE bill_scans SET status='used'", "DELETE FROM bill_scans", "INSERT INTO bill_scans (id) VALUES ('x')"):
        with pytest.raises(psycopg.errors.InsufficientPrivilege):
            app.execute(sql)
        ctx(app, world)


# ----------------------------------------------------------------------------------------------------------------- property
hyp = pytest.importorskip("hypothesis")
from hypothesis import HealthCheck, given, settings, strategies as st  # noqa: E402

op = st.tuples(st.sampled_from(["start", "ready", "fail", "discard", "post"]), st.integers(0, 3), st.integers(0, 2))


@settings(max_examples=40, deadline=None, suppress_health_check=[HealthCheck.function_scoped_fixture])
@given(st.lists(op, min_size=1, max_size=18))
def test_property_a_scan_makes_at_most_one_document_and_files_stay_unique(app, world, owner, ops):
    try:
        A = setup(app, world)
        p = party(app, world, "supplier")
        rent = acc(app, world, "rent")
        u = world["a"]["user"]
        scans = []
        for kind, a, b in ops:
            if kind == "start":
                sid, r = start(app, world, f"file{a}")
                if r[0] == "ok":
                    scans.append(sid)
            elif scans:
                sid = scans[a % len(scans)]
                if kind == "ready":
                    ready(app, sid)
                elif kind == "fail":
                    app.execute("SELECT bill_scan_fail(%s,'x','m',0,0,0)", (sid,))
                elif kind == "discard":
                    app.execute("SELECT bill_scan_discard(%s,%s)", (sid, u))
                else:
                    post(app, world, sid, "bill" if b else "expense", p if b else None, [line(rent, 1000 + b)], pay=None if b else A["cash"])
        used = app.execute("SELECT count(*) FROM bill_scans WHERE status='used'").fetchone()[0]
        assert used == app.execute("SELECT count(*) FROM acct_docs").fetchone()[0]                    # every document has exactly one scan behind it
        assert app.execute("SELECT count(DISTINCT doc_id) FROM bill_scans WHERE doc_id IS NOT NULL").fetchone()[0] == used
        assert app.execute("SELECT count(*) FROM (SELECT sha256 FROM bill_scans WHERE status IN ('scanning','ready','used') GROUP BY sha256 HAVING count(*) > 1) x").fetchone()[0] == 0
        tb = app.execute("SELECT coalesce(sum(debit_minor),0), coalesce(sum(credit_minor),0) FROM acct_trial_balance('2099-12-31')").fetchone()
        assert tb[0] == tb[1]
    finally:
        app.rollback()
        wipe(owner)
