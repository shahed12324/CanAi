"""The SQL strings behind the invoice/bill/expense screens (PgDocumentRepo), run against real PG."""
import json

from conftest import ulid
from test_accounting import ctx
from test_accounting_docs import clean, line, make, party, setup  # noqa: F401  (autouse cleanup reused)
from test_lead_php_sql import sql_of

REPO = "app/Accounting/PgDocumentRepo.php"


def test_repo_statements_run(app, world):
    t, u = world["a"]["tenant"], world["a"]["user"]
    ctx(app, world)
    a = setup(app, world)
    # parties
    assert app.execute(sql_of(REPO, "acct_party_add"), (ulid(), t, "customer", "রহিম", "017", None)).fetchone()[0] == "ok"
    sup = party(app, world, "supplier", name="অসাপ্লায়ার")
    names = app.execute(sql_of(REPO, "from acct_parties where (? = 'all'"), ("all", "all")).fetchall()
    assert len(names) == 2
    only = app.execute(sql_of(REPO, "from acct_parties where (? = 'all'"), ("supplier", "supplier")).fetchall()
    assert [r[2] for r in only] == ["অসাপ্লায়ার"]
    app.execute(sql_of(REPO, "update acct_parties set active"), ("false", sup))
    assert app.execute("SELECT active FROM acct_parties WHERE id=%s", (sup,)).fetchone()[0] is False
    assert [r[2] for r in app.execute(sql_of(REPO, "from acct_parties where (? = 'all'"), ("all", "all")).fetchall()] == ["রহিম", "অসাপ্লায়ার"]   # active first, inactive last
    # create through the repo's statement
    cust = party(app, world)
    did = ulid()
    r = app.execute(sql_of(REPO, "from acct_doc_create("), (did, ulid(), t, u, "invoice", cust, "2026-11-01", "2026-11-10", "ref",
                                                           json.dumps([line(a["sales"], 10000)]), 500, None)).fetchone()
    assert (r[0], r[2], r[3]) == ("ok", "INV-0001", 10500)
    # lists and detail
    rows = app.execute(sql_of(REPO, "from acct_docs d left join acct_parties p on p.id = d.party_id and p.tenant_id = d.tenant_id where d.kind"), ("invoice", 100)).fetchall()
    assert len(rows) == 1 and rows[0][1] == "INV-0001" and rows[0][4] == 10500 and rows[0][7] == "রহিম স্টোর"
    make(app, world, "invoice", cust, [line(a["sales"], 100)], date="2026-11-02")
    rows = app.execute(sql_of(REPO, "from acct_docs d left join acct_parties p on p.id = d.party_id and p.tenant_id = d.tenant_id where d.kind"), ("invoice", 100)).fetchall()
    assert [x[1] for x in rows] == ["INV-0002", "INV-0001"]
    d = app.execute(sql_of(REPO, "d.void_entry_id, a.name as pay_account"), (did,)).fetchone()
    assert d[1] == "invoice" and d[2] == "INV-0001" and d[10] == 10500 and d[9] == 500
    ls = app.execute(sql_of(REPO, "from acct_doc_lines l join accounts a"), (did,)).fetchall()
    assert [(x[1], x[2]) for x in ls] == [(10000, "4100")]
    # payments
    pid = ulid()
    assert app.execute(sql_of(REPO, "select acct_payment_add"), (pid, ulid(), did, u, "2026-11-03", 4000, a["cash"], "part")).fetchone()[0] == "ok"
    pays = app.execute(sql_of(REPO, "from acct_payments p join accounts a"), (did,)).fetchall()
    assert len(pays) == 1 and pays[0][2] == 4000
    ag = app.execute(sql_of(REPO, "from acct_aging("), ("invoice", "2026-11-30")).fetchall()
    assert sorted(x[7] for x in ag) == [100, 6500]
    cb = app.execute(sql_of(REPO, "from acct_cash_balances("), ("2026-11-30",)).fetchall()
    assert {x[1]: x[4] for x in cb}["1110"] == 4000
    assert app.execute(sql_of(REPO, "select acct_payment_void"), (ulid(), pid, u, "2026-11-04")).fetchone()[0] == "ok"
    assert app.execute(sql_of(REPO, "select acct_doc_void"), (ulid(), did, u, "2026-11-04")).fetchone()[0] == "ok"
    assert app.execute("SELECT status FROM acct_docs WHERE id=%s", (did,)).fetchone()[0] == "void"
    assert app.execute(sql_of(REPO, "select acct_account_cash"), (a["rent"], "true")).fetchone()[0] == "not_asset"
    assert app.execute(sql_of(REPO, "select acct_account_cash"), (a["bank"], "false")).fetchone()[0] == "ok"
    assert {x[1] for x in app.execute(sql_of(REPO, "from acct_cash_balances("), ("2026-11-30",)).fetchall()} == {"1110", "1130"}
