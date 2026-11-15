"""File writing: DOCX / XLSX / PPTX are built from a checked spec; nothing is a formula, sizes are capped, the route is signed."""

import io
import json

import pytest
from docx import Document
from openpyxl import load_workbook
from pptx import Presentation

from tests.conftest import make_headers


def call(c, kind, payload):
    path = f"/internal/v1/office/{kind}"
    body = json.dumps(payload).encode()
    h = make_headers(method="POST", path=path, body=body) | {"content-type": "application/json"}
    return c.post(path, content=body, headers=h)


DOC = {
    "kind": "docx",
    "title": "মাসিক রিপোর্ট",
    "blocks": [
        {"t": "h1", "text": "সারাংশ"},
        {"t": "p", "text": "বিক্রি বেড়েছে।"},
        {"t": "ul", "items": ["এক", "দুই"]},
        {"t": "ol", "text": "ধাপ", "items": ["ক", "খ"]},
        {"t": "table", "headers": ["পণ্য", "দাম"], "rows": [["কাজু", "১২০০"], ["কিসমিস"]]},
        {"t": "h3", "text": ""},
    ],
}
SHEET = {
    "kind": "xlsx",
    "title": "বাজেট",
    "sheets": [
        {
            "name": "Jan",
            "headers": ["আইটেম", "টাকা"],
            "rows": [["ভাড়া", 20000], ["=SUM(B2:B2)", 1.5], ["+1", None]],
        },
        {"name": "Jan", "headers": [], "rows": [["x"]]},
    ],
}
DECK = {
    "kind": "pptx",
    "title": "পিচ",
    "slides": [
        {"title": "সমস্যা", "bullets": ["এক", "দুই"], "notes": "বলার নোট"},
        {"title": "সমাধান"},
    ],
}


def test_unsigned_requests_are_rejected(client):
    for k, p in (("docx", DOC), ("xlsx", SHEET), ("pptx", DECK)):
        assert client.post(f"/internal/v1/office/{k}", json=p).status_code in (401, 403)


def test_docx_has_title_headings_lists_and_table(client):
    r = call(client, "docx", DOC)
    assert r.status_code == 200 and r.headers["content-type"].startswith(
        "application/vnd.openxmlformats"
    )
    d = Document(io.BytesIO(r.content))
    texts = [p.text for p in d.paragraphs]
    assert "মাসিক রিপোর্ট" in texts and "সারাংশ" in texts and "বিক্রি বেড়েছে।" in texts and "ধাপ" in texts
    assert [p.style.name for p in d.paragraphs if p.text in ("এক", "দুই")] == ["List Bullet"] * 2
    assert [p.style.name for p in d.paragraphs if p.text in ("ক", "খ")] == ["List Number"] * 2
    t = d.tables[0]
    assert [c.text for c in t.rows[0].cells] == ["পণ্য", "দাম"] and t.rows[0].cells[0].paragraphs[
        0
    ].runs[0].bold
    assert [c.text for c in t.rows[2].cells] == ["কিসমিস", ""]  # short rows are padded
    assert d.core_properties.title == "মাসিক রিপোর্ট"


def test_xlsx_cells_are_never_formulas_and_sheet_names_are_unique(client):
    r = call(client, "xlsx", SHEET)
    assert r.status_code == 200
    wb = load_workbook(io.BytesIO(r.content))
    assert wb.sheetnames == ["Jan", "Jan 2"]
    ws = wb["Jan"]
    assert (
        [c.value for c in ws[1]] == ["আইটেম", "টাকা"]
        and ws["A1"].font.bold
        and ws.freeze_panes == "A2"
    )
    assert ws["B2"].value == 20000 and ws["B3"].value == 1.5
    assert ws["A3"].value == "=SUM(B2:B2)" and ws["A3"].data_type == "s"  # text, not a formula
    assert ws["A4"].value == "+1" and ws["A4"].data_type == "s" and ws["B4"].value is None
    raw = load_workbook(io.BytesIO(r.content), data_only=False)["Jan"]["A3"]
    assert raw.data_type != "f"


def test_pptx_has_title_slide_bullets_and_notes(client):
    r = call(client, "pptx", DECK)
    assert r.status_code == 200
    p = Presentation(io.BytesIO(r.content))
    assert len(p.slides) == 3 and p.slides[0].shapes.title.text == "পিচ"
    s = p.slides[1]
    assert s.shapes.title.text == "সমস্যা" and [
        x.text for x in s.placeholders[1].text_frame.paragraphs
    ] == ["এক", "দুই"]
    assert s.notes_slide.notes_text_frame.text == "বলার নোট"
    assert not p.slides[2].has_notes_slide


