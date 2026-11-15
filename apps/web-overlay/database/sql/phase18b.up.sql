-- Phase 18b: payroll. Employees, a salary structure of earning / deduction components (effective-dated, so a raise keeps history), and a MONTHLY RUN:
--   draft  -> build the month from the structure, add one-off bonuses / deductions, check the totals
--   posted -> books the month:  Dr salaries (gross) / Cr salary tax payable / Cr other salary deductions payable / Cr salaries payable (net)
--   paid   -> books the payout: Dr salaries payable / Cr cash-bank
-- NOTHING about tax slabs, provident fund rules or minimum wage is in code: every amount is what the person enters (or copies from their own structure); the income-tax a person
-- withholds from a salary is a deduction line the person marks as "tax". Not prorated for part months / unpaid leave - use a one-off deduction. A professional reviews.

CREATE TABLE payroll_employees (
  id          char(26)     PRIMARY KEY,
  tenant_id   char(26)     NOT NULL REFERENCES tenants (id),
  code        varchar(20)  NOT NULL CHECK (code ~ '^[A-Za-z0-9._-]{1,20}$'),
  name        varchar(80)  NOT NULL CHECK (btrim(name) <> ''),
  designation varchar(80),
  join_date   date         NOT NULL CHECK (join_date >= DATE '1990-01-01'),
  end_date    date,
  active      boolean      NOT NULL DEFAULT true,
  created_by  char(26)     NOT NULL REFERENCES users (id),
  created_at  timestamptz  NOT NULL DEFAULT now(),
  UNIQUE (id, tenant_id),
  UNIQUE (tenant_id, code),
  CONSTRAINT employment_range CHECK (end_date IS NULL OR end_date >= join_date)
);

CREATE TABLE payroll_components (
  id             char(26)    PRIMARY KEY,
  tenant_id      char(26)    NOT NULL REFERENCES tenants (id),
  employee_id    char(26)    NOT NULL,
  kind           text        NOT NULL CHECK (kind IN ('earning', 'deduction')),
  is_tax         boolean     NOT NULL DEFAULT false,                       -- a deduction that is income tax withheld from the salary
  label          varchar(60) NOT NULL CHECK (btrim(label) <> ''),
  amount_minor   bigint      NOT NULL CHECK (amount_minor > 0 AND amount_minor <= 99999999999),
  effective_from date        NOT NULL,
  effective_to   date,
  created_by     char(26)    NOT NULL REFERENCES users (id),
  created_at     timestamptz NOT NULL DEFAULT now(),
  UNIQUE (employee_id, kind, label, effective_from),
  FOREIGN KEY (employee_id, tenant_id) REFERENCES payroll_employees (id, tenant_id),
  CONSTRAINT component_range CHECK (effective_to IS NULL OR effective_to >= effective_from),
  CONSTRAINT tax_is_a_deduction CHECK (NOT is_tax OR kind = 'deduction')
);
CREATE UNIQUE INDEX payroll_components_one_open ON payroll_components (employee_id, kind, label) WHERE effective_to IS NULL;

