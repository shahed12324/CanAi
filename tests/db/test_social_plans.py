import psycopg
import pytest

from conftest import as_admin, ulid
from test_accounting import ctx
from test_social import post

START = "SELECT social_plan_start(%s,%s,%s,%s,%s,%s)"


@pytest.fixture(autouse=True)
def clean(owner, world):
    yield
    as_admin(owner)
    owner.execute("DELETE FROM social_plans")
    owner.execute("DELETE FROM social_posts")


def start(app, world, who="a", user=None, brief="ঈদ অফার", days=7, platforms=("facebook",), pid=None):
    ctx(app, world, who)
    return app.execute(START, (pid or ulid(), world[who]["tenant"], user or world[who]["user"], brief, days, list(platforms))).fetchone()[0]


def test_a_request_is_logged_and_finished_once(app, world):
    pid = ulid()
    assert start(app, world, pid=pid, brief="  ঈদ অফার  ") == "ok"
    t, u = world["a"]["tenant"], world["a"]["user"]
    assert app.execute("SELECT brief, days, platforms, outcome, items FROM social_plans WHERE id=%s", (pid,)).fetchone() == ("ঈদ অফার", 7, ["facebook"], "started", None)
    assert app.execute("SELECT social_plan_finish(%s,%s,%s,true,99)", (pid, t, u)).fetchone()[0] == "ok"
    assert app.execute("SELECT outcome, items FROM social_plans WHERE id=%s", (pid,)).fetchone() == ("done", 14)
    assert app.execute("SELECT social_plan_finish(%s,%s,%s,false,0)", (pid, t, u)).fetchone()[0] == "not_found"       # already closed
    p2 = ulid()
    start(app, world, pid=p2)
    assert app.execute("SELECT social_plan_finish(%s,%s,%s,false,5)", (p2, t, ulid())).fetchone()[0] == "not_found"     # not the requester
    assert app.execute("SELECT social_plan_finish(%s,%s,%s,false,5)", (p2, t, u)).fetchone()[0] == "ok"
    assert app.execute("SELECT outcome, items FROM social_plans WHERE id=%s", (p2,)).fetchone() == ("failed", None)


@pytest.mark.parametrize("kw", [{"brief": ""}, {"brief": "   "}, {"brief": None}, {"brief": "ক" * 1501}, {"days": 0}, {"days": 15}, {"days": None}, {"platforms": ()}, {"platforms": ("myspace",)},
                                {"platforms": ("facebook", "instagram", "youtube", "tiktok", "linkedin", "other", "facebook")}])
def test_bad_requests(app, world, kw):
    assert start(app, world, **kw) == "bad_input"
    assert app.execute("SELECT count(*) FROM social_plans").fetchone()[0] == 0


def test_exact_edges_are_accepted(app, world):
    assert start(app, world, brief="ক" * 1500, days=14, platforms=("facebook", "instagram", "youtube", "tiktok", "linkedin", "other")) == "ok"
    assert start(app, world, days=1) == "ok"


def test_ten_a_day_per_person_thirty_per_workspace(app, world, owner):
    for _ in range(10):
        assert start(app, world) == "ok"
    assert start(app, world) == "person_cap"
    assert start(app, world, who="b") == "ok"                                         # the other workspace is not affected
    as_admin(owner)
    other = ulid()
    owner.execute("INSERT INTO users (id,name,email,password,email_verified_at) VALUES (%s,'x',%s,'x',now())", (other, f"{other}@example.com"))
    owner.execute("INSERT INTO tenant_users (id,tenant_id,user_id,joined_at) VALUES (%s,%s,%s,now())", (ulid(), world["a"]["tenant"], other))
    for _ in range(10):
        assert start(app, world, user=other) == "ok"
    third = ulid()
    owner.execute("INSERT INTO users (id,name,email,password,email_verified_at) VALUES (%s,'y',%s,'x',now())", (third, f"{third}@example.com"))
    owner.execute("INSERT INTO tenant_users (id,tenant_id,user_id,joined_at) VALUES (%s,%s,%s,now())", (ulid(), world["a"]["tenant"], third))
    for _ in range(10):
        assert start(app, world, user=third) == "ok"
    fourth = ulid()
    owner.execute("INSERT INTO users (id,name,email,password,email_verified_at) VALUES (%s,'z',%s,'x',now())", (fourth, f"{fourth}@example.com"))
    assert start(app, world, user=fourth) == "workspace_cap"


def test_old_requests_do_not_count(app, world, owner):
    for _ in range(10):
        start(app, world)
    assert start(app, world) == "person_cap"
    as_admin(owner)
    owner.execute("UPDATE social_plans SET created_at = now() - interval '25 hours'")
    assert start(app, world) == "ok"


def test_plans_are_private_to_the_workspace_and_foreign_ids_are_refused(app, world):
    start(app, world)
    ctx(app, world, "b")
    assert app.execute("SELECT count(*) FROM social_plans").fetchone()[0] == 0
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute(START, (ulid(), world["a"]["tenant"], world["a"]["user"], "x", 3, ["facebook"]))
    app.rollback()
    ctx(app, world)
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("INSERT INTO social_plans (id,tenant_id,user_id,brief,days,platforms) VALUES (%s,%s,%s,'x',1,'{facebook}')", (ulid(), world["a"]["tenant"], world["a"]["user"]))


def test_note_only_on_the_authors_open_post(app, world):
    pid, _ = post(app, world)
    t, u = world["a"]["tenant"], world["a"]["user"]
    N = "SELECT social_post_set_note(%s,%s,%s,%s)"
    assert app.execute(N, (pid, t, u, "  একটি ছবি: কাজুবাদাম  ")).fetchone()[0] == "ok"
    assert app.execute("SELECT agent_note FROM social_posts WHERE id=%s", (pid,)).fetchone()[0] == "একটি ছবি: কাজুবাদাম"
    assert app.execute(N, (pid, t, u, "ক" * 500)).fetchone()[0] == "ok"
    assert app.execute(N, (pid, t, u, "ক" * 501)).fetchone()[0] == "bad_input"
    assert app.execute(N, (pid, t, ulid(), "x")).fetchone()[0] == "not_found"
    assert app.execute(N, (ulid(), t, u, "x")).fetchone()[0] == "not_found"
    assert app.execute(N, (pid, t, u, "  ")).fetchone()[0] == "ok"
    assert app.execute("SELECT agent_note FROM social_posts WHERE id=%s", (pid,)).fetchone()[0] is None
    app.execute("SELECT social_post_cancel(%s,%s,%s)", (pid, t, u))
    assert app.execute(N, (pid, t, u, "x")).fetchone()[0] == "locked"
