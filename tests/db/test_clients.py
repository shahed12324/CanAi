import psycopg
import pytest

from conftest import as_admin, ulid
from test_accounting import ctx
from test_ads_meta import acct as meta_acct
from test_gads import acct as g_acct
from test_social_publish import account


@pytest.fixture(autouse=True)
def clean(owner, world):
    yield
    as_admin(owner)
    for t in ("ads_accounts", "gads_accounts", "social_accounts"):
        owner.execute(f"DELETE FROM {t}")
    owner.execute("DELETE FROM clients")
    owner.execute("DELETE FROM brand_kits")


def save(app, world, name="ক্লায়েন্ট ১", who="a", cid=None, create=True, primary="#112233", accent="#445566", logo=None, set_logo=False, notes="", email="a@b.co"):
    ctx(app, world, who)
    cid = cid or ulid()
    r = app.execute("SELECT client_save(%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)", (cid, ulid(), world[who]["tenant"], world[who]["user"], create, name, primary, accent, email, "", "", "", notes, logo, set_logo)).fetchone()[0]
    return r, cid


def assign(app, world, kind, acc, client, who="a"):
    ctx(app, world, who)
    return app.execute("SELECT client_assign(%s,%s,%s,%s,%s)", (world[who]["tenant"], world[who]["user"], kind, acc, client)).fetchone()[0]


def test_create_makes_a_client_with_its_own_brand_kit_and_leaves_the_default_alone(app, world, owner):
    as_admin(owner)
    owner.execute("INSERT INTO brand_kits (id,tenant_id,name,is_default) VALUES (%s,%s,'mine',true)", (ulid(), world["a"]["tenant"]))
    r, c = save(app, world, logo="QUJD", set_logo=True, notes="  নোট ")
    assert r == "ok"
    ctx(app, world)
    row = app.execute("SELECT c.name, c.notes, k.name, k.primary_color, k.logo_b64, k.is_default, k.email FROM clients c JOIN brand_kits k ON k.id=c.brand_kit_id WHERE c.id=%s", (c,)).fetchone()
    assert row == ("ক্লায়েন্ট ১", "নোট", "ক্লায়েন্ট ১", "#112233", "QUJD", False, "a@b.co")
    assert app.execute("SELECT count(*) FROM brand_kits WHERE is_default").fetchone()[0] == 1
    assert app.execute("SELECT name FROM brand_kits WHERE is_default").fetchone()[0] == "mine"


def test_update_changes_name_and_brand_but_keeps_the_logo_unless_asked(app, world):
    r, c = save(app, world, logo="QUJD", set_logo=True)
    assert save(app, world, name="নতুন নাম", cid=c, create=False, primary="#aabbcc")[0] == "ok"
    ctx(app, world)
    assert app.execute("SELECT c.name, k.primary_color, k.logo_b64 FROM clients c JOIN brand_kits k ON k.id=c.brand_kit_id WHERE c.id=%s", (c,)).fetchone() == ("নতুন নাম", "#aabbcc", "QUJD")
    assert save(app, world, name="নতুন নাম", cid=c, create=False, set_logo=True, logo=None)[0] == "ok"
    ctx(app, world)
    assert app.execute("SELECT k.logo_b64 FROM clients c JOIN brand_kits k ON k.id=c.brand_kit_id WHERE c.id=%s", (c,)).fetchone()[0] is None
    assert save(app, world, cid=ulid(), create=False)[0] == "missing"


def test_names_are_unique_among_active_clients_and_limited_to_one_hundred(app, world, owner):
    assert save(app, world, name="Acme")[0] == "ok"
    assert save(app, world, name="  acme ")[0] == "exists"
    assert save(app, world, name="Acme", who="b")[0] == "ok"
    r, other = save(app, world, name="Beta")
    assert save(app, world, name="ACME", cid=other, create=False)[0] == "exists"
    as_admin(owner)
    owner.execute("INSERT INTO clients (id,tenant_id,name,created_by) SELECT left(md5(g::text),26), %s, 'bulk'||g, %s FROM generate_series(1,98) g", (world["a"]["tenant"], world["a"]["user"]))
    assert save(app, world, name="One too many")[0] == "limit"