CREATE TABLE payroll_runs (
  id             char(26)    PRIMARY KEY,
  tenant_id      char(26)    NOT NULL REFERENCES tenants (id),
  period         char(7)     NOT NULL CHECK (period ~ '^20[0-9]{2}-(0[1-9]|1[0-2])$'),
  status         text        NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'posted', 'paid', 'void')),
  gross_minor    bigint      NOT NULL DEFAULT 0 CHECK (gross_minor >= 0),
  deduction_minor bigint     NOT NULL DEFAULT 0 CHECK (deduction_minor >= 0),
  tax_minor      bigint      NOT NULL DEFAULT 0 CHECK (tax_minor >= 0),
  net_minor      bigint      NOT NULL DEFAULT 0 CHECK (net_minor >= 0),
  post_date      date,
  post_entry_id  char(26),
  pay_date       date,
  pay_account_id char(26),
  pay_entry_id   char(26),
  void_entry_id  char(26),
  created_by     char(26)    NOT NULL REFERENCES users (id),
  created_at     timestamptz NOT NULL DEFAULT now(),
  posted_at      timestamptz,
  paid_at        timestamptz,
  voided_at      timestamptz,
  UNIQUE (id, tenant_id),
  FOREIGN KEY (post_entry_id, tenant_id)  REFERENCES journal_entries (id, tenant_id),
  FOREIGN KEY (pay_entry_id, tenant_id)   REFERENCES journal_entries (id, tenant_id),
  FOREIGN KEY (void_entry_id, tenant_id)  REFERENCES journal_entries (id, tenant_id),
  FOREIGN KEY (pay_account_id, tenant_id) REFERENCES accounts (id, tenant_id),
  CONSTRAINT totals_add_up CHECK (net_minor = gross_minor - deduction_minor AND tax_minor <= deduction_minor),
  CONSTRAINT status_shape CHECK (
    (status = 'draft'  AND post_entry_id IS NULL AND pay_entry_id IS NULL AND voided_at IS NULL) OR
    (status = 'posted' AND post_entry_id IS NOT NULL AND post_date IS NOT NULL AND pay_entry_id IS NULL AND voided_at IS NULL) OR
    (status = 'paid'   AND post_entry_id IS NOT NULL AND pay_entry_id IS NOT NULL AND pay_account_id IS NOT NULL AND pay_date IS NOT NULL AND voided_at IS NULL) OR
    (status = 'void'   AND voided_at IS NOT NULL AND pay_entry_id IS NULL))
);
CREATE UNIQUE INDEX payroll_runs_one_per_month ON payroll_runs (tenant_id, period) WHERE status <> 'void';

-- items = [{"id","kind":"earning|deduction","label","amount","tax":bool,"src":"structure|bonus|adjust"}]; the line totals always equal the items (CHECK-free: functions keep them in step, a test proves it).
CREATE TABLE payroll_lines (
  run_id          char(26)    NOT NULL,
  tenant_id       char(26)    NOT NULL REFERENCES tenants (id),
  employee_id     char(26)    NOT NULL,
  items           jsonb       NOT NULL,
  gross_minor     bigint      NOT NULL CHECK (gross_minor > 0),
  deduction_minor bigint      NOT NULL CHECK (deduction_minor >= 0),
  tax_minor       bigint      NOT NULL CHECK (tax_minor >= 0),
  net_minor       bigint      NOT NULL,
  PRIMARY KEY (run_id, employee_id),
  FOREIGN KEY (run_id, tenant_id)      REFERENCES payroll_runs (id, tenant_id),
  FOREIGN KEY (employee_id, tenant_id) REFERENCES payroll_employees (id, tenant_id),
  CONSTRAINT line_net CHECK (net_minor = gross_minor - deduction_minor AND net_minor >= 0 AND tax_minor <= deduction_minor)
);

DO $$
DECLARE t text;
BEGIN
  FOREACH t IN ARRAY ARRAY['payroll_employees', 'payroll_components', 'payroll_runs', 'payroll_lines'] LOOP
    EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', t);
    EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY', t);
    EXECUTE format('CREATE POLICY tenant_isolation ON %I USING (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin())) WITH CHECK (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()))', t);
    EXECUTE format('REVOKE ALL ON %I FROM jarvis_app_rw, jarvis_admin', t);
    EXECUTE format('GRANT SELECT ON %I TO jarvis_app_rw, jarvis_admin', t);
  END LOOP;
END $$;

-- Totals of an items array -> (gross, deductions, tax).  Malformed items make the whole result NULL -> callers refuse.
CREATE FUNCTION payroll_items_total(p_items jsonb) RETURNS TABLE (gross bigint, ded bigint, tax bigint)
LANGUAGE sql IMMUTABLE AS $$
  SELECT coalesce(sum((i ->> 'amount')::bigint) FILTER (WHERE i ->> 'kind' = 'earning'), 0)::bigint,
         coalesce(sum((i ->> 'amount')::bigint) FILTER (WHERE i ->> 'kind' = 'deduction'), 0)::bigint,
         coalesce(sum((i ->> 'amount')::bigint) FILTER (WHERE i ->> 'kind' = 'deduction' AND (i ->> 'tax')::boolean), 0)::bigint
  FROM jsonb_array_elements(p_items) i
$$;

