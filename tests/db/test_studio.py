import hashlib

import psycopg
import pytest

from conftest import as_admin, ulid
from test_accounting import ctx
from test_memory import second_user  # noqa: F401  (fixture)

SAVE = "SELECT design_save(%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s::jsonb,%s,%s)"
BRAND = '{"primary":"#112233","accent":"#445566","name":"GV"}'


def h(token="tok"):
    return hashlib.sha256(token.encode()).hexdigest()


@pytest.fixture(autouse=True)
def clean(owner, world):
    yield
    as_admin(owner)
    owner.execute("DELETE FROM inbox_notifications WHERE kind = 'design_review'")
    owner.execute("DELETE FROM design_versions")
    owner.execute("DELETE FROM design_reviews")
    owner.execute("DELETE FROM designs")


def save(app, world, *, who="a", user=None, did=None, title="ঈদ অফার", brief="b", head="H", sub="S", cta="C", theme="dark", sizes=("post", "story"), brand=BRAND, logo=None, base=None):
    ctx(app, world, who)
    did = did or ulid()
    r = app.execute(SAVE, (did, world[who]["tenant"], user or world[who]["user"], title, brief, head, sub, cta, theme, list(sizes), brand, logo, base)).fetchone()[0]
    return did, r


def review(app, world, did, *, who="a", user=None, token="tok", client="রহিম", size="post", preview="UFJFVg==", days=7, rid=None):
    ctx(app, world, who)
    rid = rid or ulid()
    r = app.execute("SELECT review_create(%s,%s,%s,%s,%s,%s,%s,%s,%s)", (rid, world[who]["tenant"], user or world[who]["user"], did, h(token), client, size, preview, days)).fetchone()[0]
    return rid, r


def public(app):
    app.execute("SELECT set_config('app.tenant_id','',false)")
    return app


def test_save_normalises_the_title_and_keeps_the_record(app, world):
    did, r = save(app, world, title="  ঈদ   অফার ", base="QUJD", logo="TE9HTw==")
    assert r == "ok"
    row = app.execute("SELECT title, headline, sizes, brand->>'name', base_b64, logo_b64, theme FROM designs WHERE id=%s", (did,)).fetchone()
    assert row == ("ঈদ অফার", "H", ["post", "story"], "GV", "QUJD", "TE9HTw==", "dark")


@pytest.mark.parametrize("kw", [{"title": ""}, {"title": "  "}, {"title": None}, {"title": "ক" * 121}, {"brief": "x" * 1501}, {"head": "x" * 201}, {"sub": "x" * 401}, {"cta": "x" * 101},
                                {"theme": "neon"}, {"theme": None}, {"sizes": ()}, {"sizes": ("huge",)}, {"sizes": ("post",) * 6}, {"brand": "[]"}, {"brand": None}, {"logo": "x" * 900001}, {"base": "x" * 5600001}])
def test_save_bad_input(app, world, kw):
    assert save(app, world, **kw)[1] == "bad_input"
    assert app.execute("SELECT count(*) FROM designs").fetchone()[0] == 0


def test_save_exact_edges_and_limit(app, world):
    assert save(app, world, title="ক" * 120, brief="x" * 1500, head="x" * 200, sub="x" * 400, cta="x" * 100, sizes=("post", "portrait", "story", "cover", "ad"), logo="x" * 900000, base="x" * 5600000)[1] == "ok"
    for i in range(39):
        assert save(app, world, title=f"d{i}")[1] == "ok"
    assert save(app, world, title="one more")[1] == "limit"
    assert save(app, world, who="b")[1] == "ok"        # the limit is per person


def test_same_id_never_overwrites(app, world):
    did, _ = save(app, world, title="first")
    save(app, world, did=did, title="second")
    assert app.execute("SELECT title FROM designs WHERE id=%s", (did,)).fetchone()[0] == "first"


def test_person_must_be_given(app, world):
    ctx(app, world)
    for sql, args in ((SAVE, (ulid(), world["a"]["tenant"], None, "t", "", "", "", "", "dark", ["post"], BRAND, None, None)), ("SELECT design_delete(%s,%s,NULL)", (ulid(), world["a"]["tenant"])),
                      ("SELECT review_create(%s,%s,NULL,%s,%s,'',%s,%s,5)", (ulid(), world["a"]["tenant"], ulid(), h(), "post", "x")), ("SELECT review_revoke(%s,%s,NULL)", (ulid(), world["a"]["tenant"]))):
        with pytest.raises(psycopg.Error) as e:
            app.execute(sql, args)
        assert e.value.sqlstate == "22023"
        app.rollback()


