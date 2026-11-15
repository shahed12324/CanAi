"""Phase 27.2a: public API keys."""
import hashlib

import psycopg
import pytest

from conftest import as_admin, ulid
from test_accounting import ctx

CREATE = "SELECT api_key_create(%s,%s,%s,%s,%s,%s,%s::text[],%s)"
RESOLVE = "SELECT api_key_resolve(%s)"


def h(k="key1"):
    return hashlib.sha256(k.encode()).hexdigest()


@pytest.fixture(autouse=True)
def clean(owner, world):
    yield
    as_admin(owner)
    owner.execute("DELETE FROM api_key_usage")
    owner.execute("DELETE FROM api_keys")


def make(app, world, *, who="a", key="key1", name="n8n", scopes=("me",), days=None, kid=None, user=None):
    ctx(app, world, who)
    kid = kid or ulid()
    r = app.execute(CREATE, (kid, world[who]["tenant"], user or world[who]["user"], name, "jk_live_ab12", h(key), list(scopes), days)).fetchone()[0]
    return kid, r


def anon(app):
    app.execute("SELECT set_config('app.tenant_id','',false)")
    return app


def test_create_resolve_and_the_key_itself_is_never_stored(app, world, owner):
    kid, r = make(app, world, scopes=("me", "workflows:run"))
    assert r == "ok"
    got = anon(app).execute(RESOLVE, (h(),)).fetchone()[0]
    assert got["key_id"] == kid and got["tenant_id"] == world["a"]["tenant"] and got["user_id"] == world["a"]["user"]
    assert sorted(got["scopes"]) == ["me", "workflows:run"]
    as_admin(owner)
    row = owner.execute("SELECT key_hash, prefix FROM api_keys").fetchone()
    assert row[0] == h() and "key1" not in row[0] and row[1] == "jk_live_ab12"


def test_unknown_or_malformed_hash_resolves_to_nothing(app, world):
    make(app, world)
    a = anon(app)
    assert a.execute(RESOLVE, (h("other"),)).fetchone()[0] is None
    assert a.execute(RESOLVE, ("zz",)).fetchone()[0] is None
    assert a.execute(RESOLVE, (None,)).fetchone()[0] is None
    assert a.execute(RESOLVE, (h().upper(),)).fetchone()[0] is None


@pytest.mark.parametrize("kw", [
    dict(name="  "), dict(name="x" * 81), dict(scopes=()), dict(scopes=("admin",)), dict(scopes=("me", "nope")),
    dict(days=0), dict(days=366),
])
def test_bad_input_is_refused(app, world, kw):
    _, r = make(app, world, **kw)
    assert r == "bad_input"


def test_bad_hash_prefix_and_ownership_inputs(app, world):
    ctx(app, world)
    t, u = world["a"]["tenant"], world["a"]["user"]
    assert app.execute(CREATE, (ulid(), t, u, "n", "jk", h(), ["me"], None)).fetchone()[0] == "bad_input"       # prefix too short
    assert app.execute(CREATE, (ulid(), t, u, "n", "jk_live_ab12", "XYZ", ["me"], None)).fetchone()[0] == "bad_input"
    with pytest.raises(psycopg.Error):
        app.execute(CREATE, (ulid(), t, None, "n", "jk_live_ab12", h(), ["me"], None))
    app.rollback()


def test_exact_limits_and_duplicates(app, world):
    _, r = make(app, world, name="x" * 80, days=365, scopes=("me", "credits:read", "workflows:read", "workflows:run"))
    assert r == "ok"
    kid = ulid()
    assert make(app, world, key="k2", kid=kid)[1] == "ok"
    assert make(app, world, key="k3", kid=kid)[1] == "duplicate"          # same id
    assert make(app, world, key="key1")[1] == "duplicate"                  # same key


