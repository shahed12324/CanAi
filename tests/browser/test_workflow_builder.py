"""Real-browser test of the visual workflow builder (public/js/workflow-builder.js) in headless Chrome.
Run:  python -m pytest tests/browser/test_workflow_builder.py -q
By default it builds a small page with the same markup contract as resources/views/workflow/show.blade.php.
Set WFB_PAGE=/path/to/rendered_show.html to run the same checks against a page rendered from the real Blade view (see docs/AGENTS_WORKFLOWS_AUDIT.md)."""
import json
import os
import pathlib
import shutil

import pytest

playwright = pytest.importorskip("playwright.sync_api")
CHROME = shutil.which("google-chrome") or shutil.which("chromium")
pytestmark = pytest.mark.skipif(CHROME is None, reason="no Chrome/Chromium installed")

ROOT = pathlib.Path(__file__).resolve().parents[2]
JS = ROOT / "apps/web-overlay/public/js/workflow-builder.js"

TOOLS = {
    "knowledge.search": {"label": "Search the knowledge base", "risk": "read", "args": [{"name": "query", "optional": False, "label": "Query"}], "desktop": False},
    "notes.add": {"label": "Add a note", "risk": "write_own", "args": [{"name": "title", "optional": False, "label": "Title"}], "desktop": False},
    "web.send": {"label": "Send to a web address", "risk": "external", "args": [{"name": "url", "optional": False, "label": "URL"}], "desktop": False},
    "pc.list_dir": {"label": "List a folder on your computer", "risk": "write_own", "args": [{"name": "path", "optional": False, "label": "Path"}], "desktop": True},
    "pay.send": {"label": "Send money", "risk": "money", "args": [], "desktop": False},
}
DEF = {"start": "ask", "inputs": [{"key": "q", "label": "Question"}], "nodes": {
    "ask": {"type": "ai", "prompt": "Answer {{q}}", "save_as": "answer", "max_tokens": 600, "next": "chk"},
    "chk": {"type": "condition", "var": "answer", "op": "not_empty", "value": "", "then": "out", "else": "out2"},
    "out": {"type": "output", "text": "{{answer}}"},
    "out2": {"type": "output", "text": "nothing"}}}


def page_html():
    p = os.environ.get("WFB_PAGE")
    if p:
        return pathlib.Path(p).read_text().replace("/assets/js/workflow-builder.js", JS.as_uri())
    esc = lambda o: json.dumps(o).replace("&", "&amp;").replace('"', "&quot;")
    return f"""<!doctype html><html><body style="width:1300px">
<form method="POST" action="/x" id="wfb-form"><input type="hidden" name="definition" id="wfb-def" value="">
<div data-wfb data-input="wfb-def" data-definition="{esc(DEF)}" data-tools="{esc(TOOLS)}"></div>
<button id="save">Save steps</button></form><script src="{JS.as_uri()}"></script></body></html>"""


@pytest.fixture()
def page(tmp_path):
    f = tmp_path / "page.html"
    f.write_text(page_html())
    with playwright.sync_playwright() as p:
        b = p.chromium.launch(executable_path=CHROME, args=["--no-sandbox"])
        pg = b.new_page(viewport={"width": 1400, "height": 900})
        pg.errors = []
        pg.on("pageerror", lambda e: pg.errors.append(str(e)))
        pg.on("console", lambda m: pg.errors.append(m.text) if m.type == "error" and "favicon" not in m.text else None)
        pg.goto(f.as_uri())
        pg.wait_for_selector(".wfb-node")
        pg.evaluate("""() => { window.__submits = []; document.addEventListener('submit', e => { window.__submits.push(e.defaultPrevented); e.preventDefault(); }); }""")
        yield pg
        assert pg.errors == [], pg.errors
        b.close()


def definition(pg):
    return json.loads(pg.input_value("#wfb-def"))


def nodes(pg):
    return pg.locator(".wfb-node").count()


def test_mounts_every_node_and_one_connector_per_link(page):
    d = definition(page)
    assert nodes(page) == len(d["nodes"])
    links = sum(1 for n in d["nodes"].values() for f in ("next", "then", "else", "join") if n.get(f))
    assert page.locator("svg.wfb-links path[data-from]").count() == links
    assert page.locator(".wfb-node.start").count() == 1