-- A liability account the payroll needs that the standard chart lacks; created on first use under 2000.  p_key: salary_tax_payable | salary_deductions
CREATE FUNCTION payroll_account(p_tenant char(26), p_key text) RETURNS char(26)
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); id char(26); parent char(26); c integer := 2410;
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT a.id INTO id FROM accounts a WHERE a.tenant_id = p_tenant AND a.system_key = p_key;
  IF id IS NULL AND p_key IN ('salary_tax_payable', 'salary_deductions') THEN
    SELECT a.id INTO parent FROM accounts a WHERE a.tenant_id = p_tenant AND a.code = '2000' AND a.type = 'liability';
    IF parent IS NOT NULL THEN
      WHILE c < 2500 AND EXISTS (SELECT 1 FROM accounts a WHERE a.tenant_id = p_tenant AND a.code = c::text) LOOP c := c + 10; END LOOP;
      IF c < 2500 THEN
        id := credit_new_id();
        INSERT INTO accounts (id, tenant_id, code, name, name_bn, type, parent_id, postable, system_key)
        VALUES (id, p_tenant, c::text, CASE p_key WHEN 'salary_tax_payable' THEN 'Salary Income Tax Payable' ELSE 'Salary Deductions Payable' END,
                CASE p_key WHEN 'salary_tax_payable' THEN 'বেতনের উৎসে আয়কর পরিশোধযোগ্য' ELSE 'বেতন থেকে অন্যান্য কর্তন পরিশোধযোগ্য' END, 'liability', parent, true, p_key);
      END IF;
    END IF;
  END IF;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN id;
END $$;

-- Employee: create (unknown id) or change (known id).  result: ok | bad_input | bad_date | code_taken
CREATE FUNCTION payroll_employee_save(p_id char(26), p_tenant char(26), p_user char(26), p_code text, p_name text, p_designation text, p_join date, p_end date) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); found boolean;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  IF p_code IS NULL OR p_code !~ '^[A-Za-z0-9._-]{1,20}$' OR p_name IS NULL OR btrim(p_name) = '' THEN RETURN 'bad_input'; END IF;
  IF p_join IS NULL OR p_join < DATE '1990-01-01' OR p_join > (now() AT TIME ZONE 'Asia/Dhaka')::date + 366 OR (p_end IS NOT NULL AND p_end < p_join) THEN RETURN 'bad_date'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  IF EXISTS (SELECT 1 FROM payroll_employees WHERE tenant_id = p_tenant AND code = p_code AND id <> p_id) THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'code_taken'; END IF;
  SELECT true INTO found FROM payroll_employees WHERE id = p_id AND tenant_id = p_tenant;
  IF found THEN
    UPDATE payroll_employees SET code = p_code, name = left(btrim(p_name), 80), designation = left(nullif(btrim(coalesce(p_designation, '')), ''), 80), join_date = p_join, end_date = p_end WHERE id = p_id;
  ELSE
    IF EXISTS (SELECT 1 FROM payroll_employees WHERE id = p_id) THEN PERFORM set_config('app.bypass_rls', prev, true); RAISE EXCEPTION 'forbidden' USING ERRCODE = '42501'; END IF;
    INSERT INTO payroll_employees (id, tenant_id, code, name, designation, join_date, end_date, created_by) VALUES (p_id, p_tenant, p_code, left(btrim(p_name), 80), left(nullif(btrim(coalesce(p_designation, '')), ''), 80), p_join, p_end, p_user);
  END IF;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

CREATE FUNCTION payroll_employee_active(p_id char(26), p_tenant char(26), p_active boolean) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); n integer;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE payroll_employees SET active = coalesce(p_active, true) WHERE id = p_id AND tenant_id = p_tenant;
  GET DIAGNOSTICS n = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  IF n = 0 THEN RAISE EXCEPTION 'forbidden' USING ERRCODE = '42501'; END IF;
  RETURN 'ok';
END $$;

