DROP FUNCTION IF EXISTS social_publish_fail(char, char, text);
DROP FUNCTION IF EXISTS social_publish_done(char, char, text, text);
DROP FUNCTION IF EXISTS social_publish_claim(integer);
DROP FUNCTION IF EXISTS social_post_retry(char, char, char);
DROP FUNCTION IF EXISTS social_post_set_account(char, char, char, char);
DROP FUNCTION IF EXISTS social_account_mark(char, text);
DROP FUNCTION IF EXISTS social_account_credentials(char);
DROP FUNCTION IF EXISTS social_account_delete(char, char);
DROP FUNCTION IF EXISTS social_account_save(char, char, text, text, text, text, smallint);
-- restore the 23a versions of the three redefined functions
CREATE OR REPLACE FUNCTION social_post_reopen(p_id char(26), p_tenant char(26), p_user char(26)) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); rc integer;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE social_posts SET status = 'draft', approved_by = NULL, approved_at = NULL, updated_at = now() WHERE id = p_id AND tenant_id = p_tenant AND user_id = p_user AND status IN ('pending', 'approved');
  GET DIAGNOSTICS rc = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN rc = 0 THEN 'not_found' ELSE 'ok' END;
END $$;
CREATE OR REPLACE FUNCTION social_post_mark_posted(p_id char(26), p_tenant char(26), p_user char(26), p_url text) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); rc integer; u text := nullif(btrim(coalesce(p_url, '')), '');
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  IF u IS NOT NULL AND (char_length(u) > 300 OR u !~ '^https://[^[:space:]]+$') THEN RETURN 'bad_url'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE social_posts SET status = 'posted', posted_at = now(), post_url = u, updated_at = now() WHERE id = p_id AND tenant_id = p_tenant AND user_id = p_user AND status = 'approved';
  GET DIAGNOSTICS rc = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN rc = 0 THEN 'not_found' ELSE 'ok' END;
END $$;
CREATE OR REPLACE FUNCTION social_post_cancel(p_id char(26), p_tenant char(26), p_user char(26)) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); rc integer;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE social_posts SET status = 'cancelled', updated_at = now() WHERE id = p_id AND tenant_id = p_tenant AND user_id = p_user AND status IN ('draft', 'pending', 'approved');
  GET DIAGNOSTICS rc = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN rc = 0 THEN 'not_found' ELSE 'ok' END;
END $$;
DROP INDEX IF EXISTS social_posts_due;
ALTER TABLE social_posts DROP CONSTRAINT IF EXISTS social_posts_publish_needs_account, DROP CONSTRAINT IF EXISTS social_posts_publish_only_approved, DROP CONSTRAINT IF EXISTS social_posts_account_fk,
  DROP COLUMN IF EXISTS attempts, DROP COLUMN IF EXISTS remote_id, DROP COLUMN IF EXISTS publish_started_at, DROP COLUMN IF EXISTS publish_error, DROP COLUMN IF EXISTS publish_state, DROP COLUMN IF EXISTS account_id;
DROP TABLE IF EXISTS social_accounts;
