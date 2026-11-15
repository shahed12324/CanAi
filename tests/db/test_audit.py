import datetime

import psycopg
import pytest

from conftest import as_admin, ulid
from test_accounting import acc, cr, ctx, dr, post  # noqa: F401
from test_accounting_docs import D, line, make, party, pay, setup, wipe_all
from test_tax_withholding import item, settle, wipe as tax_wipe

TODAY = datetime.date.today()
TABLES = ("audit_item_done", "audit_items", "audit_findings", "audit_settings")


@pytest.fixture(autouse=True)
def clean_audit(owner, world):
    yield
    as_admin(owner)
    for t in TABLES:
        owner.execute(f"DELETE FROM {t}")
    owner.execute("DELETE FROM bank_lines")
    owner.execute("DELETE FROM bank_imports")
    tax_wipe(owner)


def scan(app, world, who="a"):
    ctx(app, world, who)
    return app.execute("SELECT audit_scan(%s,%s)", (world[who]["tenant"], world[who]["user"])).fetchone()[0]


def findings(app, rule=None):
    q = "SELECT rule, severity, subject_type, status, amount_minor, detail FROM audit_findings" + (" WHERE rule=%s" if rule else "") + " ORDER BY rule, found_at"
    return app.execute(q, (rule,) if rule else ()).fetchall()


def settings(app, world, **kw):
    v = dict(dup=3, factor=5, hist=5, back=30, cash=5000000, mod=100000, minimum=1000000) | kw
    ctx(app, world, "a")
    return app.execute("SELECT audit_settings_save(%s,%s,%s,%s,%s,%s,%s,%s,%s)", (world["a"]["tenant"], world["a"]["user"], v["dup"], v["factor"], v["hist"], v["back"], v["cash"], v["mod"], v["minimum"])).fetchone()[0]


def test_a_clean_book_has_no_findings(app, world):
    s = setup(app, world)
    c = party(app, world, "customer")
    make(app, world, "invoice", c, [line(s["sales"], 50000)])
    make(app, world, "invoice", c, [line(s["sales"], 70000)], date="2026-10-01")
    assert scan(app, world) == 0
    assert findings(app) == []


def test_duplicate_documents_are_found_once_and_never_re_raised(app, world):
    s = setup(app, world)
    p = party(app, world, "supplier")
    make(app, world, "bill", p, [line(s["rent"], 123400)], date="2026-11-01")
    make(app, world, "bill", p, [line(s["rent"], 123400)], date="2026-11-03")        # 2 days apart, same party and total
    make(app, world, "bill", p, [line(s["rent"], 123400)], date="2026-11-20")        # too far apart
    assert scan(app, world) == 1
    f = findings(app, "dup_doc")
    assert len(f) == 1 and f[0][1] == "high" and f[0][2] == "doc" and f[0][4] == 123400 and f[0][5]["days"] == 2
    assert scan(app, world) == 0                                                      # second scan adds nothing
    ctx(app, world, "a")
    fid = app.execute("SELECT id FROM audit_findings").fetchone()[0]
    assert app.execute("SELECT audit_review(%s,%s,%s,'dismissed','দুইটি আলাদা মাসের ভাড়া')", (fid, world["a"]["tenant"], world["a"]["user"])).fetchone()[0] == "ok"
    assert scan(app, world) == 0                                                      # a dismissed finding is not raised again
    assert findings(app)[0][3] == "dismissed"


def test_different_party_total_or_void_is_not_a_duplicate(app, world):
    s = setup(app, world)
    p1, p2 = party(app, world, "supplier", name="ক"), party(app, world, "supplier", name="খ")
    make(app, world, "bill", p1, [line(s["rent"], 5000)])
    make(app, world, "bill", p2, [line(s["rent"], 5000)])
    make(app, world, "bill", p1, [line(s["rent"], 5001)])
    d, _ = make(app, world, "bill", p1, [line(s["rent"], 5000)])
    ctx(app, world, "a")
    assert app.execute("SELECT acct_doc_void(%s,%s,%s,%s::date)", (ulid(), d, world["a"]["user"], D)).fetchone()[0] == "ok"
    assert scan(app, world) == 0


def test_duplicate_dates_boundary_follows_the_setting(app, world):
    s = setup(app, world)
    p = party(app, world, "supplier")
    make(app, world, "bill", p, [line(s["rent"], 9000)], date="2026-11-01")
    make(app, world, "bill", p, [line(s["rent"], 9000)], date="2026-11-04")          # exactly 3 days
    assert scan(app, world) == 1
    assert settings(app, world, dup=0) == "ok"
    assert scan(app, world) == 0                                                      # already recorded


