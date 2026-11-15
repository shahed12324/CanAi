-- Phase 17b: tax withheld at source (VDS / TDS) and advance tax (AIT).
--  * paying a supplier bill: we may withhold VDS / TDS - the supplier gets less, the withheld tax is a liability to the government (account tax_payable) until we deposit it
--  * receiving an invoice payment: a customer may withhold from us - that is an advance-tax asset (account tax_advance), not income lost
--  * depositing withheld tax to the treasury: one posting for all undeposited items of a kind
--  * advance income tax (AIT) paid directly: an asset (tax_advance)
-- RATES ARE NOT CODE: rules (kind/code/rate/dates) are data the person keeps; the amount withheld is what the person confirms. NBR rules change and differ by payment type.

CREATE TABLE tax_rules (
  id             char(26)    PRIMARY KEY,
  tenant_id      char(26)    NOT NULL REFERENCES tenants (id),
  kind           text        NOT NULL CHECK (kind IN ('vds', 'tds')),
  code           varchar(20) NOT NULL CHECK (code ~ '^[A-Za-z0-9._-]{1,20}$'),
  label          varchar(80) NOT NULL CHECK (btrim(label) <> ''),
  rate_bp        integer     NOT NULL CHECK (rate_bp BETWEEN 0 AND 10000),
  effective_from date        NOT NULL,
  effective_to   date,
  created_by     char(26)    NOT NULL REFERENCES users (id),
  created_at     timestamptz NOT NULL DEFAULT now(),
  UNIQUE (tenant_id, kind, code, effective_from),
  CONSTRAINT rule_range CHECK (effective_to IS NULL OR effective_to >= effective_from)
);
CREATE UNIQUE INDEX tax_rules_one_open ON tax_rules (tenant_id, kind, code) WHERE effective_to IS NULL;

CREATE TABLE tax_deposits (
  id           char(26)    PRIMARY KEY,
  tenant_id    char(26)    NOT NULL REFERENCES tenants (id),
  kind         text        NOT NULL CHECK (kind IN ('vds', 'tds')),
  deposit_date date        NOT NULL,
  amount_minor bigint      NOT NULL CHECK (amount_minor > 0),
  account_id   char(26)    NOT NULL,
  challan_ref  varchar(60),
  entry_id     char(26)    NOT NULL,
  created_by   char(26)    NOT NULL REFERENCES users (id),
  created_at   timestamptz NOT NULL DEFAULT now(),
  UNIQUE (id, tenant_id),
  FOREIGN KEY (account_id, tenant_id) REFERENCES accounts (id, tenant_id),
  FOREIGN KEY (entry_id, tenant_id)   REFERENCES journal_entries (id, tenant_id)
);

CREATE TABLE tax_withholdings (
  id           char(26)    PRIMARY KEY,
  tenant_id    char(26)    NOT NULL REFERENCES tenants (id),
  direction    text        NOT NULL CHECK (direction IN ('deducted', 'suffered')),   -- deducted = we withheld from a supplier; suffered = a customer withheld from us
  kind         text        NOT NULL CHECK (kind IN ('vds', 'tds')),
  rule_code    varchar(20),
  base_minor   bigint      NOT NULL CHECK (base_minor > 0),
  rate_bp      integer     NOT NULL CHECK (rate_bp BETWEEN 0 AND 10000),
  amount_minor bigint      NOT NULL CHECK (amount_minor > 0),
  tax_date     date        NOT NULL,
  doc_id       char(26)    NOT NULL,
  payment_id   char(26)    NOT NULL,
  party_id     char(26),
  cert_no      varchar(40),
  deposit_id   char(26),
  created_by   char(26)    NOT NULL REFERENCES users (id),
  created_at   timestamptz NOT NULL DEFAULT now(),
  UNIQUE (id, tenant_id),
  FOREIGN KEY (doc_id, tenant_id)     REFERENCES acct_docs (id, tenant_id),
  FOREIGN KEY (payment_id, tenant_id) REFERENCES acct_payments (id, tenant_id),
  FOREIGN KEY (deposit_id, tenant_id) REFERENCES tax_deposits (id, tenant_id),
  CONSTRAINT only_deducted_is_deposited CHECK (direction = 'deducted' OR deposit_id IS NULL)
);
CREATE INDEX tax_withholdings_open ON tax_withholdings (tenant_id, kind, tax_date) WHERE deposit_id IS NULL AND direction = 'deducted';
CREATE INDEX tax_withholdings_payment ON tax_withholdings (payment_id);

