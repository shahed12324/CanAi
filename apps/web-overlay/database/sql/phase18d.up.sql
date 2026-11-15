-- Phase 18d: financial analyst. Saved reports (facts + forecast + Bengali explanation) and a fix for usage_records.source.

-- FIX (found while wiring the analyst's credit charge): the app settles AI usage with source 'catalog', 'bill_scan' and 'inbox', but the phase-5 CHECK only allowed
-- nine values, so credit_settle() raised for those features (the charge failed, the usage was never recorded). The allowed list now includes every source the app uses.
ALTER TABLE usage_records DROP CONSTRAINT usage_records_source_check;
ALTER TABLE usage_records ADD CONSTRAINT usage_records_source_check CHECK (source IN
  ('chat', 'agent', 'workflow', 'image', 'video', 'voice', 'email', 'storage', 'render', 'catalog', 'bill_scan', 'inbox', 'analyst'));

CREATE TABLE analyst_reports (
  id              char(26)      PRIMARY KEY,
  tenant_id       char(26)      NOT NULL REFERENCES tenants (id),
  created_by      char(26)      NOT NULL REFERENCES users (id),
  month_to        char(7)       NOT NULL CHECK (month_to ~ '^20[0-9]{2}-(0[1-9]|1[0-2])$'),         -- last COMPLETE month analysed
  facts           jsonb         NOT NULL,
  forecast        jsonb         NOT NULL,
  narrative       text          NOT NULL CHECK (btrim(narrative) <> '' AND length(narrative) <= 8000),
  source          text          NOT NULL CHECK (source IN ('ai', 'template')),
  note            varchar(40),                                                                       -- why the template was used (no_model | no_credits | provider | rejected_numbers ...)
  model           varchar(120),
  input_tokens    integer       NOT NULL DEFAULT 0 CHECK (input_tokens >= 0),
  output_tokens   integer       NOT NULL DEFAULT 0 CHECK (output_tokens >= 0),
  credits_charged numeric(18,4) NOT NULL DEFAULT 0 CHECK (credits_charged >= 0),
  created_at      timestamptz   NOT NULL DEFAULT now(),
  UNIQUE (id, tenant_id),
  CONSTRAINT ai_has_model CHECK (source <> 'ai' OR model IS NOT NULL)
);
CREATE INDEX analyst_reports_recent ON analyst_reports (tenant_id, created_at DESC);

ALTER TABLE analyst_reports ENABLE ROW LEVEL SECURITY;
ALTER TABLE analyst_reports FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON analyst_reports USING (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin())) WITH CHECK (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()));
REVOKE ALL ON analyst_reports FROM jarvis_app_rw, jarvis_admin;
GRANT SELECT ON analyst_reports TO jarvis_app_rw, jarvis_admin;

-- Save a finished report. result: ok | bad_input.  Only the application computes facts/forecast; this just stores them.
CREATE FUNCTION analyst_report_save(p_id char(26), p_tenant char(26), p_user char(26), p_month char(7), p_facts jsonb, p_forecast jsonb, p_narrative text, p_source text, p_note text, p_model text,
                                    p_in integer, p_out integer, p_credits numeric) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), '');
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  IF p_id IS NULL OR p_month IS NULL OR p_month !~ '^20[0-9]{2}-(0[1-9]|1[0-2])$' OR p_facts IS NULL OR p_forecast IS NULL OR jsonb_typeof(p_facts) <> 'object' OR jsonb_typeof(p_forecast) <> 'object'
     OR p_narrative IS NULL OR btrim(p_narrative) = '' OR length(p_narrative) > 8000 OR p_source IS NULL OR p_source NOT IN ('ai', 'template')
     OR (p_source = 'ai' AND nullif(btrim(coalesce(p_model, '')), '') IS NULL) OR coalesce(p_in, 0) < 0 OR coalesce(p_out, 0) < 0 OR coalesce(p_credits, 0) < 0 THEN RETURN 'bad_input'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  INSERT INTO analyst_reports (id, tenant_id, created_by, month_to, facts, forecast, narrative, source, note, model, input_tokens, output_tokens, credits_charged)
  VALUES (p_id, p_tenant, p_user, p_month, p_facts, p_forecast, p_narrative, p_source, left(nullif(btrim(coalesce(p_note, '')), ''), 40), left(nullif(btrim(coalesce(p_model, '')), ''), 120),
          coalesce(p_in, 0), coalesce(p_out, 0), coalesce(p_credits, 0));
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

REVOKE ALL ON FUNCTION analyst_report_save(char, char, char, char, jsonb, jsonb, text, text, text, text, integer, integer, numeric) FROM PUBLIC, jarvis_app_rw, jarvis_admin;
GRANT EXECUTE ON FUNCTION analyst_report_save(char, char, char, char, jsonb, jsonb, text, text, text, text, integer, integer, numeric) TO jarvis_app_rw, jarvis_admin;
