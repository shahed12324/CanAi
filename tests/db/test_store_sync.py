import psycopg
import pytest

from conftest import as_admin, set_tenant, ulid


@pytest.fixture(autouse=True)
def clean(owner, world):
    yield
    as_admin(owner)
    for t in ("store_sync_runs", "stock_movements", "product_events", "product_variants", "products", "store_connections"):
        owner.execute(f"ALTER TABLE {t} DISABLE TRIGGER USER")
        owner.execute(f"DELETE FROM {t}")
        owner.execute(f"ALTER TABLE {t} ENABLE TRIGGER USER")


def woo(app, w, who="a"):
    i = ulid()
    app.execute("INSERT INTO store_connections (id,tenant_id,platform,name,base_url,credentials_ct,key_version,currency) VALUES (%s,%s,'woocommerce','Shop','https://shop.example.com','CIPHER',1,'BDT')",
                (i, w[who]["tenant"]))
    return i


def test_secret_columns_are_not_readable_by_the_app_only_through_the_function(app, world):
    set_tenant(app, world["a"]["tenant"])
    s = woo(app, world)
    for col in ("credentials_ct", "key_version"):
        with pytest.raises(psycopg.errors.InsufficientPrivilege):
            app.execute(f"SELECT {col} FROM store_connections")
        app.rollback()
        set_tenant(app, world["a"]["tenant"])
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("SELECT * FROM store_connections")
    app.rollback()
    set_tenant(app, world["a"]["tenant"])
    assert app.execute("SELECT credentials_ct, key_version FROM store_credentials(%s)", (s,)).fetchone() == ("CIPHER", 1)
    app.execute("UPDATE store_connections SET credentials_ct='NEW', key_version=2 WHERE id=%s", (s,))     # writing is allowed (reconnect)
    assert app.execute("SELECT credentials_ct FROM store_credentials(%s)", (s,)).fetchone()[0] == "NEW"


def test_another_workspace_cannot_read_the_secret(app, world):
    set_tenant(app, world["b"]["tenant"])
    sb = woo(app, world, "b")
    set_tenant(app, world["a"]["tenant"])
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("SELECT * FROM store_credentials(%s)", (sb,))
    app.rollback()
    set_tenant(app, world["a"]["tenant"])
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("SELECT store_sync_reap(%s)", (sb,))


def test_connection_shape_and_currency(app, world):
    set_tenant(app, world["a"]["tenant"])
    with pytest.raises(psycopg.errors.CheckViolation):                       # an external store needs url + secret
        app.execute("INSERT INTO store_connections (id,tenant_id,platform,name) VALUES (%s,%s,'shopify','x')", (ulid(), world["a"]["tenant"]))
    app.rollback()
    set_tenant(app, world["a"]["tenant"])
    with pytest.raises(psycopg.errors.CheckViolation):
        app.execute("INSERT INTO store_connections (id,tenant_id,platform,name,base_url,credentials_ct,key_version,currency) VALUES (%s,%s,'shopify','x','https://a.myshopify.com','c',1,'tk')",
                    (ulid(), world["a"]["tenant"]))


def test_only_one_running_sync_per_store_and_stale_runs_are_reaped(app, world):
    t = world["a"]["tenant"]
    set_tenant(app, t)
    s = woo(app, world)
    r1 = ulid()
    app.execute("INSERT INTO store_sync_runs (id,tenant_id,store_id,trigger) VALUES (%s,%s,%s,'manual')", (r1, t, s))
    with pytest.raises(psycopg.errors.UniqueViolation):
        app.execute("INSERT INTO store_sync_runs (id,tenant_id,store_id,trigger) VALUES (%s,%s,%s,'schedule')", (ulid(), t, s))
    app.rollback()
    set_tenant(app, t)
    assert app.execute("SELECT store_sync_reap(%s)", (s,)).fetchone()[0] == 0                 # fresh run is left alone
    app.execute("UPDATE store_sync_runs SET status='ok', finished_at=now() WHERE id=%s", (r1,))
    app.execute("INSERT INTO store_sync_runs (id,tenant_id,store_id,trigger) VALUES (%s,%s,%s,'schedule')", (ulid(), t, s))              # free again


def test_stale_run_is_reaped(app, world, owner):
    t = world["a"]["tenant"]
    set_tenant(app, t)
    s = woo(app, world)
    r = ulid()
    app.execute("INSERT INTO store_sync_runs (id,tenant_id,store_id,trigger) VALUES (%s,%s,%s,'manual')", (r, t, s))
    app.commit()
    as_admin(owner)
    owner.execute("UPDATE store_sync_runs SET started_at = now() - interval '20 minutes' WHERE id=%s", (r,))
    set_tenant(app, t)
    assert app.execute("SELECT store_sync_reap(%s)", (s,)).fetchone()[0] == 1
    assert app.execute("SELECT status, note FROM store_sync_runs WHERE id=%s", (r,)).fetchone() == ("failed", "interrupted")


def test_sync_runs_are_isolated_and_remote_columns_writable(app, world):
    ta, tb = world["a"]["tenant"], world["b"]["tenant"]
    set_tenant(app, tb)
    sb = woo(app, world, "b")
    app.execute("INSERT INTO store_sync_runs (id,tenant_id,store_id,trigger) VALUES (%s,%s,%s,'manual')", (ulid(), tb, sb))
    set_tenant(app, ta)
    assert app.execute("SELECT count(*) FROM store_sync_runs").fetchone()[0] == 0
    sa = woo(app, world)
    p, v = ulid(), ulid()
    app.execute("INSERT INTO products (id,tenant_id,store_id,title,external_id) VALUES (%s,%s,%s,'t','EXT1')", (p, ta, sa))
    app.execute("INSERT INTO product_variants (id,tenant_id,product_id,external_id) VALUES (%s,%s,%s,'V1')", (v, ta, p))
    app.execute("UPDATE products SET remote_hash='h', remote_synced_at=now() WHERE id=%s", (p,))
    app.execute("UPDATE product_variants SET remote_stock=4 WHERE id=%s", (v,))
    assert app.execute("SELECT status FROM products WHERE id=%s", (p,)).fetchone()[0] == "draft"          # bookkeeping never moves the workflow
    with pytest.raises(psycopg.errors.UniqueViolation):                                                    # one remote id, one local product
        app.execute("INSERT INTO products (id,tenant_id,store_id,title,external_id) VALUES (%s,%s,%s,'t2','EXT1')", (ulid(), ta, sa))