CREATE TABLE tax_advances (
  id           char(26)    PRIMARY KEY,
  tenant_id    char(26)    NOT NULL REFERENCES tenants (id),
  kind         text        NOT NULL CHECK (kind IN ('ait')),
  tax_date     date        NOT NULL,
  amount_minor bigint      NOT NULL CHECK (amount_minor > 0),
  account_id   char(26)    NOT NULL,
  reference    varchar(60),
  note         varchar(200),
  entry_id     char(26)    NOT NULL,
  void_entry_id char(26),
  voided_at    timestamptz,
  created_by   char(26)    NOT NULL REFERENCES users (id),
  created_at   timestamptz NOT NULL DEFAULT now(),
  UNIQUE (id, tenant_id),
  FOREIGN KEY (account_id, tenant_id)    REFERENCES accounts (id, tenant_id),
  FOREIGN KEY (entry_id, tenant_id)      REFERENCES journal_entries (id, tenant_id),
  FOREIGN KEY (void_entry_id, tenant_id) REFERENCES journal_entries (id, tenant_id),
  CONSTRAINT advance_void_rule CHECK ((voided_at IS NULL) = (void_entry_id IS NULL))
);

DO $$
DECLARE t text;
BEGIN
  FOREACH t IN ARRAY ARRAY['tax_rules', 'tax_deposits', 'tax_withholdings', 'tax_advances'] LOOP
    EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', t);
    EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY', t);
    EXECUTE format('CREATE POLICY tenant_isolation ON %I USING (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin())) WITH CHECK (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()))', t);
    EXECUTE format('REVOKE ALL ON %I FROM jarvis_app_rw, jarvis_admin', t);
    EXECUTE format('GRANT SELECT ON %I TO jarvis_app_rw, jarvis_admin', t);
  END LOOP;
END $$;

-- A payment that carries withheld tax already deposited cannot be cancelled (the deposit would no longer match the books).
CREATE FUNCTION tax_guard_payment_void() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF NEW.voided_at IS NOT NULL AND OLD.voided_at IS NULL AND EXISTS (SELECT 1 FROM tax_withholdings w WHERE w.payment_id = OLD.id AND w.deposit_id IS NOT NULL) THEN
    RAISE EXCEPTION 'withheld_tax_deposited' USING ERRCODE = '23514';
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER tax_guard_payment_void BEFORE UPDATE OF voided_at ON acct_payments FOR EACH ROW EXECUTE FUNCTION tax_guard_payment_void();

-- ---------------------------------------------------------------------------------------------------------------------
-- The advance-tax asset account (system key tax_advance), created on first use under Current Assets with a free code. Internal: not granted.
CREATE FUNCTION tax_advance_account(p_tenant char(26)) RETURNS char(26)
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); id char(26); parent char(26); c integer := 1410;
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT a.id INTO id FROM accounts a WHERE a.tenant_id = p_tenant AND a.system_key = 'tax_advance';
  IF id IS NULL THEN
    SELECT a.id INTO parent FROM accounts a WHERE a.tenant_id = p_tenant AND a.code = '1100' AND a.type = 'asset';
    IF parent IS NOT NULL THEN
      WHILE c < 1500 AND EXISTS (SELECT 1 FROM accounts a WHERE a.tenant_id = p_tenant AND a.code = c::text) LOOP c := c + 10; END LOOP;
      IF c < 1500 THEN
        id := credit_new_id();
        INSERT INTO accounts (id, tenant_id, code, name, name_bn, type, parent_id, postable, system_key) VALUES (id, p_tenant, c::text, 'Advance Income Tax (AIT / TDS / VDS suffered)', 'অগ্রিম আয়কর (AIT/উৎসে কাটা)', 'asset', parent, true, 'tax_advance');
      END IF;
    END IF;
  END IF;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN id;
