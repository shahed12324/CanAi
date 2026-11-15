-- Phase 18a: the auditor. Deterministic rules look through the books for things worth a second look (probable duplicates, unusually large amounts, back-dated or round manual
-- entries, large cash payments, gaps in the entry numbering) and record each as a FINDING. A finding is a question, not a verdict: a person confirms or dismisses it
-- (a dismissal needs a reason). Nothing here changes the books. Thresholds are the person's settings. Also: a monthly audit checklist and a few automatic health checks.

CREATE TABLE audit_settings (
  tenant_id        char(26)  PRIMARY KEY REFERENCES tenants (id),
  dup_days         smallint  NOT NULL DEFAULT 3 CHECK (dup_days BETWEEN 0 AND 30),            -- two documents this close with the same party and total look like a duplicate
  outlier_factor   smallint  NOT NULL DEFAULT 5 CHECK (outlier_factor BETWEEN 2 AND 100),     -- a total this many times the party's usual (median) total
  min_history      smallint  NOT NULL DEFAULT 5 CHECK (min_history BETWEEN 3 AND 100),        -- earlier documents needed before "usual" means anything
  backdate_days    smallint  NOT NULL DEFAULT 30 CHECK (backdate_days BETWEEN 1 AND 365),
  cash_limit_minor bigint    NOT NULL DEFAULT 5000000 CHECK (cash_limit_minor > 0),         -- a cash payment at or above this is flagged
  round_modulus    bigint    NOT NULL DEFAULT 100000 CHECK (round_modulus > 0),
  round_min_minor  bigint    NOT NULL DEFAULT 1000000 CHECK (round_min_minor > 0),
  updated_by       char(26)  REFERENCES users (id),
  updated_at       timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE audit_findings (
  id           char(26)     PRIMARY KEY,
  tenant_id    char(26)     NOT NULL REFERENCES tenants (id),
  rule         text         NOT NULL CHECK (rule IN ('dup_doc', 'dup_payment', 'dup_entry', 'outlier', 'backdated', 'round_manual', 'cash_large', 'numbering_gap')),
  severity     text         NOT NULL CHECK (severity IN ('high', 'medium', 'low')),
  subject_type text         NOT NULL CHECK (subject_type IN ('doc', 'payment', 'entry', 'gap')),
  subject_id   char(26),
  subject_key  varchar(80)  NOT NULL,
  amount_minor bigint       NOT NULL DEFAULT 0 CHECK (amount_minor >= 0),
  detail       jsonb        NOT NULL DEFAULT '{}'::jsonb,
  status       text         NOT NULL DEFAULT 'open' CHECK (status IN ('open', 'confirmed', 'dismissed')),
  note         varchar(300),
  reviewed_by  char(26)     REFERENCES users (id),
  reviewed_at  timestamptz,
  found_at     timestamptz  NOT NULL DEFAULT now(),
  UNIQUE (tenant_id, rule, subject_key),
  CONSTRAINT review_shape CHECK ((status = 'open') = (reviewed_at IS NULL)),
  CONSTRAINT dismiss_needs_note CHECK (status <> 'dismissed' OR btrim(coalesce(note, '')) <> '')
);
CREATE INDEX audit_findings_status ON audit_findings (tenant_id, status, severity, found_at DESC);

CREATE TABLE audit_items (
  id         char(26)    PRIMARY KEY,
  tenant_id  char(26)    NOT NULL REFERENCES tenants (id),
  label      varchar(120) NOT NULL CHECK (btrim(label) <> ''),
  sort       smallint    NOT NULL DEFAULT 100,
  active     boolean     NOT NULL DEFAULT true,
  created_by char(26)    NOT NULL REFERENCES users (id),
  created_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (id, tenant_id)
);

CREATE TABLE audit_item_done (
  item_id    char(26)    NOT NULL,
  tenant_id  char(26)    NOT NULL REFERENCES tenants (id),
  period     char(7)     NOT NULL CHECK (period ~ '^20[0-9]{2}-(0[1-9]|1[0-2])$'),
  done_on    date        NOT NULL,
  note       varchar(200),
  done_by    char(26)    NOT NULL REFERENCES users (id),
  created_at timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY (item_id, period),
  FOREIGN KEY (item_id, tenant_id) REFERENCES audit_items (id, tenant_id)
);

DO $$
DECLARE t text;
BEGIN
  FOREACH t IN ARRAY ARRAY['audit_settings', 'audit_findings', 'audit_items', 'audit_item_done'] LOOP
    EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', t);
    EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY', t);
    EXECUTE format('CREATE POLICY tenant_isolation ON %I USING (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin())) WITH CHECK (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()))', t);
    EXECUTE format('REVOKE ALL ON %I FROM jarvis_app_rw, jarvis_admin', t);
    EXECUTE format('GRANT SELECT ON %I TO jarvis_app_rw, jarvis_admin', t);
  END LOOP;
END $$;

-- Thresholds.  result: ok | bad_input
CREATE FUNCTION audit_settings_save(p_tenant char(26), p_user char(26), p_dup integer, p_factor integer, p_hist integer, p_back integer, p_cash bigint, p_mod bigint, p_min bigint) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), '');
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  IF NOT coalesce(p_dup BETWEEN 0 AND 30 AND p_factor BETWEEN 2 AND 100 AND p_hist BETWEEN 3 AND 100 AND p_back BETWEEN 1 AND 365 AND p_cash > 0 AND p_mod > 0 AND p_min > 0, false) THEN RETURN 'bad_input'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  INSERT INTO audit_settings (tenant_id, dup_days, outlier_factor, min_history, backdate_days, cash_limit_minor, round_modulus, round_min_minor, updated_by)
  VALUES (p_tenant, p_dup, p_factor, p_hist, p_back, p_cash, p_mod, p_min, p_user)
  ON CONFLICT (tenant_id) DO UPDATE SET dup_days = p_dup, outlier_factor = p_factor, min_history = p_hist, backdate_days = p_back, cash_limit_minor = p_cash, round_modulus = p_mod, round_min_minor = p_min, updated_by = p_user, updated_at = now();
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

