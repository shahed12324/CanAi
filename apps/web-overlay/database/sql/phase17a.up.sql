-- Phase 17a: VAT (মূসক). Settings, effective-dated rates, "can this purchase VAT be claimed" flags, and saved return snapshots.
-- Nothing here posts to the books: VAT is already booked per document (acct_docs.vat_minor -> vat_output / vat_input). This phase reads those documents into the
-- purchase / sales registers and a return DRAFT, and lets a person record what was actually filed. Every rate, period and date is DATA a person can change: the rules
-- are not in code because NBR rules change and sources disagree (monthly vs quarterly return) - the person / accountant decides, a professional reviews.

CREATE TABLE vat_settings (
  tenant_id     char(26)    PRIMARY KEY REFERENCES tenants (id),
  registered    boolean     NOT NULL DEFAULT false,
  bin           varchar(13) CHECK (bin IS NULL OR bin ~ '^[0-9]{9,13}$'),
  filing_period text        NOT NULL DEFAULT 'monthly' CHECK (filing_period IN ('monthly', 'quarterly')),
  updated_by    char(26)    REFERENCES users (id),
  updated_at    timestamptz NOT NULL DEFAULT now(),
  CONSTRAINT registered_has_bin CHECK (NOT registered OR bin IS NOT NULL)
);

CREATE TABLE vat_rates (
  id             char(26)    PRIMARY KEY,
  tenant_id      char(26)    NOT NULL REFERENCES tenants (id),
  code           varchar(20) NOT NULL CHECK (code ~ '^[A-Za-z0-9._-]{1,20}$'),
  label          varchar(80) NOT NULL CHECK (btrim(label) <> ''),
  rate_bp        integer     NOT NULL CHECK (rate_bp BETWEEN 0 AND 10000),      -- basis points: 1500 = 15%
  effective_from date        NOT NULL,
  effective_to   date,
  created_by     char(26)    NOT NULL REFERENCES users (id),
  created_at     timestamptz NOT NULL DEFAULT now(),
  UNIQUE (tenant_id, code, effective_from),
  CONSTRAINT rate_range CHECK (effective_to IS NULL OR effective_to >= effective_from)
);
CREATE UNIQUE INDEX vat_rates_one_open ON vat_rates (tenant_id, code) WHERE effective_to IS NULL;

-- Per purchase document: may its VAT be claimed as input credit? Default yes (when the document has VAT). A person turns it off with a reason (no valid Mushak-6.3, cash over limit, ...).
CREATE TABLE vat_doc_flags (
  doc_id     char(26)     NOT NULL,
  tenant_id  char(26)     NOT NULL REFERENCES tenants (id),
  creditable boolean      NOT NULL,
  reason     varchar(120),
  updated_by char(26)     NOT NULL REFERENCES users (id),
  updated_at timestamptz  NOT NULL DEFAULT now(),
  PRIMARY KEY (doc_id),
  FOREIGN KEY (doc_id, tenant_id) REFERENCES acct_docs (id, tenant_id),
  CONSTRAINT reason_when_blocked CHECK (creditable OR btrim(coalesce(reason, '')) <> '')
);

CREATE TABLE vat_returns (
  id          char(26)    PRIMARY KEY,
  tenant_id   char(26)    NOT NULL REFERENCES tenants (id),
  period_from date        NOT NULL,
  period_to   date        NOT NULL,
  status      text        NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'filed')),
  figures     jsonb       NOT NULL,
  note        varchar(200),
  filed_on    date,
  filed_ref   varchar(60),
  created_by  char(26)    NOT NULL REFERENCES users (id),
  created_at  timestamptz NOT NULL DEFAULT now(),
  updated_at  timestamptz NOT NULL DEFAULT now(),
  UNIQUE (id, tenant_id),
  CONSTRAINT period_order CHECK (period_to >= period_from AND period_to - period_from <= 366),
  CONSTRAINT filed_rule CHECK ((status = 'filed') = (filed_on IS NOT NULL))
);
CREATE UNIQUE INDEX vat_returns_one_per_period ON vat_returns (tenant_id, period_from, period_to, status);

DO $$
DECLARE t text;
BEGIN
  FOREACH t IN ARRAY ARRAY['vat_settings', 'vat_rates', 'vat_doc_flags', 'vat_returns'] LOOP
    EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', t);
    EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY', t);
    EXECUTE format('CREATE POLICY tenant_isolation ON %I USING (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin())) WITH CHECK (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()))', t);
    EXECUTE format('REVOKE ALL ON %I FROM jarvis_app_rw, jarvis_admin', t);
    EXECUTE format('GRANT SELECT ON %I TO jarvis_app_rw, jarvis_admin', t);
  END LOOP;