def test_click_on_palette_adds_a_node_and_updates_the_form_field(page):
    before = nodes(page)
    page.click(".wfb-pal[data-type=approval]")
    assert nodes(page) == before + 1
    d = definition(page)
    assert any(n["type"] == "approval" for n in d["nodes"].values())
    assert "approve1" in d["layout"]


def test_drag_from_palette_onto_the_canvas_drops_at_that_spot(page):
    before = nodes(page)
    page.locator(".wfb-pal[data-type=delay]").drag_to(page.locator(".wfb-canvas"), target_position={"x": 700, "y": 400})
    assert nodes(page) == before + 1
    pos = definition(page)["layout"]["wait1"]
    assert 450 < pos["x"] < 700 and 300 < pos["y"] < 420


def test_dragging_a_title_bar_moves_the_node_and_the_connectors_follow(page):
    head = page.locator(".wfb-node[data-id=out] .wfb-head")
    box = head.bounding_box()
    old = definition(page)["layout"]["out"]
    page.mouse.move(box["x"] + 20, box["y"] + 8)
    page.mouse.down()
    page.mouse.move(box["x"] + 20, box["y"] + 128, steps=6)
    page.mouse.up()
    new = definition(page)["layout"]["out"]
    assert new["y"] - old["y"] in range(120, 137) and new["x"] == old["x"]
    path = page.locator("svg.wfb-links path[data-to=out]").first.get_attribute("d")
    el = page.locator(".wfb-node[data-id=out]")
    top = page.evaluate("e => e.offsetTop + e.offsetHeight / 2", el.element_handle())
    assert str(int(top)) in path.split(" ")[-1] or str(round(top)) in path


def test_selecting_a_node_edits_its_prompt_and_model_settings(page):
    page.click(".wfb-node[data-id=ask] .wfb-body")
    ta = page.locator(".wfb-inspector textarea").first
    assert ta.input_value() == "Answer {{q}}"
    ta.fill("Be brief: {{q}}")
    assert definition(page)["nodes"]["ask"]["prompt"] == "Be brief: {{q}}"
    assert "Be brief" in page.locator(".wfb-node[data-id=ask]").inner_text()
    page.fill(".wfb-inspector input[type=number]", "900")
    assert definition(page)["nodes"]["ask"]["max_tokens"] == 900


def test_connections_can_be_changed_and_deleting_a_step_relinks(page):
    page.click(".wfb-node[data-id=chk] .wfb-body")
    page.select_option(".wfb-inspector select[data-link=else]", "out")
    assert definition(page)["nodes"]["chk"]["else"] == "out"
    page.click(".wfb-node[data-id=out2] .wfb-body")
    page.click("button[data-act=delete]")
    assert "out2" not in definition(page)["nodes"]
    page.click(".wfb-node[data-id=ask] .wfb-body")
    page.click(".wfb-node[data-id=chk] .wfb-head")
    page.click("button[data-act=delete]")
    d = definition(page)
    assert "chk" not in d["nodes"] and d["nodes"]["ask"]["next"] == ""      # chk had no `next`, so the link is cleared, not left dangling


def test_tool_picker_hides_money_tools_and_warns_for_desktop_and_outside_tools(page):
    page.click(".wfb-pal[data-type=tool]")
    sel = page.locator(".wfb-inspector select[data-field=tool]")
    opts = sel.locator("option").all_inner_texts()
    assert not any("money" in o.lower() for o in opts) and not any("pay.send" in o for o in opts)
    groups = sel.locator("optgroup").evaluate_all("els => els.map(e => e.label)")
    assert groups == ["Your data", "Outside the app (needs approval)", "Your computer (permission + approval)"]
    sel.select_option("pc.list_dir")
    assert "paired computer" in page.locator(".wfb-inspector").inner_text()
    assert "never sends it" in page.locator(".wfb-inspector").inner_text()
    assert page.locator(".wfb-node.sel .wfb-tag.risk").inner_text().startswith("Your computer")
    page.fill(".wfb-inspector input[data-arg=path]", "/home/me/docs")
    d = definition(page)
    tool_id = [k for k, n in d["nodes"].items() if n["type"] == "tool"][0]
    assert d["nodes"][tool_id]["tool"] == "pc.list_dir" and d["nodes"][tool_id]["args"] == {"path": "/home/me/docs"}
    sel.select_option("web.send")
    assert "Approval step must come before" in page.locator(".wfb-inspector").inner_text()
    assert definition(page)["nodes"][tool_id]["args"] == {}                    # changing the tool clears the old arguments


