-- Phase 15a: the accounting core. Double-entry rules live in the database so no screen, import or AI can break them:
--   * an entry is posted whole or not at all: >= 2 lines, every line a positive debit XOR credit, debits = credits (also enforced by a deferred constraint trigger as a backstop);
--   * journal entries and lines are append-only — a mistake is fixed by a reversing entry, never by an edit;
--   * a closed (locked) period accepts no new entry; unlocking can only move the lock back, needs a reason and is logged;
--   * only active, postable accounts of the same workspace can be used; amounts are integer minor units (poisha), one currency (BDT) for now.

CREATE TABLE acct_settings (
  tenant_id       char(26)  PRIMARY KEY REFERENCES tenants (id),
  currency        char(3)   NOT NULL DEFAULT 'BDT' CHECK (currency = 'BDT'),
  locked_through  date,                                   -- no entry dated on/before this day may be posted
  next_number     integer   NOT NULL DEFAULT 1 CHECK (next_number > 0),
  seeded_at       timestamptz,
  updated_at      timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE accounts (
  id          char(26)     PRIMARY KEY,
  tenant_id   char(26)     NOT NULL REFERENCES tenants (id),
  code        varchar(10)  NOT NULL CHECK (code ~ '^[0-9]{3,10}$'),
  name        varchar(120) NOT NULL CHECK (btrim(name) <> ''),
  name_bn     varchar(120),
  type        text         NOT NULL CHECK (type IN ('asset', 'liability', 'equity', 'income', 'expense')),
  parent_id   char(26),
  postable    boolean      NOT NULL DEFAULT true,           -- group headings are not postable
  system_key  varchar(40),                                  -- stable handle the app uses (cash, receivable, sales ...)
  active      boolean      NOT NULL DEFAULT true,
  created_at  timestamptz  NOT NULL DEFAULT now(),
  UNIQUE (id, tenant_id),
  UNIQUE (tenant_id, code),
  FOREIGN KEY (parent_id, tenant_id) REFERENCES accounts (id, tenant_id)
);
CREATE UNIQUE INDEX accounts_system_key ON accounts (tenant_id, system_key) WHERE system_key IS NOT NULL;

CREATE TABLE journal_entries (
  id            char(26)     PRIMARY KEY,
  tenant_id     char(26)     NOT NULL REFERENCES tenants (id),
  number        integer      NOT NULL CHECK (number > 0),
  entry_date    date         NOT NULL,
  memo          varchar(300) NOT NULL CHECK (btrim(memo) <> ''),
  source        text         NOT NULL DEFAULT 'manual' CHECK (source IN ('manual', 'reversal', 'invoice', 'bill', 'expense', 'payment', 'system')),
  source_ref    varchar(60),
  reverses_id   char(26),
  created_by    char(26)     REFERENCES users (id),
  created_at    timestamptz  NOT NULL DEFAULT now(),
  UNIQUE (id, tenant_id),
  UNIQUE (tenant_id, number),
  FOREIGN KEY (reverses_id, tenant_id) REFERENCES journal_entries (id, tenant_id),
  CONSTRAINT reversal_shape CHECK ((source = 'reversal') = (reverses_id IS NOT NULL))
);
CREATE UNIQUE INDEX journal_one_reversal ON journal_entries (reverses_id) WHERE reverses_id IS NOT NULL;     -- an entry can be reversed once
CREATE INDEX journal_entries_date ON journal_entries (tenant_id, entry_date DESC, number DESC);

CREATE TABLE journal_lines (
  seq          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  tenant_id    char(26)    NOT NULL REFERENCES tenants (id),
  entry_id     char(26)    NOT NULL,
  account_id   char(26)    NOT NULL,
  debit_minor  bigint      NOT NULL DEFAULT 0 CHECK (debit_minor >= 0 AND debit_minor <= 999999999999999),
  credit_minor bigint      NOT NULL DEFAULT 0 CHECK (credit_minor >= 0 AND credit_minor <= 999999999999999),
  memo         varchar(200),
  FOREIGN KEY (entry_id, tenant_id)   REFERENCES journal_entries (id, tenant_id),
  FOREIGN KEY (account_id, tenant_id) REFERENCES accounts (id, tenant_id),
  CONSTRAINT one_side_only CHECK ((debit_minor > 0 AND credit_minor = 0) OR (credit_minor > 0 AND debit_minor = 0))
);
CREATE INDEX journal_lines_entry ON journal_lines (entry_id);
CREATE INDEX journal_lines_account ON journal_lines (tenant_id, account_id);

CREATE TABLE acct_lock_log (
  seq         bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  tenant_id   char(26)    NOT NULL REFERENCES tenants (id),
  action      text        NOT NULL CHECK (action IN ('lock', 'unlock')),
  from_date   date,
  to_date     date,
  user_id     char(26)    NOT NULL REFERENCES users (id),
  reason      varchar(200),
  created_at  timestamptz NOT NULL DEFAULT now()
);

CREATE TRIGGER journal_entries_no_change BEFORE UPDATE OR DELETE ON journal_entries FOR EACH ROW EXECUTE FUNCTION catalog_log_immutable();
CREATE TRIGGER journal_lines_no_change   BEFORE UPDATE OR DELETE ON journal_lines   FOR EACH ROW EXECUTE FUNCTION catalog_log_immutable();
CREATE TRIGGER acct_lock_log_no_change   BEFORE UPDATE OR DELETE ON acct_lock_log   FOR EACH ROW EXECUTE FUNCTION catalog_log_immutable();

-- Backstop: whatever path inserted the lines, at commit every touched entry must balance and have >= 2 lines.
CREATE FUNCTION journal_balance_check() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE d bigint; c bigint; n integer;
BEGIN
  SELECT coalesce(sum(debit_minor), 0), coalesce(sum(credit_minor), 0), count(*) INTO d, c, n FROM journal_lines WHERE entry_id = NEW.entry_id;
  IF d <> c OR n < 2 THEN RAISE EXCEPTION 'journal entry % does not balance (debit %, credit %, lines %)', NEW.entry_id, d, c, n USING ERRCODE = '23514'; END IF;
  RETURN NULL;
END $$;
CREATE CONSTRAINT TRIGGER journal_lines_balance AFTER INSERT ON journal_lines DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION journal_balance_check();

DO $$ DECLARE t text; BEGIN
  FOREACH t IN ARRAY ARRAY['acct_settings', 'accounts', 'journal_entries', 'journal_lines', 'acct_lock_log'] LOOP
    EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', t);
    EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY', t);
    EXECUTE format('CREATE POLICY tenant_isolation ON %I USING (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin())) WITH CHECK (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()))', t);
    EXECUTE format('REVOKE ALL ON %I FROM jarvis_app_rw, jarvis_admin', t);
    EXECUTE format('GRANT SELECT ON %I TO jarvis_app_rw, jarvis_admin', t);
  END LOOP;
END $$;
-- Entries, lines, settings and the lock log are written only by the functions below. Accounts: rename / (de)activate directly, add through a function.
GRANT UPDATE (name, name_bn, active) ON accounts TO jarvis_app_rw;

-- ---------------------------------------------------------------------------------------------------------------------
-- Authorises the caller for a workspace and makes sure its settings row exists. Returns the row (locked FOR UPDATE).
CREATE FUNCTION acct_open(p_tenant char(26)) RETURNS acct_settings LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); s acct_settings%ROWTYPE;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  PERFORM set_config('app.bypass_rls', 'on', true);
  INSERT INTO acct_settings (tenant_id) VALUES (p_tenant) ON CONFLICT DO NOTHING;
  SELECT * INTO s FROM acct_settings WHERE tenant_id = p_tenant FOR UPDATE;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN s;
