-- Phase 33b (CAN AI Desktop, Phase E): device-token rotation.
-- The AGENT makes the new token (so the server never has to hand a secret back over a connection that might drop) and sends it once, authenticated by the old one.
-- Only the hash is stored, exactly like at pairing.  The old token stops working in the same statement.  A revoked device cannot rotate (its token_hash is NULL).
-- Signed plans are signed at pull time from the CURRENT token hash, so a rotation never strands an approved plan.

ALTER TABLE pc_devices ADD COLUMN token_rotated_at timestamptz;

CREATE FUNCTION pc_agent_rotate(p_old_hash text, p_new_hash text) RETURNS jsonb
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); d pc_devices%ROWTYPE;
BEGIN
  IF p_old_hash IS NULL OR p_new_hash IS NULL OR p_old_hash !~ '^[0-9a-f]{64}$' OR p_new_hash !~ '^[0-9a-f]{64}$' OR p_old_hash = p_new_hash THEN RETURN NULL; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  BEGIN
    UPDATE pc_devices SET token_hash = p_new_hash, token_rotated_at = now() WHERE token_hash = p_old_hash AND revoked_at IS NULL RETURNING * INTO d;
  EXCEPTION WHEN unique_violation THEN
    PERFORM set_config('app.bypass_rls', prev, true); RETURN NULL;
  END;
  PERFORM set_config('app.bypass_rls', prev, true);
  IF d.id IS NULL THEN RETURN NULL; END IF;                 -- (FOUND would have been reset by the PERFORM above)
  RETURN jsonb_build_object('device_id', d.id, 'tenant_id', d.tenant_id, 'user_id', d.user_id);
END $$;

REVOKE ALL ON FUNCTION pc_agent_rotate(text, text) FROM PUBLIC, jarvis_app_rw, jarvis_admin;
GRANT EXECUTE ON FUNCTION pc_agent_rotate(text, text) TO jarvis_app_rw, jarvis_admin;
