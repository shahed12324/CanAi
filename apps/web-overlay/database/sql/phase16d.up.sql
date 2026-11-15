-- Phase 16d: AI bill scan. A photo / PDF of a purchase bill is read by a vision model into a DRAFT; a person reviews it and only then is a bill / expense posted.
--   * the scan row keeps what the model read (extracted jsonb, flags) and what it cost; it never posts anything by itself;
--   * the same file (sha256) is scanned once while a scan is live (scanning / ready / used) — a re-upload points at the existing one and costs nothing;
--   * a scan becomes a document exactly once: bill_scan_post takes the scan row's lock, creates the document through acct_doc_create (all the usual rules: period lock, accounts, balance)
--     and marks the scan used in the same transaction — a refused document leaves the scan ready to fix and retry;
--   * a scan stuck in `scanning` (crash) is closed as failed after 10 minutes (its credit reservation is returned by credit_release_stale).
-- NB: PERFORM sets FOUND, so existence is tested on a row's id, never on FOUND after a set_config.

CREATE TABLE bill_scans (
  id             char(26)     PRIMARY KEY,
  tenant_id      char(26)     NOT NULL REFERENCES tenants (id),
  created_by     char(26)     NOT NULL REFERENCES users (id),
  file_name      varchar(120) NOT NULL CHECK (btrim(file_name) <> ''),
  mime           text         NOT NULL CHECK (mime IN ('image/jpeg', 'image/png', 'image/webp', 'application/pdf')),
  size_bytes     integer      NOT NULL CHECK (size_bytes BETWEEN 1 AND 10485760),
  sha256         char(64)     NOT NULL CHECK (sha256 ~ '^[0-9a-f]{64}$'),
  status         text         NOT NULL DEFAULT 'scanning' CHECK (status IN ('scanning', 'ready', 'failed', 'used', 'discarded')),
  error          varchar(60),
  model          varchar(100),
  input_tokens   integer      CHECK (input_tokens >= 0),
  output_tokens  integer      CHECK (output_tokens >= 0),
  credits        numeric(18,4) CHECK (credits >= 0),
  extracted      jsonb,
  flags          text[]       NOT NULL DEFAULT '{}',
  doc_id         char(26),
  created_at     timestamptz  NOT NULL DEFAULT now(),
  updated_at     timestamptz  NOT NULL DEFAULT now(),
  UNIQUE (id, tenant_id),
  FOREIGN KEY (doc_id, tenant_id) REFERENCES acct_docs (id, tenant_id),
  CONSTRAINT bill_scan_used_has_doc CHECK ((status = 'used') = (doc_id IS NOT NULL)),
  CONSTRAINT bill_scan_ready_has_data CHECK (status NOT IN ('ready', 'used') OR extracted IS NOT NULL)
);
CREATE UNIQUE INDEX bill_scans_live_file ON bill_scans (tenant_id, sha256) WHERE status IN ('scanning', 'ready', 'used');
CREATE INDEX bill_scans_list ON bill_scans (tenant_id, created_at DESC);

ALTER TABLE bill_scans ENABLE ROW LEVEL SECURITY;
ALTER TABLE bill_scans FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON bill_scans USING (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin())) WITH CHECK (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()));
REVOKE ALL ON bill_scans FROM jarvis_app_rw, jarvis_admin;
GRANT SELECT ON bill_scans TO jarvis_app_rw, jarvis_admin;

-- ---------------------------------------------------------------------------------------------------------------------
-- Register an upload. result: ok | duplicate (scan_id = the live scan of the same file) | bad_input
CREATE FUNCTION bill_scan_start(p_id char(26), p_tenant char(26), p_user char(26), p_name text, p_mime text, p_size integer, p_sha text) RETURNS TABLE (result text, scan_id char(26))
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); ex char(26);
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  IF p_name IS NULL OR btrim(p_name) = '' OR p_mime NOT IN ('image/jpeg', 'image/png', 'image/webp', 'application/pdf') OR p_size IS NULL OR p_size < 1 OR p_size > 10485760
     OR p_sha IS NULL OR p_sha !~ '^[0-9a-f]{64}$' THEN RETURN QUERY SELECT 'bad_input'::text, NULL::char(26); RETURN; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE bill_scans SET status = 'failed', error = 'timeout', updated_at = now() WHERE tenant_id = p_tenant AND status = 'scanning' AND created_at < now() - interval '10 minutes';
  SELECT id INTO ex FROM bill_scans WHERE tenant_id = p_tenant AND sha256 = p_sha AND status IN ('scanning', 'ready', 'used');
  IF ex IS NOT NULL THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN QUERY SELECT 'duplicate'::text, ex; RETURN; END IF;
  INSERT INTO bill_scans (id, tenant_id, created_by, file_name, mime, size_bytes, sha256) VALUES (p_id, p_tenant, p_user, left(btrim(p_name), 120), p_mime, p_size, p_sha);
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN QUERY SELECT 'ok'::text, p_id;
END $$;

