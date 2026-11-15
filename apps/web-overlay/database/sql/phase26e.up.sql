-- Phase 26e (CAN AI handover, Milestone B): Companion mode <-> Agent mode, per person.
--   companion : chat / voice / persona only. The computer cannot be asked to do anything: new jobs and plans are blocked ('companion_mode'),
--               approved plans are not handed to the agent, and switching INTO this mode stops everything of theirs that was waiting.
--   agent     : the Computer Control rules apply as before (device policy, approvals, phone PIN for high risk).
-- No row = 'agent', so nobody's existing setup changes until they choose. Honest limit: a plan that is already RUNNING on the PC cannot be recalled by the server; local `stop` does that.
CREATE TABLE user_modes (
  tenant_id    char(26)    NOT NULL REFERENCES tenants (id),
  user_id      char(26)    NOT NULL REFERENCES users (id),
  mode         text        NOT NULL CHECK (mode IN ('companion', 'agent')),
  character_id char(26)    REFERENCES characters (id),
  updated_at   timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY (tenant_id, user_id)
);
ALTER TABLE user_modes ENABLE ROW LEVEL SECURITY;
ALTER TABLE user_modes FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON user_modes USING (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()))
  WITH CHECK (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()));
REVOKE INSERT, UPDATE, DELETE, TRUNCATE ON user_modes FROM jarvis_app_rw;

CREATE FUNCTION user_mode(p_tenant char(26), p_user char(26)) RETURNS text LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
  SELECT coalesce((SELECT mode FROM user_modes WHERE tenant_id = p_tenant AND user_id = p_user), 'agent')
$$;

-- Set the mode (and optionally the companion's character). ok | bad_input | forbidden
CREATE FUNCTION user_mode_set(p_tenant char(26), p_user char(26), p_mode text, p_character char(26)) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), '');
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  IF p_mode IS NULL OR p_mode NOT IN ('companion', 'agent') THEN RETURN 'bad_input'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  IF NOT EXISTS (SELECT 1 FROM tenant_users WHERE tenant_id = p_tenant AND user_id = p_user) THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'forbidden'; END IF;
  IF p_character IS NOT NULL AND NOT EXISTS (SELECT 1 FROM characters WHERE id = p_character AND tenant_id = p_tenant AND deleted_at IS NULL) THEN
    PERFORM set_config('app.bypass_rls', prev, true); RETURN 'bad_input';
  END IF;
  INSERT INTO user_modes (tenant_id, user_id, mode, character_id) VALUES (p_tenant, p_user, p_mode, p_character)
  ON CONFLICT (tenant_id, user_id) DO UPDATE SET mode = EXCLUDED.mode, character_id = EXCLUDED.character_id, updated_at = now();
  IF p_mode = 'companion' THEN
    -- everything of theirs that was waiting (not yet handed to a PC) is stopped; running work is the PC's to stop
    UPDATE pc_actions a SET status = 'stopped', reason = 'companion_mode', finished_at = now()
     WHERE a.tenant_id = p_tenant AND a.status IN ('pending', 'approved') AND a.requested_by = p_user;
    UPDATE pc_plans p SET status = 'stopped', reason = 'companion_mode', finished_at = now()
     WHERE p.tenant_id = p_tenant AND p.status IN ('pending', 'approved') AND p.requested_by = p_user;
  END IF;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

-- user_mode() is an internal helper for the pc_* definer functions (the web role reads the user_modes table directly, under row-level security).
REVOKE ALL ON FUNCTION user_mode(char, char), user_mode_set(char, char, text, char) FROM PUBLIC, jarvis_app_rw, jarvis_admin;
GRANT EXECUTE ON FUNCTION user_mode_set(char, char, text, char) TO jarvis_app_rw, jarvis_admin;