def test_text_a_person_types_is_never_turned_into_html(page):
    page.click(".wfb-node[data-id=ask] .wfb-body")
    page.locator(".wfb-inspector textarea").first.fill('<img src=x onerror="window.__pwned=1"><script>window.__pwned=2</script>')
    assert page.locator(".wfb-canvas img, .wfb-canvas script, .wfb-inspector img").count() == 0
    assert page.evaluate("window.__pwned") is None
    assert "<img" in page.locator(".wfb-node[data-id=ask]").inner_text()


def test_saving_is_blocked_while_the_flow_is_incomplete_and_allowed_when_complete(page):
    page.click(".wfb-pal[data-type=ai]")                                        # new AI step: no prompt, not connected
    assert "Before you save" in page.locator(".wfb-inspector").inner_text()
    page.click("#save")
    assert page.evaluate("window.__submits") == [True]                          # the builder stopped it (defaultPrevented was already true)
    page.click(".wfb-node.sel button[data-act=delete]") if page.locator(".wfb-node.sel button[data-act=delete]").count() else page.click("button[data-act=delete]")
    assert "Looks complete" in page.locator(".wfb-inspector").inner_text()
    page.click("#save")
    assert page.evaluate("window.__submits") == [True, False]                   # a complete flow is submitted
    json.loads(page.input_value("#wfb-def"))


def test_run_form_fields_can_be_added_edited_and_removed(page):
    page.click("button[data-act=add-input]")
    assert len(definition(page)["inputs"]) == 2
    page.locator("input[data-input-key='1']").fill("topic")
    assert definition(page)["inputs"][1]["key"] == "topic"
    for _ in range(4):
        if page.locator("button[data-act=add-input]").count():
            page.click("button[data-act=add-input]")
    assert len(definition(page)["inputs"]) == 5                                  # at most 5
    assert page.locator("button[data-act=add-input]").count() == 0


def test_a_parallel_step_is_kept_untouched(page):
    page.evaluate("""() => { const r = document.querySelector('[data-wfb]'); const d = r.__wfb.def;
        d.nodes.par = {type:'parallel', branches:['b1','b2'], join:'out'}; d.nodes.b1 = {type:'ai', prompt:'p', save_as:'u', next:'out'}; d.nodes.b2 = {type:'ai', prompt:'p', save_as:'v', next:'out'}; r.__wfb.render(); r.__wfb.select('par'); }""")
    assert "Advanced" in page.locator(".wfb-inspector").inner_text()
    d = definition(page)
    assert d["nodes"]["par"] == {"type": "parallel", "branches": ["b1", "b2"], "join": "out"}


def test_the_thirty_step_limit_is_enforced_in_the_page(page):
    for _ in range(40):
        page.evaluate("document.querySelector('[data-wfb]').__wfb.add('output', {x: 10, y: 10})")
    assert nodes(page) == 30


@pytest.mark.skipif(not os.environ.get("WFB_RENDERED"), reason="set WFB_RENDERED to a page rendered from resources/views/workflow/show.blade.php")
def test_page_rendered_from_the_real_blade_view(tmp_path):
    """The Blade page and the JS agree on the markup contract: nodes appear, the hidden field is filled, forms point at the right routes, nothing throws."""
    html = pathlib.Path(os.environ["WFB_RENDERED"]).read_text().replace("/assets/js/workflow-builder.js", JS.as_uri())
    f = tmp_path / "real.html"
    f.write_text(html)
    with playwright.sync_playwright() as p:
        b = p.chromium.launch(executable_path=CHROME, args=["--no-sandbox"])
        pg = b.new_page(viewport={"width": 1400, "height": 900})
        errs = []
        pg.on("pageerror", lambda e: errs.append(str(e)))
        pg.goto(f.as_uri())
        pg.wait_for_selector(".wfb-node")
        n = pg.locator(".wfb-node").count()
        assert n >= 2
        assert json.loads(pg.input_value("#wfb-def"))["start"]
        pg.click(".wfb-pal[data-type=output]")
        assert pg.locator(".wfb-node").count() == n + 1
        actions = pg.eval_on_selector_all("form", "fs => fs.map(f => f.getAttribute('action'))")
        for need in ("/r/agents.rename/", "/r/agents.steps/", "/r/agents.settings/", "/r/agents.test/", "/r/agents.run/", "/r/agents.state/", "/r/agents.duplicate/", "/r/agents.destroy/"):
            assert any(a and a.startswith(need) for a in actions), need
        assert errs == []
        b.close()
