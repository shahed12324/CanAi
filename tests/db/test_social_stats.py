import itertools

import psycopg
import pytest

from conftest import as_admin, ulid
from test_accounting import ctx
from test_social_publish import account, approved, claim

_page = itertools.count(40000001)


@pytest.fixture(autouse=True)
def clean(owner, world):
    yield
    as_admin(owner)
    owner.execute("DELETE FROM social_post_stats")
    owner.execute("DELETE FROM social_posts")
    owner.execute("DELETE FROM social_accounts")


def posted(app, world, admin, owner, *, age="1 hour", remote="123_456", acc=None, who="a"):
    a = acc or account(app, world, who=who, page=str(next(_page)))
    p = approved(app, world, a, who=who)
    claim(admin)
    assert admin.execute("SELECT social_publish_done(%s,%s,%s,'https://www.facebook.com/x')", (p, world[who]["tenant"], remote)).fetchone()[0] == "ok"
    as_admin(owner)
    owner.execute("UPDATE social_posts SET posted_at = now() - %s::interval WHERE id=%s", (age, p))
    return p, a


def due(admin, n=10):
    as_admin(admin)
    return [r[0] for r in admin.execute("SELECT post_id FROM social_stats_due(%s)", (n,)).fetchall()]


def save(admin, p, world, r=5, c=2, s=1, reach=100, who="a"):
    as_admin(admin)
    return admin.execute("SELECT social_stats_save(%s,%s,%s,%s,%s,%s)", (p, world[who]["tenant"], r, c, s, reach)).fetchone()[0]


def test_a_new_post_waits_ten_minutes_then_is_due_once(app, world, admin, owner):
    p, _ = posted(app, world, admin, owner, age="5 minutes")
    assert due(admin) == []
    as_admin(owner)
    owner.execute("UPDATE social_posts SET posted_at = now() - interval '11 minutes' WHERE id=%s", (p,))
    assert due(admin) == [p]
    assert save(admin, p, world) == "ok"
    assert due(admin) == []                                                        # looked at just now


def test_fresh_posts_are_rechecked_every_six_hours_older_ones_daily(app, world, admin, owner):
    p, _ = posted(app, world, admin, owner, age="2 days")
    save(admin, p, world)
    as_admin(owner)
    owner.execute("UPDATE social_post_stats SET taken_at = now() - interval '7 hours' WHERE post_id=%s", (p,))
    assert due(admin) == [p]
    owner.execute("UPDATE social_post_stats SET taken_at = now() - interval '5 hours' WHERE post_id=%s", (p,))
    assert due(admin) == []
    owner.execute("UPDATE social_posts SET posted_at = now() - interval '20 days' WHERE id=%s", (p,))
    owner.execute("UPDATE social_post_stats SET taken_at = now() - interval '7 hours' WHERE post_id=%s", (p,))
    assert due(admin) == []                                                        # older than 14 days: daily
    owner.execute("UPDATE social_post_stats SET taken_at = now() - interval '25 hours' WHERE post_id=%s", (p,))
    assert due(admin) == [p]
    owner.execute("UPDATE social_posts SET posted_at = now() - interval '61 days' WHERE id=%s", (p,))
    assert due(admin) == []                                                        # after 60 days we stop


def test_only_posted_system_posts_with_an_active_page_are_due(app, world, admin, owner):
    p, a = posted(app, world, admin, owner)
    manual = approved(app, world, None)
    app.execute("SELECT social_post_mark_posted(%s,%s,%s,NULL)", (manual, world["a"]["tenant"], world["a"]["user"]))   # posted by hand: no remote id
    as_admin(owner)
    owner.execute("UPDATE social_posts SET posted_at = now() - interval '2 hours' WHERE id=%s", (manual,))
    assert due(admin) == [p]
    as_admin(admin)
    admin.execute("SELECT social_account_mark(%s,'invalid')", (a,))
    assert due(admin) == []


def test_failed_looks_space_out_retries_and_are_never_shown(app, world, admin, owner):
    p, _ = posted(app, world, admin, owner)
    assert save(admin, p, world, r=None, c=None, s=None, reach=None) == "ok"
    assert due(admin) == []
    ctx(app, world)
    assert app.execute("SELECT count(*) FROM social_post_latest_stats").fetchone()[0] == 0


def test_optional_numbers_and_validation(app, world, admin, owner):
    p, _ = posted(app, world, admin, owner)
    assert save(admin, p, world, r=3, c=0, s=None, reach=None) == "ok"
    assert save(admin, p, world, r=-1) == "bad_input"
    assert save(admin, p, world, r=None, c=1, s=None, reach=None) == "bad_input"     # numbers without reactions
    assert save(admin, ulid(), world) == "not_found"
    assert save(admin, p, world, who="b") == "not_found"                              # wrong workspace
    ctx(app, world)
    assert app.execute("SELECT reactions, comments, shares, reach FROM social_post_latest_stats WHERE post_id=%s", (p,)).fetchone() == (3, 0, None, None)


def test_the_app_sees_the_newest_real_snapshot_of_its_own_workspace_only(app, world, admin, owner):
    p, _ = posted(app, world, admin, owner)
    save(admin, p, world, r=5)
    as_admin(owner)
    owner.execute("UPDATE social_post_stats SET taken_at = now() - interval '3 hours' WHERE post_id=%s", (p,))
    save(admin, p, world, r=9)
    save(admin, p, world, r=None, c=None, s=None, reach=None)                          # a later failed look does not hide the real one
    ctx(app, world)
    assert app.execute("SELECT reactions FROM social_post_latest_stats WHERE post_id=%s", (p,)).fetchone() == (9,)
    ctx(app, world, "b")
    assert app.execute("SELECT count(*) FROM social_post_stats").fetchone()[0] == 0
    assert app.execute("SELECT count(*) FROM social_post_latest_stats").fetchone()[0] == 0


def test_only_sixty_snapshots_are_kept(app, world, admin, owner):
    p, _ = posted(app, world, admin, owner)
    as_admin(owner)
    owner.execute("INSERT INTO social_post_stats (post_id, tenant_id, taken_at, reactions) SELECT %s, %s, now() - (g || ' minutes')::interval, g FROM generate_series(10, 80) g", (p, world["a"]["tenant"]))
    assert save(admin, p, world, r=1) == "ok"
    as_admin(owner)
    assert owner.execute("SELECT count(*) FROM social_post_stats WHERE post_id=%s", (p,)).fetchone()[0] == 60


def test_deleting_the_post_deletes_its_numbers(app, world, admin, owner):
    p, _ = posted(app, world, admin, owner)
    save(admin, p, world)
    ctx(app, world)
    assert app.execute("SELECT social_post_delete(%s,%s,%s)", (p, world["a"]["tenant"], world["a"]["user"])).fetchone()[0] == "ok"
    as_admin(owner)
    assert owner.execute("SELECT count(*) FROM social_post_stats").fetchone()[0] == 0


def test_the_app_role_cannot_write_or_run_worker_functions(app, world, admin, owner):
    p, _ = posted(app, world, admin, owner)
    ctx(app, world)
    for q in ("INSERT INTO social_post_stats (post_id, tenant_id, reactions) VALUES ('%s','%s',1)" % (p, world["a"]["tenant"]), "SELECT * FROM social_stats_due(1)",
              "SELECT social_stats_save('%s','%s',1,1,1,1)" % (p, world["a"]["tenant"])):
        with pytest.raises(psycopg.errors.InsufficientPrivilege):
            app.execute(q)
        app.rollback()
        ctx(app, world)
