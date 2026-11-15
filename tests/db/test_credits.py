import random
import threading
import time
import uuid
from concurrent.futures import ThreadPoolExecutor
from decimal import Decimal as D

import psycopg
import pytest

from conftest import ADMIN_DSN, as_admin, connect, set_tenant


def k() -> str:
    return "k-" + uuid.uuid4().hex


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


@pytest.fixture
def A(world):
    return world["a"]["tenant"]


@pytest.fixture
def B(world):
    return world["b"]["tenant"]


# ---- helpers ---------------------------------------------------------------------------
def grant(conn, tenant, amount, *, type="grant_plan", idem=None, ref=None, expires=None):
    return conn.execute(
        "SELECT * FROM credit_grant(%s,%s,%s,%s,%s,%s,%s)",
        (tenant, type, amount, idem or k(), ref[0] if ref else None, ref[1] if ref else None, expires),
    ).fetchone()


def reserve(conn, tenant, amount, idem=None, ttl=900):
    return conn.execute("SELECT * FROM credit_reserve(%s,%s,%s,%s,%s)", (tenant, amount, idem or k(), None, ttl)).fetchone()


def settle(conn, rid, actual, idem=None, usage="{}"):
    return conn.execute("SELECT * FROM credit_settle(%s,%s,%s,%s::jsonb)", (rid, actual, idem or k(), usage)).fetchone()


def release(conn, rid, idem=None):
    return conn.execute("SELECT * FROM credit_release(%s,%s)", (rid, idem or k())).fetchone()


def wallet(conn, tenant):
    as_admin(conn)
    r = conn.execute("SELECT balance, reserved FROM wallets WHERE tenant_id=%s", (tenant,)).fetchone()
    conn.execute("SELECT set_config('app.bypass_rls','',false)")
    return (r[0], r[1]) if r else None


def reconcile(admin):
    as_admin(admin)
    rows = admin.execute("SELECT * FROM credit_reconcile()").fetchall()
    admin.execute("SELECT set_config('app.bypass_rls','',false)")
    return rows


@pytest.fixture
def ta(app, A):
    set_tenant(app, A)
    return app


def err(excinfo):
    return str(excinfo.value)


# ---- grants ----------------------------------------------------------------------------
def test_grant_creates_wallet_lot_and_ledger_row(ta, A, admin):
    tx, replayed = grant(ta, A, D("100.5"))
    assert replayed is False
    assert wallet(admin, A) == (D("100.5"), D("0"))
    assert ta.execute("SELECT type, amount, balance_after FROM credit_transactions WHERE id=%s", (tx,)).fetchone() == ("grant_plan", D("100.5"), D("100.5"))
    assert reconcile(admin) == []


def test_grant_is_idempotent_and_detects_key_reuse(ta, A, admin):
    key = k()
    t1, r1 = grant(ta, A, 50, idem=key)
    t2, r2 = grant(ta, A, 50, idem=key)
    assert (t1, r1, r2) == (t2, False, True)
    assert wallet(admin, A)[0] == 50
    with pytest.raises(psycopg.errors.RaiseException, match="idempotency_conflict"):
        grant(ta, A, 51, idem=key)
    with pytest.raises(psycopg.errors.RaiseException, match="idempotency_conflict"):
        grant(ta, A, 50, idem=key, type="grant_free")


def test_grant_validation(ta, A):
    for bad in (0, -5, None):
        with pytest.raises(psycopg.errors.RaiseException, match="invalid_amount"):
            grant(ta, A, bad)
    with pytest.raises(psycopg.errors.RaiseException, match="invalid_type"):
        grant(ta, A, 5, type="adjustment")
    with pytest.raises(psycopg.errors.RaiseException, match="invalid_type"):
        grant(ta, A, 5, type="debit")
    with pytest.raises(psycopg.errors.RaiseException, match="reference_required"):
        grant(ta, A, 5, type="purchase")
    with pytest.raises(psycopg.errors.RaiseException, match="idempotency_key_required"):
        ta.execute("SELECT * FROM credit_grant(%s,'grant_free',5,'x')", (A,))
    with pytest.raises(psycopg.errors.RaiseException, match="already_expired"):
        grant(ta, A, 5, expires="2000-01-01")
    grant(ta, A, 5, type="purchase", ref=("payment", "pay_1"))


