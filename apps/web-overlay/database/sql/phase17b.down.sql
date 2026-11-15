DROP TRIGGER IF EXISTS tax_guard_payment_void ON acct_payments;
DROP FUNCTION IF EXISTS tax_guard_payment_void(), tax_advance_void(char, char, char, date), tax_advance_pay(char, char, char, char, text, date, bigint, char, text, text), tax_deposit(char, char, char, char, text, date, date, char, text),
  tax_cert_set(char, char, text), tax_settle(char, char, char, char, date, bigint, char, text, jsonb), tax_rule_save(char, char, char, text, text, text, integer, date), tax_advance_account(char);
DROP TABLE IF EXISTS tax_withholdings, tax_advances, tax_deposits, tax_rules;
DELETE FROM accounts a WHERE a.system_key = 'tax_advance' AND NOT EXISTS (SELECT 1 FROM journal_lines l WHERE l.account_id = a.id);
