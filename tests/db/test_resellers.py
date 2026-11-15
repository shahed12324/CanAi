"""Phase 27.1a: resellers, joining by code, commission report, payouts."""
import psycopg
import pytest

from conftest import as_admin, ulid
from test_accounting import ctx
from test_memory import second_user  # noqa: F401  (fixture)


@pytest.fixture(autouse=True)
def clean(owner, world):
    yield
    as_admin(owner)
    owner.execute("DELETE FROM reseller_payouts")
    owner.execute("UPDATE tenants SET reseller_id = NULL, reseller_bp = NULL, reseller_joined_at = NULL")
    owner.execute("DELETE FROM resellers")


def make_user(owner, name="rs"):
    as_admin(owner)
    uid = ulid()
    owner.execute("INSERT INTO users (id,name,email,password,email_verified_at) VALUES (%s,%s,%s,'x',now())", (uid, name, f"{name}-{uid}@example.com"))
    return uid


def create(admin, user, code="PARTNER1", bp=1000, name="Partner"):
    as_admin(admin)
    rid = ulid()
    return rid, admin.execute("SELECT reseller_create(%s,%s,%s,%s,%s)", (rid, user, name, code, bp)).fetchone()[0]


def join(app, world, code, who="a", user=None):
    ctx(app, world, who)
    return app.execute("SELECT reseller_join(%s,%s,%s)", (world[who]["tenant"], user or world[who]["user"], code)).fetchone()[0]


def package(owner):
    as_admin(owner)
    pid = ulid()
    owner.execute("INSERT INTO credit_packages (id,name,credits,price,currency,status) VALUES (%s,%s,100,10,'BDT','active')", (pid, f"p-{pid}"))
    return pid


def order(owner, tenant, pkg, total, *, vat=0, cur="BDT", status="paid", refunded=0, paid_at="now()"):
    as_admin(owner)
    oid = ulid()
    owner.execute(
        f"""INSERT INTO orders (id,tenant_id,kind,change_kind,package_id,credits,item_name,currency,list_minor,vat_minor,total_minor,gateway,gateway_txn_id,status,paid_at,expires_at)
            VALUES (%s,%s,'credits','credits',%s,100,'x',%s,%s,%s,%s,'manual',%s,'pending',NULL,now()+interval '1 day')""",
        (oid, tenant, pkg, cur, total - vat, vat, total, ulid()),
    )
    if status != "pending":
        owner.execute(f"UPDATE orders SET status = 'paid', paid_at = {paid_at} WHERE id = %s", (oid,)) if status in ("paid", "partially_refunded", "refunded") else owner.execute(f"UPDATE orders SET status = %s WHERE id = %s", (status, oid))
        if status in ("partially_refunded", "refunded"):
            owner.execute("UPDATE orders SET status = %s, refunded_minor = %s WHERE id = %s", (status, refunded, oid))
    return oid


def report(app, world, user, who="a"):
    ctx(app, world, who)
    return app.execute("SELECT reseller_report(%s,%s)", (world[who]["tenant"], user)).fetchone()[0]


# ---------- admin: create / update
def test_create_and_bad_inputs(admin, owner, world):
    u = make_user(owner)
    assert create(admin, u)[1] == "ok"
    assert create(admin, u, code="OTHER123")[1] == "already"
    u2 = make_user(owner, "r2")
    assert create(admin, u2, code="partner1")[1] == "code_taken"          # codes are case-insensitive
    assert create(admin, ulid(), code="NEWCODE1")[1] == "no_user"
    for kw in (dict(code="abc"), dict(code="has space"), dict(code="x" * 13), dict(bp=-1), dict(bp=5001), dict(name="  "), dict(name="x" * 121)):
        assert create(admin, u2, **kw)[1] == "bad_input"
    assert create(admin, u2, code="MAXRATE1", bp=5000)[1] == "ok"


def test_app_role_cannot_create_update_or_pay(app, owner, admin, world):
    u = make_user(owner)
    rid, _ = create(admin, u)
    ctx(app, world, "a")
    for sql, args in (("SELECT reseller_create(%s,%s,'n','ABCDEF1',10)", (ulid(), u)), ("SELECT reseller_update(%s,'suspended',0)", (rid,)), ("SELECT reseller_payout(%s,%s,'BDT',1,'')", (ulid(), rid))):
        with pytest.raises(psycopg.errors.InsufficientPrivilege):
            app.execute(sql, args)
        app.rollback()


