-- Phase 26g: the desktop agent can ask "is the emergency stop on?" WHILE it runs a plan, so a stop pressed on the website or the phone reaches a running plan
-- (until now it was only seen when the agent next pulled).  The agent token identifies the device; nothing else is revealed.
CREATE OR REPLACE FUNCTION pc_agent_emergency(p_tenant char(26), p_device char(26)) RETURNS boolean
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); ok boolean; r boolean;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT EXISTS (SELECT 1 FROM pc_devices WHERE id = p_device AND tenant_id = p_tenant AND revoked_at IS NULL) INTO ok;
  IF NOT ok THEN PERFORM set_config('app.bypass_rls', prev, true); RAISE EXCEPTION 'forbidden' USING ERRCODE = '42501'; END IF;
  r := emergency_active(p_tenant);
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN r;
END $$;
REVOKE ALL ON FUNCTION pc_agent_emergency(char, char) FROM PUBLIC, jarvis_app_rw, jarvis_admin;
GRANT EXECUTE ON FUNCTION pc_agent_emergency(char, char) TO jarvis_app_rw, jarvis_admin;
