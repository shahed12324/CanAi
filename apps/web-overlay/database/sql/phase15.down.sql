DROP FUNCTION IF EXISTS acct_ledger(char, date, date), acct_trial_balance(date), acct_unlock(char, date, char, text), acct_lock(char, date, char), journal_reverse(char, char, char, date, text),
  journal_post(char, char, char, date, text, text, text, jsonb), acct_account_add(char, char, text, text, text, text, char), acct_seed(char, text[]), acct_open(char);
DROP TABLE IF EXISTS acct_lock_log, journal_lines, journal_entries, accounts, acct_settings;
DROP FUNCTION IF EXISTS journal_balance_check();