def test_tables_are_not_readable_by_the_app(app, owner, admin, world):
    create(admin, make_user(owner))
    ctx(app, world, "a")
    for t in ("resellers", "reseller_payouts"):
        with pytest.raises(psycopg.errors.InsufficientPrivilege):
            app.execute(f"SELECT * FROM {t}")
        app.rollback()


# ---------- joining
def test_owner_joins_once_by_code_and_the_rate_is_snapshotted(app, owner, admin, world):
    rid, _ = create(admin, make_user(owner), bp=1500)
    assert join(app, world, "partner1") == "ok"                           # lower case works
    as_admin(owner)
    assert owner.execute("SELECT reseller_id, reseller_bp, reseller_joined_at IS NOT NULL FROM tenants WHERE id = %s", (world["a"]["tenant"],)).fetchone() == (rid, 1500, True)
    assert join(app, world, "PARTNER1") == "already"
    as_admin(admin)
    admin.execute("SELECT reseller_update(%s,'active',3000)", (rid,))
    as_admin(owner)
    assert owner.execute("SELECT reseller_bp FROM tenants WHERE id = %s", (world["a"]["tenant"],)).fetchone()[0] == 1500   # later rate change does not touch existing customers


def test_join_refusals(app, owner, admin, world, second_user):
    ru = make_user(owner)
    rid, _ = create(admin, ru)
    assert join(app, world, "NOPE1234") == "not_found"
    assert join(app, world, "bad") == "not_found"
    assert join(app, world, "' OR 1=1") == "not_found"
    assert join(app, world, "PARTNER1", user=second_user) == "not_owner"          # a member who is not the owner
    assert join(app, world, "PARTNER1", user=ulid()) == "not_owner"
    # the reseller cannot join themselves, even through another workspace they own
    as_admin(owner)
    owner.execute("UPDATE tenants SET owner_id = %s WHERE id = %s", (ru, world["b"]["tenant"]))
    ctx(app, world, "b")
    assert app.execute("SELECT reseller_join(%s,%s,'PARTNER1')", (world["b"]["tenant"], ru)).fetchone()[0] == "self"
    as_admin(admin)
    admin.execute("SELECT reseller_update(%s,'suspended',1000)", (rid,))
    assert join(app, world, "PARTNER1") == "not_found"                    # suspended resellers take no new customers
    as_admin(owner)
    assert owner.execute("SELECT count(*) FROM tenants WHERE reseller_id IS NOT NULL").fetchone()[0] == 0


def test_join_needs_the_right_workspace_and_a_person(app, owner, admin, world):
    create(admin, make_user(owner))
    ctx(app, world, "a")
    with pytest.raises(psycopg.Error):
        app.execute("SELECT reseller_join(%s,%s,'PARTNER1')", (world["b"]["tenant"], world["b"]["user"]))
    app.rollback()
    ctx(app, world, "a")
    with pytest.raises(psycopg.errors.InvalidParameterValue):
        app.execute("SELECT reseller_join(%s,NULL,'PARTNER1')", (world["a"]["tenant"],))


# ---------- me / report
def test_me_and_report_for_non_resellers(app, owner, admin, world):
    ctx(app, world, "a")
    assert app.execute("SELECT reseller_me(%s,%s)", (world["a"]["tenant"], world["a"]["user"])).fetchone()[0] is None
    assert report(app, world, world["a"]["user"]) is None


def test_me_shows_own_row(app, owner, admin, world):
    create(admin, world["a"]["user"], code="MYCODE12", bp=700, name="Me Co")
    ctx(app, world, "a")
    me = app.execute("SELECT reseller_me(%s,%s)", (world["a"]["tenant"], world["a"]["user"])).fetchone()[0]
    assert (me["name"], me["code"], me["commission_bp"], me["status"]) == ("Me Co", "MYCODE12", 700, "active")


