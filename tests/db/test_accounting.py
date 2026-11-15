import json

import psycopg
import pytest

from conftest import as_admin, set_tenant, ulid

TRIGGERS = ("journal_entries_no_change", "journal_lines_no_change", "acct_lock_log_no_change")


@pytest.fixture(autouse=True)
def clean(owner, world):
    yield
    as_admin(owner)
    owner.execute("ALTER TABLE journal_entries DISABLE TRIGGER journal_entries_no_change")
    owner.execute("ALTER TABLE journal_lines DISABLE TRIGGER journal_lines_no_change")
    owner.execute("ALTER TABLE acct_lock_log DISABLE TRIGGER acct_lock_log_no_change")
    for t in ("acct_lock_log", "journal_lines", "journal_entries", "accounts", "acct_settings"):
        owner.execute(f"DELETE FROM {t}")
    owner.execute("ALTER TABLE journal_entries ENABLE TRIGGER journal_entries_no_change")
    owner.execute("ALTER TABLE journal_lines ENABLE TRIGGER journal_lines_no_change")
    owner.execute("ALTER TABLE acct_lock_log ENABLE TRIGGER acct_lock_log_no_change")


def ctx(app, world, who="a"):
    set_tenant(app, world[who]["tenant"])
    return world[who]["user"]


def seed(app, world, who="a"):
    ctx(app, world, who)
    n = app.execute("SELECT acct_seed(%s, %s)", (world[who]["tenant"], [ulid() for _ in range(60)])).fetchone()[0]
    return n


def acc(app, world, key, who="a"):
    ctx(app, world, who)
    return app.execute("SELECT id FROM accounts WHERE system_key=%s", (key,)).fetchone()[0].strip()


def post(app, world, lines, *, date="2026-11-01", memo="test", source="manual", who="a", user=True):
    t = world[who]["tenant"]
    eid = ulid()
    r = app.execute("SELECT * FROM journal_post(%s,%s,%s,%s::date,%s,%s,NULL,%s::jsonb)", (eid, t, world[who]["user"] if user else None, date, memo, source, json.dumps(lines))).fetchone()
    return eid, r


def dr(a, n, memo=None):
    return {"account_id": a, "debit_minor": n, "credit_minor": 0, "memo": memo}


def cr(a, n, memo=None):
    return {"account_id": a, "debit_minor": 0, "credit_minor": n, "memo": memo}


def sale(app, world, amount=50000, **kw):
    cash, sales = acc(app, world, "cash"), acc(app, world, "sales")
    return post(app, world, [dr(cash, amount), cr(sales, amount)], **kw)


def test_seed_builds_the_bd_chart_once(app, world):
    n = seed(app, world)
    assert n >= 45
    assert seed(app, world) == 0                                                    # idempotent
    types = dict(app.execute("SELECT type, count(*) FROM accounts GROUP BY type").fetchall())
    assert set(types) == {"asset", "liability", "equity", "income", "expense"}
    assert app.execute("SELECT count(*) FROM accounts WHERE system_key IS NOT NULL").fetchone()[0] >= 30
    assert app.execute("SELECT postable FROM accounts WHERE code='1100'").fetchone()[0] is False        # headings are not postable
    assert app.execute("SELECT p.code FROM accounts a JOIN accounts p ON p.id=a.parent_id WHERE a.code='1110'").fetchone()[0] == "1100"
    assert app.execute("SELECT count(*) FROM accounts WHERE name_bn IS NULL").fetchone()[0] == 0
    with pytest.raises(psycopg.errors.InvalidParameterValue):
        app.execute("SELECT acct_seed(%s, %s)", (world["a"]["tenant"], [ulid()]))