END $$;

-- A rule from a date on; the open one of the same kind+code ends the day before. result: ok | bad_input | bad_date | overlap
CREATE FUNCTION tax_rule_save(p_id char(26), p_tenant char(26), p_user char(26), p_kind text, p_code text, p_label text, p_bp integer, p_from date) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), '');
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  IF p_kind NOT IN ('vds', 'tds') OR p_code IS NULL OR p_code !~ '^[A-Za-z0-9._-]{1,20}$' OR p_label IS NULL OR btrim(p_label) = '' OR p_bp IS NULL OR p_bp < 0 OR p_bp > 10000 THEN RETURN 'bad_input'; END IF;
  IF p_from IS NULL OR p_from < DATE '2012-07-01' OR p_from > (now() AT TIME ZONE 'Asia/Dhaka')::date + 366 THEN RETURN 'bad_date'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  IF EXISTS (SELECT 1 FROM tax_rules WHERE tenant_id = p_tenant AND kind = p_kind AND code = p_code AND effective_from >= p_from) THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'overlap'; END IF;
  UPDATE tax_rules SET effective_to = p_from - 1 WHERE tenant_id = p_tenant AND kind = p_kind AND code = p_code AND effective_to IS NULL;
  INSERT INTO tax_rules (id, tenant_id, kind, code, label, rate_bp, effective_from, created_by) VALUES (p_id, p_tenant, p_kind, p_code, left(btrim(p_label), 80), p_bp, p_from, p_user);
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