CREATE OR REPLACE FUNCTION pc_action_request(p_tenant char(26), p_user char(26), p_device char(26), p_kind text, p_params jsonb) RETURNS jsonb
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); pol pc_policies%ROWTYPE; risk text := pc_kind_risk(p_kind); mode text; st text; why text; aid char(26); has_pol boolean;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  IF risk IS NULL OR p_params IS NULL OR jsonb_typeof(p_params) <> 'object' OR length(p_params::text) > 4000 THEN RETURN jsonb_build_object('status', 'rejected_input', 'reason', 'bad_input'); END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  IF NOT EXISTS (SELECT 1 FROM pc_devices WHERE id = p_device AND tenant_id = p_tenant AND user_id = p_user AND revoked_at IS NULL) THEN
    PERFORM set_config('app.bypass_rls', prev, true); RETURN jsonb_build_object('status', 'rejected_input', 'reason', 'not_found');
  END IF;
  SELECT * INTO pol FROM pc_policies WHERE device_id = p_device;
  has_pol := FOUND;
  IF emergency_active(p_tenant) THEN st := 'blocked'; why := 'emergency_stop';
  ELSIF user_mode(p_tenant, p_user) = 'companion' THEN st := 'blocked'; why := 'companion_mode';
  ELSIF NOT has_pol THEN st := 'blocked'; why := 'no_policy';
  ELSE
    mode := coalesce(pol.modes ->> p_kind, 'ask');
    IF mode = 'deny' THEN st := 'blocked'; why := 'denied_by_policy';
    ELSE
      why := pc_target_ok(p_kind, p_params, pol.roots, pol.apps, pol.commands, pol.hosts);
      IF why <> 'ok' THEN st := 'blocked';
      ELSE why := NULL; st := CASE WHEN mode = 'allow' AND risk IN ('low', 'medium') THEN 'approved' ELSE 'pending' END; END IF;
    END IF;
  END IF;
  aid := lower(substr(md5(random()::text || clock_timestamp()::text) || md5(random()::text), 1, 26));
  INSERT INTO pc_actions (id, tenant_id, device_id, requested_by, kind, risk, params, status, reason, expires_at, finished_at)
  VALUES (aid, p_tenant, p_device, p_user, p_kind, risk, p_params, st, why, now() + interval '10 minutes', CASE WHEN st = 'blocked' THEN now() END);
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN jsonb_build_object('status', st, 'reason', why, 'id', aid, 'risk', risk);
END $$;

CREATE OR REPLACE FUNCTION pc_plan_submit(p_tenant char(26), p_user char(26), p_device char(26), p_goal text, p_plan jsonb) RETURNS jsonb
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); pol pc_policies%ROWTYPE; s jsonb; sid text; kind text; prm jsonb; risk text; mode text; why text;
        st text := 'pending'; reason text; needs text[] := '{}'; texts jsonb := '{}'; ids text[] := '{}'; pid char(26); n int; chk text; refs text;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  IF p_goal IS NULL OR char_length(btrim(p_goal)) NOT BETWEEN 1 AND 2000 OR p_plan IS NULL OR jsonb_typeof(p_plan -> 'steps') IS DISTINCT FROM 'array'
     OR jsonb_array_length(p_plan -> 'steps') NOT BETWEEN 1 AND 20 OR length(p_plan::text) > 60000 THEN
    RETURN jsonb_build_object('status', 'rejected_input', 'reason', 'bad_input');
  END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  IF NOT EXISTS (SELECT 1 FROM pc_devices WHERE id = p_device AND tenant_id = p_tenant AND user_id = p_user AND revoked_at IS NULL) THEN
    PERFORM set_config('app.bypass_rls', prev, true); RETURN jsonb_build_object('status', 'rejected_input', 'reason', 'not_found');
  END IF;
  SELECT * INTO pol FROM pc_policies WHERE device_id = p_device;
  IF emergency_active(p_tenant) THEN st := 'blocked'; reason := 'emergency_stop';
  ELSIF user_mode(p_tenant, p_user) = 'companion' THEN st := 'blocked'; reason := 'companion_mode';
  ELSIF NOT FOUND THEN st := 'blocked'; reason := 'no_policy';
  ELSE
    FOR s IN SELECT * FROM jsonb_array_elements(p_plan -> 'steps') LOOP
      sid := s ->> 'id'; kind := s ->> 'kind'; prm := coalesce(s -> 'params', '{}'::jsonb);
      IF jsonb_typeof(s) <> 'object' OR sid IS NULL OR sid !~ '^[a-z][a-z0-9_]{0,23}$' OR sid = ANY (ids) OR jsonb_typeof(prm) <> 'object' OR length(s::text) > 8000 THEN
        st := 'blocked'; reason := 'bad_params:' || CASE WHEN sid ~ '^[a-z][a-z0-9_]{0,23}$' THEN sid ELSE '?' END; EXIT;
      END IF;
      ids := ids || sid;
      IF kind = 'verify_file' THEN risk := 'low'; chk := pc_target_ok('read_file', prm, pol.roots, pol.apps, pol.commands, pol.hosts);
      ELSE
        risk := pc_kind_risk(kind);
        IF risk IS NULL THEN st := 'blocked'; reason := 'unknown_kind:' || sid; EXIT; END IF;
        mode := coalesce(pol.modes ->> kind, 'ask');
        IF mode = 'deny' THEN st := 'blocked'; reason := 'denied_by_policy:' || sid; EXIT; END IF;
        chk := pc_target_ok(kind, prm, pol.roots, pol.apps, pol.commands, pol.hosts);
      END IF;
      -- {{sN.out}} references make the parameter dynamic, so the path check above cannot see the final value: refuse references inside path-like fields
      refs := prm::text;
      IF refs ~ '\{\{' AND (prm ->> 'path' ~ '\{\{' OR prm ->> 'src' ~ '\{\{' OR prm ->> 'dst' ~ '\{\{') THEN chk := 'bad_params'; END IF;
      IF chk <> 'ok' THEN st := 'blocked'; reason := chk || ':' || sid; EXIT; END IF;
      IF risk IN ('high', 'critical') OR prm -> 'overwrite' = 'true'::jsonb THEN needs := needs || sid; END IF;
      texts := texts || jsonb_build_object(sid, s::text);
    END LOOP;
  END IF;
  pid := lower(substr(md5(random()::text || clock_timestamp()::text) || md5(random()::text), 1, 26));
  INSERT INTO pc_plans (id, tenant_id, device_id, requested_by, goal, plan_text, step_texts, needs, status, reason, expires_at, finished_at)
  VALUES (pid, p_tenant, p_device, p_user, btrim(p_goal), p_plan::text, texts, needs, st, reason, now() + interval '30 minutes', CASE WHEN st = 'blocked' THEN now() END);
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN jsonb_build_object('status', st, 'reason', reason, 'id', pid, 'needs', to_jsonb(needs));
END $$;

