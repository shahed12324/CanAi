DROP FUNCTION IF EXISTS ads_launch_retry(char, char, char, boolean);
DROP FUNCTION IF EXISTS ads_launch_fail(char, char, text, boolean);
DROP FUNCTION IF EXISTS ads_launch_done(char, char);
DROP FUNCTION IF EXISTS ads_launch_step(char, char, text, text);
DROP FUNCTION IF EXISTS ads_launch_claim(integer, integer);
DROP FUNCTION IF EXISTS ads_draft_set_page(char, char, char, char);
DROP FUNCTION IF EXISTS ads_account_set_pixel(char, char, text);
ALTER TABLE ads_drafts DROP CONSTRAINT ads_drafts_created_complete, DROP CONSTRAINT ads_drafts_launch_only_approved;
DROP INDEX IF EXISTS ads_drafts_launch;
ALTER TABLE ads_drafts DROP COLUMN page_account_id, DROP COLUMN launch_state, DROP COLUMN launch_error, DROP COLUMN launch_unknown, DROP COLUMN launch_started_at, DROP COLUMN launch_attempts, DROP COLUMN launched_at,
  DROP COLUMN remote_campaign, DROP COLUMN remote_adset, DROP COLUMN remote_creative, DROP COLUMN remote_ad;
ALTER TABLE ads_accounts DROP COLUMN pixel_id;
CREATE OR REPLACE FUNCTION ads_account_delete(p_id char(26), p_tenant char(26)) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); rc integer;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  PERFORM set_config('app.bypass_rls', 'on', true);
  IF EXISTS (SELECT 1 FROM ads_drafts WHERE account_id = p_id AND tenant_id = p_tenant AND status IN ('pending', 'approved')) THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'busy'; END IF;
  DELETE FROM ads_accounts WHERE id = p_id AND tenant_id = p_tenant;
  GET DIAGNOSTICS rc = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN rc = 0 THEN 'not_found' ELSE 'ok' END;
END $$;
CREATE OR REPLACE FUNCTION ads_draft_check(p_id char(26), p_tenant char(26)) RETURNS text
LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE d ads_drafts%ROWTYPE; s ads_settings%ROWTYPE;
BEGIN
  SELECT * INTO d FROM ads_drafts WHERE id = p_id AND tenant_id = p_tenant;
  IF NOT FOUND THEN RETURN 'incomplete'; END IF;
  IF btrim(d.primary_text) = '' OR btrim(d.headline) = '' OR (d.objective IN ('sales', 'leads', 'traffic') AND d.link_url IS NULL) THEN RETURN 'incomplete'; END IF;
  SELECT * INTO s FROM ads_settings WHERE tenant_id = p_tenant;
  IF NOT FOUND THEN RETURN 'no_cap'; END IF;
  IF d.daily_budget > s.max_daily OR d.daily_budget * d.days > s.max_total THEN RETURN 'over_cap'; END IF;
  RETURN 'ok';
END $$;
CREATE OR REPLACE FUNCTION ads_draft_reopen(p_id char(26), p_tenant char(26), p_user char(26)) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); rc integer;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE ads_drafts SET status = 'draft', approved_by = NULL, approved_at = NULL, updated_at = now() WHERE id = p_id AND tenant_id = p_tenant AND user_id = p_user AND status IN ('pending', 'approved');
  GET DIAGNOSTICS rc = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN rc = 0 THEN 'not_found' ELSE 'ok' END;
END $$;
REVOKE ALL ON FUNCTION ads_draft_check(char, char) FROM PUBLIC, jarvis_app_rw, jarvis_admin;