def test_ten_active_keys_per_person_and_revoked_or_expired_ones_do_not_count(app, world, owner):
    ids = [make(app, world, key=f"k{i}")[0] for i in range(10)]
    assert make(app, world, key="k11")[1] == "limit"
    ctx(app, world)
    assert app.execute("SELECT api_key_revoke(%s,%s,%s)", (ids[0], world["a"]["tenant"], world["a"]["user"])).fetchone()[0] == "ok"
    assert make(app, world, key="k12")[1] == "ok"
    as_admin(owner)
    owner.execute("UPDATE api_keys SET expires_at = now() - interval '1 minute' WHERE id=%s", (ids[1],))
    assert make(app, world, key="k13")[1] == "ok"
    assert make(app, world, key="k14")[1] == "limit"


def test_revoke_is_final_owner_only_and_stops_the_key(app, world):
    kid, _ = make(app, world)
    t, u = world["a"]["tenant"], world["a"]["user"]
    assert app.execute("SELECT api_key_revoke(%s,%s,%s)", (kid, t, "U" * 26)).fetchone()[0] == "not_found"          # not my key
    assert anon(app).execute(RESOLVE, (h(),)).fetchone()[0] is not None
    ctx(app, world)
    assert app.execute("SELECT api_key_revoke(%s,%s,%s)", (kid, t, u)).fetchone()[0] == "ok"
    assert app.execute("SELECT api_key_revoke(%s,%s,%s)", (kid, t, u)).fetchone()[0] == "not_found"
    assert anon(app).execute(RESOLVE, (h(),)).fetchone()[0] is None
    with pytest.raises(psycopg.Error):
        app.execute("SELECT api_key_revoke(%s,%s,NULL)", (kid, t))
    app.rollback()


def test_an_expired_key_stops_working(app, world, owner):
    kid, _ = make(app, world, days=1)
    assert anon(app).execute(RESOLVE, (h(),)).fetchone()[0] is not None
    as_admin(owner)
    owner.execute("UPDATE api_keys SET expires_at = now() - interval '1 second' WHERE id=%s", (kid,))
    assert anon(app).execute(RESOLVE, (h(),)).fetchone()[0] is None


def test_a_key_dies_with_the_membership_or_the_workspace(app, world, owner):
    make(app, world)
    as_admin(owner)
    owner.execute("UPDATE tenant_users SET status='removed' WHERE tenant_id=%s AND user_id=%s", (world["a"]["tenant"], world["a"]["user"]))
    assert anon(app).execute(RESOLVE, (h(),)).fetchone()[0] is None
    owner.execute("UPDATE tenant_users SET status='active' WHERE tenant_id=%s AND user_id=%s", (world["a"]["tenant"], world["a"]["user"]))
    assert anon(app).execute(RESOLVE, (h(),)).fetchone()[0] is not None
    owner.execute("UPDATE tenants SET status='suspended' WHERE id=%s", (world["a"]["tenant"],))
    assert anon(app).execute(RESOLVE, (h(),)).fetchone()[0] is None
    owner.execute("UPDATE tenants SET status='active' WHERE id=%s", (world["a"]["tenant"],))


def test_last_used_is_noted_but_only_once_a_minute(app, world, owner):
    kid, _ = make(app, world)
    as_admin(owner)
    assert owner.execute("SELECT last_used_at FROM api_keys WHERE id=%s", (kid,)).fetchone()[0] is None
    anon(app).execute(RESOLVE, (h(),))
    as_admin(owner)
    first = owner.execute("SELECT last_used_at FROM api_keys WHERE id=%s", (kid,)).fetchone()[0]
    assert first is not None
    anon(app).execute(RESOLVE, (h(),))
    as_admin(owner)
    assert owner.execute("SELECT last_used_at FROM api_keys WHERE id=%s", (kid,)).fetchone()[0] == first
    owner.execute("UPDATE api_keys SET last_used_at = now() - interval '5 minutes' WHERE id=%s", (kid,))
    anon(app).execute(RESOLVE, (h(),))
    as_admin(owner)
    assert owner.execute("SELECT last_used_at > now() - interval '1 minute' FROM api_keys WHERE id=%s", (kid,)).fetchone()[0] is True