END $$;

-- The Bangladeshi small-business chart of accounts. Idempotent. Returns how many accounts were added.
CREATE FUNCTION acct_seed(p_tenant char(26), p_ids text[]) RETURNS integer LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); s acct_settings%ROWTYPE; r record; n integer := 0; i integer := 0; got integer; parent char(26);
BEGIN
  s := acct_open(p_tenant);
  IF coalesce(array_length(p_ids, 1), 0) < 60 THEN RAISE EXCEPTION 'need 60 ids' USING ERRCODE = '22023'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  FOR r IN SELECT * FROM (VALUES
    ('1000', 'Assets', 'সম্পদ', 'asset', NULL, false, NULL),
    ('1100', 'Current Assets', 'চলতি সম্পদ', 'asset', '1000', false, NULL),
    ('1110', 'Cash in Hand', 'হাতে নগদ', 'asset', '1100', true, 'cash'),
    ('1120', 'Cash at Bank', 'ব্যাংকে জমা', 'asset', '1100', true, 'bank'),
    ('1130', 'bKash / Nagad Wallet', 'বিকাশ / নগদ', 'asset', '1100', true, 'mobile_wallet'),
    ('1200', 'Accounts Receivable', 'পাওনা (গ্রাহক)', 'asset', '1100', true, 'receivable'),
    ('1210', 'Courier COD Receivable', 'কুরিয়ারের কাছে পাওনা (COD)', 'asset', '1100', true, 'courier_receivable'),
    ('1300', 'Inventory', 'মজুত পণ্য', 'asset', '1100', true, 'inventory'),
    ('1400', 'Advance & Prepayments', 'অগ্রিম ও প্রিপেইড', 'asset', '1100', true, 'prepaid'),
    ('1500', 'VAT Input (Receivable)', 'ইনপুট ভ্যাট', 'asset', '1100', true, 'vat_input'),
    ('1600', 'Fixed Assets', 'স্থায়ী সম্পদ', 'asset', '1000', false, NULL),
    ('1610', 'Furniture & Equipment', 'আসবাব ও যন্ত্রপাতি', 'asset', '1600', true, 'fixed_equipment'),
    ('1620', 'Computers & Electronics', 'কম্পিউটার ও ইলেকট্রনিক্স', 'asset', '1600', true, NULL),
    ('1690', 'Accumulated Depreciation', 'পুঞ্জীভূত অবচয়', 'asset', '1600', true, 'accum_depreciation'),
    ('2000', 'Liabilities', 'দায়', 'liability', NULL, false, NULL),
    ('2100', 'Accounts Payable', 'দেনা (সরবরাহকারী)', 'liability', '2000', true, 'payable'),
    ('2200', 'VAT Output (Payable)', 'আউটপুট ভ্যাট', 'liability', '2000', true, 'vat_output'),
    ('2300', 'Tax Payable (TDS/AIT)', 'কর পরিশোধযোগ্য (TDS/AIT)', 'liability', '2000', true, 'tax_payable'),
    ('2400', 'Salaries Payable', 'বেতন পরিশোধযোগ্য', 'liability', '2000', true, 'salary_payable'),
    ('2500', 'Customer Advances', 'গ্রাহকের অগ্রিম', 'liability', '2000', true, 'customer_advance'),
    ('2600', 'Loans', 'ঋণ', 'liability', '2000', true, 'loans'),
    ('3000', 'Equity', 'মালিকানা স্বত্ব', 'equity', NULL, false, NULL),
    ('3100', 'Owner Capital', 'মালিকের মূলধন', 'equity', '3000', true, 'capital'),
    ('3200', 'Owner Drawings', 'মালিকের উত্তোলন', 'equity', '3000', true, 'drawings'),
    ('3300', 'Retained Earnings', 'জমাকৃত মুনাফা', 'equity', '3000', true, 'retained_earnings'),
    ('4000', 'Income', 'আয়', 'income', NULL, false, NULL),
    ('4100', 'Sales', 'বিক্রয়', 'income', '4000', true, 'sales'),
    ('4200', 'Service Income', 'সেবা থেকে আয়', 'income', '4000', true, 'service_income'),
    ('4300', 'Delivery Charge Income', 'ডেলিভারি চার্জ আয়', 'income', '4000', true, 'delivery_income'),
    ('4400', 'Other Income', 'অন্যান্য আয়', 'income', '4000', true, 'other_income'),
    ('4900', 'Sales Returns & Discounts', 'বিক্রয় ফেরত ও ছাড়', 'income', '4000', true, 'sales_returns'),
    ('5000', 'Cost of Goods Sold', 'বিক্রীত পণ্যের ব্যয়', 'expense', NULL, true, 'cogs'),
    ('6000', 'Operating Expenses', 'পরিচালন ব্যয়', 'expense', NULL, false, NULL),
    ('6100', 'Salaries & Wages', 'বেতন ও মজুরি', 'expense', '6000', true, 'salaries'),
    ('6200', 'Rent', 'ভাড়া', 'expense', '6000', true, 'rent'),
    ('6300', 'Utilities (Electricity, Internet, Phone)', 'ইউটিলিটি (বিদ্যুৎ, ইন্টারনেট, ফোন)', 'expense', '6000', true, 'utilities'),
    ('6400', 'Marketing & Advertising', 'বিজ্ঞাপন ও মার্কেটিং', 'expense', '6000', true, 'marketing'),
    ('6500', 'Delivery & Courier Charges', 'ডেলিভারি ও কুরিয়ার খরচ', 'expense', '6000', true, 'courier_expense'),
    ('6600', 'Transport & Conveyance', 'যাতায়াত', 'expense', '6000', true, 'transport'),
    ('6700', 'Office Supplies', 'অফিস সামগ্রী', 'expense', '6000', true, 'office'),
    ('6800', 'Software & Subscriptions', 'সফটওয়্যার ও সাবস্ক্রিপশন', 'expense', '6000', true, 'software'),
    ('6900', 'Bank & Mobile Banking Charges', 'ব্যাংক ও মোবাইল ব্যাংকিং চার্জ', 'expense', '6000', true, 'bank_charges'),
    ('6910', 'Depreciation Expense', 'অবচয় ব্যয়', 'expense', '6000', true, 'depreciation'),
    ('6920', 'Repairs & Maintenance', 'মেরামত ও রক্ষণাবেক্ষণ', 'expense', '6000', true, NULL),
    ('6930', 'Professional Fees', 'পেশাদার ফি', 'expense', '6000', true, NULL),
    ('6940', 'Tax & License Fees', 'কর ও লাইসেন্স ফি', 'expense', '6000', true, NULL),
    ('6990', 'Miscellaneous Expense', 'বিবিধ ব্যয়', 'expense', '6000', true, 'misc_expense'),
    ('7000', 'Non-operating', 'অ-পরিচালন', 'expense', NULL, false, NULL),
    ('7100', 'Interest Expense', 'সুদ ব্যয়', 'expense', '7000', true, 'interest_expense'),
    ('7200', 'Rounding Difference', 'রাউন্ডিং পার্থক্য', 'expense', '7000', true, 'rounding')
  ) AS t(code, name, name_bn, type, parent, postable, skey) LOOP
    IF EXISTS (SELECT 1 FROM accounts a WHERE a.tenant_id = p_tenant AND a.code = r.code) THEN CONTINUE; END IF;
    i := i + 1;
    SELECT a.id INTO parent FROM accounts a WHERE a.tenant_id = p_tenant AND a.code = r.parent;
    INSERT INTO accounts (id, tenant_id, code, name, name_bn, type, parent_id, postable, system_key) VALUES (p_ids[i], p_tenant, r.code, r.name, r.name_bn, r.type, parent, r.postable, r.skey);
    n := n + 1;
  END LOOP;
  UPDATE acct_settings SET seeded_at = coalesce(seeded_at, now()) WHERE tenant_id = p_tenant;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN n;