-- Pay a supplier bill / receive an invoice payment WITH tax withheld.
-- p_gross = what is settled against the document; p_items = [{"kind":"vds|tds","rule_code","base_minor","rate_bp","amount_minor","cert_no"}] (1..5); cash moves gross - withheld.
-- bill:    Dr payable gross / Cr cash (gross - W) / Cr tax_payable W        invoice: Dr cash (gross - W) / Dr tax_advance W / Cr receivable gross
-- result: ok | bad_items | bad_amount | overpay | not_payable | bad_account | no_system_account | <journal_post result: locked | bad_date ...>
CREATE FUNCTION tax_settle(p_payment char(26), p_entry char(26), p_doc char(26), p_user char(26), p_date date, p_gross bigint, p_account char(26), p_note text, p_items jsonb) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); d acct_docs%ROWTYPE; cash accounts%ROWTYPE; ctl char(26); tx char(26); jr record; jl jsonb; w bigint := 0; it jsonb; n integer; dir text; ex bigint;
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO d FROM acct_docs WHERE id = p_doc FOR UPDATE;
  PERFORM set_config('app.bypass_rls', prev, true);
  IF d.id IS NULL THEN RAISE EXCEPTION 'forbidden' USING ERRCODE = '42501'; END IF;
  PERFORM acct_open(d.tenant_id);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  IF d.kind = 'expense' OR d.status <> 'open' THEN RETURN 'not_payable'; END IF;
  IF p_gross IS NULL OR p_gross <= 0 THEN RETURN 'bad_amount'; END IF;
  IF p_gross > d.total_minor - d.paid_minor THEN RETURN 'overpay'; END IF;
  IF p_items IS NULL OR jsonb_typeof(p_items) <> 'array' THEN RETURN 'bad_items'; END IF;
  n := jsonb_array_length(p_items);
  IF n < 1 OR n > 5 THEN RETURN 'bad_items'; END IF;
  FOR it IN SELECT * FROM jsonb_array_elements(p_items) LOOP
    BEGIN
      IF coalesce(it ->> 'kind', '') NOT IN ('vds', 'tds') OR coalesce((it ->> 'amount_minor')::bigint, 0) <= 0 OR coalesce((it ->> 'base_minor')::bigint, 0) <= 0 OR coalesce((it ->> 'rate_bp')::integer, -1) NOT BETWEEN 0 AND 10000
         OR length(coalesce(it ->> 'rule_code', '')) > 20 OR length(coalesce(it ->> 'cert_no', '')) > 40 THEN RETURN 'bad_items'; END IF;
      w := w + (it ->> 'amount_minor')::bigint;
    EXCEPTION WHEN others THEN RETURN 'bad_items';
    END;
  END LOOP;
  IF w >= p_gross THEN RETURN 'bad_items'; END IF;                                 -- some cash must move
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO cash FROM accounts WHERE id = p_account AND tenant_id = d.tenant_id AND active AND cash_account;
  IF NOT FOUND THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'bad_account'; END IF;
  SELECT id INTO ctl FROM accounts WHERE tenant_id = d.tenant_id AND system_key = CASE d.kind WHEN 'invoice' THEN 'receivable' ELSE 'payable' END;
  IF d.kind = 'invoice' THEN tx := tax_advance_account(d.tenant_id); ELSE SELECT id INTO tx FROM accounts WHERE tenant_id = d.tenant_id AND system_key = 'tax_payable' AND active AND postable; END IF;
  PERFORM set_config('app.bypass_rls', prev, true);
  IF ctl IS NULL OR tx IS NULL THEN RETURN 'no_system_account'; END IF;
  dir := CASE d.kind WHEN 'invoice' THEN 'suffered' ELSE 'deducted' END;
  jl := CASE d.kind
    WHEN 'invoice' THEN jsonb_build_array(jsonb_build_object('account_id', p_account, 'debit_minor', p_gross - w, 'credit_minor', 0), jsonb_build_object('account_id', tx, 'debit_minor', w, 'credit_minor', 0), jsonb_build_object('account_id', ctl, 'debit_minor', 0, 'credit_minor', p_gross))
    ELSE jsonb_build_array(jsonb_build_object('account_id', ctl, 'debit_minor', p_gross, 'credit_minor', 0), jsonb_build_object('account_id', p_account, 'debit_minor', 0, 'credit_minor', p_gross - w), jsonb_build_object('account_id', tx, 'debit_minor', 0, 'credit_minor', w)) END;
  SELECT * INTO jr FROM journal_post(p_entry, d.tenant_id, p_user, p_date, CASE d.kind WHEN 'invoice' THEN 'Receipt (tax withheld) for ' ELSE 'Payment (tax withheld) of ' END || d.number, 'payment', d.number, jl);
  IF jr.result <> 'ok' THEN RETURN jr.result; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  INSERT INTO acct_payments (id, tenant_id, doc_id, pay_date, amount_minor, account_id, note, entry_id, created_by) VALUES (p_payment, d.tenant_id, p_doc, p_date, p_gross, p_account, left(p_note, 200), p_entry, p_user);
  UPDATE acct_docs SET paid_minor = paid_minor + p_gross, status = CASE WHEN paid_minor + p_gross = total_minor THEN 'paid' ELSE 'open' END WHERE id = p_doc;
  FOR it IN SELECT * FROM jsonb_array_elements(p_items) LOOP
    INSERT INTO tax_withholdings (id, tenant_id, direction, kind, rule_code, base_minor, rate_bp, amount_minor, tax_date, doc_id, payment_id, party_id, cert_no, created_by)
      VALUES (credit_new_id(), d.tenant_id, dir, it ->> 'kind', nullif(it ->> 'rule_code', ''), (it ->> 'base_minor')::bigint, (it ->> 'rate_bp')::integer, (it ->> 'amount_minor')::bigint, p_date, p_doc, p_payment, d.party_id, nullif(btrim(coalesce(it ->> 'cert_no', '')), ''), p_user);
  END LOOP;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