-- A structure line from a date on; the open one with the same kind+label ends the day before (history kept).  result: ok | bad_input | bad_date | overlap
CREATE FUNCTION payroll_component_save(p_id char(26), p_tenant char(26), p_user char(26), p_employee char(26), p_kind text, p_tax boolean, p_label text, p_amount bigint, p_from date) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), '');
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  IF p_kind IS NULL OR p_kind NOT IN ('earning', 'deduction') OR p_label IS NULL OR btrim(p_label) = '' OR p_amount IS NULL OR p_amount <= 0 OR p_amount > 99999999999 OR (coalesce(p_tax, false) AND p_kind <> 'deduction') THEN RETURN 'bad_input'; END IF;
  IF p_from IS NULL OR p_from < DATE '1990-01-01' OR p_from > (now() AT TIME ZONE 'Asia/Dhaka')::date + 366 THEN RETURN 'bad_date'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  IF NOT EXISTS (SELECT 1 FROM payroll_employees WHERE id = p_employee AND tenant_id = p_tenant) THEN PERFORM set_config('app.bypass_rls', prev, true); RAISE EXCEPTION 'forbidden' USING ERRCODE = '42501'; END IF;
  IF EXISTS (SELECT 1 FROM payroll_components WHERE employee_id = p_employee AND kind = p_kind AND label = left(btrim(p_label), 60) AND effective_from >= p_from) THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'overlap'; END IF;
  UPDATE payroll_components SET effective_to = p_from - 1 WHERE employee_id = p_employee AND kind = p_kind AND label = left(btrim(p_label), 60) AND effective_to IS NULL;
  INSERT INTO payroll_components (id, tenant_id, employee_id, kind, is_tax, label, amount_minor, effective_from, created_by) VALUES (p_id, p_tenant, p_employee, p_kind, coalesce(p_tax, false), left(btrim(p_label), 60), p_amount, p_from, p_user);
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

-- Stop a structure line after a date.  result: ok | bad_date | already_ended
CREATE FUNCTION payroll_component_end(p_id char(26), p_tenant char(26), p_to date) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); c payroll_components%ROWTYPE;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO c FROM payroll_components WHERE id = p_id AND tenant_id = p_tenant FOR UPDATE;
  IF c.id IS NULL THEN PERFORM set_config('app.bypass_rls', prev, true); RAISE EXCEPTION 'forbidden' USING ERRCODE = '42501'; END IF;
  IF c.effective_to IS NOT NULL THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'already_ended'; END IF;
  IF p_to IS NULL OR p_to < c.effective_from THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'bad_date'; END IF;
  UPDATE payroll_components SET effective_to = p_to WHERE id = p_id;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

-- Build a DRAFT month from the structure in force on the last day of the month, for every active employee employed during it who has an earning.
-- result: ok | bad_period | exists | nothing | negative_net (an employee's deductions exceed their earnings)
CREATE FUNCTION payroll_run_create(p_id char(26), p_tenant char(26), p_user char(26), p_period text) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); first_d date; last_d date; e record; items jsonb; t record; n integer := 0; g bigint := 0; d bigint := 0; x bigint := 0;
BEGIN
  PERFORM acct_open(p_tenant);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  IF p_period IS NULL OR p_period !~ '^20[0-9]{2}-(0[1-9]|1[0-2])$' OR p_period > to_char((now() AT TIME ZONE 'Asia/Dhaka')::date, 'YYYY-MM') THEN RETURN 'bad_period'; END IF;
  first_d := (p_period || '-01')::date; last_d := (first_d + interval '1 month - 1 day')::date;
  PERFORM set_config('app.bypass_rls', 'on', true);
  IF EXISTS (SELECT 1 FROM payroll_runs WHERE tenant_id = p_tenant AND period = p_period AND status <> 'void') THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'exists'; END IF;
  INSERT INTO payroll_runs (id, tenant_id, period, created_by) VALUES (p_id, p_tenant, p_period, p_user);
  FOR e IN SELECT * FROM payroll_employees WHERE tenant_id = p_tenant AND active AND join_date <= last_d AND (end_date IS NULL OR end_date >= first_d) ORDER BY code LOOP
    SELECT coalesce(jsonb_agg(jsonb_build_object('id', credit_new_id(), 'kind', c.kind, 'label', c.label, 'amount', c.amount_minor, 'tax', c.is_tax, 'src', 'structure') ORDER BY c.kind DESC, c.label), '[]'::jsonb) INTO items
      FROM payroll_components c WHERE c.employee_id = e.id AND c.effective_from <= last_d AND (c.effective_to IS NULL OR c.effective_to >= last_d);
    SELECT * INTO t FROM payroll_items_total(items);
    CONTINUE WHEN t.gross = 0;
    IF t.ded > t.gross THEN RAISE EXCEPTION 'negative_net' USING ERRCODE = 'P0001'; END IF;
    INSERT INTO payroll_lines (run_id, tenant_id, employee_id, items, gross_minor, deduction_minor, tax_minor, net_minor) VALUES (p_id, p_tenant, e.id, items, t.gross, t.ded, t.tax, t.gross - t.ded);
    n := n + 1; g := g + t.gross; d := d + t.ded; x := x + t.tax;
  END LOOP;
  IF n = 0 THEN RAISE EXCEPTION 'nothing' USING ERRCODE = 'P0001'; END IF;
  UPDATE payroll_runs SET gross_minor = g, deduction_minor = d, tax_minor = x, net_minor = g - d WHERE id = p_id;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
