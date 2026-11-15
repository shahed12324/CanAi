-- Phase 27.2a: public API keys for developers.  A key belongs to one person in one workspace, carries a short list of scopes, and is shown ONCE:
-- only the sha256 of the key is stored (plus a short display prefix).  A key can only do what its scopes say and what its person may do; it never moves money or approves anything.
-- api_key_resolve() is the one pre-login door: it takes the hash, returns who/where/what or NULL, and notes "last used" at most once a minute.

CREATE TABLE api_keys (
  id           char(26)     PRIMARY KEY,
  tenant_id    char(26)     NOT NULL REFERENCES tenants (id),
  user_id      char(26)     NOT NULL REFERENCES users (id),
  name         varchar(80)  NOT NULL CHECK (btrim(name) <> ''),
  prefix       varchar(16)  NOT NULL,
  key_hash     char(64)     NOT NULL UNIQUE CHECK (key_hash ~ '^[0-9a-f]{64}$'),
  scopes       text[]       NOT NULL CHECK (cardinality(scopes) BETWEEN 1 AND 4 AND scopes <@ ARRAY['me', 'credits:read', 'workflows:read', 'workflows:run']),
  created_at   timestamptz  NOT NULL DEFAULT now(),
  expires_at   timestamptz,
  last_used_at timestamptz,
  revoked_at   timestamptz
);
CREATE INDEX api_keys_user ON api_keys (tenant_id, user_id, created_at DESC);
ALTER TABLE api_keys ENABLE ROW LEVEL SECURITY;
ALTER TABLE api_keys FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON api_keys USING (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin())) WITH CHECK (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()));
REVOKE ALL ON api_keys FROM jarvis_app_rw, jarvis_admin;
GRANT SELECT ON api_keys TO jarvis_app_rw, jarvis_admin;          -- the hash column is never selected by the app code; writes go through the functions below

CREATE FUNCTION api_key_create(p_id char(26), p_tenant char(26), p_user char(26), p_name text, p_prefix text, p_hash text, p_scopes text[], p_days integer) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); n integer; rc integer; nm text := btrim(coalesce(p_name, ''));
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  IF p_id IS NULL OR nm = '' OR char_length(nm) > 80 OR p_prefix IS NULL OR char_length(p_prefix) NOT BETWEEN 6 AND 16 OR p_hash IS NULL OR p_hash !~ '^[0-9a-f]{64}$'
     OR p_scopes IS NULL OR cardinality(p_scopes) NOT BETWEEN 1 AND 4 OR NOT (p_scopes <@ ARRAY['me', 'credits:read', 'workflows:read', 'workflows:run'])
     OR (p_days IS NOT NULL AND p_days NOT BETWEEN 1 AND 365) THEN RETURN 'bad_input'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT count(*) INTO n FROM api_keys WHERE tenant_id = p_tenant AND user_id = p_user AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at > now());
  IF n >= 10 THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'limit'; END IF;
  INSERT INTO api_keys (id, tenant_id, user_id, name, prefix, key_hash, scopes, expires_at)
  VALUES (p_id, p_tenant, p_user, nm, p_prefix, p_hash, p_scopes, CASE WHEN p_days IS NULL THEN NULL ELSE now() + make_interval(days => p_days) END) ON CONFLICT DO NOTHING;
  GET DIAGNOSTICS rc = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN rc = 0 THEN 'duplicate' ELSE 'ok' END;
END $$;

CREATE FUNCTION api_key_revoke(p_id char(26), p_tenant char(26), p_user char(26)) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); rc integer;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE api_keys SET revoked_at = now() WHERE id = p_id AND tenant_id = p_tenant AND user_id = p_user AND revoked_at IS NULL;
  GET DIAGNOSTICS rc = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN rc = 0 THEN 'not_found' ELSE 'ok' END;
END $$;

-- The pre-login door.  A key works only while: not revoked, not expired, its workspace is active, and its person is still an active member.
CREATE FUNCTION api_key_resolve(p_hash text) RETURNS jsonb
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); k api_keys%ROWTYPE; out jsonb;
BEGIN
  IF p_hash IS NULL OR p_hash !~ '^[0-9a-f]{64}$' THEN RETURN NULL; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO k FROM api_keys WHERE key_hash = p_hash AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at > now());
  IF NOT FOUND OR NOT EXISTS (
       SELECT 1 FROM tenant_users tu JOIN tenants t ON t.id = tu.tenant_id
       WHERE tu.user_id = k.user_id AND tu.tenant_id = k.tenant_id AND tu.status = 'active' AND t.status = 'active' AND t.deleted_at IS NULL) THEN
    PERFORM set_config('app.bypass_rls', prev, true); RETURN NULL;
  END IF;
  IF k.last_used_at IS NULL OR k.last_used_at < now() - interval '1 minute' THEN UPDATE api_keys SET last_used_at = now() WHERE id = k.id; END IF;
  out := jsonb_build_object('key_id', trim(k.id), 'tenant_id', trim(k.tenant_id), 'user_id', trim(k.user_id), 'scopes', to_jsonb(k.scopes));
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN out;
END $$;

DO $$ DECLARE f text; BEGIN
  FOREACH f IN ARRAY ARRAY['api_key_create(char, char, char, text, text, text, text[], integer)', 'api_key_revoke(char, char, char)', 'api_key_resolve(text)'] LOOP
    EXECUTE format('REVOKE ALL ON FUNCTION %s FROM PUBLIC, jarvis_app_rw, jarvis_admin', f);
    EXECUTE format('GRANT EXECUTE ON FUNCTION %s TO jarvis_app_rw, jarvis_admin', f);
  END LOOP;
END $$;
