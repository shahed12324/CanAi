-- Phase 18c: how each employee is paid (for the salary file). Sensitive: bank account / wallet numbers are kept ONLY here, never copied into runs, journal, logs or pay slips,
-- and they appear in the one place they are needed - the salary file the person downloads. The app role can read the table but only the function writes it.

CREATE TABLE payroll_pay_details (
  employee_id char(26)     PRIMARY KEY,
  tenant_id   char(26)     NOT NULL REFERENCES tenants (id),
  method      text         NOT NULL CHECK (method IN ('bank', 'bkash', 'nagad', 'cash')),
  bank_name   varchar(80),
  branch      varchar(80),
  routing_no  char(9)      CHECK (routing_no IS NULL OR routing_no ~ '^[0-9]{9}$'),
  account_no  varchar(30)  CHECK (account_no IS NULL OR account_no ~ '^[0-9][0-9-]{4,29}$'),
  wallet_no   char(11)     CHECK (wallet_no IS NULL OR wallet_no ~ '^01[3-9][0-9]{8}$'),
  updated_by  char(26)     NOT NULL REFERENCES users (id),
  updated_at  timestamptz  NOT NULL DEFAULT now(),
  FOREIGN KEY (employee_id, tenant_id) REFERENCES payroll_employees (id, tenant_id),
  CONSTRAINT method_shape CHECK (
    (method = 'bank'  AND btrim(coalesce(bank_name, '')) <> '' AND account_no IS NOT NULL AND wallet_no IS NULL) OR
    (method IN ('bkash', 'nagad') AND wallet_no IS NOT NULL AND bank_name IS NULL AND branch IS NULL AND routing_no IS NULL AND account_no IS NULL) OR
    (method = 'cash'  AND bank_name IS NULL AND branch IS NULL AND routing_no IS NULL AND account_no IS NULL AND wallet_no IS NULL))
);

ALTER TABLE payroll_pay_details ENABLE ROW LEVEL SECURITY;
ALTER TABLE payroll_pay_details FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON payroll_pay_details USING (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin())) WITH CHECK (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()));
REVOKE ALL ON payroll_pay_details FROM jarvis_app_rw, jarvis_admin;
GRANT SELECT ON payroll_pay_details TO jarvis_app_rw, jarvis_admin;

-- Set (replace) how an employee is paid.  Spaces and a +88 / 88 prefix on a wallet number are removed; spaces in an account number too.
-- result: ok | bad_input
CREATE FUNCTION payroll_pay_details_save(p_tenant char(26), p_user char(26), p_employee char(26), p_method text, p_bank text, p_branch text, p_routing text, p_account text, p_wallet text) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); bank text := left(nullif(btrim(coalesce(p_bank, '')), ''), 80); br text := left(nullif(btrim(coalesce(p_branch, '')), ''), 80);
        rt text := nullif(regexp_replace(coalesce(p_routing, ''), '[[:space:]-]', '', 'g'), ''); ac text := nullif(regexp_replace(coalesce(p_account, ''), '[[:space:]]', '', 'g'), '');
        wl text := nullif(regexp_replace(regexp_replace(coalesce(p_wallet, ''), '[[:space:]-]', '', 'g'), '^\+?88', ''), '');
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  IF p_method IS NULL OR p_method NOT IN ('bank', 'bkash', 'nagad', 'cash') THEN RETURN 'bad_input'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  IF NOT EXISTS (SELECT 1 FROM payroll_employees WHERE id = p_employee AND tenant_id = p_tenant) THEN PERFORM set_config('app.bypass_rls', prev, true); RAISE EXCEPTION 'forbidden' USING ERRCODE = '42501'; END IF;
  PERFORM set_config('app.bypass_rls', prev, true);
  IF p_method = 'bank' THEN
    IF bank IS NULL OR ac IS NULL OR ac !~ '^[0-9][0-9-]{4,29}$' OR (rt IS NOT NULL AND rt !~ '^[0-9]{9}$') OR wl IS NOT NULL THEN RETURN 'bad_input'; END IF;
  ELSIF p_method IN ('bkash', 'nagad') THEN
    IF wl IS NULL OR wl !~ '^01[3-9][0-9]{8}$' OR bank IS NOT NULL OR br IS NOT NULL OR rt IS NOT NULL OR ac IS NOT NULL THEN RETURN 'bad_input'; END IF;
  ELSE
    IF bank IS NOT NULL OR br IS NOT NULL OR rt IS NOT NULL OR ac IS NOT NULL OR wl IS NOT NULL THEN RETURN 'bad_input'; END IF;
  END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  INSERT INTO payroll_pay_details (employee_id, tenant_id, method, bank_name, branch, routing_no, account_no, wallet_no, updated_by)
  VALUES (p_employee, p_tenant, p_method, bank, br, rt, ac, wl, p_user)
  ON CONFLICT (employee_id) DO UPDATE SET method = EXCLUDED.method, bank_name = EXCLUDED.bank_name, branch = EXCLUDED.branch, routing_no = EXCLUDED.routing_no, account_no = EXCLUDED.account_no,
    wallet_no = EXCLUDED.wallet_no, updated_by = p_user, updated_at = now();
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

REVOKE ALL ON FUNCTION payroll_pay_details_save(char, char, char, text, text, text, text, text, text) FROM PUBLIC, jarvis_app_rw, jarvis_admin;
GRANT EXECUTE ON FUNCTION payroll_pay_details_save(char, char, char, text, text, text, text, text, text) TO jarvis_app_rw, jarvis_admin;