END $$;

-- Add one account. result: ok | bad_code | duplicate_code | bad_parent | bad_name
CREATE FUNCTION acct_account_add(p_id char(26), p_tenant char(26), p_code text, p_name text, p_name_bn text, p_type text, p_parent char(26)) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); s acct_settings%ROWTYPE; pa accounts%ROWTYPE;
BEGIN
  s := acct_open(p_tenant);
  IF p_code IS NULL OR p_code !~ '^[0-9]{3,10}$' THEN RETURN 'bad_code'; END IF;
  IF p_name IS NULL OR btrim(p_name) = '' OR p_type NOT IN ('asset', 'liability', 'equity', 'income', 'expense') THEN RETURN 'bad_name'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  IF EXISTS (SELECT 1 FROM accounts WHERE tenant_id = p_tenant AND code = p_code) THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'duplicate_code'; END IF;
  IF p_parent IS NOT NULL THEN
    SELECT * INTO pa FROM accounts WHERE id = p_parent AND tenant_id = p_tenant;
    IF NOT FOUND OR pa.type <> p_type THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'bad_parent'; END IF;
  END IF;
  INSERT INTO accounts (id, tenant_id, code, name, name_bn, type, parent_id) VALUES (p_id, p_tenant, p_code, left(btrim(p_name), 120), nullif(left(btrim(coalesce(p_name_bn, '')), 120), ''), p_type, p_parent);
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