-- The model's draft arrived (scanning -> ready). result: ok | not_scanning | bad_input
CREATE FUNCTION bill_scan_ready(p_id char(26), p_extracted jsonb, p_flags text[], p_model text, p_in integer, p_out integer, p_credits numeric) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); s bill_scans%ROWTYPE;
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO s FROM bill_scans WHERE id = p_id FOR UPDATE;
  PERFORM set_config('app.bypass_rls', prev, true);
  IF s.id IS NULL THEN RAISE EXCEPTION 'forbidden' USING ERRCODE = '42501'; END IF;
  PERFORM credit_authorize(s.tenant_id, false);
  IF s.status <> 'scanning' THEN RETURN 'not_scanning'; END IF;
  IF p_extracted IS NULL OR jsonb_typeof(p_extracted) <> 'object' OR jsonb_typeof(p_extracted -> 'lines') IS DISTINCT FROM 'array' OR jsonb_array_length(p_extracted -> 'lines') = 0
     OR p_in < 0 OR p_out < 0 OR p_credits < 0 THEN RETURN 'bad_input'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE bill_scans SET status = 'ready', extracted = p_extracted, flags = coalesce(p_flags, '{}'), model = left(p_model, 100), input_tokens = p_in, output_tokens = p_out, credits = p_credits, error = NULL, updated_at = now() WHERE id = p_id;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

-- The scan failed (scanning -> failed). A failed scan frees the file for another try. Credits charged (the provider was paid even for an unreadable page) are recorded.
CREATE FUNCTION bill_scan_fail(p_id char(26), p_error text, p_model text, p_in integer, p_out integer, p_credits numeric) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); s bill_scans%ROWTYPE;
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO s FROM bill_scans WHERE id = p_id FOR UPDATE;
  PERFORM set_config('app.bypass_rls', prev, true);
  IF s.id IS NULL THEN RAISE EXCEPTION 'forbidden' USING ERRCODE = '42501'; END IF;
  PERFORM credit_authorize(s.tenant_id, false);
  IF s.status <> 'scanning' THEN RETURN 'not_scanning'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE bill_scans SET status = 'failed', error = left(coalesce(nullif(btrim(p_error), ''), 'failed'), 60), model = left(p_model, 100), input_tokens = greatest(p_in, 0), output_tokens = greatest(p_out, 0),
                        credits = greatest(p_credits, 0), updated_at = now() WHERE id = p_id;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

-- Put a draft aside (ready | failed -> discarded). result: ok | not_open
CREATE FUNCTION bill_scan_discard(p_id char(26), p_user char(26)) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); s bill_scans%ROWTYPE;
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO s FROM bill_scans WHERE id = p_id FOR UPDATE;
  PERFORM set_config('app.bypass_rls', prev, true);
  IF s.id IS NULL THEN RAISE EXCEPTION 'forbidden' USING ERRCODE = '42501'; END IF;
  PERFORM credit_authorize(s.tenant_id, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  IF s.status NOT IN ('ready', 'failed') THEN RETURN 'not_open'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE bill_scans SET status = 'discarded', updated_at = now() WHERE id = p_id;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

-- Turn a reviewed draft into a bill / paid expense, once. The values are what the person confirmed on screen (not the model's). result: not_ready | bad_kind | <acct_doc_create result>
CREATE FUNCTION bill_scan_post(p_scan char(26), p_doc char(26), p_entry char(26), p_user char(26), p_kind text, p_party char(26), p_date date, p_due date, p_ref text, p_lines jsonb, p_vat_minor bigint, p_pay_account char(26))
RETURNS TABLE (result text, doc_id char(26), number varchar, total_minor bigint)
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); s bill_scans%ROWTYPE; r record;
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO s FROM bill_scans WHERE id = p_scan FOR UPDATE;
  PERFORM set_config('app.bypass_rls', prev, true);
  IF s.id IS NULL THEN RAISE EXCEPTION 'forbidden' USING ERRCODE = '42501'; END IF;
  PERFORM credit_authorize(s.tenant_id, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  IF s.status <> 'ready' THEN RETURN QUERY SELECT 'not_ready'::text, NULL::char(26), NULL::varchar, NULL::bigint; RETURN; END IF;
  IF p_kind NOT IN ('bill', 'expense') THEN RETURN QUERY SELECT 'bad_kind'::text, NULL::char(26), NULL::varchar, NULL::bigint; RETURN; END IF;
  SELECT * INTO r FROM acct_doc_create(p_doc, p_entry, s.tenant_id, p_user, p_kind, p_party, p_date, p_due, p_ref, p_lines, p_vat_minor, p_pay_account);
  IF r.result = 'ok' THEN
    PERFORM set_config('app.bypass_rls', 'on', true);
    UPDATE bill_scans SET status = 'used', doc_id = p_doc, updated_at = now() WHERE id = p_scan;
    PERFORM set_config('app.bypass_rls', prev, true);
  END IF;
  RETURN QUERY SELECT r.result, r.doc_id, r.number, r.total_minor;
END $$;

REVOKE ALL ON FUNCTION bill_scan_start(char, char, char, text, text, integer, text), bill_scan_ready(char, jsonb, text[], text, integer, integer, numeric), bill_scan_fail(char, text, text, integer, integer, numeric),
  bill_scan_discard(char, char), bill_scan_post(char, char, char, char, text, char, date, date, text, jsonb, bigint, char) FROM PUBLIC, jarvis_app_rw, jarvis_admin;
GRANT EXECUTE ON FUNCTION bill_scan_start(char, char, char, text, text, integer, text), bill_scan_ready(char, jsonb, text[], text, integer, integer, numeric), bill_scan_fail(char, text, text, integer, integer, numeric),
  bill_scan_discard(char, char), bill_scan_post(char, char, char, char, text, char, date, date, text, jsonb, bigint, char) TO jarvis_app_rw, jarvis_admin;
