-- Phase 27.2c down: api_key_resolve without the usage counter.
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
  out := jsonb_build_object('key_id', trim(k.id), 'tenant_id', trim(k.tenant_id), 'user_id', trim(k.user_id), 'scopes', to_jsonb(k.scopes));
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN out;
END $$;
DROP TABLE IF EXISTS api_key_usage;
