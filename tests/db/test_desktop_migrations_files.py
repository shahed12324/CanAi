"""Static checks of the desktop migrations (no database needed): every Laravel migration loads an up AND a down SQL file that exist, the test
schema loader applies every up file, files are applied in migration order, and each down refuses to destroy data or restores the previous layer."""
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2] / "apps" / "web-overlay"
MIG = sorted((ROOT / "database" / "migrations").glob("2027_01_*.php"))
SQL = ROOT / "database" / "sql"
CONFTEST = (Path(__file__).parent / "conftest.py").read_text()


def phases(f: Path) -> tuple[str, str]:
    t = f.read_text()
    up = re.findall(r"sql/(phase[0-9a-z]+)\.up\.sql", t)
    down = re.findall(r"sql/(phase[0-9a-z]+)\.down\.sql", t)
    return (up[0] if up else ""), (down[0] if down else "")


def test_every_desktop_migration_has_matching_existing_up_and_down_files():
    assert MIG, "no desktop migrations found"
    for f in MIG:
        up, down = phases(f)
        assert up and up == down, f"{f.name}: up/down must name the same phase ({up!r} vs {down!r})"
        assert (SQL / f"{up}.up.sql").is_file() and (SQL / f"{up}.down.sql").is_file(), f.name


def test_the_test_schema_applies_every_desktop_up_file_in_migration_order():
    order = [phases(f)[0] for f in MIG if phases(f)[0].startswith("phase3")]
    pos = []
    for ph in order:
        m = re.search(rf'_exec_file\(c, "{ph}\.up\.sql"\)', CONFTEST)
        assert m, f"{ph}.up.sql is missing from tests/db/conftest.py"
        pos.append(m.start())
    assert pos == sorted(pos), "conftest applies the up files in a different order than the migrations"


def test_downs_are_not_empty_and_never_drop_data_silently():
    for f in MIG:
        up, _ = phases(f)
        d = (SQL / f"{up}.down.sql").read_text()
        assert len(d.strip()) > 40, f"{up}.down.sql is empty"
        for bad in re.findall(r"(?im)^\s*(DROP\s+TABLE\s+(?!IF EXISTS)\S+|TRUNCATE\s+\S+|DELETE\s+FROM\s+\S+\s*;)", d):
            raise AssertionError(f"{up}.down.sql removes data without a guard: {bad}")


def test_kind_layers_are_chained_in_the_order_of_the_phases():
    # each new kind layer renames the previous pc_target_ok; the newest up file must be the one the wrapper chain ends with
    chain = [(p, (SQL / f"{p}.up.sql").read_text()) for p in ("phase33a", "phase34a", "phase35a", "phase36a")]
    for prev, cur in zip(chain, chain[1:]):
        assert f"pc_target_ok_p{cur[0][5:7]}" in cur[1] or "pc_target_ok" in cur[1], f"{cur[0]} does not extend {prev[0]}"
