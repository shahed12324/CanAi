-- Phase 24.3c: SEO audits.  The engine audits one site (rule-based, no AI, no credits) and Laravel keeps the result here so the owner can compare runs
-- over time and attach an audit to an agency CLIENT (24.3b).  Nothing is published and nothing is spent.
--   * written only through seo_audit_save() / seo_audit_delete() (SECURITY DEFINER); the app role can only SELECT;
--   * at most 30 audits per workspace per rolling 24 hours (seo_audit_allowed() lets the app refuse BEFORE it makes the engine crawl a site);
--   * at most 300 kept per workspace (the oldest are dropped on save);
--   * a stored result is capped at 300 KB; an audit may be attached to an active client of the SAME workspace only.

CREATE TABLE seo_audits (
  id          char(26)      PRIMARY KEY,
  tenant_id   char(26)      NOT NULL REFERENCES tenants (id),
  client_id   char(26),
  url         varchar(2048) NOT NULL CHECK (btrim(url) <> ''),
  domain      varchar(253)  NOT NULL DEFAULT '',
  keyword     varchar(80),
  status      text          NOT NULL CHECK (status IN ('ok', 'blocked_by_robots', 'unreachable', 'unsafe_url', 'not_html', 'timeout')),
  score       smallint      CHECK (score BETWEEN 0 AND 100),
  pages_checked smallint    NOT NULL DEFAULT 0 CHECK (pages_checked BETWEEN 0 AND 8),
  result      jsonb         NOT NULL DEFAULT '{}'::jsonb,
  created_by  char(26)      NOT NULL,
  created_at  timestamptz   NOT NULL DEFAULT now(),
  UNIQUE (id, tenant_id),
  FOREIGN KEY (client_id, tenant_id) REFERENCES clients (id, tenant_id),
  CHECK ((status = 'ok') = (score IS NOT NULL))
);
CREATE INDEX seo_audits_tenant_time ON seo_audits (tenant_id, created_at DESC);
CREATE INDEX seo_audits_tenant_domain ON seo_audits (tenant_id, domain, created_at DESC);
ALTER TABLE seo_audits ENABLE ROW LEVEL SECURITY;
ALTER TABLE seo_audits FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON seo_audits USING (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin())) WITH CHECK (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()));
REVOKE ALL ON seo_audits FROM jarvis_app_rw, jarvis_admin;
GRANT SELECT ON seo_audits TO jarvis_app_rw, jarvis_admin;

-- true while the workspace is under its daily limit (read-only; the app calls it before crawling).
CREATE FUNCTION seo_audit_allowed(p_tenant char(26)) RETURNS boolean
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); n integer;
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT count(*) INTO n FROM seo_audits WHERE tenant_id = p_tenant AND created_at > now() - interval '24 hours';
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN n < 30;
END $$;

-- Store one audit.  result: ok | limit | client | too_large
CREATE FUNCTION seo_audit_save(p_id char(26), p_tenant char(26), p_user char(26), p_client char(26), p_url text, p_domain text, p_keyword text, p_status text, p_score integer, p_pages integer, p_result jsonb) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), '');
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  IF p_url IS NULL OR btrim(p_url) = '' OR length(p_url) > 2048 THEN RAISE EXCEPTION 'bad url' USING ERRCODE = '22023'; END IF;
  IF p_result IS NULL OR jsonb_typeof(p_result) <> 'object' THEN RAISE EXCEPTION 'bad result' USING ERRCODE = '22023'; END IF;
  IF length(p_result::text) > 300000 THEN RETURN 'too_large'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  IF p_client IS NOT NULL AND NOT EXISTS (SELECT 1 FROM clients WHERE id = p_client AND tenant_id = p_tenant AND status = 'active') THEN
    PERFORM set_config('app.bypass_rls', prev, true); RETURN 'client';
  END IF;
  IF (SELECT count(*) FROM seo_audits WHERE tenant_id = p_tenant AND created_at > now() - interval '24 hours') >= 30 THEN
    PERFORM set_config('app.bypass_rls', prev, true); RETURN 'limit';
  END IF;
  INSERT INTO seo_audits (id, tenant_id, client_id, url, domain, keyword, status, score, pages_checked, result, created_by)
    VALUES (p_id, p_tenant, p_client, left(btrim(p_url), 2048), left(coalesce(p_domain, ''), 253), nullif(left(btrim(coalesce(p_keyword, '')), 80), ''), p_status, p_score, coalesce(p_pages, 0), p_result, p_user);
  DELETE FROM seo_audits WHERE tenant_id = p_tenant AND id IN (SELECT id FROM seo_audits WHERE tenant_id = p_tenant ORDER BY created_at DESC, id DESC OFFSET 300);
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

-- Remove one stored audit.  result: ok | missing
CREATE FUNCTION seo_audit_delete(p_tenant char(26), p_user char(26), p_id char(26)) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), '');
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  DELETE FROM seo_audits WHERE id = p_id AND tenant_id = p_tenant;
  IF NOT FOUND THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'missing'; END IF;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

DO $$ DECLARE f text; BEGIN
  FOREACH f IN ARRAY ARRAY['seo_audit_allowed(char)', 'seo_audit_save(char, char, char, char, text, text, text, text, integer, integer, jsonb)', 'seo_audit_delete(char, char, char)'] LOOP
    EXECUTE format('REVOKE ALL ON FUNCTION %s FROM PUBLIC, jarvis_app_rw, jarvis_admin', f);
    EXECUTE format('GRANT EXECUTE ON FUNCTION %s TO jarvis_app_rw, jarvis_admin', f);
  END LOOP;
END $$;
