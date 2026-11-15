DROP FUNCTION IF EXISTS vat_return_file(char, char, date, text), vat_return_save(char, char, char, date, date, jsonb, text), vat_doc_flag(char, char, boolean, text),
  vat_rate_save(char, char, char, text, text, integer, date), vat_rate_seed(char, char), vat_settings_save(char, char, boolean, text, text);
DROP TABLE IF EXISTS vat_returns, vat_doc_flags, vat_rates, vat_settings;
