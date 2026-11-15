-- Phase 15b: parties, invoices (money owed to us), bills (money we owe), direct expenses and payments — all posted through journal_post (15a),
-- so the double-entry, locked-period and append-only rules apply to them automatically.
--   * invoice: Dr Receivable / Cr Income (+ Cr VAT Output)          * bill: Dr Expense|Asset (+ Dr VAT Input) / Cr Payable
--   * expense (paid now): Dr Expense|Asset (+ VAT Input) / Cr Cash|Bank  * payment: Dr Cash|Bank / Cr Receivable  (invoice)  or  Dr Payable / Cr Cash|Bank  (bill)
-- Documents are never edited or deleted: they are voided by a reversing entry. The Receivable / Payable accounts always equal the open documents (property-tested).

ALTER TABLE accounts ADD COLUMN cash_account boolean NOT NULL DEFAULT false;     -- money can be received into / paid from these (cash, bank, bKash ...)
UPDATE accounts SET cash_account = true WHERE system_key IN ('cash', 'bank', 'mobile_wallet');
CREATE FUNCTION accounts_cash_default() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN IF NEW.system_key IN ('cash', 'bank', 'mobile_wallet') THEN NEW.cash_account := true; END IF; RETURN NEW; END $$;
CREATE TRIGGER accounts_cash_default BEFORE INSERT ON accounts FOR EACH ROW EXECUTE FUNCTION accounts_cash_default();

ALTER TABLE acct_settings ADD COLUMN next_invoice integer NOT NULL DEFAULT 1 CHECK (next_invoice > 0), ADD COLUMN next_bill integer NOT NULL DEFAULT 1 CHECK (next_bill > 0), ADD COLUMN next_expense integer NOT NULL DEFAULT 1 CHECK (next_expense > 0);

CREATE TABLE acct_parties (
  id          char(26)     PRIMARY KEY,
  tenant_id   char(26)     NOT NULL REFERENCES tenants (id),
  kind        text         NOT NULL CHECK (kind IN ('customer', 'supplier')),
  name        varchar(120) NOT NULL CHECK (btrim(name) <> ''),
  phone       varchar(30),
  note        varchar(200),
  active      boolean      NOT NULL DEFAULT true,
  created_at  timestamptz  NOT NULL DEFAULT now(),
  UNIQUE (id, tenant_id)
);

CREATE TABLE acct_docs (
  id             char(26)     PRIMARY KEY,
  tenant_id      char(26)     NOT NULL REFERENCES tenants (id),
  kind           text         NOT NULL CHECK (kind IN ('invoice', 'bill', 'expense')),
  number         varchar(20)  NOT NULL,
  party_id       char(26),
  issue_date     date         NOT NULL,
  due_date       date         NOT NULL,
  reference      varchar(60),
  subtotal_minor bigint       NOT NULL CHECK (subtotal_minor > 0),
  vat_minor      bigint       NOT NULL DEFAULT 0 CHECK (vat_minor >= 0),
  total_minor    bigint       NOT NULL CHECK (total_minor > 0),
  paid_minor     bigint       NOT NULL DEFAULT 0 CHECK (paid_minor >= 0),
  pay_account_id char(26),                                                   -- expenses only: where the money went from
  status         text         NOT NULL DEFAULT 'open' CHECK (status IN ('open', 'paid', 'void')),
  entry_id       char(26)     NOT NULL,
  void_entry_id  char(26),
  voided_at      timestamptz,
  created_by     char(26)     NOT NULL REFERENCES users (id),
  created_at     timestamptz  NOT NULL DEFAULT now(),
  UNIQUE (id, tenant_id),
  UNIQUE (tenant_id, number),
  FOREIGN KEY (party_id, tenant_id)       REFERENCES acct_parties (id, tenant_id),
  FOREIGN KEY (pay_account_id, tenant_id) REFERENCES accounts (id, tenant_id),
  FOREIGN KEY (entry_id, tenant_id)       REFERENCES journal_entries (id, tenant_id),
  FOREIGN KEY (void_entry_id, tenant_id)  REFERENCES journal_entries (id, tenant_id),
  CONSTRAINT doc_total CHECK (total_minor = subtotal_minor + vat_minor),
  CONSTRAINT doc_paid_within_total CHECK (paid_minor <= total_minor),
  CONSTRAINT doc_due_after_issue CHECK (due_date >= issue_date),
  CONSTRAINT doc_party_rule CHECK (kind = 'expense' OR party_id IS NOT NULL),
  CONSTRAINT doc_expense_rule CHECK ((kind = 'expense') = (pay_account_id IS NOT NULL)),
  CONSTRAINT doc_status_rule CHECK ((status = 'void') = (void_entry_id IS NOT NULL) AND (status <> 'paid' OR paid_minor = total_minor) AND (status <> 'open' OR paid_minor < total_minor))
);
CREATE INDEX acct_docs_list ON acct_docs (tenant_id, kind, issue_date DESC);
CREATE INDEX acct_docs_party ON acct_docs (party_id);

