"""Phase 27.1c: Enterprise licences and seat limits."""
import psycopg
import pytest

from conftest import as_admin, ulid
from test_accounting import ctx


@pytest.fixture(autouse=True)
def clean(owner, world):
    yield
    as_admin(owner)
    owner.execute("DELETE FROM licenses")
    owner.execute("DELETE FROM tenant_users WHERE user_id NOT IN (%s,%s)", (world["a"]["user"], world["b"]["user"]))


def lic(admin, tenant, seats=3, days=30, note=""):
    as_admin(admin)
    return admin.execute("SELECT license_set(%s,%s,now() + make_interval(days => %s),%s)", (tenant, seats, days, note)).fetchone()[0]


def add_member(owner, tenant, status="active"):
    as_admin(owner)
    uid = ulid()
    owner.execute("INSERT INTO users (id,name,email,password,email_verified_at) VALUES (%s,'m',%s,'x',now())", (uid, f"m-{uid}@example.com"))
    owner.execute("INSERT INTO tenant_users (id,tenant_id,user_id,status,joined_at) VALUES (%s,%s,%s,%s,now())", (ulid(), tenant, uid, status))
    return uid


def members(owner, tenant):
    as_admin(owner)
    return owner.execute("SELECT count(*) FROM tenant_users WHERE tenant_id = %s AND status = 'active'", (tenant,)).fetchone()[0]


def left(app, world, who="a"):
    ctx(app, world, who)
    return app.execute("SELECT license_seats_left(%s)", (world[who]["tenant"],)).fetchone()[0]


def test_no_licence_means_no_limit(app, owner, world):
    for _ in range(5):
        add_member(owner, world["a"]["tenant"])
    assert left(app, world) is None


def test_seats_are_enforced_on_every_way_of_adding_a_member(app, owner, admin, world):
    tid = world["a"]["tenant"]
    assert members(owner, tid) == 1                     # the owner holds one seat
    assert lic(admin, tid, seats=3) == "ok"
    assert left(app, world) == 2
    add_member(owner, tid)
    add_member(owner, tid)
    assert left(app, world) == 0
    with pytest.raises(psycopg.errors.RaiseException, match="seat_limit"):
        add_member(owner, tid)
    owner.rollback()
    assert members(owner, tid) == 3


def test_reactivating_a_removed_member_needs_a_free_seat(app, owner, admin, world):
    tid = world["a"]["tenant"]
    u = add_member(owner, tid)
    as_admin(owner)
    owner.execute("UPDATE tenant_users SET status = 'removed' WHERE user_id = %s", (u,))
    lic(admin, tid, seats=1)
    as_admin(owner)
    with pytest.raises(psycopg.errors.RaiseException, match="seat_limit"):
        owner.execute("UPDATE tenant_users SET status = 'active' WHERE user_id = %s", (u,))
    owner.rollback()
    lic(admin, tid, seats=2)
    as_admin(owner)
    owner.execute("UPDATE tenant_users SET status = 'active' WHERE user_id = %s", (u,))
    assert members(owner, tid) == 2


def test_inactive_rows_and_unrelated_updates_never_hit_the_limit(app, owner, admin, world):
    tid = world["a"]["tenant"]
    lic(admin, tid, seats=1)
    add_member(owner, tid, status="invited")            # an invited person holds no seat yet
    as_admin(owner)
    owner.execute("UPDATE tenant_users SET joined_at = now() WHERE tenant_id = %s AND status = 'active'", (tid,))   # the owner, already seated
    owner.execute("UPDATE tenant_users SET status = 'active', updated_at = now() WHERE tenant_id = %s AND user_id = %s", (tid, world["a"]["user"]))
    assert members(owner, tid) == 1


def test_an_expired_licence_lets_nobody_new_in_but_keeps_current_members(app, owner, admin, world):
    tid = world["a"]["tenant"]
    add_member(owner, tid)
    lic(admin, tid, seats=10)
    as_admin(owner)
    owner.execute("UPDATE licenses SET expires_at = now() - interval '1 day' WHERE tenant_id = %s", (tid,))
    assert left(app, world) == 0
    with pytest.raises(psycopg.errors.RaiseException, match="license_expired"):
        add_member(owner, tid)
    owner.rollback()
    assert members(owner, tid) == 2
    assert lic(admin, tid, seats=10, days=30) == "ok"   # renewing re-opens it
    add_member(owner, tid)
    assert members(owner, tid) == 3


def test_the_limit_is_per_workspace(app, owner, admin, world):
    lic(admin, world["a"]["tenant"], seats=1)
    for _ in range(3):
        add_member(owner, world["b"]["tenant"])
    assert left(app, world, "b") is None


def test_cannot_set_fewer_seats_than_members_now(app, owner, admin, world):
    tid = world["a"]["tenant"]
    add_member(owner, tid)
    add_member(owner, tid)
    assert lic(admin, tid, seats=2) == "below_used"
    assert lic(admin, tid, seats=3) == "ok"


def test_set_validates_and_replaces(app, owner, admin, world):
    tid = world["a"]["tenant"]
    for kw in (dict(seats=0), dict(seats=100001), dict(note="x" * 301)):
        assert lic(admin, tid, **kw) == "bad_input"
    assert lic(admin, ulid()) == "not_found"
    as_admin(admin)
    assert admin.execute("SELECT license_set(NULL,1,now(),'')").fetchone()[0] == "bad_input"
    assert admin.execute("SELECT license_set(%s,1,NULL,'')", (tid,)).fetchone()[0] == "bad_input"
    assert lic(admin, tid, seats=5, note="  acme deal ") == "ok"
    assert lic(admin, tid, seats=7) == "ok"
    as_admin(owner)
    assert owner.execute("SELECT seats, note, count(*) OVER () FROM licenses WHERE tenant_id = %s", (tid,)).fetchone() == (7, "", 1)


def test_remove_lifts_the_limit(app, owner, admin, world):
    tid = world["a"]["tenant"]
    lic(admin, tid, seats=1)
    as_admin(admin)
    assert admin.execute("SELECT license_remove(%s)", (tid,)).fetchone()[0] == "ok"
    assert admin.execute("SELECT license_remove(%s)", (tid,)).fetchone()[0] == "not_found"
    add_member(owner, tid)
    assert left(app, world) is None


def test_app_cannot_set_or_remove_or_write_and_sees_only_its_own_licence(app, owner, admin, world):
    lic(admin, world["a"]["tenant"], seats=5)
    lic(admin, world["b"]["tenant"], seats=9)
    ctx(app, world, "a")
    for sql in ("SELECT license_set(%s,100,now() + interval '1 year','')", "SELECT license_remove(%s)"):
        with pytest.raises(psycopg.errors.InsufficientPrivilege):
            app.execute(sql, (world["a"]["tenant"],)) if "set" not in sql else app.execute(sql, (world["a"]["tenant"],))
        app.rollback()
        ctx(app, world, "a")
    assert app.execute("SELECT seats FROM licenses").fetchall() == [(5,)]
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("UPDATE licenses SET seats = 99999")


def test_seats_left_refuses_another_workspace(app, owner, admin, world):
    ctx(app, world, "a")
    with pytest.raises(psycopg.Error):
        app.execute("SELECT license_seats_left(%s)", (world["b"]["tenant"],))