def test_the_same_payment_twice(app, world):
    s = setup(app, world)
    p = party(app, world, "supplier")
    d, _ = make(app, world, "bill", p, [line(s["rent"], 100000)])
    assert pay(app, world, d, 20000, s["bank"])[1] == "ok"
    assert pay(app, world, d, 20000, s["bank"])[1] == "ok"
    assert pay(app, world, d, 20001, s["bank"])[1] == "ok"
    assert scan(app, world) == 1
    assert findings(app, "dup_payment")[0][4] == 20000


def test_a_cancelled_payment_is_not_a_duplicate(app, world):
    s = setup(app, world)
    p = party(app, world, "supplier")
    d, _ = make(app, world, "bill", p, [line(s["rent"], 100000)])
    pid, _ = pay(app, world, d, 20000, s["bank"])
    pay(app, world, d, 20000, s["bank"])
    ctx(app, world, "a")
    assert app.execute("SELECT acct_payment_void(%s,%s,%s,%s::date)", (ulid(), pid, world["a"]["user"], D)).fetchone()[0] == "ok"
    assert scan(app, world) == 0


def test_manual_entry_duplicates_ignore_reversed_ones(app, world):
    s = setup(app, world)
    ctx(app, world, "a")
    for _ in range(2):
        assert post(app, world, [dr(s["bank"], 7700), cr(s["sales"], 7700)], memo=" Same Memo ")[1][0] == "ok"
    assert scan(app, world) == 1
    assert findings(app, "dup_entry")[0][1] == "medium"
    e, _ = post(app, world, [dr(s["bank"], 333), cr(s["sales"], 333)], memo="x")
    e2, _ = post(app, world, [dr(s["bank"], 333), cr(s["sales"], 333)], memo="x")
    assert app.execute("SELECT journal_reverse(%s,%s,%s,%s::date,'undo')", (ulid(), e, world["a"]["user"], D)).fetchone()[0] == "ok"
    assert scan(app, world) == 0                                                      # one of the pair was reversed: not flagged


def test_outliers_need_history_and_a_big_enough_jump(app, world):
    s = setup(app, world)
    p = party(app, world, "supplier")
    for i in range(5):
        make(app, world, "bill", p, [line(s["rent"], 10000 + i * 1000)], date=f"2026-0{i + 1}-10")        # median 12000
    make(app, world, "bill", p, [line(s["rent"], 60000)], date="2026-07-10")        # exactly 5 x median: NOT above
    assert scan(app, world) == 0
    make(app, world, "bill", p, [line(s["rent"], 62501)], date="2026-08-10")
    assert scan(app, world) == 1
    f = findings(app, "outlier")[0]
    assert f[4] == 62501 and f[5]["usual"] > 0
    q = party(app, world, "supplier", name="নতুন")
    make(app, world, "bill", q, [line(s["rent"], 10)], date="2026-01-01")
    make(app, world, "bill", q, [line(s["rent"], 9999999)], date="2026-02-01")        # no history: never an outlier
    assert scan(app, world) == 0


def test_backdated_and_round_manual_entries(app, world):
    s = setup(app, world)
    ctx(app, world, "a")
    assert post(app, world, [dr(s["bank"], 3333), cr(s["sales"], 3333)], date="2026-08-01")[1][0] == "ok"      # entered ~95 days late
    assert post(app, world, [dr(s["bank"], 3333), cr(s["sales"], 3333)], date=D, memo="recent")[1][0] == "ok"
    assert post(app, world, [dr(s["bank"], 2000000), cr(s["sales"], 2000000)], date=D, memo="round")[1][0] == "ok"
    assert post(app, world, [dr(s["bank"], 900000), cr(s["sales"], 900000)], date=D, memo="below minimum")[1][0] == "ok"
    assert post(app, world, [dr(s["bank"], 1234567), cr(s["sales"], 1234567)], date=D, memo="odd")[1][0] == "ok"
    scan(app, world)
    assert [f[0] for f in findings(app, "backdated")] == ["backdated"]
    assert findings(app, "backdated")[0][5]["late_days"] > 30
    assert len(findings(app, "round_manual")) == 1 and findings(app, "round_manual")[0][4] == 2000000 and findings(app, "round_manual")[0][1] == "low"


