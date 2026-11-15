"""Phase 3 on the device: make_report (numbers computed locally), per-step needs / when / continue_on_error, and the verify type xlsx_report."""
import pytest

from jarvis_agent import actions, docs, stop, taskengine, verify
from jarvis_agent.config import ALL_KINDS, Config
from jarvis_agent.taskengine import Engine

CSV = "region,units,price\nNorth,10,2.5\nSouth,5,3\nNorth,7,2.5\nEast,2,10\n"


@pytest.fixture
def box(tmp_path, monkeypatch):
    monkeypatch.setenv("JARVIS_AGENT_HOME", str(tmp_path / "home"))
    stop.clear()
    r = tmp_path / "work"
    r.mkdir()
    (r / "sales.csv").write_text(CSV)
    yield r
    stop.clear()


def go(root, plan):
    cfg = Config(roots=[str(root)], kinds=list(ALL_KINDS), device_id="d")
    eng = Engine(cfg)
    return eng.run(plan, plan_approval=eng.approvals.issue(taskengine.plan_digest(plan)), step_approvals={})


def rep(root, dst="r.xlsx", **kw):
    return {"kind": "make_report", "params": {"src": str(root / "sales.csv"), "dst": str(root / dst), **kw}}


def test_numbers_are_exact_and_grouped(box):
    cfg = Config(roots=[str(box)], kinds=list(ALL_KINDS))
    ok, out = actions.execute(cfg, rep(box, group_by="region"))
    assert ok and "4 rows" in out
    d = docs.read_xlsx(str(box / "r.xlsx"))
    s = {r[0]: r for r in d["Summary"] if r and r[0] in ("units", "price")}
    assert s["units"][1:6] == [4, 24, 6, 2, 10] and s["price"][1:6] == [4, 18, 4.5, 2.5, 10]
    assert {r[0]: r[1:] for r in d["By region"][1:]} == {"East": [1, 2, 10], "North": [2, 17, 5], "South": [1, 5, 3]}
    assert verify.check(cfg, {"type": "xlsx_report", "path": str(box / "r.xlsx")})[0]


def test_formula_like_text_in_the_csv_is_stored_as_text(box):
    (box / "sales.csv").write_text("name,amount\n=HYPERLINK(\"http://evil\"),5\n+cmd|x,7\n")
    cfg = Config(roots=[str(box)], kinds=list(ALL_KINDS))
    assert actions.execute(cfg, rep(box))[0]
    import openpyxl
    ws = openpyxl.load_workbook(box / "r.xlsx")["Data"]
    assert ws["A2"].data_type == "s" and ws["A3"].data_type == "s"


def test_big_csv_summarises_all_rows_but_says_the_data_sheet_is_cut(box):
    (box / "sales.csv").write_text("n,v\n" + "".join(f"r{i},{i}\n" for i in range(2500)))
    cfg = Config(roots=[str(box)], kinds=list(ALL_KINDS))
    assert actions.execute(cfg, rep(box))[0]
    d = docs.read_xlsx(str(box / "r.xlsx"))
    assert len(d["Data"]) == 2000 and any("first 1999 rows" in str(c) for r in d["Summary"] for c in r) and [r for r in d["Summary"] if r and r[0] == "v"][0][2] == sum(range(2500))


def test_bad_inputs_are_refused_and_nothing_is_overwritten(box):
    cfg = Config(roots=[str(box)], kinds=list(ALL_KINDS))
    (box / "empty.csv").write_text("a,b\n")
    (box / "dup.csv").write_text("a,a\n1,2\n")
    for params in ({"src": str(box / "sales.csv")}, {"src": str(box / "sales.csv"), "dst": str(box / "r.xlsx"), "group_by": "nope"}, {"src": str(box / "empty.csv"), "dst": str(box / "r.xlsx")},
                   {"src": str(box / "dup.csv"), "dst": str(box / "r.xlsx")}, {"src": "/etc/hosts", "dst": str(box / "r.xlsx")},
                   {"src": str(box / "sales.csv"), "dst": str(box.parent / "out.xlsx")}, {"src": str(box / "sales.csv"), "dst": str(box / "r.txt")}, {"src": str(box / "sales.csv"), "dst": str(box / "r.xlsx"), "x": 1}):
        assert not actions.execute(cfg, {"kind": "make_report", "params": params})[0], params
    assert actions.execute(cfg, rep(box))[0]
    assert not actions.execute(cfg, rep(box))[0]                               # second run refuses: no silent overwrite
    assert actions.execute(cfg, rep(box, overwrite=True))[0]


def test_condition_needs_and_continue_on_error(box):
    plan = {"id": "t1", "steps": [
        {"id": "s1", "kind": "list_dir", "params": {"path": str(box)}},
        {"id": "s2", **rep(box, "a.xlsx"), "when": {"step": "s1", "contains": "sales.csv"}},
        {"id": "s3", **rep(box, "b.xlsx"), "when": {"step": "s1", "contains": "no-such-file"}},
        {"id": "s4", **rep(box, "c.xlsx"), "when": {"step": "s1", "not_contains": "no-such-file"}, "needs": ["s2"]},
    ]}
    res = go(box, plan)
    assert res.state == "completed", res.summary
    assert [s.state for s in res.steps] == ["completed", "completed", "not_needed", "completed"]
    assert "○" in res.summary
    plan2 = {"id": "t2", "steps": [
        {"id": "s1", "kind": "read_file", "params": {"path": str(box / "missing.txt")}, "continue_on_error": True},
        {"id": "s2", **rep(box, "d.xlsx"), "needs": ["s1"], "continue_on_error": True},
        {"id": "s3", **rep(box, "e.xlsx"), "continue_on_error": True},        # "run this step even if an earlier one failed"
    ]}
    res = go(box, plan2)
    assert [s.state for s in res.steps] == ["failed", "skipped", "completed"] and res.state == "failed" and (box / "e.xlsx").exists() and not (box / "d.xlsx").exists()


def test_conditions_are_part_of_the_plan_digest_so_they_cannot_be_swapped(box):
    base = {"id": "t", "steps": [{"id": "s1", "kind": "list_dir", "params": {"path": str(box)}}, {"id": "s2", **rep(box), "when": {"step": "s1", "contains": "a"}}]}
    other = {"id": "t", "steps": [base["steps"][0], {**base["steps"][1], "when": {"step": "s1", "contains": "b"}}]}
    plain = {"id": "t", "steps": [{"id": "s1", "kind": "list_dir", "params": {"path": str(box)}}]}
    assert taskengine.plan_digest(base) != taskengine.plan_digest(other)
    import hashlib
    assert taskengine.plan_digest(plain) == hashlib.sha256(taskengine.canonical({"id": "t", "steps": [{"id": "s1", "kind": "list_dir", "params": {"path": str(box)}}]}).encode()).hexdigest()


def test_validate_rejects_bad_conditions(box):
    cfg = Config(roots=[str(box)], kinds=list(ALL_KINDS))
    for bad in ({"needs": ["s2"]}, {"needs": ["s1"]}, {"needs": "s0"}, {"when": {"step": "s2", "contains": "a"}}, {"when": {"step": "s1"}}, {"when": {"step": "s1", "contains": ""}}, {"continue_on_error": 1}):
        plan = {"id": "t3", "steps": [{"id": "s1", "kind": "list_dir", "params": {"path": str(box)}, **bad}, {"id": "s2", "kind": "list_dir", "params": {"path": str(box)}}]}
        with pytest.raises(taskengine.PlanError):
            taskengine.validate(plan, cfg)