def test_a_balanced_entry_posts_and_numbers_run_on(app, world):
    seed(app, world)
    e1, r1 = sale(app, world, 50000)
    e2, r2 = sale(app, world, 20000)
    assert r1[0] == "ok" and r1[2] == 1 and r2[2] == 2
    cash = acc(app, world, "cash")
    tb = {r[1]: (r[5], r[6]) for r in app.execute("SELECT * FROM acct_trial_balance('2026-12-31')").fetchall()}
    assert tb["1110"] == (70000, 0) and tb["4100"] == (0, 70000)
    led = app.execute("SELECT * FROM acct_ledger(%s,'2026-11-01','2026-11-30')", (cash,)).fetchall()
    assert [r[7] for r in led] == [0, 50000, 70000]                                  # opening, then the running balance


def test_every_unbalanced_or_malformed_entry_is_refused_and_leaves_nothing(app, world):
    seed(app, world)
    cash, sales, rent = acc(app, world, "cash"), acc(app, world, "sales"), acc(app, world, "rent")
    head = app.execute("SELECT id FROM accounts WHERE code='1100'").fetchone()[0].strip()
    cases = {
        "unbalanced": [dr(cash, 100), cr(sales, 99)],
        "too_few_lines": [dr(cash, 100)],
        "bad_line": [dr(cash, 0), cr(sales, 0)],
        "bad_account": [dr(cash, 100), cr(ulid(), 100)],
        "not_postable": [dr(head, 100), cr(sales, 100)],
    }
    for want, lines in cases.items():
        assert post(app, world, lines)[1][0] == want, want
    assert post(app, world, [{"account_id": cash, "debit_minor": 5, "credit_minor": 5}, cr(sales, 5)])[1][0] == "bad_line"      # both sides on one line
    assert post(app, world, [dr(cash, -5), cr(sales, -5)])[1][0] == "bad_line"
    assert post(app, world, [dr(cash, "x"), cr(sales, 1)])[1][0] == "bad_line"
    assert post(app, world, [dr(cash, 10**15), cr(sales, 10**15)])[1][0] == "bad_line"            # beyond the sane ceiling: refused cleanly, not a raw constraint error
    assert post(app, world, [dr(cash, 1), cr(sales, 1)], memo="  ")[1][0] == "bad_memo"
    assert post(app, world, [dr(cash, 1), cr(sales, 1)], date="1999-01-01")[1][0] == "bad_date"
    assert post(app, world, [dr(cash, 1), cr(sales, 1)], date="2099-01-01")[1][0] == "bad_date"
    assert post(app, world, [dr(cash, 1), cr(sales, 1)], source="reversal")[1][0] == "bad_source"
    app.execute("UPDATE accounts SET active=false WHERE id=%s", (rent,))
    assert post(app, world, [dr(rent, 1), cr(cash, 1)])[1][0] == "inactive_account"
    with pytest.raises(psycopg.errors.InvalidParameterValue):
        post(app, world, [dr(cash, 1), cr(sales, 1)], user=False)
    assert app.execute("SELECT count(*) FROM journal_entries").fetchone()[0] == 0
    assert app.execute("SELECT count(*) FROM journal_lines").fetchone()[0] == 0
    assert post(app, world, [dr(cash, 1), cr(sales, 1)])[1][2] == 1                    # refused attempts did not use up numbers


def test_entries_are_append_only_and_cannot_be_written_directly(app, world, owner):
    seed(app, world)
    e, r = sale(app, world)
    for sql in ("UPDATE journal_entries SET memo='x'", "DELETE FROM journal_entries", "UPDATE journal_lines SET debit_minor=1", "DELETE FROM journal_lines", "INSERT INTO journal_entries (id) VALUES ('x')",
                "INSERT INTO journal_lines (tenant_id) VALUES ('x')", "UPDATE acct_settings SET locked_through=NULL", "UPDATE accounts SET code='9999'", "DELETE FROM accounts", "DELETE FROM acct_lock_log"):
        with pytest.raises(psycopg.errors.InsufficientPrivilege):
            app.execute(sql)
    as_admin(owner)                                            # even the table owner is stopped by the triggers
    for sql in ("UPDATE journal_entries SET memo='x'", "DELETE FROM journal_lines", "DELETE FROM journal_entries"):
        with pytest.raises(psycopg.errors.Error):
            owner.execute(sql)