-- Look through the books and record NEW findings (existing ones - open, confirmed or dismissed - are never touched or re-raised).  Returns how many were new.
CREATE FUNCTION audit_scan(p_tenant char(26), p_user char(26)) RETURNS integer
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); s audit_settings%ROWTYPE; n integer := 0; k integer;
BEGIN
  PERFORM acct_open(p_tenant);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO s FROM audit_settings WHERE tenant_id = p_tenant;
  IF NOT FOUND THEN s.dup_days := 3; s.outlier_factor := 5; s.min_history := 5; s.backdate_days := 30; s.cash_limit_minor := 5000000; s.round_modulus := 100000; s.round_min_minor := 1000000; END IF;

  -- same kind + party + total, issued within dup_days of each other (the later one is the subject)
  INSERT INTO audit_findings (id, tenant_id, rule, severity, subject_type, subject_id, subject_key, amount_minor, detail)
  SELECT credit_new_id(), p_tenant, 'dup_doc', 'high', 'doc', b.id, a.id || ':' || b.id, b.total_minor,
         jsonb_build_object('first', a.number, 'second', b.number, 'kind', b.kind, 'days', abs(b.issue_date - a.issue_date))
  FROM acct_docs a JOIN acct_docs b ON b.tenant_id = a.tenant_id AND b.kind = a.kind AND b.party_id IS NOT DISTINCT FROM a.party_id AND b.total_minor = a.total_minor
       AND (a.created_at, a.id) < (b.created_at, b.id) AND abs(b.issue_date - a.issue_date) <= s.dup_days
  WHERE a.tenant_id = p_tenant AND a.status <> 'void' AND b.status <> 'void' AND (a.party_id IS NOT NULL OR a.kind = 'expense')
  ON CONFLICT DO NOTHING;
  GET DIAGNOSTICS k = ROW_COUNT; n := n + k;

  -- the same payment typed twice: same document, same amount, same day, not cancelled
  INSERT INTO audit_findings (id, tenant_id, rule, severity, subject_type, subject_id, subject_key, amount_minor, detail)
  SELECT credit_new_id(), p_tenant, 'dup_payment', 'high', 'payment', b.id, a.id || ':' || b.id, b.amount_minor,
         jsonb_build_object('doc', d.number, 'date', b.pay_date)
  FROM acct_payments a JOIN acct_payments b ON b.tenant_id = a.tenant_id AND b.doc_id = a.doc_id AND b.amount_minor = a.amount_minor AND b.pay_date = a.pay_date AND (a.created_at, a.id) < (b.created_at, b.id)
       JOIN acct_docs d ON d.id = b.doc_id AND d.tenant_id = b.tenant_id
  WHERE a.tenant_id = p_tenant AND a.voided_at IS NULL AND b.voided_at IS NULL
  ON CONFLICT DO NOTHING;
  GET DIAGNOSTICS k = ROW_COUNT; n := n + k;

  -- manual journal entries on the same date with the same memo and the same total
  INSERT INTO audit_findings (id, tenant_id, rule, severity, subject_type, subject_id, subject_key, amount_minor, detail)
  WITH t AS (SELECT e.id, e.number, e.entry_date, lower(btrim(e.memo)) AS memo, e.created_at, (SELECT sum(l.debit_minor) FROM journal_lines l WHERE l.entry_id = e.id AND l.tenant_id = e.tenant_id) AS total
             FROM journal_entries e WHERE e.tenant_id = p_tenant AND e.source = 'manual'
               AND NOT EXISTS (SELECT 1 FROM journal_entries r WHERE r.reverses_id = e.id AND r.tenant_id = e.tenant_id))
  SELECT credit_new_id(), p_tenant, 'dup_entry', 'medium', 'entry', b.id, a.id || ':' || b.id, b.total, jsonb_build_object('first', a.number, 'second', b.number, 'date', b.entry_date)
  FROM t a JOIN t b ON b.entry_date = a.entry_date AND b.memo = a.memo AND b.total = a.total AND (a.created_at, a.id) < (b.created_at, b.id)
  ON CONFLICT DO NOTHING;
  GET DIAGNOSTICS k = ROW_COUNT; n := n + k;

  -- unusually large: total is outlier_factor times the party's median earlier total (needs min_history earlier documents)
  INSERT INTO audit_findings (id, tenant_id, rule, severity, subject_type, subject_id, subject_key, amount_minor, detail)
  SELECT credit_new_id(), p_tenant, 'outlier', 'medium', 'doc', d.id, d.id::text, d.total_minor, jsonb_build_object('number', d.number, 'kind', d.kind, 'usual', h.med::bigint, 'times', round((d.total_minor / h.med)::numeric, 1))
  FROM acct_docs d CROSS JOIN LATERAL (
         SELECT percentile_cont(0.5) WITHIN GROUP (ORDER BY p.total_minor) AS med, count(*) AS cnt
         FROM acct_docs p WHERE p.tenant_id = d.tenant_id AND p.kind = d.kind AND p.party_id = d.party_id AND p.status <> 'void' AND (p.issue_date, p.created_at, p.id) < (d.issue_date, d.created_at, d.id)) h
  WHERE d.tenant_id = p_tenant AND d.status <> 'void' AND d.party_id IS NOT NULL AND h.cnt >= s.min_history AND d.total_minor > h.med * s.outlier_factor
  ON CONFLICT DO NOTHING;
  GET DIAGNOSTICS k = ROW_COUNT; n := n + k;

  -- entered long after its date
  INSERT INTO audit_findings (id, tenant_id, rule, severity, subject_type, subject_id, subject_key, amount_minor, detail)
  SELECT credit_new_id(), p_tenant, 'backdated', 'medium', 'entry', e.id, e.id::text, coalesce((SELECT sum(l.debit_minor) FROM journal_lines l WHERE l.entry_id = e.id AND l.tenant_id = e.tenant_id), 0),
         jsonb_build_object('number', e.number, 'date', e.entry_date, 'late_days', (e.created_at AT TIME ZONE 'Asia/Dhaka')::date - e.entry_date)
  FROM journal_entries e WHERE e.tenant_id = p_tenant AND e.source IN ('manual', 'payment') AND (e.created_at AT TIME ZONE 'Asia/Dhaka')::date - e.entry_date > s.backdate_days
  ON CONFLICT DO NOTHING;
  GET DIAGNOSTICS k = ROW_COUNT; n := n + k;

  -- large round-number manual entries
  INSERT INTO audit_findings (id, tenant_id, rule, severity, subject_type, subject_id, subject_key, amount_minor, detail)
  SELECT credit_new_id(), p_tenant, 'round_manual', 'low', 'entry', t.id, t.id::text, t.total, jsonb_build_object('number', t.number, 'date', t.entry_date)
  FROM (SELECT e.id, e.number, e.entry_date, (SELECT sum(l.debit_minor) FROM journal_lines l WHERE l.entry_id = e.id AND l.tenant_id = e.tenant_id) AS total
        FROM journal_entries e WHERE e.tenant_id = p_tenant AND e.source = 'manual') t
  WHERE t.total >= s.round_min_minor AND t.total % s.round_modulus = 0
  ON CONFLICT DO NOTHING;
  GET DIAGNOSTICS k = ROW_COUNT; n := n + k;

  -- large payments out of the cash account (expenses paid in cash, and payments on documents)
  INSERT INTO audit_findings (id, tenant_id, rule, severity, subject_type, subject_id, subject_key, amount_minor, detail)
  SELECT credit_new_id(), p_tenant, 'cash_large', 'medium', 'doc', d.id, 'e:' || d.id, d.total_minor, jsonb_build_object('number', d.number, 'kind', d.kind)
  FROM acct_docs d JOIN accounts a ON a.id = d.pay_account_id AND a.tenant_id = d.tenant_id
  WHERE d.tenant_id = p_tenant AND d.kind = 'expense' AND d.status <> 'void' AND a.system_key = 'cash' AND d.total_minor >= s.cash_limit_minor
  ON CONFLICT DO NOTHING;
  GET DIAGNOSTICS k = ROW_COUNT; n := n + k;
  INSERT INTO audit_findings (id, tenant_id, rule, severity, subject_type, subject_id, subject_key, amount_minor, detail)
  SELECT credit_new_id(), p_tenant, 'cash_large', 'medium', 'payment', p.id, 'p:' || p.id, p.amount_minor, jsonb_build_object('number', d.number, 'kind', d.kind)
  FROM acct_payments p JOIN accounts a ON a.id = p.account_id AND a.tenant_id = p.tenant_id JOIN acct_docs d ON d.id = p.doc_id AND d.tenant_id = p.tenant_id
  WHERE p.tenant_id = p_tenant AND p.voided_at IS NULL AND a.system_key = 'cash' AND p.amount_minor >= s.cash_limit_minor
  ON CONFLICT DO NOTHING;
  GET DIAGNOSTICS k = ROW_COUNT; n := n + k;

  -- entry numbers are never reused or deleted: a hole means something was removed or bypassed
  INSERT INTO audit_findings (id, tenant_id, rule, severity, subject_type, subject_id, subject_key, amount_minor, detail)
  SELECT credit_new_id(), p_tenant, 'numbering_gap', 'high', 'gap', NULL, g.lo || '-' || g.hi, 0, jsonb_build_object('from', g.lo, 'to', g.hi)
  FROM (SELECT prev_n + 1 AS lo, number - 1 AS hi FROM (SELECT number, coalesce(lag(number) OVER (ORDER BY number), 0) AS prev_n FROM journal_entries WHERE tenant_id = p_tenant) x WHERE number - prev_n > 1) g
  ON CONFLICT DO NOTHING;
  GET DIAGNOSTICS k = ROW_COUNT; n := n + k;

  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN n;