def test_delete_only_the_owners_and_reviews_go_with_it(app, world):
    did, _ = save(app, world)
    review(app, world, did)
    me = world["a"]["user"]
    assert app.execute("SELECT design_delete(%s,%s,%s)", (did, world["a"]["tenant"], ulid())).fetchone()[0] == "not_found"
    assert app.execute("SELECT design_delete(%s,%s,%s)", (did, world["a"]["tenant"], me)).fetchone()[0] == "ok"
    assert app.execute("SELECT design_delete(%s,%s,%s)", (did, world["a"]["tenant"], me)).fetchone()[0] == "not_found"
    assert app.execute("SELECT count(*) FROM design_reviews").fetchone()[0] == 0


def test_other_workspaces_cannot_see_or_touch_designs(app, world):
    did, _ = save(app, world)
    rid, _ = review(app, world, did)
    ctx(app, world, "b")
    assert app.execute("SELECT count(*) FROM designs").fetchone()[0] == 0
    for sql, args in (("SELECT design_delete(%s,%s,%s)", (did, world["a"]["tenant"], world["a"]["user"])), ("SELECT review_revoke(%s,%s,%s)", (rid, world["a"]["tenant"], world["a"]["user"])),
                      ("SELECT review_create(%s,%s,%s,%s,%s,'','post','x',5)", (ulid(), world["a"]["tenant"], world["a"]["user"], did, h("z")))):
        with pytest.raises(psycopg.Error) as e:
            app.execute(sql, args)
        assert e.value.sqlstate == "42501"
        app.rollback()


def test_app_role_cannot_write_the_tables_directly(app, world):
    ctx(app, world)
    with pytest.raises(psycopg.Error):
        app.execute("INSERT INTO designs (id,tenant_id,user_id,title,sizes,brand) VALUES (%s,%s,%s,'t','{post}','{}')", (ulid(), world["a"]["tenant"], world["a"]["user"]))
    app.rollback()


# ───────────────────────────────────────────────────────────── client approval links
def test_create_review_and_the_public_sees_only_what_it_should(app, world):
    did, _ = save(app, world, base="QUJD")
    rid, r = review(app, world, did, preview="UFJFVklFVw==")
    assert r == "ok"
    v = public(app).execute("SELECT review_public_view(%s)", (h(),)).fetchone()[0]
    assert v["title"] == "ঈদ অফার" and v["brand_name"] == "GV" and v["client_name"] == "রহিম" and v["size"] == "post"
    assert v["preview_b64"] == "UFJFVklFVw==" and v["status"] == "pending" and v["comment"] is None
    assert set(v) == {"title", "brand_name", "client_name", "size", "preview_b64", "status", "comment", "decided_at", "expires_at"}   # no ids, no tenant, no picture source
    assert app.execute("SELECT review_public_view(%s)", (h("other"),)).fetchone()[0] is None
    assert app.execute("SELECT review_public_view(%s)", ("not-a-hash",)).fetchone()[0] is None
    assert app.execute("SELECT review_public_view(NULL)").fetchone()[0] is None


def test_the_stored_token_is_only_a_hash(app, world, owner):
    did, _ = save(app, world)
    review(app, world, did, token="the-real-token")
    as_admin(owner)
    stored = owner.execute("SELECT token_hash FROM design_reviews").fetchone()[0]
    assert stored == h("the-real-token") and "the-real-token" not in stored


@pytest.mark.parametrize("kw", [{"size": "huge"}, {"size": None}, {"preview": ""}, {"preview": None}, {"preview": "x" * 5600001}, {"days": 0}, {"days": 31}, {"days": None}, {"client": "x" * 81}])
def test_review_bad_input(app, world, kw):
    did, _ = save(app, world)
    assert review(app, world, did, **kw)[1] == "bad_input"


def test_review_needs_my_design_and_a_size_it_has(app, world):
    did, _ = save(app, world, sizes=("post",))
    assert review(app, world, did, size="story")[1] == "not_found"           # the design has no such size
    assert review(app, world, ulid())[1] == "not_found"
    assert review(app, world, did, user=ulid())[1] == "not_found"            # somebody else's design
    assert review(app, world, did, days=30, size="post")[1] == "ok"


def test_ten_live_links_per_design_and_duplicate_tokens(app, world):
    did, _ = save(app, world)
    for i in range(10):
        assert review(app, world, did, token=f"t{i}")[1] == "ok"
    assert review(app, world, did, token="t11")[1] == "limit"
    other, _ = save(app, world)
    assert review(app, world, other, token="t0")[1] == "duplicate"            # a token hash is globally unique
    assert review(app, world, other, token="fresh")[1] == "ok"