def test_large_cash_payments(app, world):
    s = setup(app, world)
    p = party(app, world, "supplier")
    d, _ = make(app, world, "bill", p, [line(s["rent"], 6000000)])
    assert pay(app, world, d, 5000000, s["cash"])[1] == "ok"
    d2, _ = make(app, world, "bill", p, [line(s["rent"], 10000000)], date="2026-10-01")
    assert pay(app, world, d2, 4999999, s["cash"])[1] == "ok"                          # just under
    assert pay(app, world, d2, 5000000, s["bank"])[1] == "ok"                          # bank, not cash
    e, r = make(app, world, "expense", None, [line(s["rent"], 5000000)], pay=s["cash"])
    assert r[0] == "ok"
    e2, r = make(app, world, "expense", None, [line(s["rent"], 5000000)], pay=s["bank"], date="2026-10-02")
    assert r[0] == "ok"
    scan(app, world)
    f = findings(app, "cash_large")
    assert sorted(x[2] for x in f) == ["doc", "payment"] and all(x[4] == 5000000 for x in f)


def test_numbering_gaps_are_reported_as_ranges(app, world, owner):
    s = setup(app, world)
    ctx(app, world, "a")
    for i in range(4):
        post(app, world, [dr(s["bank"], 100 + i), cr(s["sales"], 100 + i)], memo=f"m{i}")
    as_admin(owner)
    owner.execute("ALTER TABLE journal_entries DISABLE TRIGGER journal_entries_no_change")
    owner.execute("UPDATE journal_entries SET number = number + 4 WHERE number >= 3")          # 1,2,7,8 -> hole 3..6
    owner.execute("ALTER TABLE journal_entries ENABLE TRIGGER journal_entries_no_change")
    assert scan(app, world) >= 1
    g = findings(app, "numbering_gap")
    assert len(g) == 1 and g[0][5] == {"from": 3, "to": 6} and g[0][1] == "high"
    assert scan(app, world) == 0


def test_review_rules(app, world):
    s = setup(app, world)
    p = party(app, world, "supplier")
    make(app, world, "bill", p, [line(s["rent"], 777)])
    make(app, world, "bill", p, [line(s["rent"], 777)])
    scan(app, world)
    ctx(app, world, "a")
    t, u = world["a"]["tenant"], world["a"]["user"]
    fid = app.execute("SELECT id FROM audit_findings").fetchone()[0]
    assert app.execute("SELECT audit_review(%s,%s,%s,'dismissed','  ')", (fid, t, u)).fetchone()[0] == "need_note"
    assert app.execute("SELECT audit_review(%s,%s,%s,'dismissed',NULL)", (fid, t, u)).fetchone()[0] == "need_note"
    assert app.execute("SELECT audit_review(%s,%s,%s,'weird','n')", (fid, t, u)).fetchone()[0] == "bad_status"
    assert app.execute("SELECT status FROM audit_findings").fetchone()[0] == "open"
    assert app.execute("SELECT audit_review(%s,%s,%s,'confirmed',NULL)", (fid, t, u)).fetchone()[0] == "ok"
    assert app.execute("SELECT status, reviewed_by IS NOT NULL, reviewed_at IS NOT NULL FROM audit_findings").fetchone() == ("confirmed", True, True)
    assert app.execute("SELECT audit_review(%s,%s,%s,'open',NULL)", (fid, t, u)).fetchone()[0] == "ok"
    assert app.execute("SELECT status, reviewed_by, reviewed_at FROM audit_findings").fetchone() == ("open", None, None)


def test_settings_validation_and_effect(app, world):
    s = setup(app, world)
    for kw in [dict(dup=31), dict(dup=-1), dict(factor=1), dict(hist=2), dict(back=0), dict(back=366), dict(cash=0), dict(mod=0), dict(minimum=0), dict(factor=None)]:
        assert settings(app, world, **kw) == "bad_input"
    assert app.execute("SELECT count(*) FROM audit_settings").fetchone()[0] == 0
    assert settings(app, world, cash=100) == "ok"
    assert settings(app, world, cash=200) == "ok"
    assert app.execute("SELECT count(*), max(cash_limit_minor) FROM audit_settings").fetchone() == (1, 200)
    p = party(app, world, "supplier")
    d, _ = make(app, world, "bill", p, [line(s["rent"], 500)])
    assert pay(app, world, d, 200, s["cash"])[1] == "ok"
    assert scan(app, world) == 1                                                       # limit 200 now applies