END $$;

-- ---------------------------------------------------------------------------------------------------------------------
-- result: ok | bad_bin | bad_period
CREATE FUNCTION vat_settings_save(p_tenant char(26), p_user char(26), p_registered boolean, p_bin text, p_period text) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); b text := nullif(btrim(coalesce(p_bin, '')), '');
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  IF p_period IS NULL OR p_period NOT IN ('monthly', 'quarterly') THEN RETURN 'bad_period'; END IF;
  IF (b IS NOT NULL AND b !~ '^[0-9]{9,13}$') OR (coalesce(p_registered, false) AND b IS NULL) THEN RETURN 'bad_bin'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  INSERT INTO vat_settings (tenant_id, registered, bin, filing_period, updated_by) VALUES (p_tenant, coalesce(p_registered, false), b, p_period, p_user)
    ON CONFLICT (tenant_id) DO UPDATE SET registered = EXCLUDED.registered, bin = EXCLUDED.bin, filing_period = EXCLUDED.filing_period, updated_by = p_user, updated_at = now();
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

-- Starter rates, only when the workspace has none: standard 15%, trader 7.5%, zero. They are STARTING VALUES from public summaries - verify with NBR / your accountant.
-- result: ok | already
CREATE FUNCTION vat_rate_seed(p_tenant char(26), p_user char(26)) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); n integer;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT count(*) INTO n FROM vat_rates WHERE tenant_id = p_tenant;
  IF n > 0 THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'already'; END IF;
  INSERT INTO vat_rates (id, tenant_id, code, label, rate_bp, effective_from, created_by) VALUES
    (credit_new_id(), p_tenant, 'STD', 'স্ট্যান্ডার্ড হার', 1500, DATE '2012-07-01', p_user),
    (credit_new_id(), p_tenant, 'TRADER', 'ট্রেডার হার', 750, DATE '2012-07-01', p_user),
    (credit_new_id(), p_tenant, 'ZERO', 'শূন্য হার (রপ্তানি ইত্যাদি)', 0, DATE '2012-07-01', p_user);
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

-- A new rate for a code from a date on: the code's open rate ends the day before. result: ok | bad_input | bad_date | overlap
CREATE FUNCTION vat_rate_save(p_id char(26), p_tenant char(26), p_user char(26), p_code text, p_label text, p_bp integer, p_from date) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); o vat_rates%ROWTYPE;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  IF p_code IS NULL OR p_code !~ '^[A-Za-z0-9._-]{1,20}$' OR p_label IS NULL OR btrim(p_label) = '' OR p_bp IS NULL OR p_bp < 0 OR p_bp > 10000 THEN RETURN 'bad_input'; END IF;
  IF p_from IS NULL OR p_from < DATE '2012-07-01' OR p_from > (now() AT TIME ZONE 'Asia/Dhaka')::date + 366 THEN RETURN 'bad_date'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  IF EXISTS (SELECT 1 FROM vat_rates WHERE tenant_id = p_tenant AND code = p_code AND (effective_from >= p_from OR (effective_to IS NOT NULL AND effective_to >= p_from))) THEN
    PERFORM set_config('app.bypass_rls', prev, true); RETURN 'overlap';
  END IF;
  UPDATE vat_rates SET effective_to = p_from - 1 WHERE tenant_id = p_tenant AND code = p_code AND effective_to IS NULL;
  INSERT INTO vat_rates (id, tenant_id, code, label, rate_bp, effective_from, created_by) VALUES (p_id, p_tenant, p_code, left(btrim(p_label), 80), p_bp, p_from, p_user);
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

