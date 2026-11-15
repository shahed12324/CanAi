DROP FUNCTION IF EXISTS inv_revalue(char, char, char, date, char), inv_valuation(date), inv_value_rows(char, date), inv_cost_set(char, bigint, date, char),
  fa_dispose(char, char, char, date, bigint, char), fa_run(char, char, char, date), fa_pending(date), fa_register(char, char, char, text, char, date, bigint, bigint, integer), fa_month_amount(bigint, bigint, integer, integer);
DROP TABLE IF EXISTS inv_costs, fa_dep_lines, fixed_assets;