-- Record the certificate number (Mushak-6.6 / TDS certificate) of one withholding. result: ok | bad_cert
CREATE FUNCTION tax_cert_set(p_id char(26), p_user char(26), p_cert text) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); w tax_withholdings%ROWTYPE; c text := nullif(btrim(coalesce(p_cert, '')), '');
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO w FROM tax_withholdings WHERE id = p_id;
  PERFORM set_config('app.bypass_rls', prev, true);
  IF w.id IS NULL THEN RAISE EXCEPTION 'forbidden' USING ERRCODE = '42501'; END IF;
  PERFORM credit_authorize(w.tenant_id, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  IF c IS NOT NULL AND length(c) > 40 THEN RETURN 'bad_cert'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE tax_withholdings SET cert_no = c WHERE id = p_id;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

-- Deposit all undeposited withheld tax of one kind dated up to p_upto: Dr tax_payable / Cr bank. Items whose payment was cancelled are not included.
-- result: ok | bad_kind | nothing | bad_account | bad_date | no_system_account | <journal_post result>.   amount = what was deposited
CREATE FUNCTION tax_deposit(p_id char(26), p_entry char(26), p_tenant char(26), p_user char(26), p_kind text, p_date date, p_upto date, p_account char(26), p_challan text) RETURNS TABLE (result text, amount_minor bigint)
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); cash accounts%ROWTYPE; tx char(26); tot bigint; last date; jr record; ch text := nullif(btrim(coalesce(p_challan, '')), '');
BEGIN
  PERFORM acct_open(p_tenant);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  IF p_kind NOT IN ('vds', 'tds') THEN RETURN QUERY SELECT 'bad_kind'::text, 0::bigint; RETURN; END IF;
  IF p_date IS NULL OR p_upto IS NULL OR p_date > (now() AT TIME ZONE 'Asia/Dhaka')::date + 1 OR ch IS NOT NULL AND length(ch) > 60 THEN RETURN QUERY SELECT 'bad_date'::text, 0::bigint; RETURN; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO cash FROM accounts WHERE id = p_account AND tenant_id = p_tenant AND active AND cash_account;
  IF NOT FOUND THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN QUERY SELECT 'bad_account'::text, 0::bigint; RETURN; END IF;
  SELECT id INTO tx FROM accounts WHERE tenant_id = p_tenant AND system_key = 'tax_payable' AND active AND postable;
  IF tx IS NULL THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN QUERY SELECT 'no_system_account'::text, 0::bigint; RETURN; END IF;
  SELECT coalesce(sum(w.amount_minor), 0), max(w.tax_date) INTO tot, last FROM tax_withholdings w JOIN acct_payments p ON p.id = w.payment_id AND p.tenant_id = w.tenant_id
    WHERE w.tenant_id = p_tenant AND w.direction = 'deducted' AND w.kind = p_kind AND w.deposit_id IS NULL AND w.tax_date <= p_upto AND p.voided_at IS NULL;
  IF tot = 0 THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN QUERY SELECT 'nothing'::text, 0::bigint; RETURN; END IF;
  IF p_date < last THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN QUERY SELECT 'bad_date'::text, 0::bigint; RETURN; END IF;     -- cannot deposit before the tax was withheld
  PERFORM set_config('app.bypass_rls', prev, true);
  SELECT * INTO jr FROM journal_post(p_entry, p_tenant, p_user, p_date, 'Deposit of withheld ' || upper(p_kind), 'system', 'taxdep:' || p_id,
    jsonb_build_array(jsonb_build_object('account_id', tx, 'debit_minor', tot, 'credit_minor', 0), jsonb_build_object('account_id', p_account, 'debit_minor', 0, 'credit_minor', tot)));
  IF jr.result <> 'ok' THEN RETURN QUERY SELECT jr.result, 0::bigint; RETURN; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  INSERT INTO tax_deposits (id, tenant_id, kind, deposit_date, amount_minor, account_id, challan_ref, entry_id, created_by) VALUES (p_id, p_tenant, p_kind, p_date, tot, p_account, left(ch, 60), p_entry, p_user);
  UPDATE tax_withholdings w SET deposit_id = p_id FROM acct_payments p
    WHERE p.id = w.payment_id AND p.tenant_id = w.tenant_id AND w.tenant_id = p_tenant AND w.direction = 'deducted' AND w.kind = p_kind AND w.deposit_id IS NULL AND w.tax_date <= p_upto AND p.voided_at IS NULL;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN QUERY SELECT 'ok'::text, tot;
