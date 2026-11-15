-- Phase 28g (Phase 26 remainder): high / critical computer actions need a SECOND check from the phone.
--   * pc_action_decide (web) can still REJECT anything, and approve low / medium, but approving a high / critical action returns 'mobile_required';
--   * mobile_pc_pending / mobile_pc_decide: the phone lists the person's own pending actions and approves them - approving needs the PIN step-up window (same rule as every phone approval);
--   * pc_actions.decided_via records where the approval came from; a CHECK and pc_action_next both refuse to run a high / critical action that was not approved on the phone (defence in depth).
-- Honest limit: the database knows the phone path by function (mobile_pc_decide needs a registered phone device of the same user + a live step-up); it cannot tell which HTTP route called pc_action_decide.
ALTER TABLE pc_actions ADD COLUMN decided_via text CHECK (decided_via IN ('web', 'mobile'));
ALTER TABLE pc_actions ADD CONSTRAINT pc_actions_high_via_phone CHECK (NOT (risk IN ('high', 'critical') AND status IN ('approved', 'running', 'done', 'failed') AND decided_via IS DISTINCT FROM 'mobile')) NOT VALID;

CREATE OR REPLACE FUNCTION pc_actions_guard() RETURNS trigger LANGUAGE plpgsql SET search_path = public, pg_temp AS $$
BEGIN
  IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'pc actions are an audit trail' USING ERRCODE = '22023'; END IF;
  IF NEW.id IS DISTINCT FROM OLD.id OR NEW.tenant_id IS DISTINCT FROM OLD.tenant_id OR NEW.device_id IS DISTINCT FROM OLD.device_id
     OR NEW.kind IS DISTINCT FROM OLD.kind OR NEW.risk IS DISTINCT FROM OLD.risk OR NEW.params IS DISTINCT FROM OLD.params
     OR NEW.requested_by IS DISTINCT FROM OLD.requested_by OR (OLD.decided_via IS NOT NULL AND NEW.decided_via IS DISTINCT FROM OLD.decided_via) THEN
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
  ELSIF p_decision = 'approve' AND act.risk IN ('high', 'critical') THEN out := 'mobile_required';
  ELSE
    UPDATE pc_actions SET status = CASE p_decision WHEN 'approve' THEN 'approved' ELSE 'rejected' END, decided_by = p_user, decided_at = now(), decided_via = 'web',
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
  SELECT * INTO act FROM pc_actions WHERE device_id = p_device AND status = 'approved' AND expires_at > now() AND (risk NOT IN ('high', 'critical') OR decided_via = 'mobile') ORDER BY created_at LIMIT 1 FOR UPDATE SKIP LOCKED;
  IF NOT FOUND THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN NULL; END IF;
  UPDATE pc_actions SET status = 'running', started_at = now() WHERE id = act.id;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN jsonb_build_object('id', act.id, 'kind', act.kind, 'risk', act.risk, 'params', act.params);
END $$;

-- The phone's list: this person's own pending, unexpired actions (any risk) on their live computers.
CREATE FUNCTION mobile_pc_pending(p_device char(26), p_user char(26)) RETURNS TABLE (id char(26), kind text, risk text, device text, params jsonb, created_at timestamptz, expires_at timestamptz)
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE d mobile_devices%ROWTYPE; prev text := coalesce(current_setting('app.bypass_rls', true), '');
BEGIN
  d := mobile_device_guard(p_device, p_user);
  PERFORM set_config('app.bypass_rls', 'on', true);
  RETURN QUERY SELECT x.id, x.kind, x.risk, pd.name::text, x.params, x.created_at, x.expires_at
    FROM pc_actions x JOIN pc_devices pd ON pd.id = x.device_id
   WHERE x.tenant_id = d.tenant_id AND pd.user_id = p_user AND pd.revoked_at IS NULL AND x.status = 'pending' AND x.expires_at > now()
   ORDER BY x.created_at DESC LIMIT 50;
  PERFORM set_config('app.bypass_rls', prev, true);
END $$;

-- Approve / reject from the phone.  ok | not_found | not_pending | expired | emergency_stop | step_up_required | bad_input.  Approving needs a live PIN step-up.
CREATE FUNCTION mobile_pc_decide(p_device char(26), p_user char(26), p_action char(26), p_decision text) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE d mobile_devices%ROWTYPE; prev text := coalesce(current_setting('app.bypass_rls', true), ''); act pc_actions%ROWTYPE; out text;
BEGIN
  d := mobile_device_guard(p_device, p_user);
  IF p_decision IS NULL OR p_decision NOT IN ('approve', 'reject') THEN RETURN 'bad_input'; END IF;
  IF p_decision = 'approve' AND (d.stepup_until IS NULL OR d.stepup_until <= now()) THEN RETURN 'step_up_required'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT x.* INTO act FROM pc_actions x JOIN pc_devices pd ON pd.id = x.device_id
   WHERE x.id = p_action AND x.tenant_id = d.tenant_id AND pd.user_id = p_user AND pd.revoked_at IS NULL FOR UPDATE OF x;
  IF NOT FOUND THEN out := 'not_found';
  ELSIF act.status <> 'pending' THEN out := 'not_pending';
  ELSIF emergency_active(d.tenant_id) THEN UPDATE pc_actions SET status = 'stopped', reason = 'emergency_stop', finished_at = now() WHERE id = act.id; out := 'emergency_stop';
  ELSIF act.expires_at <= now() THEN UPDATE pc_actions SET status = 'expired', finished_at = now() WHERE id = act.id; out := 'expired';
  ELSE
    UPDATE pc_actions SET status = CASE p_decision WHEN 'approve' THEN 'approved' ELSE 'rejected' END, decided_by = p_user, decided_at = now(), decided_via = 'mobile',
           finished_at = CASE p_decision WHEN 'reject' THEN now() END, expires_at = CASE p_decision WHEN 'approve' THEN now() + interval '10 minutes' ELSE expires_at END
     WHERE id = act.id;
    out := 'ok';
  END IF;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN out;
END $$;

REVOKE ALL ON FUNCTION mobile_pc_pending(char, char), mobile_pc_decide(char, char, char, text) FROM PUBLIC, jarvis_app_rw, jarvis_admin;
GRANT EXECUTE ON FUNCTION mobile_pc_pending(char, char), mobile_pc_decide(char, char, char, text) TO jarvis_app_rw, jarvis_admin;