def test_bad_input_is_refused(app, world):
    for kw in ({"name": "   "}, {"primary": "red"}, {"accent": "#GGGGGG"}, {"logo": "x" * 820001, "set_logo": True}):
        with pytest.raises(psycopg.errors.InvalidParameterValue):
            save(app, world, **kw)
        app.rollback()
    ctx(app, world)
    with pytest.raises(psycopg.errors.InvalidParameterValue):
        app.execute("SELECT client_save(%s,%s,%s,NULL,true,'x','#112233','#445566','','','','','',NULL,false)", (ulid(), ulid(), world["a"]["tenant"]))
    app.rollback()


def test_assign_release_and_cross_workspace_are_checked(app, world):
    r, c = save(app, world)
    m, _ = meta_acct(app, world)
    g, _ = g_acct(app, world)
    s = account(app, world, page="77000001")
    for kind, acc in (("meta", m), ("google", g), ("social", s)):
        assert assign(app, world, kind, acc, c) == "ok"
    ctx(app, world)
    assert [x[0] for x in (app.execute("SELECT client_id FROM ads_accounts").fetchone(), app.execute("SELECT client_id FROM gads_accounts").fetchone(), app.execute("SELECT client_id FROM social_accounts").fetchone())] == [c, c, c]
    assert assign(app, world, "meta", m, None) == "ok"
    assert assign(app, world, "meta", ulid(), c) == "account"
    assert assign(app, world, "meta", m, ulid()) == "client"
    rb, cb = save(app, world, name="B client", who="b")
    assert assign(app, world, "meta", m, cb) == "client"            # another workspace's client
    assert assign(app, world, "google", g, cb, who="b") == "account"  # another workspace's account
    with pytest.raises(psycopg.errors.InvalidParameterValue):
        assign(app, world, "tiktok", m, c)
    app.rollback()
    ctx(app, world)
    assert app.execute("SELECT client_id FROM gads_accounts").fetchone()[0] == c


def test_archiving_releases_accounts_and_frees_the_name(app, world):
    r, c = save(app, world, name="Gone")
    m, _ = meta_acct(app, world)
    assert assign(app, world, "meta", m, c) == "ok"
    ctx(app, world)
    assert app.execute("SELECT client_archive(%s,%s,%s)", (world["a"]["tenant"], world["a"]["user"], c)).fetchone()[0] == "ok"
    assert app.execute("SELECT client_id FROM ads_accounts").fetchone()[0] is None
    assert app.execute("SELECT status FROM clients WHERE id=%s", (c,)).fetchone()[0] == "archived"
    assert app.execute("SELECT client_archive(%s,%s,%s)", (world["a"]["tenant"], world["a"]["user"], c)).fetchone()[0] == "missing"
    assert assign(app, world, "meta", m, c) == "client"
    assert save(app, world, name="gone")[0] == "ok"
    assert save(app, world, cid=c, create=False)[0] == "missing"
    ctx(app, world, "b")
    assert app.execute("SELECT client_archive(%s,%s,%s)", (world["b"]["tenant"], world["b"]["user"], c)).fetchone()[0] == "missing"


def test_clients_are_private_and_the_app_role_cannot_write_directly(app, world):
    r, c = save(app, world)
    ctx(app, world, "b")
    assert app.execute("SELECT count(*) FROM clients").fetchone()[0] == 0
    ctx(app, world)
    for q in ("INSERT INTO clients (id,tenant_id,name,created_by) VALUES ('%s','%s','x','%s')" % (ulid(), world["a"]["tenant"], world["a"]["user"]), "UPDATE clients SET name='h'", "DELETE FROM clients", "UPDATE ads_accounts SET client_id=NULL"):
        with pytest.raises(psycopg.errors.InsufficientPrivilege):
            app.execute(q)
        app.rollback()
        ctx(app, world)