def test_cannot_touch_another_tenant_or_without_context(app, A, B):
    set_tenant(app, A)
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        grant(app, B, 10)
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        reserve(app, B, 1)
    app.execute("SELECT set_config('app.tenant_id','',false)")
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        grant(app, A, 10)


def test_app_role_cannot_fake_admin_with_bypass_flag(app, A, B):
    """The bypass flag alone must not unlock admin-only/cross-tenant money functions for the app login."""
    set_tenant(app, A)
    app.execute("SELECT set_config('app.bypass_rls','on',false)")
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        grant(app, B, 10)
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("SELECT * FROM credit_adjust(%s,'adjustment',5,'because',%s,NULL)", (A, k()))
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("SELECT * FROM credit_reconcile()")


# ---- direct writes are impossible ------------------------------------------------------
@pytest.mark.parametrize("sql", [
    "UPDATE wallets SET balance = 1000000",
    "INSERT INTO wallets (id, tenant_id, balance) VALUES ('{x}', '{t}', 5)",
    "DELETE FROM wallets",
    "UPDATE credit_transactions SET amount = 1",
    "DELETE FROM credit_transactions",
    "UPDATE credit_lots SET remaining = 1000000",
    "UPDATE credit_reservations SET status = 'released'",
    "DELETE FROM usage_records",
    "UPDATE credit_alerts SET handled_at = now()",
    "TRUNCATE credit_transactions",
])
def test_app_login_has_no_direct_write_access(ta, A, sql):
    grant(ta, A, 10)
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        ta.execute(sql.format(x="x" * 26, t=A))


def test_ledger_and_usage_are_append_only_even_for_the_owner(ta, A, owner):
    grant(ta, A, 10)
    as_admin(owner)
    with pytest.raises(psycopg.errors.InsufficientPrivilege, match="append-only"):
        owner.execute("UPDATE credit_transactions SET note='x'")
    with pytest.raises(psycopg.errors.InsufficientPrivilege, match="append-only"):
        owner.execute("DELETE FROM credit_transactions")
    r = reserve(ta, A, 5)[0]
    settle(ta, r, 5)
    with pytest.raises(psycopg.errors.InsufficientPrivilege, match="append-only"):
        owner.execute("UPDATE usage_records SET credits_charged=0")
    with pytest.raises(psycopg.errors.InsufficientPrivilege, match="append-only"):
        owner.execute("DELETE FROM usage_records")


def test_wallet_check_constraints_hold_even_for_the_owner(ta, A, owner):
    grant(ta, A, 10)
    as_admin(owner)
    with pytest.raises(psycopg.errors.CheckViolation):
        owner.execute("UPDATE wallets SET balance = -1")
    with pytest.raises(psycopg.errors.CheckViolation):
        owner.execute("UPDATE wallets SET reserved = balance + 1")


# ---- tenant isolation (reads) ----------------------------------------------------------
def test_each_tenant_sees_only_its_own_money(app, A, B, admin):
    set_tenant(app, A)
    grant(app, A, 10)
    r = reserve(app, A, 3)[0]
    settle(app, r, 3, usage='{"source":"chat"}')
    set_tenant(app, B)
    grant(app, B, 99)
    for t in ("wallets", "credit_transactions", "credit_lots", "credit_reservations", "usage_records"):
        assert app.execute(f"SELECT count(*) FROM {t} WHERE tenant_id <> %s", (B,)).fetchone()[0] == 0
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        settle(app, r, 1)          # B cannot settle A's reservation
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        release(app, r)