END $$;

-- Record the person's judgement.  status: confirmed | dismissed (needs a note) | open (re-open).  result: ok | need_note | bad_status ; unknown/foreign id -> forbidden
CREATE FUNCTION audit_review(p_id char(26), p_tenant char(26), p_user char(26), p_status text, p_note text) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); n integer;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  IF p_status IS NULL OR p_status NOT IN ('confirmed', 'dismissed', 'open') THEN RETURN 'bad_status'; END IF;
  IF p_status = 'dismissed' AND btrim(coalesce(p_note, '')) = '' THEN RETURN 'need_note'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE audit_findings SET status = p_status, note = left(nullif(btrim(coalesce(p_note, '')), ''), 300),
         reviewed_by = CASE WHEN p_status = 'open' THEN NULL ELSE p_user END, reviewed_at = CASE WHEN p_status = 'open' THEN NULL ELSE now() END
   WHERE id = p_id AND tenant_id = p_tenant;
  GET DIAGNOSTICS n = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  IF n = 0 THEN RAISE EXCEPTION 'forbidden' USING ERRCODE = '42501'; END IF;
  RETURN 'ok';
END $$;

-- Checklist items: create (unknown id) or change (known id).  result: ok | bad_input
CREATE FUNCTION audit_item_save(p_id char(26), p_tenant char(26), p_user char(26), p_label text, p_sort integer) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); found boolean;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  IF p_label IS NULL OR btrim(p_label) = '' OR p_sort IS NULL OR p_sort NOT BETWEEN 0 AND 1000 THEN RETURN 'bad_input'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT true INTO found FROM audit_items WHERE id = p_id AND tenant_id = p_tenant;
  IF found THEN
    UPDATE audit_items SET label = left(btrim(p_label), 120), sort = p_sort WHERE id = p_id;
  ELSE
    IF EXISTS (SELECT 1 FROM audit_items WHERE id = p_id) THEN PERFORM set_config('app.bypass_rls', prev, true); RAISE EXCEPTION 'forbidden' USING ERRCODE = '42501'; END IF;
    INSERT INTO audit_items (id, tenant_id, label, sort, created_by) VALUES (p_id, p_tenant, left(btrim(p_label), 120), p_sort, p_user);
  END IF;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

