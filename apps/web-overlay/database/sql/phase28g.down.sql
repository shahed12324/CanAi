DROP FUNCTION IF EXISTS mobile_pc_decide(char, char, char, text), mobile_pc_pending(char, char);
ALTER TABLE pc_actions DROP CONSTRAINT IF EXISTS pc_actions_high_via_phone;
CREATE OR REPLACE FUNCTION pc_actions_guard() RETURNS trigger LANGUAGE plpgsql SET search_path = public, pg_temp AS $$
BEGIN
  IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'pc actions are an audit trail' USING ERRCODE = '22023'; END IF;
  IF NEW.id IS DISTINCT FROM OLD.id OR NEW.tenant_id IS DISTINCT FROM OLD.tenant_id OR NEW.device_id IS DISTINCT FROM OLD.device_id
     OR NEW.kind IS DISTINCT FROM OLD.kind OR NEW.risk IS DISTINCT FROM OLD.risk OR NEW.params IS DISTINCT FROM OLD.params
     OR NEW.requested_by IS DISTINCT FROM OLD.requested_by THEN
    RAISE EXCEPTION 'pc action is immutable' USING ERRCODE = '22023';
  END IF;
  IF NEW.status IS DISTINCT FROM OLD.status AND NOT (
       (OLD.status = 'pending'  AND NEW.status IN ('approved', 'rejected', 'expired', 'stopped'))
    OR (OLD.status = 'approved' AND NEW.status IN ('running', 'expired', 'stopped'))
    OR (OLD.status = 'running'  AND NEW.status IN ('done', 'failed', 'stopped'))) THEN
    RAISE EXCEPTION 'illegal pc action transition % -> %', OLD.status, NEW.status USING ERRCODE = '22023';
  END IF;
  RETURN NEW;
END $$;
CREATE OR REPLACE FUNCTION pc_action_decide(p_tenant char(26), p_user char(26), p_action char(26), p_decision text) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); act pc_actions%ROWTYPE; out text;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  IF p_decision IS NULL OR p_decision NOT IN ('approve', 'reject') THEN RETURN 'bad_input'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT x.* INTO act FROM pc_actions x JOIN pc_devices d ON d.id = x.device_id
   WHERE x.id = p_action AND x.tenant_id = p_tenant AND d.user_id = p_user AND d.revoked_at IS NULL FOR UPDATE OF x;
  IF NOT FOUND THEN out := 'not_found';
  ELSIF act.status <> 'pending' THEN out := 'not_pending';
  ELSIF emergency_active(p_tenant) THEN UPDATE pc_actions SET status = 'stopped', reason = 'emergency_stop', finished_at = now() WHERE id = act.id; out := 'emergency_stop';
  ELSIF act.expires_at <= now() THEN UPDATE pc_actions SET status = 'expired', finished_at = now() WHERE id = act.id; out := 'expired';
  ELSE
    UPDATE pc_actions SET status = CASE p_decision WHEN 'approve' THEN 'approved' ELSE 'rejected' END, decided_by = p_user, decided_at = now(),
           finished_at = CASE p_decision WHEN 'reject' THEN now() END, expires_at = CASE p_decision WHEN 'approve' THEN now() + interval '10 minutes' ELSE expires_at END
     WHERE id = act.id;
    out := 'ok';
  END IF;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN out;
END $$;
CREATE OR REPLACE FUNCTION pc_action_next(p_tenant char(26), p_device char(26)) RETURNS jsonb
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); act pc_actions%ROWTYPE;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  PERFORM set_config('app.bypass_rls', 'on', true);
  IF NOT EXISTS (SELECT 1 FROM pc_devices WHERE id = p_device AND tenant_id = p_tenant AND revoked_at IS NULL) THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN NULL; END IF;
  IF emergency_active(p_tenant) THEN
    UPDATE pc_actions SET status = 'stopped', reason = 'emergency_stop', finished_at = now() WHERE tenant_id = p_tenant AND status IN ('pending', 'approved', 'running');
    PERFORM set_config('app.bypass_rls', prev, true); RETURN NULL;
  END IF;
  UPDATE pc_actions SET status = 'expired', finished_at = now() WHERE device_id = p_device AND status IN ('pending', 'approved') AND expires_at <= now();
  SELECT * INTO act FROM pc_actions WHERE device_id = p_device AND status = 'approved' AND expires_at > now() ORDER BY created_at LIMIT 1 FOR UPDATE SKIP LOCKED;
  IF NOT FOUND THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN NULL; END IF;
  UPDATE pc_actions SET status = 'running', started_at = now() WHERE id = act.id;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN jsonb_build_object('id', act.id, 'kind', act.kind, 'risk', act.risk, 'params', act.params);
END $$;
ALTER TABLE pc_actions DROP COLUMN IF EXISTS decided_via;