def test_checklist(app, world):
    ctx(app, world, "a")
    t, u = world["a"]["tenant"], world["a"]["user"]
    assert app.execute("SELECT audit_item_seed(%s,%s)", (t, u)).fetchone()[0] == "ok"
    assert app.execute("SELECT audit_item_seed(%s,%s)", (t, u)).fetchone()[0] == "already"
    assert app.execute("SELECT count(*) FROM audit_items").fetchone()[0] == 8
    iid = app.execute("SELECT id FROM audit_items ORDER BY sort LIMIT 1").fetchone()[0]
    this = TODAY.strftime("%Y-%m")
    nxt = (TODAY.replace(day=28) + datetime.timedelta(days=5)).strftime("%Y-%m")
    chk = lambda per, on=TODAY.isoformat(): app.execute("SELECT audit_item_done(%s,%s,%s,%s,%s::date,' ok ')", (iid, t, u, per, on)).fetchone()[0]  # noqa: E731
    assert chk(this) == "ok"
    assert chk(this) == "already"
    assert chk(nxt) == "bad_period"
    assert chk("2026-13") == "bad_period" and chk("26-01") == "bad_period" and chk(None) == "bad_period"
    assert chk("2026-01", (TODAY + datetime.timedelta(days=1)).isoformat()) == "bad_date"
    assert chk("2026-01", None) == "bad_date"
    assert app.execute("SELECT note FROM audit_item_done").fetchone()[0] == "ok"
    assert app.execute("SELECT audit_item_undo(%s,%s,%s)", (iid, t, this)).fetchone()[0] == "ok"
    assert app.execute("SELECT audit_item_undo(%s,%s,%s)", (iid, t, this)).fetchone()[0] == "missing"
    assert app.execute("SELECT audit_item_save(%s,%s,%s,'  ',10)", (ulid(), t, u)).fetchone()[0] == "bad_input"
    assert app.execute("SELECT audit_item_save(%s,%s,%s,'নতুন',1001)", (ulid(), t, u)).fetchone()[0] == "bad_input"
    assert app.execute("SELECT audit_item_save(%s,%s,%s,'নতুন আইটেম',5)", (iid, t, u)).fetchone()[0] == "ok"
    assert app.execute("SELECT label, sort FROM audit_items WHERE id=%s", (iid,)).fetchone() == ("নতুন আইটেম", 5)
    assert app.execute("SELECT audit_item_active(%s,%s,false)", (iid, t)).fetchone()[0] == "ok"
    assert app.execute("SELECT active FROM audit_items WHERE id=%s", (iid,)).fetchone()[0] is False


def test_health_checks(app, world):
    s = setup(app, world)
    ctx(app, world, "a")
    t, u = world["a"]["tenant"], world["a"]["user"]
    h = lambda: {r[0]: r[1:] for r in app.execute("SELECT * FROM audit_health(%s)", (t,)).fetchall()}  # noqa: E731
    base = h()
    assert base["books_balanced"][0] is True and base["open_high"][0] is True and base["overdue_bills"][0] is True and base["period_locked"][0] is False
    p = party(app, world, "supplier")
    make(app, world, "bill", p, [line(s["rent"], 4000)], date="2026-09-01", due="2026-09-10")
    make(app, world, "bill", p, [line(s["rent"], 4000)], date="2026-09-01", due="2026-09-10")
    scan(app, world)
    h2 = h()
    assert h2["open_high"][0] is False and h2["open_high"][1] == 1
    assert h2["overdue_bills"] == (False, 2, 8000)
    assert app.execute("SELECT acct_lock(%s,%s::date,%s)", (t, (TODAY - datetime.timedelta(days=10)).isoformat(), u)).fetchone()[0] is not None
    assert h()["period_locked"][0] is True


