"""Phase 27.1b: white-label brands."""
import psycopg
import pytest

from conftest import as_admin, ulid
from test_accounting import ctx
from test_memory import second_user  # noqa: F401  (fixture)


@pytest.fixture(autouse=True)
def clean(owner, world):
    yield
    as_admin(owner)
    owner.execute("DELETE FROM tenant_brands")


def enable(admin, tenant, on=True):
    as_admin(admin)
    return admin.execute("SELECT brand_set_enabled(%s,%s)", (tenant, on)).fetchone()[0]


def save(app, world, who="a", user=None, name="Acme", color="#1A2B3C", email="help@acme.test", hide=False, tenant=None):
    ctx(app, world, who)
    return app.execute("SELECT brand_save(%s,%s,%s,%s,%s,%s)", (tenant or world[who]["tenant"], user or world[who]["user"], name, color, email, hide)).fetchone()[0]


def row(owner, tenant):
    as_admin(owner)
    return owner.execute("SELECT enabled, brand_name, color, support_email, hide_powered_by FROM tenant_brands WHERE tenant_id = %s", (tenant,)).fetchone()


def test_saving_needs_the_admin_switch_first(app, owner, admin, world):
    assert save(app, world) == "not_enabled"
    assert row(owner, world["a"]["tenant"]) is None
    assert enable(admin, world["a"]["tenant"]) == "ok"
    assert save(app, world, hide=True) == "ok"
    assert row(owner, world["a"]["tenant"]) == (True, "Acme", "#1a2b3c", "help@acme.test", True)      # colour stored lower-case


def test_only_the_owner_may_brand(app, owner, admin, world, second_user):
    enable(admin, world["a"]["tenant"])
    assert save(app, world, user=second_user) == "not_owner"
    assert save(app, world, user=ulid()) == "not_owner"
    assert row(owner, world["a"]["tenant"])[1] is None


def test_branding_is_per_workspace(app, owner, admin, world):
    enable(admin, world["a"]["tenant"])
    assert save(app, world, who="b") == "not_enabled"          # b was never switched on
    ctx(app, world, "a")
    with pytest.raises(psycopg.Error):
        app.execute("SELECT brand_save(%s,%s,'X',NULL,NULL,false)", (world["b"]["tenant"], world["b"]["user"]))   # a's context cannot edit b
    app.rollback()


@pytest.mark.parametrize("kw", [
    dict(name="x" * 41), dict(name="<b>hi</b>"), dict(name="a\x01b"), dict(color="red"), dict(color="#12345"), dict(color="#GGGGGG"), dict(color="#1234567"),
    dict(email="nope"), dict(email="a@b"), dict(email="a b@c.d"), dict(email="<x>@y.z"), dict(email="a@b.c" + "x" * 260),
])
def test_bad_values_are_refused(app, owner, admin, world, kw):
    enable(admin, world["a"]["tenant"])
    assert save(app, world, **kw) == "bad_input"
    assert row(owner, world["a"]["tenant"])[1] is None


def test_empty_values_mean_default_and_name_with_spaces_is_trimmed(app, owner, admin, world):
    enable(admin, world["a"]["tenant"])
    assert save(app, world, name="  Acme  ", color="", email="  ") == "ok"
    assert row(owner, world["a"]["tenant"]) == (True, "Acme", None, None, False)
    assert save(app, world, name="", color=None, email=None) == "ok"
    assert row(owner, world["a"]["tenant"]) == (True, None, None, None, False)


def test_switching_off_clears_the_brand_and_back_on_starts_blank(app, owner, admin, world):
    enable(admin, world["a"]["tenant"])
    save(app, world, hide=True)
    assert enable(admin, world["a"]["tenant"], False) == "ok"
    assert row(owner, world["a"]["tenant"]) == (False, None, None, None, False)
    assert save(app, world) == "not_enabled"
    enable(admin, world["a"]["tenant"])
    assert row(owner, world["a"]["tenant"]) == (True, None, None, None, False)


def test_enabling_again_keeps_the_brand(app, owner, admin, world):
    enable(admin, world["a"]["tenant"])
    save(app, world, hide=True)
    enable(admin, world["a"]["tenant"])
    assert row(owner, world["a"]["tenant"]) == (True, "Acme", "#1a2b3c", "help@acme.test", True)


def test_admin_function_validates_and_is_admin_only(app, owner, admin, world):
    assert enable(admin, ulid()) == "not_found"
    assert admin.execute("SELECT brand_set_enabled(NULL,true)").fetchone()[0] == "bad_input"
    assert admin.execute("SELECT brand_set_enabled(%s,NULL)", (world["a"]["tenant"],)).fetchone()[0] == "bad_input"
    ctx(app, world, "a")
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("SELECT brand_set_enabled(%s,true)", (world["a"]["tenant"],))


def test_table_is_read_only_for_the_app_and_isolated(app, owner, admin, world):
    enable(admin, world["a"]["tenant"])
    enable(admin, world["b"]["tenant"])
    ctx(app, world, "a")
    assert app.execute("SELECT count(*) FROM tenant_brands").fetchone()[0] == 1
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("UPDATE tenant_brands SET brand_name = 'hack'")
    app.rollback()
    ctx(app, world, "a")
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("INSERT INTO tenant_brands (tenant_id, enabled) VALUES (%s, true)", (ulid(),))


def test_save_needs_a_person_and_the_right_workspace(app, owner, admin, world):
    enable(admin, world["a"]["tenant"])
    ctx(app, world, "a")
    with pytest.raises(psycopg.errors.InvalidParameterValue):
        app.execute("SELECT brand_save(%s,NULL,'X',NULL,NULL,false)", (world["a"]["tenant"],))


def test_a_missing_hide_flag_is_bad_input_not_a_crash(app, owner, admin, world):
    enable(admin, world["a"]["tenant"])
    assert save(app, world, hide=None) == "bad_input"