CREATE TABLE acct_doc_lines (
  seq          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  tenant_id    char(26)     NOT NULL REFERENCES tenants (id),
  doc_id       char(26)     NOT NULL,
  account_id   char(26)     NOT NULL,
  description  varchar(200),
  amount_minor bigint       NOT NULL CHECK (amount_minor > 0),
  FOREIGN KEY (doc_id, tenant_id)     REFERENCES acct_docs (id, tenant_id),
  FOREIGN KEY (account_id, tenant_id) REFERENCES accounts (id, tenant_id)
);
CREATE INDEX acct_doc_lines_doc ON acct_doc_lines (doc_id);

CREATE TABLE acct_payments (
  id           char(26)     PRIMARY KEY,
  tenant_id    char(26)     NOT NULL REFERENCES tenants (id),
  doc_id       char(26)     NOT NULL,
  pay_date     date         NOT NULL,
  amount_minor bigint       NOT NULL CHECK (amount_minor > 0),
  account_id   char(26)     NOT NULL,
  note         varchar(200),
  entry_id     char(26)     NOT NULL,
  void_entry_id char(26),
  voided_at    timestamptz,
  created_by   char(26)     NOT NULL REFERENCES users (id),
  created_at   timestamptz  NOT NULL DEFAULT now(),
  UNIQUE (id, tenant_id),
  FOREIGN KEY (doc_id, tenant_id)        REFERENCES acct_docs (id, tenant_id),
  FOREIGN KEY (account_id, tenant_id)    REFERENCES accounts (id, tenant_id),
  FOREIGN KEY (entry_id, tenant_id)      REFERENCES journal_entries (id, tenant_id),
  FOREIGN KEY (void_entry_id, tenant_id) REFERENCES journal_entries (id, tenant_id),
  CONSTRAINT payment_void_rule CHECK ((voided_at IS NULL) = (void_entry_id IS NULL))
);
CREATE INDEX acct_payments_doc ON acct_payments (doc_id);

CREATE TRIGGER acct_doc_lines_no_change BEFORE UPDATE OR DELETE ON acct_doc_lines FOR EACH ROW EXECUTE FUNCTION catalog_log_immutable();

DO $$ DECLARE t text; BEGIN
  FOREACH t IN ARRAY ARRAY['acct_parties', 'acct_docs', 'acct_doc_lines', 'acct_payments'] LOOP
    EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', t);
    EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY', t);
    EXECUTE format('CREATE POLICY tenant_isolation ON %I USING (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin())) WITH CHECK (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()))', t);
    EXECUTE format('REVOKE ALL ON %I FROM jarvis_app_rw, jarvis_admin', t);
    EXECUTE format('GRANT SELECT ON %I TO jarvis_app_rw, jarvis_admin', t);
  END LOOP;
END $$;
GRANT UPDATE (name, phone, note, active) ON acct_parties TO jarvis_app_rw;

