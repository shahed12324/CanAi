import psycopg
import pytest

from conftest import as_admin
from test_accounting import ctx, seed  # noqa: F401
from test_payroll import emp
from test_payroll import wipe as wipe_payroll


@pytest.fixture(autouse=True)
def clean(owner, world):
    yield
    as_admin(owner)
    owner.execute("DELETE FROM payroll_pay_details")
    wipe_payroll(owner)


def save(app, world, e, method, bank=None, branch=None, routing=None, account=None, wallet=None, who="a"):
    ctx(app, world, who)
    return app.execute(
        "SELECT payroll_pay_details_save(%s,%s,%s,%s,%s,%s,%s,%s,%s)",
        (world[who]["tenant"], world[who]["user"], e, method, bank, branch, routing, account, wallet),
    ).fetchone()[0]


def row(app, world, e, who="a"):
    ctx(app, world, who)
    return app.execute("SELECT method, bank_name, branch, routing_no, account_no, wallet_no FROM payroll_pay_details WHERE employee_id=%s", (e,)).fetchone()


def test_bank_ok_and_replace(app, world):
    e = emp(app, world)
    assert save(app, world, e, "bank", "ডাচ-বাংলা", "গুলশান", "090261234", "1234 5678-90") == "ok"
    assert row(app, world, e) == ("bank", "ডাচ-বাংলা", "গুলশান", "090261234", "12345678-90", None)
    assert save(app, world, e, "bkash", wallet="+880 1712-345678") == "ok"
    assert row(app, world, e) == ("bkash", None, None, None, None, "01712345678")
    assert save(app, world, e, "nagad", wallet="8801812345678") == "ok"
    assert row(app, world, e)[5] == "01812345678"
    assert save(app, world, e, "cash") == "ok"
    assert row(app, world, e) == ("cash", None, None, None, None, None)


@pytest.mark.parametrize(
    "kw",
    [
        dict(method="bank", bank=None, account="12345"),
        dict(method="bank", bank="  ", account="12345"),
        dict(method="bank", bank="X", account=None),
        dict(method="bank", bank="X", account="12ab5"),
        dict(method="bank", bank="X", account="1234"),
        dict(method="bank", bank="X", account="12345", routing="123"),
        dict(method="bank", bank="X", account="12345", wallet="01712345678"),
        dict(method="bkash", wallet=None),
        dict(method="bkash", wallet="02712345678"),
        dict(method="bkash", wallet="0171234567"),
        dict(method="bkash", wallet="01712345678", bank="X"),
        dict(method="nagad", wallet="01712345678", account="12345"),
        dict(method="cash", account="12345"),
        dict(method="cash", wallet="01712345678"),
        dict(method="cash", bank="X"),
        dict(method="rocket", wallet="01712345678"),
        dict(method=None),
    ],
)
def test_bad_input(app, world, kw):
    e = emp(app, world)
    assert save(app, world, e, **kw) == "bad_input"
    assert row(app, world, e) is None


def test_foreign_employee_forbidden(app, world):
    e = emp(app, world, who="a")
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        save(app, world, e, "cash", who="b")


def test_no_user_refused(app, world):
    e = emp(app, world)
    ctx(app, world, "a")
    with pytest.raises(psycopg.errors.InvalidParameterValue):
        app.execute("SELECT payroll_pay_details_save(%s,NULL,%s,'cash',NULL,NULL,NULL,NULL,NULL)", (world["a"]["tenant"], e))


def test_other_tenant_cannot_read_and_app_cannot_write(app, world):
    e = emp(app, world, who="a")
    assert save(app, world, e, "cash") == "ok"
    assert row(app, world, e, who="b") is None
    ctx(app, world, "a")
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("UPDATE payroll_pay_details SET method='cash'")


def test_rocket_alone_is_bad_input(app, world):
    e = emp(app, world)
    assert save(app, world, e, "rocket") == "bad_input"
    assert save(app, world, e, "") == "bad_input"


def test_long_bank_name_is_cut_to_80(app, world):
    e = emp(app, world)
    assert save(app, world, e, "bank", "ব" * 100, "শ" * 100, account="12345") == "ok"
    r = row(app, world, e)
    assert len(r[1]) == 80 and len(r[2]) == 80


def test_wrong_tenant_context_refused_even_for_own_employee(app, world):
    e = emp(app, world, who="a")
    ctx(app, world, "b")
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("SELECT payroll_pay_details_save(%s,%s,%s,'cash',NULL,NULL,NULL,NULL,NULL)", (world["a"]["tenant"], world["b"]["user"], e))


def test_updated_by_follows_the_latest_saver(app, world, owner):
    e = emp(app, world)
    assert save(app, world, e, "cash") == "ok"
    as_admin(owner)
    owner.execute("UPDATE payroll_pay_details SET updated_by = %s", (world["b"]["user"],))
    assert save(app, world, e, "bkash", wallet="01712345678") == "ok"
    as_admin(owner)
    assert owner.execute("SELECT updated_by FROM payroll_pay_details").fetchone()[0].strip() == world["a"]["user"]