def test_decide_once_with_a_comment(app, world):
    did, _ = save(app, world)
    review(app, world, did)
    p = public(app)
    assert p.execute("SELECT review_public_decide(%s,'changes','')", (h(),)).fetchone()[0] == "bad_input"      # changes need words
    assert p.execute("SELECT review_public_decide(%s,'maybe','x')", (h(),)).fetchone()[0] == "bad_input"
    assert p.execute("SELECT review_public_decide(%s,'approved',%s)", (h(), "x" * 1001)).fetchone()[0] == "bad_input"
    assert p.execute("SELECT review_public_decide(%s,'changes',%s)", (h(), "  লোগো বড় করুন  ")).fetchone()[0] == "ok"
    v = p.execute("SELECT review_public_view(%s)", (h(),)).fetchone()[0]
    assert v["status"] == "changes" and v["comment"] == "লোগো বড় করুন" and v["decided_at"] is not None
    assert p.execute("SELECT review_public_decide(%s,'approved','')", (h(),)).fetchone()[0] == "decided"       # final
    assert p.execute("SELECT review_public_decide(%s,'approved','')", (h("nope"),)).fetchone()[0] == "not_found"
    assert p.execute("SELECT review_public_decide('zz','approved','')").fetchone()[0] == "not_found"


def test_approve_without_a_comment(app, world):
    did, _ = save(app, world)
    review(app, world, did)
    assert public(app).execute("SELECT review_public_decide(%s,'approved',NULL)", (h(),)).fetchone()[0] == "ok"
    assert app.execute("SELECT review_public_view(%s)", (h(),)).fetchone()[0]["status"] == "approved"


def test_expired_and_revoked_links_cannot_be_answered(app, world, owner):
    did, _ = save(app, world)
    rid, _ = review(app, world, did)
    as_admin(owner)
    owner.execute("UPDATE design_reviews SET expires_at = now() - interval '1 minute' WHERE id=%s", (rid,))
    p = public(app)
    assert p.execute("SELECT review_public_view(%s)", (h(),)).fetchone()[0]["status"] == "expired"
    assert p.execute("SELECT review_public_decide(%s,'approved','')", (h(),)).fetchone()[0] == "expired"
    rid2, _ = review(app, world, did, token="t2")
    ctx(app, world)
    assert app.execute("SELECT review_revoke(%s,%s,%s)", (rid2, world["a"]["tenant"], world["a"]["user"])).fetchone()[0] == "ok"
    assert app.execute("SELECT review_revoke(%s,%s,%s)", (rid2, world["a"]["tenant"], world["a"]["user"])).fetchone()[0] == "not_found"     # already revoked
    p = public(app)
    assert p.execute("SELECT review_public_decide(%s,'approved','')", (h("t2"),)).fetchone()[0] == "revoked"


def test_a_decided_link_cannot_be_revoked_and_expired_ones_do_not_count_against_the_limit(app, world, owner):
    did, _ = save(app, world)
    rid, _ = review(app, world, did)
    public(app).execute("SELECT review_public_decide(%s,'approved','')", (h(),))
    ctx(app, world)
    assert app.execute("SELECT review_revoke(%s,%s,%s)", (rid, world["a"]["tenant"], world["a"]["user"])).fetchone()[0] == "not_found"
    for i in range(9):
        review(app, world, did, token=f"x{i}")
    assert review(app, world, did, token="x9")[1] == "ok"      # the approved one is not "live"
    assert review(app, world, did, token="x10")[1] == "limit"


def test_the_public_functions_work_without_any_tenant_but_nothing_else_does(app, world):
    did, _ = save(app, world)
    review(app, world, did)
    p = public(app)
    assert p.execute("SELECT count(*) FROM design_reviews").fetchone()[0] == 0      # a visitor cannot read the table
    assert p.execute("SELECT review_public_view(%s)", (h(),)).fetchone()[0] is not None


# ---- editing with versions (phase 22c) ----

def update(app, world, did, *, who="a", user=None, title="ঈদ অফার", head="H", sub="S", cta="C", theme="dark", sizes=("post", "story")):
    ctx(app, world, who)
    return app.execute("SELECT design_update(%s,%s,%s,%s,%s,%s,%s,%s,%s)", (did, world[who]["tenant"], user or world[who]["user"], title, head, sub, cta, theme, list(sizes))).fetchone()[0]