EXCEPTION WHEN SQLSTATE 'P0001' THEN
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN SQLERRM;
END $$;

-- Add a one-off bonus (earning) or deduction to an employee's line in a DRAFT run.  result: ok | bad_input | not_draft | no_line | negative_net
CREATE FUNCTION payroll_adjust_add(p_run char(26), p_tenant char(26), p_employee char(26), p_kind text, p_label text, p_amount bigint, p_tax boolean) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); r payroll_runs%ROWTYPE; l payroll_lines%ROWTYPE; new_items jsonb; t record;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_kind IS NULL OR p_kind NOT IN ('bonus', 'deduction') OR p_label IS NULL OR btrim(p_label) = '' OR p_amount IS NULL OR p_amount <= 0 OR p_amount > 99999999999 OR (coalesce(p_tax, false) AND p_kind <> 'deduction') THEN RETURN 'bad_input'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO r FROM payroll_runs WHERE id = p_run AND tenant_id = p_tenant FOR UPDATE;
  IF r.id IS NULL THEN PERFORM set_config('app.bypass_rls', prev, true); RAISE EXCEPTION 'forbidden' USING ERRCODE = '42501'; END IF;
  IF r.status <> 'draft' THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'not_draft'; END IF;
  SELECT * INTO l FROM payroll_lines WHERE run_id = p_run AND employee_id = p_employee;
  IF l.run_id IS NULL THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'no_line'; END IF;
  new_items := l.items || jsonb_build_array(jsonb_build_object('id', credit_new_id(), 'kind', CASE p_kind WHEN 'bonus' THEN 'earning' ELSE 'deduction' END, 'label', left(btrim(p_label), 60), 'amount', p_amount, 'tax', coalesce(p_tax, false), 'src', p_kind));
  SELECT * INTO t FROM payroll_items_total(new_items);
  IF t.ded > t.gross THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'negative_net'; END IF;
  UPDATE payroll_lines SET items = new_items, gross_minor = t.gross, deduction_minor = t.ded, tax_minor = t.tax, net_minor = t.gross - t.ded WHERE run_id = p_run AND employee_id = p_employee;
  PERFORM payroll_run_retotal(p_run);
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

-- Remove a one-off item (never a structure item) from a DRAFT run.  result: ok | not_draft | no_item | negative_net
CREATE FUNCTION payroll_adjust_remove(p_run char(26), p_tenant char(26), p_employee char(26), p_item text) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); r payroll_runs%ROWTYPE; l payroll_lines%ROWTYPE; new_items jsonb; t record;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO r FROM payroll_runs WHERE id = p_run AND tenant_id = p_tenant FOR UPDATE;
  IF r.id IS NULL THEN PERFORM set_config('app.bypass_rls', prev, true); RAISE EXCEPTION 'forbidden' USING ERRCODE = '42501'; END IF;
  IF r.status <> 'draft' THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'not_draft'; END IF;
  SELECT * INTO l FROM payroll_lines WHERE run_id = p_run AND employee_id = p_employee;
  IF l.run_id IS NULL OR NOT EXISTS (SELECT 1 FROM jsonb_array_elements(l.items) i WHERE i ->> 'id' = p_item AND i ->> 'src' <> 'structure') THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'no_item'; END IF;
  SELECT coalesce(jsonb_agg(i), '[]'::jsonb) INTO new_items FROM jsonb_array_elements(l.items) i WHERE i ->> 'id' <> p_item;
  SELECT * INTO t FROM payroll_items_total(new_items);
  IF t.ded > t.gross OR t.gross = 0 THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'negative_net'; END IF;
  UPDATE payroll_lines SET items = new_items, gross_minor = t.gross, deduction_minor = t.ded, tax_minor = t.tax, net_minor = t.gross - t.ded WHERE run_id = p_run AND employee_id = p_employee;
  PERFORM payroll_run_retotal(p_run);
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

