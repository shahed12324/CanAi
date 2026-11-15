-- Phase 19.2e: the person's own Google / Microsoft calendar by OAuth (read + add an event).  Reuses user_links: kind 'gcal' / 'mcal'; `name` = the account's e-mail,
-- `host` = 'google' | 'microsoft', `secret_ct` = the encrypted refresh token.  Adds link_secret_update (a rotated refresh token replaces the old one, nothing else changes).

ALTER TABLE user_links DROP CONSTRAINT user_links_kind_check;
ALTER TABLE user_links ADD CONSTRAINT user_links_kind_check CHECK (kind IN ('ics', 'slack', 'discord', 'gcal', 'mcal'));

CREATE OR REPLACE FUNCTION link_add(p_id char(26), p_tenant char(26), p_user char(26), p_kind text, p_name text, p_ct text, p_ver integer, p_host text) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); nm text := regexp_replace(btrim(coalesce(p_name, '')), '[[:space:]]+', ' ', 'g'); n integer; rc integer;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  IF p_id IS NULL OR p_kind IS NULL OR p_kind NOT IN ('ics', 'slack', 'discord', 'gcal', 'mcal') OR nm = '' OR char_length(nm) > 60
     OR coalesce(p_ct, '') = '' OR coalesce(p_ver, 0) < 1 OR char_length(coalesce(p_host, '')) > 100 THEN RETURN 'bad_input'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT count(*) INTO n FROM user_links WHERE tenant_id = p_tenant AND user_id = p_user AND kind = p_kind;
  IF n >= 5 THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'limit'; END IF;
  INSERT INTO user_links (id, tenant_id, user_id, kind, name, secret_ct, secret_ver, host) VALUES (p_id, p_tenant, p_user, p_kind, nm, p_ct, p_ver, coalesce(p_host, '')) ON CONFLICT DO NOTHING;
  GET DIAGNOSTICS rc = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN rc = 0 THEN 'duplicate' ELSE 'ok' END;
END $$;

-- result: ok | bad_input | not_found.  Only the secret changes (and a stale error is cleared); only for calendar links, only by the owner.
CREATE FUNCTION link_secret_update(p_id char(26), p_tenant char(26), p_user char(26), p_ct text, p_ver integer) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); rc integer;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  IF coalesce(p_ct, '') = '' OR coalesce(p_ver, 0) < 1 THEN RETURN 'bad_input'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE user_links SET secret_ct = p_ct, secret_ver = p_ver, last_error = NULL WHERE id = p_id AND tenant_id = p_tenant AND user_id = p_user AND kind IN ('gcal', 'mcal');
  GET DIAGNOSTICS rc = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN rc = 0 THEN 'not_found' ELSE 'ok' END;
END $$;

REVOKE ALL ON FUNCTION link_secret_update(char, char, char, text, integer) FROM PUBLIC, jarvis_app_rw, jarvis_admin;
GRANT EXECUTE ON FUNCTION link_secret_update(char, char, char, text, integer) TO jarvis_app_rw, jarvis_admin;