def test_health_stale_bank_lines_and_undeposited_tax(app, world, owner):
    s = setup(app, world)
    t, u = world["a"]["tenant"], world["a"]["user"]
    ctx(app, world, "a")
    h = lambda: {r[0]: r[1:] for r in app.execute("SELECT * FROM audit_health(%s)", (t,)).fetchall()}  # noqa: E731
    assert h()["stale_bank_lines"] == (True, 0, 0) and h()["tax_undeposited"] == (True, 0, 0)
    as_admin(owner)
    imp = ulid()
    owner.execute("INSERT INTO bank_imports (id, tenant_id, account_id, filename, rows_total, rows_new, rows_dup, created_by) VALUES (%s,%s,%s,'f',3,3,0,%s)", (imp, t, s["bank"], u))
    old, recent = (TODAY - datetime.timedelta(days=31)).isoformat(), (TODAY - datetime.timedelta(days=30)).isoformat()
    for i, (d, amt, st) in enumerate([(old, -500, "unmatched"), (recent, 700, "unmatched"), (old, 900, "ignored")]):
        owner.execute("INSERT INTO bank_lines (id, tenant_id, account_id, import_id, txn_date, amount_minor, dedupe_key, status) VALUES (%s,%s,%s,%s,%s,%s,%s,%s)", (ulid(), t, s["bank"], imp, d, amt, f"k{i}", st))
    ctx(app, world, "a")
    assert h()["stale_bank_lines"] == (False, 1, 500)                                  # only the unmatched line older than 30 days, by absolute amount
    p = party(app, world, "supplier")
    d, _ = make(app, world, "bill", p, [line(s["rent"], 100000)])
    pid, r = settle(app, world, d, 100000, s["bank"], [item("tds", 5000, 100000, 500, "T5")])
    assert r == "ok"
    ctx(app, world, "a")
    assert h()["tax_undeposited"] == (False, 1, 5000)
    ctx(app, world, "a")
    assert app.execute("SELECT acct_payment_void(%s,%s,%s,%s::date)", (ulid(), pid, u, D)).fetchone()[0] == "ok"
    assert h()["tax_undeposited"] == (True, 0, 0)                                      # a cancelled payment owes nothing


def test_near_misses_are_not_flagged(app, world):
    s = setup(app, world)
    p = party(app, world, "supplier")
    d1, _ = make(app, world, "bill", p, [line(s["rent"], 100000)])
    d2, _ = make(app, world, "bill", p, [line(s["rent"], 90000)], date="2026-10-01")
    assert pay(app, world, d1, 1000, s["bank"])[1] == "ok" and pay(app, world, d2, 1000, s["bank"])[1] == "ok"       # same amount and day, different documents
    ctx(app, world, "a")
    for memo in ("one", "two"):
        post(app, world, [dr(s["bank"], 4321), cr(s["sales"], 4321)], memo=memo)                                       # same date and total, different memo
    late = lambda days: (TODAY - datetime.timedelta(days=days)).isoformat()  # noqa: E731
    post(app, world, [dr(s["bank"], 11), cr(s["sales"], 11)], date=late(30), memo="exactly 30 days late")
    assert scan(app, world) == 0
    post(app, world, [dr(s["bank"], 12), cr(s["sales"], 12)], date=late(31), memo="31 days late")
    assert scan(app, world) == 1 and findings(app, "backdated")


def test_a_single_missing_number_and_a_missing_start_are_gaps(app, world, owner):
    s = setup(app, world)
    ctx(app, world, "a")
    for i in range(3):
        post(app, world, [dr(s["bank"], 50 + i), cr(s["sales"], 50 + i)], memo=f"n{i}")
    as_admin(owner)
    owner.execute("ALTER TABLE journal_entries DISABLE TRIGGER journal_entries_no_change")
    owner.execute("UPDATE journal_entries SET number = number + 1000 WHERE number >= 2")
    owner.execute("UPDATE journal_entries SET number = number - 999 WHERE number > 1000")      # 1,3,4: one number (2) missing
    assert scan(app, world) == 1 and findings(app, "numbering_gap")[0][5] == {"from": 2, "to": 2}
    as_admin(owner)
    owner.execute("UPDATE journal_entries SET number = number + 1000")
    owner.execute("UPDATE journal_entries SET number = number - 997")                         # 4,6,7: 1..3 and 5 missing
    owner.execute("ALTER TABLE journal_entries ENABLE TRIGGER journal_entries_no_change")
    scan(app, world)
    assert sorted((g[5]["from"], g[5]["to"]) for g in findings(app, "numbering_gap")) == [(1, 3), (2, 2), (5, 5)]