@pytest.mark.parametrize(
    "kind,payload",
    [
        ("docx", {**DOC, "title": "   "}),
        ("docx", {**DOC, "blocks": []}),
        ("docx", {**DOC, "blocks": [{"t": "script", "text": "x"}]}),
        ("docx", {**DOC, "blocks": [{"t": "p", "text": "a" * 5001}]}),
        ("docx", {**DOC, "blocks": [{"t": "p", "text": "x"}] * 301}),
        ("docx", {**DOC, "blocks": [{"t": "table", "rows": [["c"] * 31]}]}),
        ("docx", {**DOC, "blocks": [{"t": "ul", "items": ["i"] * 61}]}),
        ("xlsx", {**SHEET, "sheets": []}),
        ("xlsx", {**SHEET, "sheets": [{"name": "s"}] * 11}),
        ("xlsx", {**SHEET, "sheets": [{"name": "s", "rows": [["a" * 501]]}]}),
        ("xlsx", {**SHEET, "sheets": [{"name": "s", "rows": [[1] * 51]}]}),
        ("xlsx", {**SHEET, "sheets": [{"name": "s", "rows": [[1]] * 2001}]}),
        ("pptx", {**DECK, "slides": []}),
        ("pptx", {**DECK, "slides": [{"title": "t"}] * 41}),
        ("pptx", {**DECK, "slides": [{"title": "t", "bullets": ["b"] * 13}]}),
        ("pptx", {**DECK, "slides": [{"title": "t", "bullets": ["b" * 301]}]}),
        ("pptx", {**DECK, "title": ""}),
        ("pptx", {**DECK, "kind": "docx"}),
    ],
)
def test_oversize_or_wrong_shapes_are_refused(client, kind, payload):
    assert call(client, kind, payload).status_code == 422


def test_limits_are_exact(client):
    assert (
        call(client, "docx", {**DOC, "blocks": [{"t": "p", "text": "a" * 5000}] * 300}).status_code
        == 200
    )
    assert (
        call(
            client, "pptx", {**DECK, "slides": [{"title": "t", "bullets": ["b" * 300] * 12}] * 40}
        ).status_code
        == 200
    )
    assert (
        call(
            client, "xlsx", {**SHEET, "sheets": [{"name": "s", "rows": [["a" * 500] * 50]}] * 10}
        ).status_code
        == 200
    )


def test_control_characters_and_bad_sheet_names_are_cleaned(client):
    r = call(
        client,
        "xlsx",
        {
            "title": "t\x00",
            "sheets": [
                {"name": "a/b:c*[x]?", "headers": ["h\x01"], "rows": [["v\x0b", float("1e5")]]}
            ],
        },
    )
    assert r.status_code == 200
    wb = load_workbook(io.BytesIO(r.content))
    assert wb.sheetnames == ["a b c  x"] or wb.sheetnames[0].replace(" ", "") == "abcx"
    assert wb.worksheets[0]["A1"].value == "h" and wb.worksheets[0]["A2"].value == "v"
    long = call(client, "xlsx", {"title": "t", "sheets": [{"name": "n" * 60}]})
    assert len(load_workbook(io.BytesIO(long.content)).sheetnames[0]) == 31


def test_nan_cells_become_empty(client):
    body = b'{"kind":"xlsx","title":"t","sheets":[{"name":"s","rows":[[NaN,1]]}]}'
    path = "/internal/v1/office/xlsx"
    h = make_headers(method="POST", path=path, body=body) | {"content-type": "application/json"}
    r = client.post(path, content=body, headers=h)
    if r.status_code == 200:
        ws = load_workbook(io.BytesIO(r.content)).worksheets[0]
        assert ws["A1"].value is None and ws["B1"].value == 1
    else:
        assert r.status_code == 422


def test_empty_table_block_is_skipped(client):
    r = call(client, "docx", {"title": "t", "blocks": [{"t": "table"}, {"t": "p", "text": "x"}]})
    assert r.status_code == 200 and Document(io.BytesIO(r.content)).tables == []


def test_headers_are_text_too_and_nan_is_dropped_by_the_model():
    from app.office.models import Sheet

    r = Sheet(name="s", headers=["=1+1"], rows=[[float("nan"), float("inf"), 2.5]])
    assert r.rows == [[None, None, 2.5]]


def test_header_formulas_stay_text(client):
    r = call(
        client,
        "xlsx",
        {"title": "t", "sheets": [{"name": "s", "headers": ["=1+1", "+x"], "rows": []}]},
    )
    ws = load_workbook(io.BytesIO(r.content)).worksheets[0]
    assert ws["A1"].value == "=1+1" and ws["A1"].data_type == "s" and ws["B1"].data_type == "s"