-- Claimable or not, for one purchase document with VAT. result: ok | not_purchase | no_vat | need_reason | not_found(raises forbidden for another workspace)
CREATE FUNCTION vat_doc_flag(p_doc char(26), p_user char(26), p_creditable boolean, p_reason text) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); d acct_docs%ROWTYPE; r text := nullif(btrim(coalesce(p_reason, '')), '');
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO d FROM acct_docs WHERE id = p_doc;
  PERFORM set_config('app.bypass_rls', prev, true);
  IF d.id IS NULL THEN RAISE EXCEPTION 'forbidden' USING ERRCODE = '42501'; END IF;
  PERFORM credit_authorize(d.tenant_id, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  IF d.kind NOT IN ('bill', 'expense') THEN RETURN 'not_purchase'; END IF;
  IF d.vat_minor = 0 THEN RETURN 'no_vat'; END IF;
  IF NOT coalesce(p_creditable, true) AND r IS NULL THEN RETURN 'need_reason'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  INSERT INTO vat_doc_flags (doc_id, tenant_id, creditable, reason, updated_by) VALUES (p_doc, d.tenant_id, coalesce(p_creditable, true), CASE WHEN coalesce(p_creditable, true) THEN NULL ELSE left(r, 120) END, p_user)
    ON CONFLICT (doc_id) DO UPDATE SET creditable = EXCLUDED.creditable, reason = EXCLUDED.reason, updated_by = p_user, updated_at = now();
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

-- Save (or refresh) the DRAFT for a period. The figures are computed by the app from the registers; the database checks their shape and refuses once that period is filed.
-- result: ok | bad_period | bad_input | filed
CREATE FUNCTION vat_return_save(p_id char(26), p_tenant char(26), p_user char(26), p_from date, p_to date, p_figures jsonb, p_note text) RETURNS TABLE (result text, return_id char(26))
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); ex char(26); k text;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  IF p_from IS NULL OR p_to IS NULL OR p_to < p_from OR p_to - p_from > 366 THEN RETURN QUERY SELECT 'bad_period'::text, NULL::char(26); RETURN; END IF;
  IF p_figures IS NULL OR jsonb_typeof(p_figures) <> 'object' THEN RETURN QUERY SELECT 'bad_input'::text, NULL::char(26); RETURN; END IF;
  FOREACH k IN ARRAY ARRAY['output_vat_minor', 'input_vat_minor', 'net_minor'] LOOP
    IF jsonb_typeof(p_figures -> k) IS DISTINCT FROM 'number' THEN RETURN QUERY SELECT 'bad_input'::text, NULL::char(26); RETURN; END IF;
  END LOOP;
  PERFORM set_config('app.bypass_rls', 'on', true);
  IF EXISTS (SELECT 1 FROM vat_returns WHERE tenant_id = p_tenant AND period_from = p_from AND period_to = p_to AND status = 'filed') THEN
    PERFORM set_config('app.bypass_rls', prev, true); RETURN QUERY SELECT 'filed'::text, NULL::char(26); RETURN;
  END IF;
  SELECT id INTO ex FROM vat_returns WHERE tenant_id = p_tenant AND period_from = p_from AND period_to = p_to AND status = 'draft';
  IF ex IS NULL THEN
    INSERT INTO vat_returns (id, tenant_id, period_from, period_to, figures, note, created_by) VALUES (p_id, p_tenant, p_from, p_to, p_figures, left(p_note, 200), p_user);
    ex := p_id;
  ELSE
    UPDATE vat_returns SET figures = p_figures, note = left(p_note, 200), updated_at = now() WHERE id = ex;
  END IF;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN QUERY SELECT 'ok'::text, ex;
END $$;

-- Record that the draft was submitted to NBR (the person did that on NBR's own system). result: ok | not_draft | bad_date | bad_ref
CREATE FUNCTION vat_return_file(p_id char(26), p_user char(26), p_on date, p_ref text) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); r vat_returns%ROWTYPE;
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO r FROM vat_returns WHERE id = p_id FOR UPDATE;
  PERFORM set_config('app.bypass_rls', prev, true);
  IF r.id IS NULL THEN RAISE EXCEPTION 'forbidden' USING ERRCODE = '42501'; END IF;
  PERFORM credit_authorize(r.tenant_id, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  IF r.status <> 'draft' THEN RETURN 'not_draft'; END IF;
  IF p_on IS NULL OR p_on < r.period_from OR p_on > (now() AT TIME ZONE 'Asia/Dhaka')::date + 1 THEN RETURN 'bad_date'; END IF;
  IF p_ref IS NOT NULL AND length(p_ref) > 60 THEN RETURN 'bad_ref'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE vat_returns SET status = 'filed', filed_on = p_on, filed_ref = nullif(btrim(coalesce(p_ref, '')), ''), updated_at = now() WHERE id = p_id;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

REVOKE ALL ON FUNCTION vat_settings_save(char, char, boolean, text, text), vat_rate_seed(char, char), vat_rate_save(char, char, char, text, text, integer, date), vat_doc_flag(char, char, boolean, text),
  vat_return_save(char, char, char, date, date, jsonb, text), vat_return_file(char, char, date, text) FROM PUBLIC, jarvis_app_rw, jarvis_admin;
GRANT EXECUTE ON FUNCTION vat_settings_save(char, char, boolean, text, text), vat_rate_seed(char, char), vat_rate_save(char, char, char, text, text, integer, date), vat_doc_flag(char, char, boolean, text),
  vat_return_save(char, char, char, date, date, jsonb, text), vat_return_file(char, char, date, text) TO jarvis_app_rw, jarvis_admin;