# ---- reserve / settle / release --------------------------------------------------------
def test_reserve_then_settle_less_than_reserved_refunds_the_difference(ta, A, admin):
    grant(ta, A, 100)
    rid, _ = reserve(ta, A, 30)
    assert wallet(admin, A) == (100, 30)
    tx, charged, short, replayed = settle(ta, rid, D("12.25"), usage='{"source":"chat","input_tokens":"10","output_tokens":"20","user_id":null}')
    assert (charged, short, replayed) == (D("12.25"), 0, False)
    assert wallet(admin, A) == (D("87.75"), 0)
    u = ta.execute("SELECT source, input_tokens, output_tokens, credits_charged, tx_id FROM usage_records").fetchone()
    assert u == ("chat", 10, 20, D("12.25"), tx)
    assert reconcile(admin) == []


def test_insufficient_credits_and_available_excludes_reserved(ta, A, admin):
    grant(ta, A, 10)
    reserve(ta, A, 7)
    with pytest.raises(psycopg.errors.RaiseException, match="insufficient_credits"):
        reserve(ta, A, 4)            # only 3 available
    reserve(ta, A, 3)
    assert wallet(admin, A) == (10, 10)


def test_reserve_is_idempotent(ta, A, admin):
    grant(ta, A, 10)
    key = k()
    r1 = reserve(ta, A, 4, idem=key)
    r2 = reserve(ta, A, 4, idem=key)
    assert r1[0] == r2[0] and (r1[1], r2[1]) == (False, True)
    assert wallet(admin, A) == (10, 4)
    with pytest.raises(psycopg.errors.RaiseException, match="idempotency_conflict"):
        reserve(ta, A, 5, idem=key)


def test_settle_is_idempotent_and_cannot_double_charge(ta, A, admin):
    grant(ta, A, 50)
    rid = reserve(ta, A, 10)[0]
    key = k()
    a = settle(ta, rid, 8, idem=key)
    b = settle(ta, rid, 8, idem=key)
    assert a[0] == b[0] and b[3] is True and b[1] == 8
    assert wallet(admin, A) == (42, 0)
    assert ta.execute("SELECT count(*) FROM usage_records").fetchone()[0] == 1
    with pytest.raises(psycopg.errors.RaiseException, match="reservation_not_open"):
        settle(ta, rid, 8)                   # a different key must not charge again
    with pytest.raises(psycopg.errors.RaiseException, match="idempotency_conflict"):
        release(ta, rid, idem=key)           # same key, different operation
    assert wallet(admin, A) == (42, 0) and reconcile(admin) == []


def test_release_returns_everything_and_blocks_later_settle(ta, A, admin):
    grant(ta, A, 20)
    rid = reserve(ta, A, 20)[0]
    key = k()
    release(ta, rid, idem=key)
    assert release(ta, rid, idem=key)[1] is True      # replay
    assert wallet(admin, A) == (20, 0)
    with pytest.raises(psycopg.errors.RaiseException, match="reservation_not_open"):
        settle(ta, rid, 1)
    with pytest.raises(psycopg.errors.RaiseException, match="reservation_not_open"):
        release(ta, rid)
    reserve(ta, A, 20)                                 # all credit is available again


def test_overrun_is_charged_only_from_free_credits_and_shortfall_is_recorded(ta, A, admin):
    grant(ta, A, 100)
    r1 = reserve(ta, A, 40)[0]
    r2 = reserve(ta, A, 50)[0]                # 10 free credits remain
    _, charged, short, _ = settle(ta, r1, 70)  # wants 30 more than reserved, only 10 are free
    assert (charged, short) == (50, 20)
    assert wallet(admin, A) == (50, 50)       # the other reservation stays fully covered
    assert ta.execute("SELECT credits_shortfall FROM usage_records").fetchone()[0] == 20
    settle(ta, r2, 50)
    assert wallet(admin, A) == (0, 0) and reconcile(admin) == []


def test_zero_actual_settles_and_frees_the_hold(ta, A, admin):
    grant(ta, A, 10)
    rid = reserve(ta, A, 10)[0]
    assert settle(ta, rid, 0)[1] == 0
    assert wallet(admin, A) == (10, 0)