-- Post one entry. p_lines = [{"account_id": "...", "debit_minor": 100, "credit_minor": 0, "memo": "..."}, ...]
-- result: ok | bad_memo | bad_date | locked | too_few_lines | bad_line | unbalanced | bad_account | inactive_account | not_postable | bad_source
CREATE FUNCTION journal_post(p_id char(26), p_tenant char(26), p_user char(26), p_date date, p_memo text, p_source text, p_ref text, p_lines jsonb)
RETURNS TABLE (result text, entry_id char(26), number integer)
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); s acct_settings%ROWTYPE; l jsonb; d bigint; c bigint; dsum bigint := 0; csum bigint := 0; n integer; a accounts%ROWTYPE; num integer;
BEGIN
  s := acct_open(p_tenant);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  IF p_source NOT IN ('manual', 'invoice', 'bill', 'expense', 'payment', 'system') THEN RETURN QUERY SELECT 'bad_source'::text, NULL::char(26), NULL::integer; RETURN; END IF;
  IF p_memo IS NULL OR btrim(p_memo) = '' THEN RETURN QUERY SELECT 'bad_memo'::text, NULL::char(26), NULL::integer; RETURN; END IF;
  IF p_date IS NULL OR p_date > (now() AT TIME ZONE 'Asia/Dhaka')::date + 1 OR p_date < DATE '2000-01-01' THEN RETURN QUERY SELECT 'bad_date'::text, NULL::char(26), NULL::integer; RETURN; END IF;
  IF s.locked_through IS NOT NULL AND p_date <= s.locked_through THEN RETURN QUERY SELECT 'locked'::text, NULL::char(26), NULL::integer; RETURN; END IF;
  IF p_lines IS NULL OR jsonb_typeof(p_lines) <> 'array' OR jsonb_array_length(p_lines) < 2 OR jsonb_array_length(p_lines) > 200 THEN RETURN QUERY SELECT 'too_few_lines'::text, NULL::char(26), NULL::integer; RETURN; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  FOR l IN SELECT * FROM jsonb_array_elements(p_lines) LOOP
    BEGIN
      d := coalesce((l->>'debit_minor')::bigint, 0); c := coalesce((l->>'credit_minor')::bigint, 0);
    EXCEPTION WHEN others THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN QUERY SELECT 'bad_line'::text, NULL::char(26), NULL::integer; RETURN;
    END;
    IF NOT ((d > 0 AND c = 0) OR (c > 0 AND d = 0)) OR d > 999999999999999 OR c > 999999999999999 THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN QUERY SELECT 'bad_line'::text, NULL::char(26), NULL::integer; RETURN; END IF;
    SELECT * INTO a FROM accounts WHERE id = (l->>'account_id') AND tenant_id = p_tenant;
    IF NOT FOUND THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN QUERY SELECT 'bad_account'::text, NULL::char(26), NULL::integer; RETURN; END IF;
    IF NOT a.active THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN QUERY SELECT 'inactive_account'::text, NULL::char(26), NULL::integer; RETURN; END IF;
    IF NOT a.postable THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN QUERY SELECT 'not_postable'::text, NULL::char(26), NULL::integer; RETURN; END IF;
    dsum := dsum + d; csum := csum + c;
  END LOOP;
  IF dsum <> csum THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN QUERY SELECT 'unbalanced'::text, NULL::char(26), NULL::integer; RETURN; END IF;
  num := s.next_number;
  UPDATE acct_settings SET next_number = next_number + 1, updated_at = now() WHERE tenant_id = p_tenant;
  INSERT INTO journal_entries (id, tenant_id, number, entry_date, memo, source, source_ref, created_by) VALUES (p_id, p_tenant, num, p_date, left(btrim(p_memo), 300), p_source, left(p_ref, 60), p_user);
  INSERT INTO journal_lines (tenant_id, entry_id, account_id, debit_minor, credit_minor, memo)
    SELECT p_tenant, p_id, x->>'account_id', coalesce((x->>'debit_minor')::bigint, 0), coalesce((x->>'credit_minor')::bigint, 0), left(x->>'memo', 200) FROM jsonb_array_elements(p_lines) x;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN QUERY SELECT 'ok'::text, p_id, num;
