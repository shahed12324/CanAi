DROP FUNCTION IF EXISTS gads_draft_delete(char, char, char);
DROP FUNCTION IF EXISTS gads_draft_reopen(char, char, char);
DROP FUNCTION IF EXISTS gads_draft_decide(char, char, char, boolean, text);
DROP FUNCTION IF EXISTS gads_draft_submit(char, char, char);
DROP FUNCTION IF EXISTS gads_draft_check(char, char);
DROP FUNCTION IF EXISTS gads_draft_save(boolean, char, char, char, char, text, text, text, text, jsonb, jsonb, jsonb, jsonb, numeric, integer, date);
DROP FUNCTION IF EXISTS gads_settings_save(char, char, numeric, numeric);
DROP FUNCTION IF EXISTS gads_keywords_ok(jsonb, integer);
DROP FUNCTION IF EXISTS gads_texts_ok(jsonb, integer, integer);
DROP TABLE IF EXISTS gads_drafts;
DROP TABLE IF EXISTS gads_settings;
CREATE OR REPLACE FUNCTION gads_account_delete(p_id char(26), p_tenant char(26)) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); rc integer;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  PERFORM set_config('app.bypass_rls', 'on', true);
  DELETE FROM gads_accounts WHERE id = p_id AND tenant_id = p_tenant;
  GET DIAGNOSTICS rc = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN rc = 0 THEN 'not_found' ELSE 'ok' END;
END $$;