-- ---------------------------------------------------------------------------------------------------------------------
CREATE FUNCTION acct_party_add(p_id char(26), p_tenant char(26), p_kind text, p_name text, p_phone text, p_note text) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); s acct_settings%ROWTYPE;
BEGIN
  s := acct_open(p_tenant);
  IF p_kind NOT IN ('customer', 'supplier') OR p_name IS NULL OR btrim(p_name) = '' THEN RETURN 'bad_input'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  INSERT INTO acct_parties (id, tenant_id, kind, name, phone, note) VALUES (p_id, p_tenant, p_kind, left(btrim(p_name), 120), nullif(left(btrim(coalesce(p_phone, '')), 30), ''), nullif(left(btrim(coalesce(p_note, '')), 200), ''));
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

-- Mark / unmark one of the workspace's own asset accounts as a cash / bank account.
CREATE FUNCTION acct_account_cash(p_account char(26), p_on boolean) RETURNS text LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); a accounts%ROWTYPE;
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO a FROM accounts WHERE id = p_account;
  PERFORM set_config('app.bypass_rls', prev, true);
  IF NOT FOUND THEN RAISE EXCEPTION 'forbidden' USING ERRCODE = '42501'; END IF;
  PERFORM credit_authorize(a.tenant_id, false);
  IF a.type <> 'asset' OR NOT a.postable THEN RETURN 'not_asset'; END IF;
  IF a.system_key IN ('receivable', 'payable', 'inventory', 'accum_depreciation', 'vat_input') THEN RETURN 'not_asset'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE accounts SET cash_account = p_on WHERE id = p_account;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

-- Create an invoice, a bill or a paid-now expense. p_lines = [{"account_id","description","amount_minor"}], p_vat_minor entered by the person (rates are Phase 17).
-- result: ok | bad_kind | bad_party | bad_lines | bad_line | bad_vat | bad_due | no_system_account | bad_pay_account | bad_line_account | <journal_post result: locked | bad_date | bad_memo ...>
CREATE FUNCTION acct_doc_create(p_id char(26), p_entry char(26), p_tenant char(26), p_user char(26), p_kind text, p_party char(26), p_date date, p_due date, p_ref text, p_lines jsonb, p_vat_minor bigint, p_pay_account char(26))
RETURNS TABLE (result text, doc_id char(26), number varchar, total_minor bigint)
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); s acct_settings%ROWTYPE; l jsonb; a accounts%ROWTYPE; party acct_parties%ROWTYPE; sub bigint := 0; amt bigint; tot bigint; num varchar; n integer;
  ctl char(26); vat char(26); jl jsonb := '[]'::jsonb; jr record; due date; memo text; src text; payacct accounts%ROWTYPE;