CREATE OR REPLACE FUNCTION pc_plan_next(p_tenant char(26), p_device char(26)) RETURNS jsonb
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); pl pc_plans%ROWTYPE; key text; exp bigint := floor(extract(epoch FROM now()))::bigint + 300; steps jsonb := '{}'; a record;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT token_hash INTO key FROM pc_devices WHERE id = p_device AND tenant_id = p_tenant AND revoked_at IS NULL;
  IF key IS NULL THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN NULL; END IF;
  IF emergency_active(p_tenant) THEN
    UPDATE pc_plans SET status = 'stopped', reason = 'emergency_stop', finished_at = now() WHERE tenant_id = p_tenant AND status IN ('pending', 'approved', 'running');
    PERFORM set_config('app.bypass_rls', prev, true); RETURN NULL;
  END IF;
  UPDATE pc_plans SET status = 'expired', finished_at = now() WHERE device_id = p_device AND status IN ('pending', 'approved') AND expires_at <= now();
  SELECT p.* INTO pl FROM pc_plans p WHERE p.device_id = p_device AND p.status = 'approved' AND user_mode(p_tenant, p.requested_by) <> 'companion' ORDER BY p.created_at LIMIT 1 FOR UPDATE SKIP LOCKED;
  IF NOT FOUND THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN NULL; END IF;
  UPDATE pc_plans SET status = 'running', started_at = now() WHERE id = pl.id;
  FOR a IN SELECT step_id FROM pc_plan_step_approvals WHERE plan_id = pl.id
            AND (via = 'mobile' OR pc_kind_risk((pl.step_texts ->> step_id)::jsonb ->> 'kind') NOT IN ('high', 'critical')) LOOP
    steps := steps || jsonb_build_object(a.step_id, pc_hmac_sha256(key, 'canai-step|' || p_device || '|' || pl.id || '|' || a.step_id || '|' || encode(sha256(convert_to(pl.step_texts ->> a.step_id, 'UTF8')), 'hex') || '|' || exp));
  END LOOP;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN jsonb_build_object('id', pl.id, 'device_id', p_device, 'plan_text', pl.plan_text, 'exp', exp, 'needs', to_jsonb(pl.needs), 'step_texts', pl.step_texts,
    'plan_sig', pc_hmac_sha256(key, 'canai-plan|' || p_device || '|' || pl.id || '|' || encode(sha256(convert_to(pl.plan_text, 'UTF8')), 'hex') || '|' || exp),
    'step_sigs', steps);
END $$;
