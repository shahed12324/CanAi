DROP FUNCTION IF EXISTS payroll_run_void(char, char, char, char, date), payroll_run_pay(char, char, char, char, date, char), payroll_run_post(char, char, char, char, date), payroll_adjust_remove(char, char, char, text),
  payroll_adjust_add(char, char, char, text, text, bigint, boolean), payroll_run_retotal(char), payroll_run_create(char, char, char, text), payroll_component_end(char, char, date),
  payroll_component_save(char, char, char, char, text, boolean, text, bigint, date), payroll_employee_active(char, char, boolean), payroll_employee_save(char, char, char, text, text, text, date, date),
  payroll_account(char, text), payroll_items_total(jsonb);
DROP TABLE IF EXISTS payroll_lines, payroll_runs, payroll_components, payroll_employees;
DELETE FROM accounts a WHERE a.system_key IN ('salary_tax_payable', 'salary_deductions') AND NOT EXISTS (SELECT 1 FROM journal_lines l WHERE l.account_id = a.id);