BEGIN
  s := acct_open(p_tenant);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  IF p_kind NOT IN ('invoice', 'bill', 'expense') THEN RETURN QUERY SELECT 'bad_kind'::text, NULL::char(26), NULL::varchar, NULL::bigint; RETURN; END IF;
  IF p_vat_minor IS NULL OR p_vat_minor < 0 OR p_vat_minor > 999999999999 THEN RETURN QUERY SELECT 'bad_vat'::text, NULL::char(26), NULL::varchar, NULL::bigint; RETURN; END IF;
  IF p_lines IS NULL OR jsonb_typeof(p_lines) <> 'array' OR jsonb_array_length(p_lines) < 1 OR jsonb_array_length(p_lines) > 50 THEN RETURN QUERY SELECT 'bad_lines'::text, NULL::char(26), NULL::varchar, NULL::bigint; RETURN; END IF;
  due := coalesce(p_due, p_date);
  IF p_date IS NULL OR due < p_date THEN RETURN QUERY SELECT 'bad_due'::text, NULL::char(26), NULL::varchar, NULL::bigint; RETURN; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  IF p_kind <> 'expense' THEN
    SELECT * INTO party FROM acct_parties WHERE id = p_party AND tenant_id = p_tenant AND active AND kind = CASE p_kind WHEN 'invoice' THEN 'customer' ELSE 'supplier' END;
    IF NOT FOUND THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN QUERY SELECT 'bad_party'::text, NULL::char(26), NULL::varchar, NULL::bigint; RETURN; END IF;
    IF p_pay_account IS NOT NULL THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN QUERY SELECT 'bad_pay_account'::text, NULL::char(26), NULL::varchar, NULL::bigint; RETURN; END IF;
  ELSE
    IF p_party IS NOT NULL THEN
      SELECT * INTO party FROM acct_parties WHERE id = p_party AND tenant_id = p_tenant AND active AND kind = 'supplier';
      IF NOT FOUND THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN QUERY SELECT 'bad_party'::text, NULL::char(26), NULL::varchar, NULL::bigint; RETURN; END IF;
    END IF;
    SELECT * INTO payacct FROM accounts WHERE id = p_pay_account AND tenant_id = p_tenant AND active AND cash_account;
    IF NOT FOUND THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN QUERY SELECT 'bad_pay_account'::text, NULL::char(26), NULL::varchar, NULL::bigint; RETURN; END IF;
  END IF;
  -- lines
  FOR l IN SELECT * FROM jsonb_array_elements(p_lines) LOOP
    BEGIN amt := (l->>'amount_minor')::bigint; EXCEPTION WHEN others THEN amt := NULL; END;
    IF amt IS NULL OR amt <= 0 OR amt > 999999999999 THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN QUERY SELECT 'bad_line'::text, NULL::char(26), NULL::varchar, NULL::bigint; RETURN; END IF;
    SELECT * INTO a FROM accounts WHERE id = (l->>'account_id') AND tenant_id = p_tenant;
    IF NOT FOUND OR NOT ((p_kind = 'invoice' AND a.type = 'income') OR (p_kind <> 'invoice' AND a.type IN ('expense', 'asset') AND NOT a.cash_account AND coalesce(a.system_key, '') NOT IN ('receivable', 'payable'))) THEN
      PERFORM set_config('app.bypass_rls', prev, true); RETURN QUERY SELECT 'bad_line_account'::text, NULL::char(26), NULL::varchar, NULL::bigint; RETURN;
    END IF;
    sub := sub + amt;
  END LOOP;
  tot := sub + p_vat_minor;
  SELECT id INTO ctl FROM accounts WHERE tenant_id = p_tenant AND system_key = CASE p_kind WHEN 'invoice' THEN 'receivable' WHEN 'bill' THEN 'payable' ELSE NULL END;
  SELECT id INTO vat FROM accounts WHERE tenant_id = p_tenant AND system_key = CASE p_kind WHEN 'invoice' THEN 'vat_output' ELSE 'vat_input' END;
  IF (p_kind <> 'expense' AND ctl IS NULL) OR (p_vat_minor > 0 AND vat IS NULL) THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN QUERY SELECT 'no_system_account'::text, NULL::char(26), NULL::varchar, NULL::bigint; RETURN; END IF;
  -- numbering (settings row is locked by acct_open)
  IF p_kind = 'invoice' THEN n := s.next_invoice; num := 'INV-' || lpad(n::text, 4, '0'); UPDATE acct_settings SET next_invoice = next_invoice + 1 WHERE tenant_id = p_tenant;
  ELSIF p_kind = 'bill' THEN n := s.next_bill; num := 'BILL-' || lpad(n::text, 4, '0'); UPDATE acct_settings SET next_bill = next_bill + 1 WHERE tenant_id = p_tenant;
  ELSE n := s.next_expense; num := 'EXP-' || lpad(n::text, 4, '0'); UPDATE acct_settings SET next_expense = next_expense + 1 WHERE tenant_id = p_tenant; END IF;
  -- journal lines
  IF p_kind = 'invoice' THEN
    jl := jsonb_build_array(jsonb_build_object('account_id', ctl, 'debit_minor', tot, 'credit_minor', 0));
    FOR jr IN SELECT x->>'account_id' AS acct, sum((x->>'amount_minor')::bigint) AS amt FROM jsonb_array_elements(p_lines) x GROUP BY 1 LOOP jl := jl || jsonb_build_object('account_id', jr.acct, 'debit_minor', 0, 'credit_minor', jr.amt); END LOOP;
    IF p_vat_minor > 0 THEN jl := jl || jsonb_build_object('account_id', vat, 'debit_minor', 0, 'credit_minor', p_vat_minor); END IF;
  ELSE
    FOR jr IN SELECT x->>'account_id' AS acct, sum((x->>'amount_minor')::bigint) AS amt FROM jsonb_array_elements(p_lines) x GROUP BY 1 LOOP jl := jl || jsonb_build_object('account_id', jr.acct, 'debit_minor', jr.amt, 'credit_minor', 0); END LOOP;
    IF p_vat_minor > 0 THEN jl := jl || jsonb_build_object('account_id', vat, 'debit_minor', p_vat_minor, 'credit_minor', 0); END IF;
    jl := jl || jsonb_build_object('account_id', CASE WHEN p_kind = 'bill' THEN ctl ELSE p_pay_account END, 'debit_minor', 0, 'credit_minor', tot);
  END IF;
  src := p_kind;
  memo := initcap(p_kind) || ' ' || num || coalesce(' — ' || party.name, '');
  PERFORM set_config('app.bypass_rls', prev, true);
  SELECT * INTO jr FROM journal_post(p_entry, p_tenant, p_user, p_date, memo, src, num, jl);
  IF jr.result <> 'ok' THEN RAISE EXCEPTION 'journal:%', jr.result USING ERRCODE = 'P0001'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  INSERT INTO acct_docs (id, tenant_id, kind, number, party_id, issue_date, due_date, reference, subtotal_minor, vat_minor, total_minor, paid_minor, pay_account_id, status, entry_id, created_by)
    VALUES (p_id, p_tenant, p_kind, num, CASE WHEN p_kind = 'expense' THEN p_party ELSE party.id END, p_date, due, left(p_ref, 60), sub, p_vat_minor, tot, CASE WHEN p_kind = 'expense' THEN tot ELSE 0 END, p_pay_account,
            CASE WHEN p_kind = 'expense' THEN 'paid' ELSE 'open' END, p_entry, p_user);
  INSERT INTO acct_doc_lines (tenant_id, doc_id, account_id, description, amount_minor)
    SELECT p_tenant, p_id, x->>'account_id', left(x->>'description', 200), (x->>'amount_minor')::bigint FROM jsonb_array_elements(p_lines) x;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN QUERY SELECT 'ok'::text, p_id, num, tot;