END $$;

-- Reverse an entry: a new entry with debits and credits swapped. Once only; a reversal itself cannot be reversed.
-- result: ok | not_found(forbidden) | already_reversed | is_reversal | locked | bad_date
CREATE FUNCTION journal_reverse(p_new char(26), p_entry char(26), p_user char(26), p_date date, p_memo text) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); e journal_entries%ROWTYPE; s acct_settings%ROWTYPE; num integer;
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO e FROM journal_entries WHERE id = p_entry;
  PERFORM set_config('app.bypass_rls', prev, true);
  IF NOT FOUND THEN RAISE EXCEPTION 'forbidden' USING ERRCODE = '42501'; END IF;
  s := acct_open(e.tenant_id);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  IF e.source = 'reversal' THEN RETURN 'is_reversal'; END IF;
  IF p_date IS NULL OR p_date > (now() AT TIME ZONE 'Asia/Dhaka')::date + 1 OR p_date < DATE '2000-01-01' THEN RETURN 'bad_date'; END IF;
  IF s.locked_through IS NOT NULL AND p_date <= s.locked_through THEN RETURN 'locked'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  IF EXISTS (SELECT 1 FROM journal_entries WHERE reverses_id = p_entry) THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'already_reversed'; END IF;
  num := s.next_number;
  UPDATE acct_settings SET next_number = next_number + 1, updated_at = now() WHERE tenant_id = e.tenant_id;
  INSERT INTO journal_entries (id, tenant_id, number, entry_date, memo, source, source_ref, reverses_id, created_by)
    VALUES (p_new, e.tenant_id, num, p_date, left(coalesce(nullif(btrim(p_memo), ''), 'Reversal of #' || e.number), 300), 'reversal', e.number::text, p_entry, p_user);
  INSERT INTO journal_lines (tenant_id, entry_id, account_id, debit_minor, credit_minor, memo)
    SELECT e.tenant_id, p_new, account_id, credit_minor, debit_minor, memo FROM journal_lines WHERE entry_id = p_entry ORDER BY seq;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