def test_the_database_itself_refuses_an_unbalanced_entry_even_if_a_function_is_bypassed(app, world, owner):
    seed(app, world)
    cash = acc(app, world, "cash")
    as_admin(owner)
    eid, t = ulid(), world["a"]["tenant"]
    owner.execute("INSERT INTO journal_entries (id, tenant_id, number, entry_date, memo) VALUES (%s,%s,99,'2026-11-01','sneaky')", (eid, t))
    with pytest.raises(psycopg.errors.CheckViolation):
        owner.execute("INSERT INTO journal_lines (tenant_id, entry_id, account_id, debit_minor) VALUES (%s,%s,%s,100)", (t, eid, cash))     # one lonely line
    with pytest.raises(psycopg.errors.CheckViolation):
        owner.execute("INSERT INTO journal_lines (tenant_id, entry_id, account_id, debit_minor, credit_minor) VALUES (%s,%s,%s,100,100)", (t, eid, cash))
    owner.execute("ALTER TABLE journal_entries DISABLE TRIGGER journal_entries_no_change")
    owner.execute("DELETE FROM journal_entries WHERE id=%s", (eid,))
    owner.execute("ALTER TABLE journal_entries ENABLE TRIGGER journal_entries_no_change")


def test_reversal_swaps_sides_once_and_cannot_be_reversed(app, world):
    seed(app, world)
    e, _ = sale(app, world, 50000)
    t, u = world["a"]["tenant"], world["a"]["user"]
    new = ulid()
    assert app.execute("SELECT journal_reverse(%s,%s,%s,'2026-11-02','oops')", (new, e, u)).fetchone()[0] == "ok"
    assert app.execute("SELECT source, reverses_id FROM journal_entries WHERE id=%s", (new,)).fetchone() == ("reversal", e)
    tb = app.execute("SELECT * FROM acct_trial_balance('2026-12-31')").fetchall()
    assert tb == []                                                                  # net zero everywhere
    assert app.execute("SELECT journal_reverse(%s,%s,%s,'2026-11-02',NULL)", (ulid(), e, u)).fetchone()[0] == "already_reversed"
    assert app.execute("SELECT journal_reverse(%s,%s,%s,'2026-11-02',NULL)", (ulid(), new, u)).fetchone()[0] == "is_reversal"
    assert app.execute("SELECT count(*) FROM journal_entries").fetchone()[0] == 2


def test_a_locked_period_accepts_nothing_and_unlock_is_deliberate(app, world):
    seed(app, world)
    u = world["a"]["user"]; t = world["a"]["tenant"]
    e, _ = sale(app, world, date="2026-10-15")
    assert app.execute("SELECT acct_lock(%s,'2026-10-31',%s)", (t, u)).fetchone()[0] == "ok"
    assert sale(app, world, date="2026-10-31")[1][0] == "locked"
    assert sale(app, world, date="2026-10-01")[1][0] == "locked"
    assert sale(app, world, date="2026-11-01")[1][0] == "ok"
    assert app.execute("SELECT journal_reverse(%s,%s,%s,'2026-10-20','x')", (ulid(), e, u)).fetchone()[0] == "locked"      # cannot reverse INTO a closed period
    assert app.execute("SELECT journal_reverse(%s,%s,%s,'2026-11-02','x')", (ulid(), e, u)).fetchone()[0] == "ok"            # but a reversal dated in the open period is the right fix
    assert app.execute("SELECT acct_lock(%s,'2026-10-31',%s)", (t, u)).fetchone()[0] == "not_forward"
    assert app.execute("SELECT acct_lock(%s,'2026-09-30',%s)", (t, u)).fetchone()[0] == "not_forward"
    assert app.execute("SELECT acct_lock(%s,'2099-01-01',%s)", (t, u)).fetchone()[0] == "bad_date"
    assert app.execute("SELECT acct_unlock(%s,'2026-09-30',%s,'')", (t, u)).fetchone()[0] == "no_reason"
    assert app.execute("SELECT acct_unlock(%s,'2026-10-31',%s,'to fix a bill')", (t, u)).fetchone()[0] == "not_back"
    assert app.execute("SELECT acct_unlock(%s,'2026-09-30',%s,'to fix a bill')", (t, u)).fetchone()[0] == "ok"
    assert sale(app, world, date="2026-10-15")[1][0] == "ok"
    assert app.execute("SELECT acct_unlock(%s,NULL,%s,'open everything')", (t, u)).fetchone()[0] == "ok"
    assert app.execute("SELECT acct_unlock(%s,NULL,%s,'open everything')", (t, u)).fetchone()[0] == "not_locked"
    log = app.execute("SELECT action, from_date, to_date, reason FROM acct_lock_log ORDER BY seq").fetchall()
    assert [l[0] for l in log] == ["lock", "unlock", "unlock"] and log[1][3] == "to fix a bill"


