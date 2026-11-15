-- Phase 19.2d: personal links - a calendar's secret ICS address (read-only) and Slack / Discord incoming-webhook addresses.  PERSONAL: a person only sees and changes
-- their own (the repo filters by user; the write functions enforce it).  The address itself is a secret (anyone holding it can read the calendar / post in the channel),
-- so only the AES-GCM ciphertext (app/Ai/CredentialVault) is stored; `host` is the harmless part kept for display.

CREATE TABLE user_links (
  id         char(26)     PRIMARY KEY,
  tenant_id  char(26)     NOT NULL REFERENCES tenants (id),
  user_id    char(26)     NOT NULL REFERENCES users (id),
  kind       text         NOT NULL CHECK (kind IN ('ics', 'slack', 'discord')),
  name       varchar(60)  NOT NULL CHECK (btrim(name) <> ''),
  secret_ct  text         NOT NULL CHECK (secret_ct <> ''),
  secret_ver integer      NOT NULL CHECK (secret_ver > 0),
  host       varchar(100) NOT NULL DEFAULT '',
  last_used_at timestamptz,
  last_error varchar(200),
  created_at timestamptz  NOT NULL DEFAULT now(),
  UNIQUE (id, tenant_id)
);
CREATE UNIQUE INDEX user_links_name ON user_links (tenant_id, user_id, kind, lower(name));
CREATE INDEX user_links_person ON user_links (tenant_id, user_id, kind, created_at);

ALTER TABLE user_links ENABLE ROW LEVEL SECURITY;
ALTER TABLE user_links FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON user_links USING (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin())) WITH CHECK (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()));
REVOKE ALL ON user_links FROM jarvis_app_rw, jarvis_admin;
GRANT SELECT ON user_links TO jarvis_app_rw, jarvis_admin;

-- result: ok | bad_input | limit | duplicate.  At most 5 of each kind per person; a name is unique per person and kind.
CREATE FUNCTION link_add(p_id char(26), p_tenant char(26), p_user char(26), p_kind text, p_name text, p_ct text, p_ver integer, p_host text) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); nm text := regexp_replace(btrim(coalesce(p_name, '')), '[[:space:]]+', ' ', 'g'); n integer; rc integer;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  IF p_id IS NULL OR p_kind IS NULL OR p_kind NOT IN ('ics', 'slack', 'discord') OR nm = '' OR char_length(nm) > 60
     OR coalesce(p_ct, '') = '' OR coalesce(p_ver, 0) < 1 OR char_length(coalesce(p_host, '')) > 100 THEN RETURN 'bad_input'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT count(*) INTO n FROM user_links WHERE tenant_id = p_tenant AND user_id = p_user AND kind = p_kind;
  IF n >= 5 THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'limit'; END IF;
  INSERT INTO user_links (id, tenant_id, user_id, kind, name, secret_ct, secret_ver, host) VALUES (p_id, p_tenant, p_user, p_kind, nm, p_ct, p_ver, coalesce(p_host, '')) ON CONFLICT DO NOTHING;
  GET DIAGNOSTICS rc = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN rc = 0 THEN 'duplicate' ELSE 'ok' END;
END $$;

CREATE FUNCTION link_delete(p_id char(26), p_tenant char(26), p_user char(26)) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); rc integer;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  DELETE FROM user_links WHERE id = p_id AND tenant_id = p_tenant AND user_id = p_user;
  GET DIAGNOSTICS rc = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN rc = 0 THEN 'not_found' ELSE 'ok' END;
END $$;

-- Records the outcome of the last use (p_error NULL = it worked).  result: ok | not_found.
CREATE FUNCTION link_touch(p_id char(26), p_tenant char(26), p_user char(26), p_error text) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); rc integer;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE user_links SET last_used_at = now(), last_error = left(p_error, 200) WHERE id = p_id AND tenant_id = p_tenant AND user_id = p_user;
  GET DIAGNOSTICS rc = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN rc = 0 THEN 'not_found' ELSE 'ok' END;
END $$;

DO $$ DECLARE f text; BEGIN
  FOREACH f IN ARRAY ARRAY['link_add(char, char, char, text, text, text, integer, text)', 'link_delete(char, char, char)', 'link_touch(char, char, char, text)'] LOOP
    EXECUTE format('REVOKE ALL ON FUNCTION %s FROM PUBLIC, jarvis_app_rw, jarvis_admin', f);
    EXECUTE format('GRANT EXECUTE ON FUNCTION %s TO jarvis_app_rw, jarvis_admin', f);
  END LOOP;
END $$;