CREATE FUNCTION audit_item_active(p_id char(26), p_tenant char(26), p_active boolean) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); n integer;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE audit_items SET active = coalesce(p_active, true) WHERE id = p_id AND tenant_id = p_tenant;
  GET DIAGNOSTICS n = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  IF n = 0 THEN RAISE EXCEPTION 'forbidden' USING ERRCODE = '42501'; END IF;
  RETURN 'ok';
END $$;

-- Tick an item for a month ('YYYY-MM', not a future month).  result: ok | bad_period | bad_date | already
CREATE FUNCTION audit_item_done(p_id char(26), p_tenant char(26), p_user char(26), p_period text, p_done date, p_note text) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); today date := (now() AT TIME ZONE 'Asia/Dhaka')::date;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  IF p_period IS NULL OR p_period !~ '^20[0-9]{2}-(0[1-9]|1[0-2])$' OR p_period > to_char(today, 'YYYY-MM') THEN RETURN 'bad_period'; END IF;
  IF p_done IS NULL OR p_done > today OR p_done < DATE '2012-07-01' THEN RETURN 'bad_date'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  IF NOT EXISTS (SELECT 1 FROM audit_items WHERE id = p_id AND tenant_id = p_tenant) THEN PERFORM set_config('app.bypass_rls', prev, true); RAISE EXCEPTION 'forbidden' USING ERRCODE = '42501'; END IF;
  IF EXISTS (SELECT 1 FROM audit_item_done WHERE item_id = p_id AND period = p_period) THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'already'; END IF;
  INSERT INTO audit_item_done (item_id, tenant_id, period, done_on, note, done_by) VALUES (p_id, p_tenant, p_period, p_done, left(nullif(btrim(coalesce(p_note, '')), ''), 200), p_user);
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