def restore(app, world, did, n, *, who="a", user=None):
    ctx(app, world, who)
    return app.execute("SELECT design_restore(%s,%s,%s,%s)", (did, world[who]["tenant"], user or world[who]["user"], n)).fetchone()[0]


def now_row(app, world, did, who="a"):
    ctx(app, world, who)
    return app.execute("SELECT title, headline, subline, cta, theme, sizes, logo_b64, base_b64 FROM designs WHERE id=%s", (did,)).fetchone()


def versions(app, world, did, who="a"):
    ctx(app, world, who)
    return app.execute("SELECT n, title, headline, subline, cta, theme, sizes FROM design_versions WHERE design_id=%s ORDER BY n", (did,)).fetchall()


def test_update_saves_the_old_words_as_a_version_and_leaves_pictures_alone(app, world):
    did, _ = save(app, world, logo="TE9HTw==", base="QkFTRQ==")
    assert update(app, world, did, title="  নতুন   নাম ", head="নতুন হেডলাইন", sub="", cta="কিনুন", theme="light", sizes=("cover", "ad", "cover")) == "ok"
    t, hd, sb, ct, th, sz, logo, base = now_row(app, world, did)
    assert (t, hd, sb, ct, th, sorted(sz), logo, base) == ("নতুন নাম", "নতুন হেডলাইন", "", "কিনুন", "light", ["ad", "cover"], "TE9HTw==", "QkFTRQ==")
    assert versions(app, world, did) == [(1, "ঈদ অফার", "H", "S", "C", "dark", ["post", "story"])]


def test_update_with_nothing_changed_makes_no_version(app, world):
    did, _ = save(app, world, sizes=("post", "story"))
    assert update(app, world, did) == "unchanged"
    assert update(app, world, did, title="  ঈদ   অফার ", sizes=("story", "post", "story")) == "unchanged"     # spacing and order do not count
    assert versions(app, world, did) == []
    assert update(app, world, did, cta="D") == "ok"
    assert len(versions(app, world, did)) == 1


def test_only_the_newest_ten_versions_are_kept(app, world):
    did, _ = save(app, world)
    for i in range(12):
        assert update(app, world, did, head=f"H{i}") == "ok"
    v = versions(app, world, did)
    assert [x[0] for x in v] == list(range(3, 13))
    assert v[0][2] == "H1" and v[-1][2] == "H10"


@pytest.mark.parametrize("kw", [{"title": ""}, {"title": "  "}, {"title": None}, {"title": "ক" * 121}, {"head": "ক" * 201}, {"sub": "ক" * 401}, {"cta": "ক" * 101}, {"theme": "neon"}, {"theme": None},
                                {"sizes": ()}, {"sizes": ("post", "tiny")}, {"sizes": ("post",) * 6 + ("story",)}])
def test_update_validates(app, world, kw):
    did, _ = save(app, world)
    assert update(app, world, did, **kw) == "bad_input"
    assert versions(app, world, did) == []
    assert now_row(app, world, did)[:5] == ("ঈদ অফার", "H", "S", "C", "dark")


def test_update_exact_limits(app, world):
    did, _ = save(app, world)
    assert update(app, world, did, title="ক" * 120, head="খ" * 200, sub="গ" * 400, cta="ঘ" * 100, sizes=("post", "portrait", "story", "cover", "ad")) == "ok"


def test_update_is_for_the_owner_only(app, world, second_user):
    did, _ = save(app, world)
    assert update(app, world, did, head="X", user=second_user) == "not_found"
    assert update(app, world, ulid(), head="X") == "not_found"
    assert now_row(app, world, did)[1] == "H"
    ctx(app, world, "b")
    with pytest.raises(psycopg.Error) as e:
        app.execute("SELECT design_update(%s,%s,%s,'t','h','s','c','dark',ARRAY['post'])", (did, world["a"]["tenant"], world["a"]["user"]))
    assert e.value.sqlstate == "42501"
    app.rollback()
    ctx(app, world, "a")
    with pytest.raises(psycopg.Error):
        app.execute("SELECT design_update(%s,%s,NULL,'t','h','s','c','dark',ARRAY['post'])", (did, world["a"]["tenant"]))
    app.rollback()


def test_restore_brings_an_old_version_back_and_can_itself_be_undone(app, world):
    did, _ = save(app, world, head="এক")
    update(app, world, did, head="দুই")
    update(app, world, did, head="তিন")
    assert [v[2] for v in versions(app, world, did)] == ["এক", "দুই"]
    assert restore(app, world, did, 1) == "ok"
    assert now_row(app, world, did)[1] == "এক"
    assert [v[2] for v in versions(app, world, did)] == ["এক", "দুই", "তিন"]      # the words it replaced are kept
    assert restore(app, world, did, 3) == "ok"
    assert now_row(app, world, did)[1] == "তিন"