def test_stale_reservations_are_released_but_a_late_settle_still_charges(ta, A, owner, admin):
    grant(ta, A, 100)
    rid = reserve(ta, A, 30, ttl=10)[0]
    as_admin(owner)
    owner.execute("UPDATE credit_reservations SET expires_at = now() - interval '1 minute'")
    assert owner.execute("SELECT credit_release_stale()").fetchone()[0] == 1
    assert owner.execute("SELECT credit_release_stale()").fetchone()[0] == 0     # idempotent
    assert wallet(admin, A) == (100, 0)
    assert reconcile(admin) == []
    _, charged, short, _ = settle(ta, rid, 25)     # provider already ran: charge it
    assert (charged, short) == (25, 0)
    assert wallet(admin, A) == (75, 0) and reconcile(admin) == []


# ---- spending limit & alerts -----------------------------------------------------------
def test_monthly_spending_limit(ta, A):
    grant(ta, A, 1000)
    ta.execute("SELECT credit_wallet_configure(%s, NULL, 50)", (A,))
    r = reserve(ta, A, 30)[0]
    settle(ta, r, 30)
    with pytest.raises(psycopg.errors.RaiseException, match="spending_limit_reached"):
        reserve(ta, A, 21)
    reserve(ta, A, 20)                              # exactly at the limit is fine
    with pytest.raises(psycopg.errors.RaiseException, match="spending_limit_reached"):
        reserve(ta, A, 1)                           # held credits count against the limit too


def test_low_balance_alert_fires_once_and_rearms(ta, A, admin):
    grant(ta, A, 100)
    ta.execute("SELECT credit_wallet_configure(%s, 20, NULL)", (A,))
    n = lambda: ta.execute("SELECT count(*) FROM credit_alerts").fetchone()[0]
    assert n() == 0
    r = reserve(ta, A, 85)[0]
    assert n() == 1                                  # available 15 < 20
    release(ta, reserve(ta, A, 5)[0])
    assert n() == 1                                  # no duplicate while still low
    settle(ta, r, 85)
    assert n() == 1
    grant(ta, A, 500)                                # recovers -> re-armed
    reserve(ta, A, 500)                              # available 15 < 20 again
    assert n() == 2


def test_wallet_configure_validates(ta, A):
    with pytest.raises(psycopg.errors.RaiseException, match="invalid_amount"):
        ta.execute("SELECT credit_wallet_configure(%s, -1, NULL)", (A,))


# ---- expiry ----------------------------------------------------------------------------
def test_expired_credits_are_unusable_immediately_and_swept_later(ta, A, owner, admin):
    grant(ta, A, 40, expires="2999-01-01")
    grant(ta, A, 60)
    as_admin(owner)
    owner.execute("UPDATE credit_lots SET expires_at = now() - interval '1 second' WHERE initial = 40")
    with pytest.raises(psycopg.errors.RaiseException, match="insufficient_credits"):
        reserve(ta, A, 61)                           # the 40 expired credits do not count, sweep not needed
    reserve(ta, A, 60)
    assert owner.execute("SELECT credit_expire_lots()").fetchone()[0] == 1
    assert wallet(admin, A) == (60, 60)
    assert owner.execute("SELECT credit_expire_lots()").fetchone()[0] == 0
    assert ta.execute("SELECT type, amount FROM credit_transactions WHERE type='expiry'").fetchone() == ("expiry", -40)
    assert reconcile(admin) == []


def test_sweep_never_goes_below_reserved(ta, A, owner, admin):
    grant(ta, A, 50, expires="2999-01-01")
    rid = reserve(ta, A, 30)[0]
    as_admin(owner)
    owner.execute("UPDATE credit_lots SET expires_at = now() - interval '1 second'")
    owner.execute("SELECT credit_expire_lots()")
    assert wallet(admin, A) == (30, 30)              # only the 20 unreserved credits expired
    settle(ta, rid, 30)                              # the held part is still honoured (last-resort use of the expired lot)
    assert wallet(admin, A) == (0, 0)
    owner.execute("SELECT credit_expire_lots()")
    assert reconcile(admin) == []