-- Close the books up to (and including) a day. Only moves the lock forward. result: ok | not_forward | bad_date
CREATE FUNCTION acct_lock(p_tenant char(26), p_through date, p_user char(26)) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); s acct_settings%ROWTYPE;
BEGIN
  s := acct_open(p_tenant);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  IF p_through IS NULL OR p_through < DATE '2000-01-01' OR p_through > (now() AT TIME ZONE 'Asia/Dhaka')::date THEN RETURN 'bad_date'; END IF;
  IF s.locked_through IS NOT NULL AND p_through <= s.locked_through THEN RETURN 'not_forward'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE acct_settings SET locked_through = p_through, updated_at = now() WHERE tenant_id = p_tenant;
  INSERT INTO acct_lock_log (tenant_id, action, from_date, to_date, user_id) VALUES (p_tenant, 'lock', s.locked_through, p_through, p_user);
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

-- Re-open earlier days (lock moves BACK to p_to, or off with NULL). A reason is mandatory and logged. result: ok | no_reason | not_back | not_locked
CREATE FUNCTION acct_unlock(p_tenant char(26), p_to date, p_user char(26), p_reason text) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); s acct_settings%ROWTYPE;
BEGIN
  s := acct_open(p_tenant);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  IF p_reason IS NULL OR char_length(btrim(p_reason)) < 5 THEN RETURN 'no_reason'; END IF;
  IF s.locked_through IS NULL THEN RETURN 'not_locked'; END IF;
  IF p_to IS NOT NULL AND p_to >= s.locked_through THEN RETURN 'not_back'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE acct_settings SET locked_through = p_to, updated_at = now() WHERE tenant_id = p_tenant;
  INSERT INTO acct_lock_log (tenant_id, action, from_date, to_date, user_id, reason) VALUES (p_tenant, 'unlock', s.locked_through, p_to, p_user, left(btrim(p_reason), 200));
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

