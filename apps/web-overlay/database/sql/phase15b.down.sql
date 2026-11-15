DROP FUNCTION IF EXISTS acct_cash_balances(date), acct_aging(text, date), acct_doc_void(char, char, char, date), acct_payment_void(char, char, char, date),
  acct_payment_add(char, char, char, char, date, bigint, char, text), acct_doc_create(char, char, char, char, text, char, date, date, text, jsonb, bigint, char), acct_account_cash(char, boolean), acct_party_add(char, char, text, text, text, text);
DROP TABLE IF EXISTS acct_payments, acct_doc_lines, acct_docs, acct_parties;
ALTER TABLE acct_settings DROP COLUMN IF EXISTS next_invoice, DROP COLUMN IF EXISTS next_bill, DROP COLUMN IF EXISTS next_expense;
DROP TRIGGER IF EXISTS accounts_cash_default ON accounts;
DROP FUNCTION IF EXISTS accounts_cash_default();
ALTER TABLE accounts DROP COLUMN IF EXISTS cash_account;