def test_expiring_credits_are_spent_before_permanent_ones(ta, A, admin):
    grant(ta, A, 100)                                # permanent
    grant(ta, A, 30, expires="2999-01-01")           # expiring
    rid = reserve(ta, A, 30)[0]
    settle(ta, rid, 30)
    rem = ta.execute("SELECT initial, remaining FROM credit_lots ORDER BY initial").fetchall()
    assert rem == [(30, 0), (100, 100)]


# ---- admin adjustments -----------------------------------------------------------------
def test_adjust_is_admin_only_audited_and_guarded(ta, A, admin):
    grant(ta, A, 100)
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        ta.execute("SELECT * FROM credit_adjust(%s,'adjustment',5,'nope',%s,NULL)", (A, k()))
    as_admin(admin)
    key = k()
    tx, rep = admin.execute("SELECT * FROM credit_adjust(%s,'bonus',25,'goodwill',%s,NULL)", (A, key)).fetchone()
    assert rep is False and admin.execute("SELECT * FROM credit_adjust(%s,'bonus',25,'goodwill',%s,NULL)", (A, key)).fetchone() == (tx, True)
    assert wallet(admin, A)[0] == 125
    as_admin(admin)
    admin.execute("SELECT * FROM credit_adjust(%s,'adjustment',-100,'chargeback fix',%s,NULL)", (A, k()))
    assert wallet(admin, A)[0] == 25
    as_admin(admin)
    with pytest.raises(psycopg.errors.RaiseException, match="reason_required"):
        admin.execute("SELECT * FROM credit_adjust(%s,'adjustment',1,' ',%s,NULL)", (A, k()))
    with pytest.raises(psycopg.errors.RaiseException, match="invalid_amount"):
        admin.execute("SELECT * FROM credit_adjust(%s,'bonus',-1,'x1x',%s,NULL)", (A, k()))
    with pytest.raises(psycopg.errors.RaiseException, match="insufficient_credits"):
        admin.execute("SELECT * FROM credit_adjust(%s,'adjustment',-26,'too much',%s,NULL)", (A, k()))
    assert reconcile(admin) == []


def test_adjust_cannot_dig_into_reserved_credits(ta, A, admin):
    grant(ta, A, 50)
    reserve(ta, A, 40)
    as_admin(admin)
    with pytest.raises(psycopg.errors.RaiseException, match="insufficient_credits"):
        admin.execute("SELECT * FROM credit_adjust(%s,'adjustment',-11,'reason',%s,NULL)", (A, k()))
    admin.execute("SELECT * FROM credit_adjust(%s,'adjustment',-10,'reason',%s,NULL)", (A, k()))
    assert wallet(admin, A) == (40, 40)


def test_admin_context_can_grant_to_any_tenant(admin, B):
    as_admin(admin)
    grant(admin, B, 7, type="grant_free")
    assert wallet(admin, B)[0] == 7


# ---- reconciliation actually detects damage --------------------------------------------
def test_reconcile_detects_tampering(ta, A, owner, admin):
    grant(ta, A, 100)
    rid = reserve(ta, A, 10)[0]
    settle(ta, rid, 10)
    assert reconcile(admin) == []
    as_admin(owner)
    owner.execute("UPDATE wallets SET balance = balance + 5")                      # superuser-level tampering
    names = {r[1] for r in reconcile(admin)}
    assert {"balance_vs_ledger", "balance_vs_lots"} <= names
    owner.execute("UPDATE wallets SET balance = balance - 5")
    owner.execute("ALTER TABLE usage_records DISABLE TRIGGER USER")
    owner.execute("UPDATE usage_records SET credits_charged = credits_charged + 1")
    owner.execute("ALTER TABLE usage_records ENABLE TRIGGER USER")
    assert {r[1] for r in reconcile(admin)} == {"usage_vs_debits"}


