-- Phase 15c: financial statements. Read-only, invoker functions (RLS keeps each to one workspace). No tables, no writes.
-- All amounts are integer poisha. Nothing is "closed": profit to date is computed from the income/expense accounts.

-- Profit & loss for a period (both days inclusive). One row per income/expense account with a non-zero result:
-- income = credits - debits, expense = debits - credits. Grouping (sales / cost of goods / operating / non-operating) is done by the screen from the account code.
CREATE FUNCTION acct_profit_loss(p_from date, p_to date) RETURNS TABLE (account_id char(26), code varchar, name varchar, name_bn varchar, type text, system_key varchar, amount_minor bigint)
LANGUAGE sql STABLE AS $$
  SELECT a.id, a.code, a.name, a.name_bn, a.type, a.system_key,
         CASE WHEN a.type = 'income' THEN s.c - s.d ELSE s.d - s.c END
    FROM accounts a
    JOIN (SELECT l.account_id, sum(l.debit_minor)::bigint AS d, sum(l.credit_minor)::bigint AS c
            FROM journal_lines l JOIN journal_entries e ON e.id = l.entry_id AND e.tenant_id = l.tenant_id
           WHERE e.entry_date >= p_from AND e.entry_date <= p_to GROUP BY l.account_id) s ON s.account_id = a.id
   WHERE a.type IN ('income', 'expense') AND s.d <> s.c
   ORDER BY a.code
$$;

-- Balance sheet as of a day. section: asset (debits - credits) | liability | equity (credits - debits) | earnings (one row: income - expenses up to the day, not yet closed to retained earnings).
-- Always: assets = liabilities + equity + earnings.
CREATE FUNCTION acct_balance_sheet(p_asof date) RETURNS TABLE (section text, account_id char(26), code varchar, name varchar, name_bn varchar, amount_minor bigint)
LANGUAGE sql STABLE AS $$
  WITH s AS (
    SELECT l.account_id, sum(l.debit_minor)::bigint AS d, sum(l.credit_minor)::bigint AS c
      FROM journal_lines l JOIN journal_entries e ON e.id = l.entry_id AND e.tenant_id = l.tenant_id
     WHERE e.entry_date <= p_asof GROUP BY l.account_id)
  SELECT a.type, a.id, a.code, a.name, a.name_bn, CASE WHEN a.type = 'asset' THEN s.d - s.c ELSE s.c - s.d END
    FROM accounts a JOIN s ON s.account_id = a.id
   WHERE a.type IN ('asset', 'liability', 'equity') AND s.d <> s.c
  UNION ALL
  SELECT 'earnings', NULL, NULL, 'Profit to date (not yet closed)', 'এখন পর্যন্ত মুনাফা (এখনও বন্ধ করা হয়নি)',
         coalesce(sum(s.c - s.d), 0)::bigint
    FROM accounts a JOIN s ON s.account_id = a.id WHERE a.type IN ('income', 'expense')
  ORDER BY 1, 3
$$;

-- Cash flow for a period (direct method) over the accounts flagged cash_account.
-- Every journal entry that moves cash is split by its NON-cash lines: a non-cash line's (credit - debit) is exactly the cash it brought in (or took out), and these add up to the entry's net cash change.
-- Entries that only move money between cash accounts net to zero and are left out.
-- section: opening | operating | investing | financing | closing.  investing = non-cash asset accounts coded 1600 or higher; financing = equity accounts and liability accounts coded 2600 or higher (loans); everything else is operating.
-- opening + operating + investing + financing = closing, always.
CREATE FUNCTION acct_cash_flow(p_from date, p_to date) RETURNS TABLE (section text, account_id char(26), code varchar, name varchar, name_bn varchar, amount_minor bigint)
LANGUAGE sql STABLE AS $$
  WITH cash AS (SELECT id FROM accounts WHERE cash_account),
  ln AS (
    SELECT l.entry_id, l.account_id, l.debit_minor, l.credit_minor, e.entry_date, (l.account_id IN (SELECT id FROM cash)) AS is_cash
      FROM journal_lines l JOIN journal_entries e ON e.id = l.entry_id AND e.tenant_id = l.tenant_id
     WHERE e.entry_date <= p_to),
  moved AS (SELECT DISTINCT entry_id FROM ln WHERE is_cash AND entry_date BETWEEN p_from AND p_to),
  flow AS (
    SELECT n.account_id, sum(n.credit_minor - n.debit_minor)::bigint AS amt
      FROM ln n JOIN moved m ON m.entry_id = n.entry_id
     WHERE NOT n.is_cash GROUP BY n.account_id),
  cls AS (
    SELECT CASE WHEN a.type = 'equity' OR (a.type = 'liability' AND a.code >= '2600') THEN 'financing'
                WHEN a.type = 'asset' AND a.code >= '1600' THEN 'investing'
                ELSE 'operating' END AS section, a.id, a.code, a.name, a.name_bn, f.amt
      FROM flow f JOIN accounts a ON a.id = f.account_id WHERE f.amt <> 0)
  SELECT 'opening'::text, NULL::char(26), NULL::varchar, 'Opening cash'::varchar, 'শুরুর নগদ'::varchar,
         coalesce((SELECT sum(debit_minor - credit_minor) FROM ln WHERE is_cash AND entry_date < p_from), 0)::bigint
  UNION ALL
  SELECT section, id, code, name, name_bn, amt FROM cls
  UNION ALL
  SELECT 'closing', NULL, NULL, 'Closing cash', 'শেষের নগদ', coalesce((SELECT sum(debit_minor - credit_minor) FROM ln WHERE is_cash), 0)::bigint
  ORDER BY 1, 3
$$;

REVOKE ALL ON FUNCTION acct_profit_loss(date, date), acct_balance_sheet(date), acct_cash_flow(date, date) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION acct_profit_loss(date, date), acct_balance_sheet(date), acct_cash_flow(date, date) TO jarvis_app_rw, jarvis_admin;