EXCEPTION WHEN SQLSTATE 'P0001' THEN
  -- a refused journal entry (locked period, bad date ...) undoes the numbering too: report its reason
  IF SQLERRM LIKE 'journal:%' THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN QUERY SELECT substr(SQLERRM, 9)::text, NULL::char(26), NULL::varchar, NULL::bigint; RETURN; END IF;
  RAISE;
END $$;

-- Record money received for an invoice / paid for a bill (partial allowed, never more than is owed).
-- result: ok | bad_amount | bad_account | not_payable (expense / void / already paid) | overpay | <journal_post result>
CREATE FUNCTION acct_payment_add(p_id char(26), p_entry char(26), p_doc char(26), p_user char(26), p_date date, p_amount bigint, p_account char(26), p_note text) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); d acct_docs%ROWTYPE; s acct_settings%ROWTYPE; cash accounts%ROWTYPE; ctl char(26); jr record; jl jsonb; memo text;
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO d FROM acct_docs WHERE id = p_doc FOR UPDATE;
  PERFORM set_config('app.bypass_rls', prev, true);
  IF NOT FOUND THEN RAISE EXCEPTION 'forbidden' USING ERRCODE = '42501'; END IF;
  s := acct_open(d.tenant_id);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  IF d.kind = 'expense' OR d.status <> 'open' THEN RETURN 'not_payable'; END IF;
  IF p_amount IS NULL OR p_amount <= 0 THEN RETURN 'bad_amount'; END IF;
  IF p_amount > d.total_minor - d.paid_minor THEN RETURN 'overpay'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO cash FROM accounts WHERE id = p_account AND tenant_id = d.tenant_id AND active AND cash_account;
  IF NOT FOUND THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'bad_account'; END IF;
  SELECT id INTO ctl FROM accounts WHERE tenant_id = d.tenant_id AND system_key = CASE d.kind WHEN 'invoice' THEN 'receivable' ELSE 'payable' END;
  PERFORM set_config('app.bypass_rls', prev, true);
  jl := CASE d.kind
    WHEN 'invoice' THEN jsonb_build_array(jsonb_build_object('account_id', p_account, 'debit_minor', p_amount, 'credit_minor', 0), jsonb_build_object('account_id', ctl, 'debit_minor', 0, 'credit_minor', p_amount))
    ELSE jsonb_build_array(jsonb_build_object('account_id', ctl, 'debit_minor', p_amount, 'credit_minor', 0), jsonb_build_object('account_id', p_account, 'debit_minor', 0, 'credit_minor', p_amount)) END;
  memo := CASE d.kind WHEN 'invoice' THEN 'Receipt for ' ELSE 'Payment of ' END || d.number;
  SELECT * INTO jr FROM journal_post(p_entry, d.tenant_id, p_user, p_date, memo, 'payment', d.number, jl);
  IF jr.result <> 'ok' THEN RETURN jr.result; END IF;                     -- nothing written yet: the journal refused it
  PERFORM set_config('app.bypass_rls', 'on', true);
  INSERT INTO acct_payments (id, tenant_id, doc_id, pay_date, amount_minor, account_id, note, entry_id, created_by) VALUES (p_id, d.tenant_id, p_doc, p_date, p_amount, p_account, left(p_note, 200), p_entry, p_user);
  UPDATE acct_docs SET paid_minor = paid_minor + p_amount, status = CASE WHEN paid_minor + p_amount = total_minor THEN 'paid' ELSE 'open' END WHERE id = p_doc;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