# ---- concurrency (the acceptance tests) ------------------------------------------------
def run_parallel(dbname, tenant, n, fn):
    """Runs fn(conn, i) in n threads, each with its own app-login connection, started together."""
    with psycopg.connect(ADMIN_DSN, autocommit=True) as su:
        limit = int(su.execute("SHOW max_connections").fetchone()[0])
    assert limit >= n + 40, f"PostgreSQL max_connections={limit}; this test needs >= {n + 40} (start the server with -c max_connections=300)"
    barrier = threading.Barrier(n, timeout=60)

    def worker(i):
        with connect(dbname, "jarvis_app") as c:
            set_tenant(c, tenant)
            barrier.wait()
            try:
                return ("ok", fn(c, i))
            except psycopg.Error as e:
                return ("err", str(e).split("\n")[0])

    with ThreadPoolExecutor(max_workers=n) as ex:
        return list(ex.map(worker, range(n)))


def test_100_concurrent_reservations_balance_is_exact(dbname, ta, A, admin):
    grant(ta, A, 1000)
    res = run_parallel(dbname, A, 100, lambda c, i: reserve(c, A, 10)[0])
    assert all(s == "ok" for s, _ in res)
    assert len({r for _, r in res}) == 100
    assert wallet(admin, A) == (1000, 1000)
    assert reconcile(admin) == []


def test_100_concurrent_reservations_on_a_small_wallet_never_overspend(dbname, ta, A, admin):
    grant(ta, A, 500)                                  # room for exactly 50 x 10
    res = run_parallel(dbname, A, 100, lambda c, i: reserve(c, A, 10)[0])
    ok = [r for s, r in res if s == "ok"]
    bad = [r for s, r in res if s == "err"]
    assert len(ok) == 50 and len(bad) == 50
    assert all("insufficient_credits" in m for m in bad)
    assert wallet(admin, A) == (500, 500)
    assert reconcile(admin) == []


def test_100_concurrent_reserve_settle_cycles_end_with_exact_balance(dbname, ta, A, admin):
    grant(ta, A, 10000)

    def cycle(c, i):
        rid = reserve(c, A, 20)[0]
        settle(c, rid, D("12.5") if i % 2 else D("20"), usage='{"source":"chat"}')
        return True

    res = run_parallel(dbname, A, 100, cycle)
    assert all(s == "ok" for s, _ in res), res
    expected = 10000 - (50 * D("12.5") + 50 * 20)
    assert wallet(admin, A) == (expected, 0)
    assert ta.execute("SELECT count(*), sum(credits_charged) FROM usage_records").fetchone() == (100, D("1625"))
    assert reconcile(admin) == []


def test_concurrent_duplicate_requests_with_one_key_take_effect_once(dbname, ta, A, admin):
    grant(ta, A, 100)
    key = k()
    res = run_parallel(dbname, A, 40, lambda c, i: reserve(c, A, 10, idem=key))
    assert all(s == "ok" for s, _ in res)
    assert len({r[0] for _, r in res}) == 1                       # everyone got the same reservation
    assert sum(1 for _, r in res if r[1] is False) == 1           # exactly one real execution
    assert wallet(admin, A) == (100, 10)

    rid = res[0][1][0]
    skey = k()
    res2 = run_parallel(dbname, A, 40, lambda c, i: settle(c, rid, 7, idem=skey))
    assert all(s == "ok" for s, _ in res2)
    assert sum(1 for _, r in res2 if r[3] is False) == 1
    assert wallet(admin, A) == (93, 0)
    assert ta.execute("SELECT count(*) FROM usage_records").fetchone()[0] == 1
    assert reconcile(admin) == []


def test_concurrent_duplicate_grants_credit_once(dbname, ta, A, admin):
    key = k()
    res = run_parallel(dbname, A, 40, lambda c, i: grant(c, A, 25, idem=key))
    assert all(s == "ok" for s, _ in res)
    assert wallet(admin, A)[0] == 25
    assert reconcile(admin) == []


