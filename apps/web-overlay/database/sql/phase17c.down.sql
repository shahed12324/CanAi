DROP FUNCTION IF EXISTS tax_cal_seed(char, char, text[]), tax_cal_undo(char, char, date), tax_cal_done(char, char, char, date, date, text), tax_obligation_active(char, char, boolean),
  tax_obligation_save(char, char, char, text, text, text, integer, integer, date, integer), tax_due_matches(text, integer, integer, date, date);
DROP TABLE IF EXISTS tax_obligation_done, tax_obligations;