CREATE FUNCTION audit_item_undo(p_id char(26), p_tenant char(26), p_period text) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); n integer;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  PERFORM set_config('app.bypass_rls', 'on', true);
  DELETE FROM audit_item_done WHERE item_id = p_id AND tenant_id = p_tenant AND period = p_period;
  GET DIAGNOSTICS n = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN n = 0 THEN 'missing' ELSE 'ok' END;
END $$;

-- Starter checklist (once).  result: ok | already
CREATE FUNCTION audit_item_seed(p_tenant char(26), p_user char(26)) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), '');
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  IF EXISTS (SELECT 1 FROM audit_items WHERE tenant_id = p_tenant) THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'already'; END IF;
  INSERT INTO audit_items (id, tenant_id, label, sort, created_by)
  SELECT credit_new_id(), p_tenant, x.label, x.sort, p_user FROM (VALUES
    ('ব্যাংক/বিকাশ/নগদ স্টেটমেন্ট মেলানো হয়েছে', 10), ('ক্যাশ গণনা করে হিসাবের সাথে মেলানো হয়েছে', 20), ('পাওনা/দেনার তালিকা পর্যালোচনা হয়েছে', 30),
    ('মজুত গণনা ও মূল্যায়ন মেলানো হয়েছে', 40), ('স্থায়ী সম্পদের তালিকা ও অবচয় দেখা হয়েছে', 50), ('মূসক রিটার্ন ও কর জমার হিসাব মেলানো হয়েছে', 60),
    ('খোলা অডিট-ফাইন্ডিংগুলো দেখা হয়েছে', 70), ('মাস বন্ধ (লক) করা হয়েছে', 80)) AS x(label, sort);
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