def test_commission_is_on_net_paid_revenue_after_joining_per_currency(app, owner, admin, world):
    ru = world["a"]["user"]
    create(admin, ru, bp=1000)
    assert join(app, world, "PARTNER1", who="b") == "ok"
    pkg, tb = package(owner), world["b"]["tenant"]
    order(owner, tb, pkg, 11500, vat=1500)                                # net 10000 -> 1000
    order(owner, tb, pkg, 5000, status="partially_refunded", refunded=2001)   # net 2999 -> floor(299.9)=299
    order(owner, tb, pkg, 3000, status="refunded", refunded=3000)         # net 0
    order(owner, tb, pkg, 9999, status="pending")                         # not paid
    order(owner, tb, pkg, 9999, status="failed")
    order(owner, tb, pkg, 7000, paid_at="now() - interval '30 days'")     # paid before joining: not counted
    order(owner, tb, pkg, 2000, cur="USD")
    r = report(app, world, ru)
    assert r["money"]["BDT"] == {"revenue": 12999, "commission": 1299, "paid_out": 0, "due": 1299}
    assert r["money"]["USD"] == {"revenue": 2000, "commission": 200, "paid_out": 0, "due": 200}
    assert [(c["name"], c["paid_orders"]) for c in r["customers"]] == [("Tenant b", 4)]
    assert set(r["customers"][0]) == {"name", "joined_at", "paid_orders"}


def test_a_reseller_sees_only_their_own_customers(app, owner, admin, world):
    ra, rb = world["a"]["user"], make_user(owner, "rb")
    create(admin, ra, code="AAAAAA11")
    create(admin, rb, code="BBBBBB22")
    assert join(app, world, "BBBBBB22", who="b") == "ok"
    pkg = package(owner)
    order(owner, world["b"]["tenant"], pkg, 1000)
    assert report(app, world, ra)["customers"] == [] and report(app, world, ra)["money"] == {}


def test_a_reseller_cannot_read_someone_elses_report(app, owner, admin, world):
    create(admin, world["a"]["user"])
    assert report(app, world, world["b"]["user"], who="a") is None        # asking as another person gives nothing


# ---------- payouts
def test_payouts_reduce_due_and_cannot_exceed_it(app, owner, admin, world):
    ru = world["a"]["user"]
    rid, _ = create(admin, ru, bp=1000)
    join(app, world, "PARTNER1", who="b")
    order(owner, world["b"]["tenant"], package(owner), 10000)
    as_admin(admin)
    pay = lambda cur, amt, note="": admin.execute("SELECT reseller_payout(%s,%s,%s,%s,%s)", (ulid(), rid, cur, amt, note)).fetchone()[0]
    assert pay("BDT", 600) == "ok"
    assert report(app, world, ru)["money"]["BDT"]["due"] == 400 and report(app, world, ru)["money"]["BDT"]["paid_out"] == 600
    as_admin(admin)
    assert pay("BDT", 401) == "exceeds_due"
    assert pay("USD", 1) == "exceeds_due"                                 # nothing earned in USD
    assert pay("BDT", 400) == "ok"
    assert pay("BDT", 1) == "exceeds_due"
    for bad in (("BDT", 0), ("BDT", -5), ("bdt", 5), ("BDT", None), ("BDT", 5, "x" * 201)):
        assert pay(*bad) == "bad_input"
    assert admin.execute("SELECT reseller_payout(%s,%s,'BDT',1,'')", (ulid(), ulid())).fetchone()[0] == "not_found"


def test_a_refund_after_a_payout_cannot_make_due_negative_silently(app, owner, admin, world):
    ru = world["a"]["user"]
    rid, _ = create(admin, ru, bp=1000)
    join(app, world, "PARTNER1", who="b")
    oid = order(owner, world["b"]["tenant"], package(owner), 10000)
    as_admin(admin)
    admin.execute("SELECT reseller_payout(%s,%s,'BDT',1000,'')", (ulid(), rid))
    as_admin(owner)
    owner.execute("UPDATE orders SET status='refunded', refunded_minor = 10000 WHERE id = %s", (oid,))
    assert report(app, world, ru)["money"]["BDT"] == {"revenue": 0, "commission": 0, "paid_out": 1000, "due": -1000}   # visible, so an admin can settle it


def test_update_validates(admin, owner):
    rid, _ = create(admin, make_user(owner))
    as_admin(admin)
    assert admin.execute("SELECT reseller_update(%s,'gone',10)", (rid,)).fetchone()[0] == "bad_input"
    assert admin.execute("SELECT reseller_update(%s,'active',5001)", (rid,)).fetchone()[0] == "bad_input"
    assert admin.execute("SELECT reseller_update(%s,'active',5)", (ulid(),)).fetchone()[0] == "not_found"