-- internal: run totals = sum of its lines (callers hold the bypass flag)
CREATE FUNCTION payroll_run_retotal(p_run char(26)) RETURNS void
LANGUAGE sql AS $$
  UPDATE payroll_runs r SET gross_minor = s.g, deduction_minor = s.d, tax_minor = s.x, net_minor = s.g - s.d
  FROM (SELECT coalesce(sum(gross_minor), 0) AS g, coalesce(sum(deduction_minor), 0) AS d, coalesce(sum(tax_minor), 0) AS x FROM payroll_lines WHERE run_id = p_run) s WHERE r.id = p_run
$$;

-- Book the month.  p_date = the accounting date (not in the future, not before the month starts).
-- result: ok | not_draft | bad_date | no_system_account | <journal_post: locked | ...>
CREATE FUNCTION payroll_run_post(p_run char(26), p_tenant char(26), p_user char(26), p_entry char(26), p_date date) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); r payroll_runs%ROWTYPE; sal char(26); pay char(26); txa char(26); dedacc char(26); jl jsonb; jr record; other bigint;
BEGIN
  PERFORM acct_open(p_tenant);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO r FROM payroll_runs WHERE id = p_run AND tenant_id = p_tenant FOR UPDATE;
  PERFORM set_config('app.bypass_rls', prev, true);
  IF r.id IS NULL THEN RAISE EXCEPTION 'forbidden' USING ERRCODE = '42501'; END IF;
  IF r.status <> 'draft' THEN RETURN 'not_draft'; END IF;
  IF p_date IS NULL OR p_date > (now() AT TIME ZONE 'Asia/Dhaka')::date OR p_date < (r.period || '-01')::date THEN RETURN 'bad_date'; END IF;
  SELECT a.id INTO sal FROM accounts a WHERE a.tenant_id = p_tenant AND a.system_key = 'salaries' AND a.active;
  SELECT a.id INTO pay FROM accounts a WHERE a.tenant_id = p_tenant AND a.system_key = 'salary_payable' AND a.active;
  IF sal IS NULL OR pay IS NULL THEN RETURN 'no_system_account'; END IF;
  other := r.deduction_minor - r.tax_minor;
  IF r.tax_minor > 0 THEN txa := payroll_account(p_tenant, 'salary_tax_payable'); IF txa IS NULL THEN RETURN 'no_system_account'; END IF; END IF;
  IF other > 0 THEN dedacc := payroll_account(p_tenant, 'salary_deductions'); IF dedacc IS NULL THEN RETURN 'no_system_account'; END IF; END IF;
  jl := jsonb_build_array(jsonb_build_object('account_id', sal, 'debit_minor', r.gross_minor, 'credit_minor', 0));
  IF r.tax_minor > 0 THEN jl := jl || jsonb_build_array(jsonb_build_object('account_id', txa, 'debit_minor', 0, 'credit_minor', r.tax_minor)); END IF;
  IF other > 0 THEN jl := jl || jsonb_build_array(jsonb_build_object('account_id', dedacc, 'debit_minor', 0, 'credit_minor', other)); END IF;
  IF r.net_minor > 0 THEN jl := jl || jsonb_build_array(jsonb_build_object('account_id', pay, 'debit_minor', 0, 'credit_minor', r.net_minor)); END IF;
  SELECT * INTO jr FROM journal_post(p_entry, p_tenant, p_user, p_date, 'Payroll ' || r.period, 'system', 'payroll:' || p_run, jl);
  IF jr.result <> 'ok' THEN RETURN jr.result; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE payroll_runs SET status = 'posted', post_date = p_date, post_entry_id = p_entry, posted_at = now() WHERE id = p_run;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