-- Cancel a payment (a reversing entry in an open period). result: ok | already_void | locked | bad_date
CREATE FUNCTION acct_payment_void(p_new_entry char(26), p_payment char(26), p_user char(26), p_date date) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); p acct_payments%ROWTYPE; r text;
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO p FROM acct_payments WHERE id = p_payment FOR UPDATE;
  PERFORM set_config('app.bypass_rls', prev, true);
  IF NOT FOUND THEN RAISE EXCEPTION 'forbidden' USING ERRCODE = '42501'; END IF;
  PERFORM acct_open(p.tenant_id);
  IF p.voided_at IS NOT NULL THEN RETURN 'already_void'; END IF;
  r := journal_reverse(p_new_entry, p.entry_id, p_user, p_date, 'Cancelled payment');
  IF r <> 'ok' THEN RETURN r; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE acct_payments SET voided_at = now(), void_entry_id = p_new_entry WHERE id = p_payment;
  UPDATE acct_docs SET paid_minor = paid_minor - p.amount_minor, status = 'open' WHERE id = p.doc_id;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

-- Void an invoice / bill / expense. Invoices and bills must have no live payments (cancel those first). result: ok | already_void | has_payments | locked | bad_date
CREATE FUNCTION acct_doc_void(p_new_entry char(26), p_doc char(26), p_user char(26), p_date date) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); d acct_docs%ROWTYPE; r text;
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO d FROM acct_docs WHERE id = p_doc FOR UPDATE;
  PERFORM set_config('app.bypass_rls', prev, true);
  IF NOT FOUND THEN RAISE EXCEPTION 'forbidden' USING ERRCODE = '42501'; END IF;
  PERFORM acct_open(d.tenant_id);
  IF d.status = 'void' THEN RETURN 'already_void'; END IF;
  IF d.kind <> 'expense' AND d.paid_minor > 0 THEN RETURN 'has_payments'; END IF;
  r := journal_reverse(p_new_entry, d.entry_id, p_user, p_date, 'Void ' || d.number);
  IF r <> 'ok' THEN RETURN r; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE acct_docs SET status = 'void', void_entry_id = p_new_entry, voided_at = now() WHERE id = p_doc;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