def test_privacy_other_workspace_sees_no_keys_and_the_app_cannot_write_directly(app, world):
    make(app, world)
    ctx(app, world, "b")
    assert app.execute("SELECT count(*) FROM api_keys").fetchone()[0] == 0
    with pytest.raises(psycopg.Error):
        app.execute("INSERT INTO api_keys (id,tenant_id,user_id,name,prefix,key_hash,scopes) VALUES (%s,%s,%s,'x','jk_live_ab12',%s,ARRAY['me'])",
                    (ulid(), world["b"]["tenant"], world["b"]["user"], h("zz")))
    app.rollback()
    ctx(app, world)
    assert app.execute("SELECT count(*) FROM api_keys").fetchone()[0] == 1


def _usage(owner, kid):
    as_admin(owner)
    return owner.execute("SELECT day, calls FROM api_key_usage WHERE key_id=%s ORDER BY day", (kid,)).fetchall()


def test_usage_counts_each_good_call_per_day_and_nothing_for_bad_ones(app, world, owner):
    kid, _ = make(app, world)
    a = anon(app)
    for _ in range(3):
        a.execute(RESOLVE, (h(),))
    a.execute(RESOLVE, (h("wrong"),))
    a.execute(RESOLVE, ("zz",))
    rows = _usage(owner, kid)
    assert len(rows) == 1 and rows[0][1] == 3
    ctx(app, world)
    assert app.execute("SELECT SUM(calls) FROM api_key_usage").fetchone()[0] == 3          # the owner can read it
    ctx(app, world, "b")
    assert app.execute("SELECT count(*) FROM api_key_usage").fetchone()[0] == 0            # another workspace cannot
    with pytest.raises(psycopg.Error):
        app.execute("INSERT INTO api_key_usage (key_id,tenant_id,day,calls) VALUES (%s,%s,now(),5)", (kid, world["b"]["tenant"]))
    app.rollback()


def test_revoked_keys_count_nothing_and_old_days_are_trimmed_after_90_days(app, world, owner):
    kid, _ = make(app, world)
    as_admin(owner)
    owner.execute("INSERT INTO api_key_usage (key_id,tenant_id,day,calls) VALUES (%s,%s,(now() AT TIME ZONE 'Asia/Dhaka')::date - 91, 7), (%s,%s,(now() AT TIME ZONE 'Asia/Dhaka')::date - 90, 4)",
                  (kid, world["a"]["tenant"], kid, world["a"]["tenant"]))
    anon(app).execute(RESOLVE, (h(),))
    days = [r[1] for r in _usage(owner, kid)]
    assert days == [4, 1]                                  # the 91-day-old row is gone, the 90-day-old one stays
    kid2, _ = make(app, world, key="other")
    as_admin(owner)
    owner.execute("INSERT INTO api_key_usage (key_id,tenant_id,day,calls) VALUES (%s,%s,(now() AT TIME ZONE 'Asia/Dhaka')::date - 200, 9)", (kid2, world["a"]["tenant"]))
    anon(app).execute(RESOLVE, (h(),))
    assert [r[1] for r in _usage(owner, kid2)] == [9]      # using one key never touches another key's rows
    ctx(app, world)
    app.execute("SELECT api_key_revoke(%s,%s,%s)", (kid, world["a"]["tenant"], world["a"]["user"]))
    anon(app).execute(RESOLVE, (h(),))
    assert [r[1] for r in _usage(owner, kid)] == [4, 2]    # a revoked key adds nothing


def test_usage_rows_go_with_the_key(app, world, owner):
    kid, _ = make(app, world)
    anon(app).execute(RESOLVE, (h(),))
    as_admin(owner)
    owner.execute("DELETE FROM api_keys WHERE id=%s", (kid,))
    assert _usage(owner, kid) == []
