DROP FUNCTION IF EXISTS bank_reconciliation(char, date), bank_suggestions(char, integer), bank_auto_match(char, char, integer), bank_create_entry(char, char, char, char, text),
  bank_ignore(char, boolean), bank_unmatch(char), bank_match(char, bigint, char), bank_line_lock(char), bank_import(char, char, char, char, text, jsonb);
DROP TABLE IF EXISTS bank_lines, bank_imports;