-- ---------------------------------------------------------------------------------------------------------------------
-- Reads (as the caller; RLS keeps them to one workspace).
-- Open invoices (kind='invoice') or bills ('bill') as of a day, with what is still owed and how late it is.
CREATE FUNCTION acct_aging(p_kind text, p_asof date) RETURNS TABLE (doc_id char(26), number varchar, party_id char(26), party_name varchar, issue_date date, due_date date, total_minor bigint, balance_minor bigint, days_overdue integer, bucket text)
LANGUAGE sql STABLE AS $$
  SELECT d.id, d.number, d.party_id, p.name, d.issue_date, d.due_date, d.total_minor, d.total_minor - coalesce(pay.paid, 0),
         greatest(0, p_asof - d.due_date),
         CASE WHEN p_asof <= d.due_date THEN 'current' WHEN p_asof - d.due_date <= 30 THEN '1-30' WHEN p_asof - d.due_date <= 60 THEN '31-60' WHEN p_asof - d.due_date <= 90 THEN '61-90' ELSE '90+' END
    FROM acct_docs d
    JOIN acct_parties p ON p.id = d.party_id AND p.tenant_id = d.tenant_id
    LEFT JOIN LATERAL (SELECT sum(amount_minor) AS paid FROM acct_payments x WHERE x.doc_id = d.id AND x.pay_date <= p_asof AND (x.voided_at IS NULL OR (x.voided_at AT TIME ZONE 'Asia/Dhaka')::date > p_asof)) pay ON true
   WHERE d.kind = p_kind AND d.issue_date <= p_asof AND (d.status <> 'void' OR (d.voided_at AT TIME ZONE 'Asia/Dhaka')::date > p_asof)
     AND d.total_minor - coalesce(pay.paid, 0) > 0
   ORDER BY d.due_date, d.number
$$;

-- Cash / bank / wallet accounts with their balance as of a day.
CREATE FUNCTION acct_cash_balances(p_asof date) RETURNS TABLE (account_id char(26), code varchar, name varchar, name_bn varchar, balance_minor bigint)
LANGUAGE sql STABLE AS $$
  SELECT a.id, a.code, a.name, a.name_bn, coalesce(sum(l.debit_minor - l.credit_minor), 0)::bigint
    FROM accounts a
    LEFT JOIN journal_lines l ON l.account_id = a.id AND l.tenant_id = a.tenant_id AND l.entry_id IN (SELECT e.id FROM journal_entries e WHERE e.tenant_id = a.tenant_id AND e.entry_date <= p_asof)
   WHERE a.cash_account AND a.active GROUP BY a.id ORDER BY a.code
$$;

REVOKE ALL ON FUNCTION acct_party_add(char, char, text, text, text, text), acct_account_cash(char, boolean),
  acct_doc_create(char, char, char, char, text, char, date, date, text, jsonb, bigint, char), acct_payment_add(char, char, char, char, date, bigint, char, text),
  acct_payment_void(char, char, char, date), acct_doc_void(char, char, char, date) FROM PUBLIC, jarvis_app_rw, jarvis_admin;
GRANT EXECUTE ON FUNCTION acct_party_add(char, char, text, text, text, text), acct_account_cash(char, boolean),
  acct_doc_create(char, char, char, char, text, char, date, date, text, jsonb, bigint, char), acct_payment_add(char, char, char, char, date, bigint, char, text),
  acct_payment_void(char, char, char, date), acct_doc_void(char, char, char, date) TO jarvis_app_rw, jarvis_admin;
GRANT EXECUTE ON FUNCTION acct_aging(text, date), acct_cash_balances(date) TO jarvis_app_rw, jarvis_admin;