def test_restore_rules(app, world, second_user):
    did, _ = save(app, world)
    update(app, world, did, head="X")
    assert restore(app, world, did, 99) == "not_found"
    assert restore(app, world, did, 1, user=second_user) == "not_found"
    assert restore(app, world, ulid(), 1) == "not_found"
    assert now_row(app, world, did)[1] == "X"
    assert len(versions(app, world, did)) == 1
    ctx(app, world, "b")
    with pytest.raises(psycopg.Error) as e:
        app.execute("SELECT design_restore(%s,%s,%s,1)", (did, world["a"]["tenant"], world["a"]["user"]))
    assert e.value.sqlstate == "42501"
    app.rollback()


def test_versions_are_private_and_follow_the_design(app, world, owner):
    did, _ = save(app, world)
    update(app, world, did, head="X")
    assert versions(app, world, did, "b") == []
    ctx(app, world, "a")
    with pytest.raises(psycopg.Error):
        app.execute("INSERT INTO design_versions (design_id, tenant_id, user_id, n, title, headline, subline, cta, theme, sizes) VALUES (%s,%s,%s,9,'t','','','','dark',ARRAY['post'])", (did, world["a"]["tenant"], world["a"]["user"]))
    app.rollback()
    ctx(app, world, "a")
    with pytest.raises(psycopg.Error):
        app.execute("SELECT design_snapshot(%s,%s)", (did, world["a"]["tenant"]))                 # the helper is not callable by the app role
    app.rollback()
    ctx(app, world, "a")
    app.execute("SELECT design_delete(%s,%s,%s)", (did, world["a"]["tenant"], world["a"]["user"]))
    as_admin(owner)
    assert owner.execute("SELECT count(*) FROM design_versions").fetchone()[0] == 0                # deleted with the design


def test_editing_does_not_touch_a_client_link_already_sent(app, world):
    did, _ = save(app, world)
    rid, r = review(app, world, did, preview="T0xEUElDVFVSRQ==")
    assert r == "ok"
    update(app, world, did, head="অন্য")
    app.execute("SELECT set_config('app.tenant_id','',false)")
    j = app.execute("SELECT review_public_view(%s)", (h(),)).fetchone()[0]
    assert j["preview_b64"] == "T0xEUElDVFVSRQ=="


def _notes(owner):
    as_admin(owner)
    return owner.execute("SELECT user_id, tenant_id, title, body, ref_kind, ref_id FROM inbox_notifications WHERE kind='design_review'").fetchall()


def test_the_designer_is_told_once_when_a_client_answers(app, world, owner):
    did, _ = save(app, world, title="ঈদ পোস্ট")
    review(app, world, did, client="রহিম")
    assert public(app).execute("SELECT review_public_decide(%s,'changes','লোগো বড় করুন')", (h(),)).fetchone()[0] == "ok"
    rows = _notes(owner)
    assert len(rows) == 1
    uid, tid, title, body, rk, rid = rows[0]
    assert (uid.strip(), tid.strip()) == (world["a"]["user"], world["a"]["tenant"])
    assert "ঈদ পোস্ট" in title and "পরিবর্তন" in title
    assert "রহিম" in body and "লোগো বড় করুন" in body
    assert rk == "design" and rid.strip() == did
    assert public(app).execute("SELECT review_public_decide(%s,'approved','')", (h(),)).fetchone()[0] == "decided"      # a replay adds nothing
    assert len(_notes(owner)) == 1


def test_an_approval_says_approved_and_no_note_is_made_for_refused_answers(app, world, owner):
    did, _ = save(app, world)
    review(app, world, did, token="t1")
    p = public(app)
    assert p.execute("SELECT review_public_decide(%s,'changes','')", (h("t1"),)).fetchone()[0] == "bad_input"
    assert p.execute("SELECT review_public_decide(%s,'approved','')", (h("zzz"),)).fetchone()[0] == "not_found"
    assert _notes(owner) == []
    assert p.execute("SELECT review_public_decide(%s,'approved','')", (h("t1"),)).fetchone()[0] == "ok"
    rows = _notes(owner)
    assert len(rows) == 1 and "অনুমোদিত" in rows[0][2] and "কোনো মন্তব্য নেই" in rows[0][3]