END $$;

-- Advance income tax paid directly (Dr tax_advance / Cr cash-bank). result: ok | bad_amount | bad_account | bad_kind | no_system_account | <journal_post result>
CREATE FUNCTION tax_advance_pay(p_id char(26), p_entry char(26), p_tenant char(26), p_user char(26), p_kind text, p_date date, p_amount bigint, p_account char(26), p_ref text, p_note text) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); cash accounts%ROWTYPE; tx char(26); jr record;
BEGIN
  PERFORM acct_open(p_tenant);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  IF p_kind IS DISTINCT FROM 'ait' THEN RETURN 'bad_kind'; END IF;
  IF p_amount IS NULL OR p_amount <= 0 THEN RETURN 'bad_amount'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO cash FROM accounts WHERE id = p_account AND tenant_id = p_tenant AND active AND cash_account;
  PERFORM set_config('app.bypass_rls', prev, true);
  IF cash.id IS NULL THEN RETURN 'bad_account'; END IF;
  tx := tax_advance_account(p_tenant);
  IF tx IS NULL THEN RETURN 'no_system_account'; END IF;
  SELECT * INTO jr FROM journal_post(p_entry, p_tenant, p_user, p_date, 'Advance income tax (AIT) paid', 'system', 'ait:' || p_id,
    jsonb_build_array(jsonb_build_object('account_id', tx, 'debit_minor', p_amount, 'credit_minor', 0), jsonb_build_object('account_id', p_account, 'debit_minor', 0, 'credit_minor', p_amount)));
  IF jr.result <> 'ok' THEN RETURN jr.result; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  INSERT INTO tax_advances (id, tenant_id, kind, tax_date, amount_minor, account_id, reference, note, entry_id, created_by) VALUES (p_id, p_tenant, p_kind, p_date, p_amount, p_account, left(nullif(btrim(coalesce(p_ref, '')), ''), 60), left(p_note, 200), p_entry, p_user);
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

-- Cancel an AIT record with a reversing entry. result: ok | already_void | locked | bad_date
CREATE FUNCTION tax_advance_void(p_new_entry char(26), p_id char(26), p_user char(26), p_date date) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); a tax_advances%ROWTYPE; r text;
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO a FROM tax_advances WHERE id = p_id FOR UPDATE;
  PERFORM set_config('app.bypass_rls', prev, true);
  IF a.id IS NULL THEN RAISE EXCEPTION 'forbidden' USING ERRCODE = '42501'; END IF;
  PERFORM acct_open(a.tenant_id);
  IF a.voided_at IS NOT NULL THEN RETURN 'already_void'; END IF;
  r := journal_reverse(p_new_entry, a.entry_id, p_user, p_date, 'Cancelled advance tax record');
  IF r <> 'ok' THEN RETURN r; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE tax_advances SET voided_at = now(), void_entry_id = p_new_entry WHERE id = p_id;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

REVOKE ALL ON FUNCTION tax_advance_account(char), tax_rule_save(char, char, char, text, text, text, integer, date), tax_settle(char, char, char, char, date, bigint, char, text, jsonb), tax_cert_set(char, char, text),
  tax_deposit(char, char, char, char, text, date, date, char, text), tax_advance_pay(char, char, char, char, text, date, bigint, char, text, text), tax_advance_void(char, char, char, date) FROM PUBLIC, jarvis_app_rw, jarvis_admin;
GRANT EXECUTE ON FUNCTION tax_rule_save(char, char, char, text, text, text, integer, date), tax_settle(char, char, char, char, date, bigint, char, text, jsonb), tax_cert_set(char, char, text),
  tax_deposit(char, char, char, char, text, date, date, char, text), tax_advance_pay(char, char, char, char, text, date, bigint, char, text, text), tax_advance_void(char, char, char, date) TO jarvis_app_rw, jarvis_admin;