-- ---------------------------------------------------------------------------------------------------------------------
-- Reads (run as the caller; row-level security keeps them to one workspace).
-- Trial balance as of a day: one row per account with a non-zero balance. debit/credit are the net side (never both).
CREATE FUNCTION acct_trial_balance(p_asof date) RETURNS TABLE (account_id char(26), code varchar, name varchar, name_bn varchar, type text, debit_minor bigint, credit_minor bigint)
LANGUAGE sql STABLE AS $$
  SELECT a.id, a.code, a.name, a.name_bn, a.type,
         CASE WHEN s.d > s.c THEN s.d - s.c ELSE 0 END, CASE WHEN s.c > s.d THEN s.c - s.d ELSE 0 END
    FROM accounts a
    JOIN (SELECT l.account_id, sum(l.debit_minor)::bigint AS d, sum(l.credit_minor)::bigint AS c
            FROM journal_lines l JOIN journal_entries e ON e.id = l.entry_id AND e.tenant_id = l.tenant_id WHERE e.entry_date <= p_asof GROUP BY l.account_id) s ON s.account_id = a.id
   WHERE s.d <> s.c
   ORDER BY a.code
$$;

-- One account's ledger between two days with a running balance (debit-positive for assets/expenses, credit-positive for the rest) and the opening balance.
CREATE FUNCTION acct_ledger(p_account char(26), p_from date, p_to date) RETURNS TABLE (entry_id char(26), number integer, entry_date date, memo varchar, line_memo varchar, debit_minor bigint, credit_minor bigint, balance_minor bigint)
LANGUAGE sql STABLE AS $$
  WITH a AS (SELECT id, type FROM accounts WHERE id = p_account),
  sign AS (SELECT CASE WHEN type IN ('asset', 'expense') THEN 1 ELSE -1 END AS k FROM a),
  opening AS (SELECT coalesce(sum(l.debit_minor - l.credit_minor), 0)::bigint * (SELECT k FROM sign) AS bal
                FROM journal_lines l JOIN journal_entries e ON e.id = l.entry_id AND e.tenant_id = l.tenant_id WHERE l.account_id = p_account AND e.entry_date < p_from),
  rows AS (SELECT e.id AS entry_id, e.number, e.entry_date, e.memo, l.memo AS line_memo, l.debit_minor, l.credit_minor, l.seq
             FROM journal_lines l JOIN journal_entries e ON e.id = l.entry_id AND e.tenant_id = l.tenant_id WHERE l.account_id = p_account AND e.entry_date BETWEEN p_from AND p_to)
  SELECT NULL::char(26), NULL::integer, p_from, 'Opening balance'::varchar, NULL::varchar, 0::bigint, 0::bigint, (SELECT bal FROM opening)
  UNION ALL
  SELECT r.entry_id, r.number, r.entry_date, r.memo, r.line_memo, r.debit_minor, r.credit_minor,
         (SELECT bal FROM opening) + (sum((r.debit_minor - r.credit_minor) * (SELECT k FROM sign)) OVER (ORDER BY r.entry_date, r.number, r.seq))::bigint
    FROM rows r
$$;

REVOKE ALL ON FUNCTION acct_open(char), acct_seed(char, text[]), acct_account_add(char, char, text, text, text, text, char), journal_post(char, char, char, date, text, text, text, jsonb),
  journal_reverse(char, char, char, date, text), acct_lock(char, date, char), acct_unlock(char, date, char, text) FROM PUBLIC, jarvis_app_rw, jarvis_admin;
GRANT EXECUTE ON FUNCTION acct_seed(char, text[]), acct_account_add(char, char, text, text, text, text, char), journal_post(char, char, char, date, text, text, text, jsonb),
  journal_reverse(char, char, char, date, text), acct_lock(char, date, char), acct_unlock(char, date, char, text) TO jarvis_app_rw, jarvis_admin;
GRANT EXECUTE ON FUNCTION acct_trial_balance(date), acct_ledger(char, date, date) TO jarvis_app_rw, jarvis_admin;
