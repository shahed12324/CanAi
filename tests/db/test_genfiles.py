import json

import psycopg
import pytest
from psycopg.types.json import Jsonb

from conftest import as_admin, ulid
from test_accounting import ctx
from test_memory import second_user  # noqa: F401  (fixture)

SPEC = {"title": "রিপোর্ট", "blocks": [{"t": "p", "text": "x"}]}


@pytest.fixture(autouse=True)
def clean(owner, world):
    yield
    as_admin(owner)
    owner.execute("DELETE FROM generated_files")


def save(app, world, *, kind="docx", title="রিপোর্ট", request="একটি রিপোর্ট", spec=SPEC, who="a", user=None, fid=None):
    ctx(app, world, who)
    fid = fid or ulid()
    r = app.execute("SELECT genfile_save(%s,%s,%s,%s,%s,%s,%s)", (fid, world[who]["tenant"], user or world[who]["user"], kind, title, request, Jsonb(spec) if spec is not None else None)).fetchone()[0]
    return fid, r


def rows(app, world, who="a"):
    ctx(app, world, who)
    return app.execute("SELECT kind, title, request, spec FROM generated_files ORDER BY title").fetchall()


def test_save_and_normalise(app, world):
    assert save(app, world, title="  মাসিক   রিপোর্ট ")[1] == "ok"
    assert rows(app, world) == [("docx", "মাসিক রিপোর্ট", "একটি রিপোর্ট", SPEC)]


@pytest.mark.parametrize("kind", ["docx", "xlsx", "pptx"])
def test_every_kind_is_accepted(app, world, kind):
    assert save(app, world, kind=kind)[1] == "ok"


@pytest.mark.parametrize("kw", [
    {"kind": "pdf"}, {"kind": None}, {"title": ""}, {"title": "   "}, {"title": None}, {"title": "ক" * 201}, {"request": "ক" * 2001},
    {"spec": None}, {"spec": [1, 2]}, {"spec": "text"}, {"spec": {"blocks": ["ক" * 1_000_001]}},
])
def test_bad_input(app, world, kw):
    assert save(app, world, **kw)[1] == "bad_input"
    assert rows(app, world) == []


def test_exact_limits(app, world):
    assert save(app, world, title="ক" * 200, request="খ" * 2000)[1] == "ok"
    assert save(app, world, spec={"x": "a" * 900_000})[1] == "ok"


def test_limit_100_per_person(app, world, owner, second_user):
    as_admin(owner)
    owner.execute("INSERT INTO generated_files (id,tenant_id,user_id,kind,title,spec) SELECT lpad(g::text,26,'g'),%s,%s,'docx','t '||g,'{}' FROM generate_series(1,100) g", (world["a"]["tenant"], world["a"]["user"]))
    assert save(app, world)[1] == "limit"
    assert save(app, world, user=second_user)[1] == "ok"        # someone else is not affected


def test_user_required_and_tenant_check(app, world):
    ctx(app, world, "a")
    with pytest.raises(psycopg.errors.InvalidParameterValue):
        app.execute("SELECT genfile_save(%s,%s,NULL,'docx','t','',%s)", (ulid(), world["a"]["tenant"], Jsonb(SPEC)))
    ctx(app, world, "b")
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("SELECT genfile_save(%s,%s,%s,'docx','t','',%s)", (ulid(), world["a"]["tenant"], world["b"]["user"], Jsonb(SPEC)))


def test_delete_is_personal(app, world, second_user):
    fid, _ = save(app, world)
    ctx(app, world, "a")
    assert app.execute("SELECT genfile_delete(%s,%s,%s)", (fid, world["a"]["tenant"], second_user)).fetchone()[0] == "not_found"
    ctx(app, world, "b")
    assert app.execute("SELECT genfile_delete(%s,%s,%s)", (fid, world["b"]["tenant"], world["b"]["user"])).fetchone()[0] == "not_found"
    assert len(rows(app, world, "a")) == 1
    ctx(app, world, "a")
    assert app.execute("SELECT genfile_delete(%s,%s,%s)", (fid, world["a"]["tenant"], world["a"]["user"])).fetchone()[0] == "ok"
    assert app.execute("SELECT genfile_delete(%s,%s,%s)", (fid, world["a"]["tenant"], world["a"]["user"])).fetchone()[0] == "not_found"


def test_other_workspace_sees_nothing(app, world):
    save(app, world)
    assert rows(app, world, "b") == []


def test_app_cannot_write_directly(app, world):
    ctx(app, world, "a")
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("INSERT INTO generated_files (id,tenant_id,user_id,kind,title,spec) VALUES (%s,%s,%s,'docx','t','{}')", (ulid(), world["a"]["tenant"], world["a"]["user"]))
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("DELETE FROM generated_files")


def test_table_rejects_bad_rows_even_for_the_owner(owner, world):
    as_admin(owner)
    with pytest.raises(psycopg.errors.CheckViolation):
        owner.execute("INSERT INTO generated_files (id,tenant_id,user_id,kind,title,spec) VALUES (%s,%s,%s,'docx','t','[]')", (ulid(), world["a"]["tenant"], world["a"]["user"]))
