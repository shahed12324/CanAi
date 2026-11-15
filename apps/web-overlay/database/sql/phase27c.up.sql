-- Phase 27.2c: how much each API key is used (calls per day, last 90 days) so a person can see which key is busy or unused.

CREATE TABLE api_key_usage (
  key_id    char(26) NOT NULL,
  tenant_id char(26) NOT NULL REFERENCES tenants (id),
  day       date     NOT NULL,
  calls     integer  NOT NULL CHECK (calls >= 1),
  PRIMARY KEY (key_id, day),
  FOREIGN KEY (key_id) REFERENCES api_keys (id) ON DELETE CASCADE
);
ALTER TABLE api_key_usage ENABLE ROW LEVEL SECURITY;
ALTER TABLE api_key_usage FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON api_key_usage USING (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin())) WITH CHECK (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()));
REVOKE ALL ON api_key_usage FROM jarvis_app_rw, jarvis_admin;
GRANT SELECT ON api_key_usage TO jarvis_app_rw, jarvis_admin;

CREATE OR REPLACE FUNCTION api_key_resolve(p_hash text) RETURNS jsonb
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
  -- Usage counter: one row per key per day (Asia/Dhaka day); only successful logins count, so guessing keys leaves no trace here.  Only the last 90 days are kept.
  INSERT INTO api_key_usage (key_id, tenant_id, day, calls) VALUES (k.id, k.tenant_id, (now() AT TIME ZONE 'Asia/Dhaka')::date, 1)
  ON CONFLICT (key_id, day) DO UPDATE SET calls = api_key_usage.calls + 1;
  DELETE FROM api_key_usage WHERE key_id = k.id AND day < (now() AT TIME ZONE 'Asia/Dhaka')::date - 90;
  out := jsonb_build_object('key_id', trim(k.id), 'tenant_id', trim(k.tenant_id), 'user_id', trim(k.user_id), 'scopes', to_jsonb(k.scopes));
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN out;
END $$;