-- Pay out the net salaries of a posted run from a cash / bank / wallet account.
-- result: ok | not_posted | bad_date | bad_account | no_system_account | nothing_to_pay | <journal_post: locked | ...>
CREATE FUNCTION payroll_run_pay(p_run char(26), p_tenant char(26), p_user char(26), p_entry char(26), p_date date, p_account char(26)) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); r payroll_runs%ROWTYPE; pay char(26); jr record; cash accounts%ROWTYPE;
BEGIN
  PERFORM acct_open(p_tenant);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO r FROM payroll_runs WHERE id = p_run AND tenant_id = p_tenant FOR UPDATE;
  SELECT * INTO cash FROM accounts WHERE id = p_account AND tenant_id = p_tenant AND active AND cash_account;
  PERFORM set_config('app.bypass_rls', prev, true);
  IF r.id IS NULL THEN RAISE EXCEPTION 'forbidden' USING ERRCODE = '42501'; END IF;
  IF r.status <> 'posted' THEN RETURN 'not_posted'; END IF;
  IF p_date IS NULL OR p_date > (now() AT TIME ZONE 'Asia/Dhaka')::date OR p_date < r.post_date THEN RETURN 'bad_date'; END IF;
  IF cash.id IS NULL THEN RETURN 'bad_account'; END IF;
  IF r.net_minor <= 0 THEN RETURN 'nothing_to_pay'; END IF;
  SELECT a.id INTO pay FROM accounts a WHERE a.tenant_id = p_tenant AND a.system_key = 'salary_payable' AND a.active;
  IF pay IS NULL THEN RETURN 'no_system_account'; END IF;
  SELECT * INTO jr FROM journal_post(p_entry, p_tenant, p_user, p_date, 'Salaries paid ' || r.period, 'system', 'payroll-pay:' || p_run,
    jsonb_build_array(jsonb_build_object('account_id', pay, 'debit_minor', r.net_minor, 'credit_minor', 0), jsonb_build_object('account_id', p_account, 'debit_minor', 0, 'credit_minor', r.net_minor)));
  IF jr.result <> 'ok' THEN RETURN jr.result; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE payroll_runs SET status = 'paid', pay_date = p_date, pay_account_id = p_account, pay_entry_id = p_entry, paid_at = now() WHERE id = p_run;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

-- Cancel a draft (no entry) or a posted-but-unpaid run (reversing entry).  A paid run cannot be cancelled here.
-- result: ok | paid | already_void | <journal_reverse: locked | bad_date ...>
CREATE FUNCTION payroll_run_void(p_run char(26), p_tenant char(26), p_user char(26), p_new_entry char(26), p_date date) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); r payroll_runs%ROWTYPE; res text;
BEGIN
  PERFORM acct_open(p_tenant);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO r FROM payroll_runs WHERE id = p_run AND tenant_id = p_tenant FOR UPDATE;
  PERFORM set_config('app.bypass_rls', prev, true);
  IF r.id IS NULL THEN RAISE EXCEPTION 'forbidden' USING ERRCODE = '42501'; END IF;
  IF r.status = 'void' THEN RETURN 'already_void'; END IF;
  IF r.status = 'paid' THEN RETURN 'paid'; END IF;
  IF r.status = 'posted' THEN
    res := journal_reverse(p_new_entry, r.post_entry_id, p_user, p_date, 'Payroll ' || r.period || ' cancelled');
    IF res <> 'ok' THEN RETURN res; END IF;
  END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE payroll_runs SET status = 'void', voided_at = now(), void_entry_id = CASE WHEN r.status = 'posted' THEN p_new_entry END WHERE id = p_run;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

REVOKE ALL ON FUNCTION payroll_items_total(jsonb), payroll_account(char, text), payroll_run_retotal(char), payroll_employee_save(char, char, char, text, text, text, date, date), payroll_employee_active(char, char, boolean),
  payroll_component_save(char, char, char, char, text, boolean, text, bigint, date), payroll_component_end(char, char, date), payroll_run_create(char, char, char, text), payroll_adjust_add(char, char, char, text, text, bigint, boolean),
  payroll_adjust_remove(char, char, char, text), payroll_run_post(char, char, char, char, date), payroll_run_pay(char, char, char, char, date, char), payroll_run_void(char, char, char, char, date) FROM PUBLIC, jarvis_app_rw, jarvis_admin;
GRANT EXECUTE ON FUNCTION payroll_employee_save(char, char, char, text, text, text, date, date), payroll_employee_active(char, char, boolean),
  payroll_component_save(char, char, char, char, text, boolean, text, bigint, date), payroll_component_end(char, char, date), payroll_run_create(char, char, char, text), payroll_adjust_add(char, char, char, text, text, bigint, boolean),
  payroll_adjust_remove(char, char, char, text), payroll_run_post(char, char, char, char, date), payroll_run_pay(char, char, char, char, date, char), payroll_run_void(char, char, char, char, date) TO jarvis_app_rw, jarvis_admin;
