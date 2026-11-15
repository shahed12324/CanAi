import json

import psycopg
import pytest

from conftest import as_admin, ulid
from test_accounting import acc, cr, ctx, dr, post
from test_accounting_docs import setup, wipe_all


@pytest.fixture(autouse=True)
def clean(owner, world):
    yield
    as_admin(owner)
    owner.execute("DELETE FROM analyst_reports")
    wipe_all(owner)


FACTS = {"month": "2026-10", "latest": {"income_minor": 1}}
FC = {"enough": False, "estimate": True}


def save(app, world, *, rid=None, who="a", month="2026-10", facts=FACTS, fc=FC, text="আয় বেড়েছে।", source="template", note=None, model=None, i=0, o=0, credits=0, user=True):
    ctx(app, world, who)
    return app.execute("SELECT analyst_report_save(%s,%s,%s,%s,%s::jsonb,%s::jsonb,%s,%s,%s,%s,%s,%s,%s)",
                       (rid or ulid(), world[who]["tenant"], world[who]["user"] if user else None, month, json.dumps(facts), json.dumps(fc), text, source, note, model, i, o, credits)).fetchone()[0]


def test_saves_a_template_and_an_ai_report(app, world):
    assert save(app, world) == "ok"
    assert save(app, world, source="ai", model="gpt-x", i=1000, o=200, credits="1.2500") == "ok"
    ctx(app, world, "a")
    rows = app.execute("SELECT source, model, input_tokens, output_tokens, credits_charged FROM analyst_reports ORDER BY source").fetchall()
    assert [(r[0], r[1]) for r in rows] == [("ai", "gpt-x"), ("template", None)]
    assert rows[0][2:] == (1000, 200, __import__("decimal").Decimal("1.2500"))


@pytest.mark.parametrize(
    "kw",
    [
        dict(month="2026-13"), dict(month="26-10"), dict(month="2026-1"), dict(month=None),
        dict(text=""), dict(text="   "), dict(text=None), dict(text="ক" * 8001),
        dict(source="human"), dict(source=None), dict(source="ai", model=None), dict(source="ai", model="  "),
        dict(i=-1), dict(o=-1), dict(credits=-1),
        dict(facts=[]), dict(fc=[1]), dict(facts=None), dict(fc=None),
    ],
)
def test_bad_input_is_refused_without_a_row(app, world, kw):
    # None for the JSON parameters means SQL NULL, not the string "null"
    ctx(app, world, "a")
    facts = kw.get("facts", FACTS)
    fc = kw.get("fc", FC)
    r = app.execute("SELECT analyst_report_save(%s,%s,%s,%s,%s::jsonb,%s::jsonb,%s,%s,%s,%s,%s,%s,%s)", (
        ulid(), world["a"]["tenant"], world["a"]["user"], kw.get("month", "2026-10"), None if facts is None else json.dumps(facts), None if fc is None else json.dumps(fc),
        kw.get("text", "আয় বেড়েছে।"), kw.get("source", "template"), None, kw.get("model"), kw.get("i", 0), kw.get("o", 0), kw.get("credits", 0))).fetchone()[0]
    assert r == "bad_input"
    assert app.execute("SELECT count(*) FROM analyst_reports").fetchone()[0] == 0


def test_long_note_is_cut_and_8000_chars_is_allowed(app, world):
    assert save(app, world, text="ক" * 8000, note="x" * 100) == "ok"
    ctx(app, world, "a")
    assert app.execute("SELECT length(narrative), length(note) FROM analyst_reports").fetchone() == (8000, 40)


def test_user_is_required(app, world):
    with pytest.raises(psycopg.errors.InvalidParameterValue):
        save(app, world, user=False)


def test_foreign_tenant_is_forbidden_and_rows_are_private(app, world):
    assert save(app, world, who="a") == "ok"
    ctx(app, world, "b")
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("SELECT analyst_report_save(%s,%s,%s,'2026-10','{}'::jsonb,'{}'::jsonb,'x','template',NULL,NULL,0,0,0)", (ulid(), world["a"]["tenant"], world["b"]["user"]))
    assert app.execute("SELECT count(*) FROM analyst_reports").fetchone()[0] == 0
    ctx(app, world, "a")
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("DELETE FROM analyst_reports")


def test_same_id_twice_is_a_duplicate_error_not_two_rows(app, world):
    rid = ulid()
    assert save(app, world, rid=rid) == "ok"
    with pytest.raises(psycopg.errors.UniqueViolation):
        save(app, world, rid=rid)