def test_health_ignores_paid_bills_medium_findings_and_old_locks(app, world):
    s = setup(app, world)
    ctx(app, world, "a")
    t, u = world["a"]["tenant"], world["a"]["user"]
    h = lambda: {r[0]: r[1:] for r in app.execute("SELECT * FROM audit_health(%s)", (t,)).fetchall()}  # noqa: E731
    p = party(app, world, "supplier")
    d, _ = make(app, world, "bill", p, [line(s["rent"], 4000)], date="2026-09-01", due="2026-09-10")
    assert pay(app, world, d, 4000, s["bank"])[1] == "ok"
    ctx(app, world, "a")
    assert h()["overdue_bills"][0] is True                                             # paid, so not overdue
    post(app, world, [dr(s["bank"], 3333), cr(s["sales"], 3333)], date="2026-08-01")   # a medium finding only
    scan(app, world)
    ctx(app, world, "a")
    assert h()["open_high"] == (True, 0, 0)
    assert app.execute("SELECT acct_lock(%s,%s::date,%s)", (t, "2026-07-01", u)).fetchone()[0] is not None
    assert h()["period_locked"][0] is False                                            # locked, but long ago


def test_workspaces_are_isolated_and_the_app_cannot_write_directly(app, world, owner):
    s = setup(app, world)
    p = party(app, world, "supplier")
    make(app, world, "bill", p, [line(s["rent"], 777)])
    make(app, world, "bill", p, [line(s["rent"], 777)])
    scan(app, world)
    ctx(app, world, "a")
    fid = app.execute("SELECT id FROM audit_findings").fetchone()[0]
    iid = ulid()
    app.execute("SELECT audit_item_save(%s,%s,%s,'x',1)", (iid, world["a"]["tenant"], world["a"]["user"]))
    ctx(app, world, "b")
    tb, ub = world["b"]["tenant"], world["b"]["user"]
    assert app.execute("SELECT count(*) FROM audit_findings").fetchone()[0] == 0
    assert app.execute("SELECT count(*) FROM audit_items").fetchone()[0] == 0
    per = TODAY.strftime("%Y-%m")
    ctx(app, world, "a")
    assert app.execute("SELECT audit_item_done(%s,%s,%s,%s,%s::date,NULL)", (iid, world["a"]["tenant"], world["a"]["user"], per, TODAY.isoformat())).fetchone()[0] == "ok"
    ctx(app, world, "b")
    assert app.execute("SELECT audit_item_undo(%s,%s,%s)", (iid, tb, per)).fetchone()[0] == "missing"          # b cannot take a's tick back
    ctx(app, world, "a")
    assert app.execute("SELECT count(*) FROM audit_item_done").fetchone()[0] == 1
    as_admin(owner)
    owner.execute("DELETE FROM audit_item_done")
    ctx(app, world, "b")
    for sql, args in [("SELECT audit_review(%s,%s,%s,'confirmed',NULL)", (fid, tb, ub)), ("SELECT audit_item_active(%s,%s,false)", (iid, tb)), ("SELECT audit_item_save(%s,%s,%s,'y',1)", (iid, tb, ub)),
                      ("SELECT audit_item_done(%s,%s,%s,%s,%s::date,NULL)", (iid, tb, ub, TODAY.strftime("%Y-%m"), TODAY.isoformat()))]:
        with pytest.raises(psycopg.errors.InsufficientPrivilege):
            app.execute(sql, args)
        ctx(app, world, "b")
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("SELECT audit_scan(%s,%s)", (world["a"]["tenant"], ub))
    ctx(app, world, "b")
    assert scan(app, world, "b") == 0 and app.execute("SELECT count(*) FROM audit_findings").fetchone()[0] == 0
    ctx(app, world, "a")
    for sql in ["UPDATE audit_findings SET status='dismissed'", "DELETE FROM audit_findings", "INSERT INTO audit_settings (tenant_id) VALUES ('x')", "UPDATE audit_items SET label='z'", "DELETE FROM audit_item_done"]:
        with pytest.raises(psycopg.errors.InsufficientPrivilege):
            app.execute(sql)
        ctx(app, world, "a")


def test_table_checks_hold_for_the_owner_too(owner, world):
    as_admin(owner)
    t, u = world["a"]["tenant"], world["a"]["user"]
    ins = "INSERT INTO audit_findings (id, tenant_id, rule, severity, subject_type, subject_key, status, note, reviewed_at, reviewed_by) VALUES (%s,%s,'dup_doc','high','doc','k',%s,%s,%s,%s)"
    now = datetime.datetime.now(datetime.timezone.utc)
    for st, note, at in [("dismissed", None, now), ("dismissed", "  ", now), ("open", None, now), ("confirmed", None, None)]:
        with pytest.raises(psycopg.errors.CheckViolation):
            owner.execute(ins, (ulid(), t, st, note, at, u))