def test_settle_and_release_racing_on_one_reservation_resolve_exactly_once(dbname, ta, A, admin):
    grant(ta, A, 100)
    rid = reserve(ta, A, 40)[0]

    def race(c, i):
        return settle(c, rid, 40)[1] if i % 2 else release(c, rid)[0]

    res = run_parallel(dbname, A, 20, race)
    assert sum(1 for s, _ in res if s == "ok") == 1
    assert all("reservation_not_open" in m for s, m in res if s == "err")
    assert wallet(admin, A)[1] == 0
    assert wallet(admin, A)[0] in (100, 60) and reconcile(admin) == []


def test_two_tenants_in_parallel_do_not_interfere(dbname, ta, A, B, admin):
    grant(ta, A, 300)
    with connect(dbname, "jarvis_app") as c:
        set_tenant(c, B)
        grant(c, B, 300)
    out = {}

    def go(tenant):
        out[tenant] = run_parallel(dbname, tenant, 30, lambda c, i: settle(c, reserve(c, tenant, 10)[0], 10)[1])

    ts = [threading.Thread(target=go, args=(t,)) for t in (A, B)]
    [t.start() for t in ts]
    [t.join() for t in ts]
    assert wallet(admin, A) == (0, 0) and wallet(admin, B) == (0, 0)
    assert reconcile(admin) == []


def test_random_operation_sequences_keep_the_books_balanced(ta, A, admin, owner):
    """Property-style: 400 random operations; the invariants must hold after every single one."""
    rng = random.Random(20261102)
    open_res: list[str] = []
    grant(ta, A, 500)
    for step in range(400):
        op = rng.choice(["reserve", "reserve", "settle", "settle", "release", "grant", "expire", "stale"])
        try:
            if op == "reserve":
                open_res.append(reserve(ta, A, D(rng.randint(1, 60)) / 4)[0])
            elif op == "settle" and open_res:
                settle(ta, open_res.pop(rng.randrange(len(open_res))), D(rng.randint(0, 120)) / 4)
            elif op == "release" and open_res:
                release(ta, open_res.pop(rng.randrange(len(open_res))))
            elif op == "grant":
                grant(ta, A, D(rng.randint(1, 200)) / 2, expires="2999-01-01" if rng.random() < 0.5 else None)
            elif op == "expire":
                as_admin(owner)
                owner.execute("UPDATE credit_lots SET expires_at = now() - interval '1 second' WHERE id = (SELECT id FROM credit_lots WHERE expires_at > now() ORDER BY random() LIMIT 1)")
                owner.execute("SELECT credit_expire_lots()")
            elif op == "stale":
                as_admin(owner)
                owner.execute("UPDATE credit_reservations SET expires_at = now() - interval '1 second' WHERE id = (SELECT id FROM credit_reservations WHERE status='held' ORDER BY random() LIMIT 1)")
                owner.execute("SELECT credit_release_stale()")
        except psycopg.errors.RaiseException as e:
            assert any(m in str(e) for m in ("insufficient_credits", "reservation_not_open")), e
        bal, rsv = wallet(admin, A)
        assert 0 <= rsv <= bal, (step, op, bal, rsv)
        bad = reconcile(admin)
        assert bad == [], (step, op, bad)


@pytest.mark.parametrize("source", ["chat", "catalog", "bill_scan", "inbox", "analyst", "agent", "render"])
def test_every_source_the_app_settles_with_is_accepted(ta, A, source):
    """Regression (found in 18d): 'catalog', 'bill_scan' and 'inbox' were rejected by the phase-5 CHECK, so those features' charges failed."""
    grant(ta, A, 10)
    rid, _ = reserve(ta, A, 3)
    settle(ta, rid, D("1"), usage='{"source":"%s"}' % source)
    assert ta.execute("SELECT source FROM usage_records").fetchone()[0] == source


def test_an_unknown_source_is_still_refused(ta, A):
    grant(ta, A, 10)
    rid, _ = reserve(ta, A, 3)
    with pytest.raises(psycopg.errors.CheckViolation):
        settle(ta, rid, D("1"), usage='{"source":"nonsense"}')