def test_add_account_rules(app, world):
    seed(app, world)
    t = world["a"]["tenant"]
    exp_head = app.execute("SELECT id FROM accounts WHERE code='6000'").fetchone()[0].strip()
    asset_head = app.execute("SELECT id FROM accounts WHERE code='1100'").fetchone()[0].strip()
    add = lambda code, typ, parent=None: app.execute("SELECT acct_account_add(%s,%s,%s,%s,%s,%s,%s)", (ulid(), t, code, "Gym", "জিম", typ, parent)).fetchone()[0]
    assert add("6950", "expense", exp_head) == "ok"
    assert add("6950", "expense", exp_head) == "duplicate_code"
    assert add("69", "expense") == "bad_code"
    assert add("6951", "expense", asset_head) == "bad_parent"                       # type must match the parent
    assert add("6952", "expense", ulid()) == "bad_parent"
    assert add("6953", "weird") == "bad_name"


def test_workspaces_are_isolated(app, world):
    seed(app, world)
    seed(app, world, "b")
    e, _ = sale(app, world)
    a_cash = acc(app, world, "cash", "a")
    ctx(app, world, "b")
    assert app.execute("SELECT count(*) FROM journal_entries").fetchone()[0] == 0
    assert app.execute("SELECT count(*) FROM accounts WHERE tenant_id=%s", (world["a"]["tenant"],)).fetchone()[0] == 0
    b_sales = app.execute("SELECT id FROM accounts WHERE system_key='sales'").fetchone()[0].strip()
    assert post(app, world, [dr(a_cash, 100), cr(b_sales, 100)], who="b")[1][0] == "bad_account"           # another workspace's account
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("SELECT journal_reverse(%s,%s,%s,'2026-11-02','x')", (ulid(), e, world["b"]["user"]))
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("SELECT acct_lock(%s,'2026-10-31',%s)", (world["a"]["tenant"], world["b"]["user"]))
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("SELECT acct_seed(%s,%s)", (world["a"]["tenant"], [ulid() for _ in range(60)]))
    assert app.execute("SELECT * FROM acct_trial_balance('2026-12-31')").fetchall() == []
    assert app.execute("SELECT count(*) FROM acct_ledger(%s,'2026-01-01','2026-12-31')", (a_cash,)).fetchone()[0] == 1       # only the synthetic opening row


# ---------------------------------------------------------------------------------------------------------------------
# Property-based: whatever sequence of good and bad entries and reversals arrives, the books stay in balance.
hyp = pytest.importorskip("hypothesis")
from hypothesis import HealthCheck, given, settings, strategies as st  # noqa: E402

