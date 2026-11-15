DROP FUNCTION IF EXISTS ads_draft_delete(char, char, char);
DROP FUNCTION IF EXISTS ads_draft_reopen(char, char, char);
DROP FUNCTION IF EXISTS ads_draft_decide(char, char, char, boolean, text);
DROP FUNCTION IF EXISTS ads_draft_submit(char, char, char);
DROP FUNCTION IF EXISTS ads_draft_check(char, char);
DROP FUNCTION IF EXISTS ads_draft_save(boolean, char, char, char, char, text, text, text, integer, integer, text, text, text, text, text, text, text, numeric, integer, date);
DROP FUNCTION IF EXISTS ads_settings_save(char, char, numeric, numeric);
DROP TABLE IF EXISTS ads_drafts;
DROP TABLE IF EXISTS ads_settings;
CREATE OR REPLACE FUNCTION ads_account_delete(p_id char(26), p_tenant char(26)) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); rc integer;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  PERFORM set_config('app.bypass_rls', 'on', true);
  DELETE FROM ads_accounts WHERE id = p_id AND tenant_id = p_tenant;
  GET DIAGNOSTICS rc = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN rc = 0 THEN 'not_found' ELSE 'ok' END;
END $$;