-- A few automatic checks on the books right now.  ok = nothing to look at.
CREATE FUNCTION audit_health(p_tenant char(26)) RETURNS TABLE (check_key text, ok boolean, cnt bigint, amt bigint)
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); s acct_settings%ROWTYPE;
BEGIN
  s := acct_open(p_tenant);
  PERFORM set_config('app.bypass_rls', 'on', true);
  RETURN QUERY SELECT 'open_high', c = 0, c, 0::bigint FROM (SELECT count(*) AS c FROM audit_findings WHERE tenant_id = p_tenant AND status = 'open' AND severity = 'high') x;
  RETURN QUERY SELECT 'books_balanced', d = c, 0::bigint, abs(d - c)::bigint FROM (SELECT coalesce(sum(debit_minor), 0) AS d, coalesce(sum(credit_minor), 0) AS c FROM journal_lines WHERE tenant_id = p_tenant) x;
  RETURN QUERY SELECT 'stale_bank_lines', c = 0, c, a::bigint FROM (SELECT count(*) AS c, coalesce(sum(abs(amount_minor)), 0) AS a FROM bank_lines WHERE tenant_id = p_tenant AND status = 'unmatched' AND txn_date < (now() AT TIME ZONE 'Asia/Dhaka')::date - 30) x;
  RETURN QUERY SELECT 'overdue_bills', c = 0, c, a::bigint FROM (SELECT count(*) AS c, coalesce(sum(total_minor - paid_minor), 0) AS a FROM acct_docs WHERE tenant_id = p_tenant AND kind = 'bill' AND status = 'open' AND due_date < (now() AT TIME ZONE 'Asia/Dhaka')::date) x;
  RETURN QUERY SELECT 'tax_undeposited', c = 0, c, a::bigint FROM (SELECT count(*) AS c, coalesce(sum(w.amount_minor), 0) AS a FROM tax_withholdings w JOIN acct_payments p ON p.id = w.payment_id AND p.tenant_id = w.tenant_id
                 WHERE w.tenant_id = p_tenant AND w.direction = 'deducted' AND w.deposit_id IS NULL AND p.voided_at IS NULL) x;
  RETURN QUERY SELECT 'period_locked', s.locked_through IS NOT NULL AND s.locked_through >= ((now() AT TIME ZONE 'Asia/Dhaka')::date - 60), 0::bigint, 0::bigint;
  PERFORM set_config('app.bypass_rls', prev, true);
END $$;

REVOKE ALL ON FUNCTION audit_settings_save(char, char, integer, integer, integer, integer, bigint, bigint, bigint), audit_scan(char, char), audit_review(char, char, char, text, text), audit_item_save(char, char, char, text, integer),
  audit_item_active(char, char, boolean), audit_item_done(char, char, char, text, date, text), audit_item_undo(char, char, text), audit_item_seed(char, char), audit_health(char) FROM PUBLIC, jarvis_app_rw, jarvis_admin;
GRANT EXECUTE ON FUNCTION audit_settings_save(char, char, integer, integer, integer, integer, bigint, bigint, bigint), audit_scan(char, char), audit_review(char, char, char, text, text), audit_item_save(char, char, char, text, integer),
  audit_item_active(char, char, boolean), audit_item_done(char, char, char, text, date, text), audit_item_undo(char, char, text), audit_item_seed(char, char), audit_health(char) TO jarvis_app_rw, jarvis_admin;