amount = st.integers(min_value=1, max_value=10_000_000)
side = st.sampled_from(["d", "c"])
line_spec = st.tuples(st.integers(min_value=0, max_value=7), side, amount)
entry_spec = st.tuples(st.lists(line_spec, min_size=1, max_size=6), st.booleans(), st.booleans())       # lines, force-balance, reverse-afterwards


@settings(max_examples=60, deadline=None, suppress_health_check=[HealthCheck.function_scoped_fixture])
@given(st.lists(entry_spec, min_size=1, max_size=8))
def test_property_books_always_balance(app, world, owner, specs):
    seed(app, world)
    ids = [r[0].strip() for r in app.execute("SELECT id FROM accounts WHERE postable ORDER BY code LIMIT 8").fetchall()]
    t, u = world["a"]["tenant"], world["a"]["user"]
    ok_net: dict[str, int] = {}
    for lines, balance_it, reverse_it in specs:
        payload = [{"account_id": ids[i], "debit_minor": n if s == "d" else 0, "credit_minor": n if s == "c" else 0} for i, s, n in lines]
        d = sum(p["debit_minor"] for p in payload)
        c = sum(p["credit_minor"] for p in payload)
        if balance_it and d != c:
            payload.append({"account_id": ids[0], "debit_minor": max(0, c - d), "credit_minor": max(0, d - c)})
            d = sum(p["debit_minor"] for p in payload); c = sum(p["credit_minor"] for p in payload)
        before = app.execute("SELECT count(*) FROM journal_entries").fetchone()[0]
        eid, r = post(app, world, payload)
        should_pass = d == c and len(payload) >= 2
        assert (r[0] == "ok") == should_pass, (r[0], payload)
        after = app.execute("SELECT count(*) FROM journal_entries").fetchone()[0]
        assert after == before + (1 if should_pass else 0)                                   # refused = nothing written
        if should_pass:
            for p in payload:
                ok_net[p["account_id"]] = ok_net.get(p["account_id"], 0) + p["debit_minor"] - p["credit_minor"]
            if reverse_it:
                assert app.execute("SELECT journal_reverse(%s,%s,%s,'2026-11-02',NULL)", (ulid(), eid, u)).fetchone()[0] == "ok"
                for p in payload:
                    ok_net[p["account_id"]] -= p["debit_minor"] - p["credit_minor"]
        # invariants after every step
        tb = app.execute("SELECT debit_minor, credit_minor FROM acct_trial_balance('2099-12-31')").fetchall()
        assert sum(x[0] for x in tb) == sum(x[1] for x in tb)
        tot = app.execute("SELECT coalesce(sum(debit_minor),0), coalesce(sum(credit_minor),0) FROM journal_lines").fetchone()
        assert tot[0] == tot[1]
        per_entry = app.execute("SELECT count(*) FROM (SELECT entry_id FROM journal_lines GROUP BY entry_id HAVING sum(debit_minor) <> sum(credit_minor) OR count(*) < 2) x").fetchone()[0]
        assert per_entry == 0
    # per-account net equals what we expect from the accepted entries and reversals
    for acct, net in ok_net.items():
        got = app.execute("SELECT coalesce(sum(debit_minor - credit_minor),0) FROM journal_lines WHERE account_id=%s", (acct,)).fetchone()[0]
        assert got == net
    wipe(owner)                                            # hypothesis re-runs the body with the same fixtures: start every example from an empty ledger


def wipe(owner):
    as_admin(owner)
    for t in ("journal_entries", "journal_lines", "acct_lock_log"):
        owner.execute(f"ALTER TABLE {t} DISABLE TRIGGER {t}_no_change")
    for t in ("acct_lock_log", "journal_lines", "journal_entries", "accounts", "acct_settings"):
        owner.execute(f"DELETE FROM {t}")
    for t in ("journal_entries", "journal_lines", "acct_lock_log"):
        owner.execute(f"ALTER TABLE {t} ENABLE TRIGGER {t}_no_change")