def test_payout_limit_uses_net_of_vat_only_orders_after_joining_and_the_same_currency(app, owner, admin, world):
    ru = world["a"]["user"]
    rid, _ = create(admin, ru, bp=1000)
    pkg, tb = package(owner), world["b"]["tenant"]
    order(owner, tb, pkg, 50000, paid_at="now() - interval '30 days'")    # before joining: earns nothing
    join(app, world, "PARTNER1", who="b")
    order(owner, tb, pkg, 11500, vat=1500)                                # net 10000 -> 1000 BDT
    order(owner, tb, pkg, 3000, cur="USD")                                # 300 USD
    as_admin(admin)
    pay = lambda cur, amt: admin.execute("SELECT reseller_payout(%s,%s,%s,%s,'')", (ulid(), rid, cur, amt)).fetchone()[0]
    assert pay("BDT", 1001) == "exceeds_due"                              # VAT is not commissionable; pre-join order does not count
    assert pay("USD", 300) == "ok"
    assert pay("BDT", 1000) == "ok"                                       # a USD payout does not eat the BDT balance
    r = report(app, world, ru)
    assert r["money"]["BDT"]["paid_out"] == 1000 and r["money"]["USD"]["paid_out"] == 300 and r["money"]["BDT"]["due"] == 0 and r["money"]["USD"]["due"] == 0


def test_closed_customers_are_not_listed_but_their_money_still_counts(app, owner, admin, world):
    ru = world["a"]["user"]
    create(admin, ru, bp=1000)
    join(app, world, "PARTNER1", who="b")
    order(owner, world["b"]["tenant"], package(owner), 1000)
    as_admin(owner)
    owner.execute("UPDATE tenants SET deleted_at = now() WHERE id = %s", (world["b"]["tenant"],))
    r = report(app, world, ru)
    assert r["customers"] == [] and r["money"]["BDT"]["commission"] == 100


def test_reseller_of_names_the_reseller_for_the_workspace_only(app, owner, admin, world):
    create(admin, make_user(owner), name="Acme Partner")
    ctx(app, world, "a")
    assert app.execute("SELECT reseller_of(%s,%s)", (world["a"]["tenant"], world["a"]["user"])).fetchone()[0] is None
    join(app, world, "PARTNER1")
    create(admin, make_user(owner, "r2"), code="OTHERCO1", name="Other Co")
    join(app, world, "OTHERCO1", who="b")
    ctx(app, world, "a")
    assert app.execute("SELECT reseller_of(%s,%s)", (world["a"]["tenant"], world["a"]["user"])).fetchone()[0] == "Acme Partner"
    ctx(app, world, "b")
    assert app.execute("SELECT reseller_of(%s,%s)", (world["b"]["tenant"], world["b"]["user"])).fetchone()[0] == "Other Co"
    ctx(app, world, "a")
    set_t = app.execute("SELECT reseller_of(%s,%s)", (world["a"]["tenant"], world["a"]["user"])).fetchone()[0]
    assert set_t == "Acme Partner"
    with pytest.raises(psycopg.Error):
        app.execute("SELECT reseller_of(%s,%s)", (world["b"]["tenant"], world["b"]["user"]))      # another workspace is refused


def test_admin_overview_matches_what_resellers_see_and_is_admin_only(app, owner, admin, world):
    ru = world["a"]["user"]
    rid, _ = create(admin, ru, bp=1000, name="Acme")
    join(app, world, "PARTNER1", who="b")
    order(owner, world["b"]["tenant"], package(owner), 11500, vat=1500)
    as_admin(admin)
    admin.execute("SELECT reseller_payout(%s,%s,'BDT',400,'')", (ulid(), rid))
    ov = admin.execute("SELECT reseller_admin_overview()").fetchone()[0]
    assert len(ov) == 1 and (ov[0]["name"], ov[0]["code"], ov[0]["customers"], ov[0]["status"]) == ("Acme", "PARTNER1", 1, "active")
    assert ov[0]["money"] == {"BDT": {"commission": 1000, "paid_out": 400, "due": 600}}
    assert ov[0]["money"]["BDT"]["due"] == report(app, world, ru)["money"]["BDT"]["due"]
    ctx(app, world, "a")
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("SELECT reseller_admin_overview()")
