DROP FUNCTION IF EXISTS gads_launch_retry(char, char, char, boolean);
DROP FUNCTION IF EXISTS gads_launch_fail(char, char, text, boolean);
DROP FUNCTION IF EXISTS gads_launch_done(char, char);
DROP FUNCTION IF EXISTS gads_launch_step(char, char, text, text);
DROP FUNCTION IF EXISTS gads_launch_claim(integer, integer);
CREATE OR REPLACE FUNCTION gads_draft_check(p_id char(26), p_tenant char(26)) RETURNS text
LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE d gads_drafts%ROWTYPE; s gads_settings%ROWTYPE;
BEGIN
  SELECT * INTO d FROM gads_drafts WHERE id = p_id AND tenant_id = p_tenant;
  IF NOT FOUND THEN RETURN 'incomplete'; END IF;
  IF d.final_url IS NULL OR d.start_date IS NULL OR jsonb_array_length(d.headlines) < 3 OR jsonb_array_length(d.descriptions) < 2 OR jsonb_array_length(d.keywords) < 1 THEN RETURN 'incomplete'; END IF;
  SELECT * INTO s FROM gads_settings WHERE tenant_id = p_tenant;
  IF NOT FOUND THEN RETURN 'no_cap'; END IF;
  IF d.daily_budget > s.max_daily OR d.daily_budget * d.days > s.max_total THEN RETURN 'over_cap'; END IF;
  RETURN 'ok';
END $$;
CREATE OR REPLACE FUNCTION gads_draft_reopen(p_id char(26), p_tenant char(26), p_user char(26)) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); rc integer;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE gads_drafts SET status = 'draft', approved_by = NULL, approved_at = NULL, updated_at = now() WHERE id = p_id AND tenant_id = p_tenant AND user_id = p_user AND status IN ('pending', 'approved');
  GET DIAGNOSTICS rc = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN rc = 0 THEN 'not_found' ELSE 'ok' END;
END $$;
CREATE OR REPLACE FUNCTION gads_account_delete(p_id char(26), p_tenant char(26)) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); rc integer;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  PERFORM set_config('app.bypass_rls', 'on', true);
  IF EXISTS (SELECT 1 FROM gads_drafts WHERE account_id = p_id AND tenant_id = p_tenant AND status IN ('pending', 'approved')) THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'busy'; END IF;
  DELETE FROM gads_accounts WHERE id = p_id AND tenant_id = p_tenant;
  GET DIAGNOSTICS rc = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN rc = 0 THEN 'not_found' ELSE 'ok' END;
END $$;
REVOKE ALL ON FUNCTION gads_draft_check(char, char) FROM PUBLIC, jarvis_app_rw, jarvis_admin;
DROP FUNCTION IF EXISTS gads_country_ok(text);
DROP INDEX IF EXISTS gads_drafts_launch;
ALTER TABLE gads_drafts DROP CONSTRAINT IF EXISTS gads_drafts_created_complete, DROP CONSTRAINT IF EXISTS gads_drafts_launch_only_approved,
  DROP COLUMN IF EXISTS targeting_done, DROP COLUMN IF EXISTS keywords_done, DROP COLUMN IF EXISTS remote_ad, DROP COLUMN IF EXISTS remote_adgroup, DROP COLUMN IF EXISTS remote_campaign, DROP COLUMN IF EXISTS remote_budget,
  DROP COLUMN IF EXISTS launched_at, DROP COLUMN IF EXISTS launch_attempts, DROP COLUMN IF EXISTS launch_started_at, DROP COLUMN IF EXISTS launch_unknown, DROP COLUMN IF EXISTS launch_error, DROP COLUMN IF EXISTS launch_state;
