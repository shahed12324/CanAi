-- Phase 24.1d: optimisation advice for Meta ads.  The advice itself is computed from the copied results (no database change, no AI cost).  The ONLY thing this system can do to a running campaign
-- is PAUSE it, and only after a person with approval rights asks for it here.  It never switches anything on, never raises a budget — scaling is advice the person carries out in Ads Manager.
--   * a pause request is validated against the synced campaign (must be ACTIVE on an active account); one open request per campaign;
--   * the worker claims a request once; a claim that never reports back (>15 min) becomes 'failed' (pausing is idempotent, the person may simply ask again);
--   * on success the local copy is marked PAUSED at once (the next sync confirms it).

CREATE TABLE ads_pause_requests (
  id            char(26)     PRIMARY KEY,
  tenant_id     char(26)     NOT NULL,
  account_id    char(26)     NOT NULL,
  campaign_ext  varchar(30)  NOT NULL CHECK (campaign_ext ~ '^[0-9]{5,30}$'),
  campaign_name varchar(200) NOT NULL,
  reason        varchar(300) NOT NULL DEFAULT '',
  requested_by  char(26)     NOT NULL,
  status        text         NOT NULL DEFAULT 'requested' CHECK (status IN ('requested', 'running', 'done', 'failed')),
  error         varchar(300),
  created_at    timestamptz  NOT NULL DEFAULT now(),
  started_at    timestamptz,
  done_at       timestamptz,
  FOREIGN KEY (account_id, tenant_id) REFERENCES ads_accounts (id, tenant_id) ON DELETE CASCADE
);
CREATE UNIQUE INDEX ads_pause_one_open ON ads_pause_requests (account_id, campaign_ext) WHERE status IN ('requested', 'running');
CREATE INDEX ads_pause_tenant ON ads_pause_requests (tenant_id, created_at DESC);
ALTER TABLE ads_pause_requests ENABLE ROW LEVEL SECURITY;
ALTER TABLE ads_pause_requests FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON ads_pause_requests USING (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin())) WITH CHECK (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()));
REVOKE ALL ON ads_pause_requests FROM jarvis_app_rw, jarvis_admin;
GRANT SELECT ON ads_pause_requests TO jarvis_app_rw, jarvis_admin;

-- A person asks for a campaign to be paused on Meta.  result: ok | not_active | account | exists
CREATE FUNCTION ads_pause_request(p_id char(26), p_tenant char(26), p_user char(26), p_account char(26), p_campaign text, p_reason text) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); c ads_campaigns%ROWTYPE; ast text;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  IF p_campaign IS NULL OR p_campaign !~ '^[0-9]{5,30}$' THEN RAISE EXCEPTION 'bad campaign' USING ERRCODE = '22023'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT status INTO ast FROM ads_accounts WHERE id = p_account AND tenant_id = p_tenant;
  IF ast IS NULL OR ast <> 'active' THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'account'; END IF;
  SELECT * INTO c FROM ads_campaigns WHERE account_id = p_account AND tenant_id = p_tenant AND external_id = p_campaign;
  IF c.id IS NULL OR c.status <> 'ACTIVE' THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'not_active'; END IF;
  IF EXISTS (SELECT 1 FROM ads_pause_requests WHERE account_id = p_account AND campaign_ext = p_campaign AND status IN ('requested', 'running')) THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'exists'; END IF;
  INSERT INTO ads_pause_requests (id, tenant_id, account_id, campaign_ext, campaign_name, reason, requested_by) VALUES (p_id, p_tenant, p_account, p_campaign, c.name, left(btrim(coalesce(p_reason, '')), 300), p_user);
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

-- WORKER (admin only).  Claims up to p_limit requests (marks them running) and returns what is needed to call Meta.
CREATE FUNCTION ads_pause_claim(p_limit integer) RETURNS TABLE (req_id char(26), tenant_id char(26), account_id char(26), account_ext varchar, campaign_ext varchar, credentials_ct text, key_version smallint)
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), '');
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE ads_pause_requests SET status = 'failed', error = 'উত্তর পাওয়া যায়নি — আবার অনুরোধ করুন (বিরতি দেওয়া নিরাপদ, দুবার হলেও ক্ষতি নেই)।', done_at = now() WHERE status = 'running' AND started_at < now() - interval '15 minutes';
  RETURN QUERY
  WITH picked AS (
    SELECT r.id FROM ads_pause_requests r JOIN ads_accounts a ON a.id = r.account_id AND a.tenant_id = r.tenant_id
     WHERE r.status = 'requested' AND a.status = 'active' ORDER BY r.created_at LIMIT greatest(1, least(coalesce(p_limit, 1), 10)) FOR UPDATE OF r SKIP LOCKED)
  UPDATE ads_pause_requests s SET status = 'running', started_at = now() FROM picked, ads_accounts ac
   WHERE s.id = picked.id AND ac.id = s.account_id
  RETURNING s.id, s.tenant_id, s.account_id, ac.external_id, s.campaign_ext, ac.credentials_ct, ac.key_version;
  PERFORM set_config('app.bypass_rls', prev, true);
END $$;

-- WORKER (admin only).  Reports the outcome of a claimed request.  result: ok | not_found
CREATE FUNCTION ads_pause_done(p_id char(26), p_tenant char(26), p_ok boolean, p_error text) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); r ads_pause_requests%ROWTYPE;
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO r FROM ads_pause_requests WHERE id = p_id AND tenant_id = p_tenant AND status = 'running' FOR UPDATE;
  IF r.id IS NULL THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'not_found'; END IF;
  IF coalesce(p_ok, false) THEN
    UPDATE ads_pause_requests SET status = 'done', error = NULL, done_at = now() WHERE id = p_id;
    UPDATE ads_campaigns SET status = 'PAUSED', updated_at = now() WHERE account_id = r.account_id AND external_id = r.campaign_ext;
  ELSE
    UPDATE ads_pause_requests SET status = 'failed', error = left(btrim(coalesce(p_error, 'ব্যর্থ')), 300), done_at = now() WHERE id = p_id;
  END IF;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

DO $$ DECLARE f text; BEGIN
  FOREACH f IN ARRAY ARRAY['ads_pause_request(char, char, char, char, text, text)'] LOOP
    EXECUTE format('REVOKE ALL ON FUNCTION %s FROM PUBLIC, jarvis_app_rw, jarvis_admin', f);
    EXECUTE format('GRANT EXECUTE ON FUNCTION %s TO jarvis_app_rw, jarvis_admin', f);
  END LOOP;
  FOREACH f IN ARRAY ARRAY['ads_pause_claim(integer)', 'ads_pause_done(char, char, boolean, text)'] LOOP
    EXECUTE format('REVOKE ALL ON FUNCTION %s FROM PUBLIC, jarvis_app_rw, jarvis_admin', f);
    EXECUTE format('GRANT EXECUTE ON FUNCTION %s TO jarvis_admin', f);
  END LOOP;
END $$;
