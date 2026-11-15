-- Phase 26d (CAN AI handover): multi-step PLANS for the desktop agent, reviewed and approved by the person on the website/phone.
-- Trust model (honest): the server never runs anything. The person approves the plan, and separately every risky step (high risk or overwrite).
-- When the agent pulls an approved plan the database signs it with HMAC-SHA256 using the device's own secret (sha256 of its token, which only the
-- agent and the database know). The agent verifies the signature over the EXACT text it was sent, so it executes precisely what was approved.
-- A stolen database could mint approvals; that is the same trust the whole pull queue already places in the database.

-- HMAC-SHA256 with the built-in sha256() (no extension needed). Key and message are text; the result is lowercase hex.
CREATE FUNCTION pc_hmac_sha256(p_key text, p_msg text) RETURNS text LANGUAGE plpgsql IMMUTABLE AS $$
DECLARE k bytea := convert_to(p_key, 'UTF8'); ipad bytea; opad bytea; i int;
BEGIN
  IF length(k) > 64 THEN k := sha256(k); END IF;
  k := k || decode(repeat('00', 64 - length(k)), 'hex');
  ipad := k; opad := k;
  FOR i IN 0..63 LOOP
    ipad := set_byte(ipad, i, get_byte(k, i) # 54);   -- 0x36
    opad := set_byte(opad, i, get_byte(k, i) # 92);   -- 0x5c
  END LOOP;
  RETURN encode(sha256(opad || sha256(ipad || convert_to(p_msg, 'UTF8'))), 'hex');
END $$;

CREATE TABLE pc_plans (
  id           char(26)    PRIMARY KEY,
  tenant_id    char(26)    NOT NULL REFERENCES tenants (id),
  device_id    char(26)    NOT NULL REFERENCES pc_devices (id),
  requested_by char(26)    NOT NULL REFERENCES users (id),
  goal         text        NOT NULL CHECK (char_length(goal) BETWEEN 1 AND 2000),
  plan_text    text        NOT NULL CHECK (length(plan_text) <= 60000),   -- the exact bytes that get signed and sent
  step_texts   jsonb       NOT NULL,                                       -- {step_id: exact text of that step}
  needs        text[]      NOT NULL DEFAULT '{}',                          -- step ids that need their own approval
  status       text        NOT NULL CHECK (status IN ('blocked','pending','approved','rejected','running','done','failed','expired','stopped')),
  reason       text,
  decided_by   char(26)    REFERENCES users (id),
  decided_at   timestamptz,
  result       text        CHECK (length(result) <= 8000),
  expires_at   timestamptz NOT NULL,
  started_at   timestamptz,
  finished_at  timestamptz,
  created_at   timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX pc_plans_device ON pc_plans (device_id, status, created_at);
CREATE INDEX pc_plans_tenant ON pc_plans (tenant_id, created_at DESC);

CREATE TABLE pc_plan_step_approvals (
  plan_id     char(26)    NOT NULL REFERENCES pc_plans (id),
  tenant_id   char(26)    NOT NULL REFERENCES tenants (id),
  step_id     text        NOT NULL,
  approved_by char(26)    NOT NULL REFERENCES users (id),
  approved_at timestamptz NOT NULL DEFAULT now(),
  via         text        NOT NULL CHECK (via IN ('web', 'mobile')),
  PRIMARY KEY (plan_id, step_id)
);

CREATE FUNCTION pc_plans_guard() RETURNS trigger LANGUAGE plpgsql SET search_path = public, pg_temp AS $$
BEGIN
  IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'pc plans are an audit trail' USING ERRCODE = '22023'; END IF;
  IF NEW.id IS DISTINCT FROM OLD.id OR NEW.tenant_id IS DISTINCT FROM OLD.tenant_id OR NEW.device_id IS DISTINCT FROM OLD.device_id
     OR NEW.requested_by IS DISTINCT FROM OLD.requested_by OR NEW.plan_text IS DISTINCT FROM OLD.plan_text
     OR NEW.step_texts IS DISTINCT FROM OLD.step_texts OR NEW.needs IS DISTINCT FROM OLD.needs OR NEW.goal IS DISTINCT FROM OLD.goal THEN
    RAISE EXCEPTION 'pc plan is immutable' USING ERRCODE = '22023';
  END IF;
  IF NEW.status IS DISTINCT FROM OLD.status AND NOT (
       (OLD.status = 'pending'  AND NEW.status IN ('approved', 'rejected', 'expired', 'stopped'))
    OR (OLD.status = 'approved' AND NEW.status IN ('running', 'expired', 'stopped'))
    OR (OLD.status = 'running'  AND NEW.status IN ('done', 'failed', 'stopped'))) THEN
    RAISE EXCEPTION 'illegal pc plan transition % -> %', OLD.status, NEW.status USING ERRCODE = '22023';
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER pc_plans_guard_trg BEFORE UPDATE OR DELETE ON pc_plans FOR EACH ROW EXECUTE FUNCTION pc_plans_guard();
CREATE FUNCTION pc_plan_approvals_guard() RETURNS trigger LANGUAGE plpgsql SET search_path = public, pg_temp AS $$
BEGIN RAISE EXCEPTION 'plan step approvals are an audit trail' USING ERRCODE = '22023'; END $$;
CREATE TRIGGER pc_plan_approvals_guard_trg BEFORE UPDATE OR DELETE ON pc_plan_step_approvals FOR EACH ROW EXECUTE FUNCTION pc_plan_approvals_guard();
REVOKE ALL ON FUNCTION pc_plans_guard(), pc_plan_approvals_guard() FROM PUBLIC;

DO $$ DECLARE t text; BEGIN
  FOREACH t IN ARRAY ARRAY['pc_plans', 'pc_plan_step_approvals'] LOOP
    EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', t);
    EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY', t);
    EXECUTE format('CREATE POLICY tenant_isolation ON %I USING (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()))
                    WITH CHECK (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()))', t);
    EXECUTE format('REVOKE INSERT, UPDATE, DELETE, TRUNCATE ON %I FROM jarvis_app_rw', t);
  END LOOP;
END $$;
-- the web role may read everything about a plan except nothing secret lives here (the signing key is in pc_devices, which it cannot read)

-- Submit a draft plan (from the planner or typed by the person). Every step is checked against the device policy exactly like a single action.
CREATE FUNCTION pc_plan_submit(p_tenant char(26), p_user char(26), p_device char(26), p_goal text, p_plan jsonb) RETURNS jsonb
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

-- The device owner approves or rejects the plan.
CREATE FUNCTION pc_plan_decide(p_tenant char(26), p_user char(26), p_plan char(26), p_decision text) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); pl pc_plans%ROWTYPE; out text;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  IF p_decision IS NULL OR p_decision NOT IN ('approve', 'reject') THEN RETURN 'bad_input'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT x.* INTO pl FROM pc_plans x JOIN pc_devices d ON d.id = x.device_id WHERE x.id = p_plan AND x.tenant_id = p_tenant AND d.user_id = p_user AND d.revoked_at IS NULL FOR UPDATE OF x;
  IF NOT FOUND THEN out := 'not_found';
  ELSIF pl.status <> 'pending' THEN out := 'not_pending';
  ELSIF emergency_active(p_tenant) THEN UPDATE pc_plans SET status = 'stopped', reason = 'emergency_stop', finished_at = now() WHERE id = pl.id; out := 'emergency_stop';
  ELSIF pl.expires_at <= now() THEN UPDATE pc_plans SET status = 'expired', finished_at = now() WHERE id = pl.id; out := 'expired';
  ELSE
    UPDATE pc_plans SET status = CASE p_decision WHEN 'approve' THEN 'approved' ELSE 'rejected' END, decided_by = p_user, decided_at = now(),
           finished_at = CASE p_decision WHEN 'reject' THEN now() END, expires_at = CASE p_decision WHEN 'approve' THEN now() + interval '30 minutes' ELSE expires_at END
     WHERE id = pl.id;
    out := 'ok';
  END IF;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN out;
END $$;

-- The device owner approves ONE risky step. (The web layer asks for the PIN step-up before calling this; the database enforces who, when and which step.)
CREATE FUNCTION pc_plan_step_approve(p_tenant char(26), p_user char(26), p_plan char(26), p_step text) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); pl pc_plans%ROWTYPE; out text;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT x.* INTO pl FROM pc_plans x JOIN pc_devices d ON d.id = x.device_id WHERE x.id = p_plan AND x.tenant_id = p_tenant AND d.user_id = p_user AND d.revoked_at IS NULL FOR UPDATE OF x;
  IF NOT FOUND THEN out := 'not_found';
  ELSIF pl.status NOT IN ('pending', 'approved') THEN out := 'not_open';
  ELSIF p_step IS NULL OR NOT (p_step = ANY (pl.needs)) THEN out := 'not_needed';
  ELSIF emergency_active(p_tenant) THEN out := 'emergency_stop';
  ELSIF pl.expires_at <= now() THEN out := 'expired';
  ELSIF pc_kind_risk((pl.step_texts ->> p_step)::jsonb ->> 'kind') IN ('high', 'critical') THEN out := 'mobile_required';   -- same rule as single actions (phase 28g)
  ELSE
    INSERT INTO pc_plan_step_approvals (plan_id, tenant_id, step_id, approved_by, via) VALUES (pl.id, p_tenant, p_step, p_user, 'web') ON CONFLICT DO NOTHING;
    out := 'ok';
  END IF;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN out;
END $$;

-- The same, from the phone: required for high / critical steps; needs the live PIN step-up window like every phone approval.
CREATE FUNCTION mobile_pc_plan_step_approve(p_device char(26), p_user char(26), p_plan char(26), p_step text) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE d mobile_devices%ROWTYPE; prev text := coalesce(current_setting('app.bypass_rls', true), ''); pl pc_plans%ROWTYPE; out text;
BEGIN
  d := mobile_device_guard(p_device, p_user);
  IF d.stepup_until IS NULL OR d.stepup_until <= now() THEN RETURN 'step_up_required'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT x.* INTO pl FROM pc_plans x JOIN pc_devices pd ON pd.id = x.device_id WHERE x.id = p_plan AND x.tenant_id = d.tenant_id AND pd.user_id = p_user AND pd.revoked_at IS NULL FOR UPDATE OF x;
  IF NOT FOUND THEN out := 'not_found';
  ELSIF pl.status NOT IN ('pending', 'approved') THEN out := 'not_open';
  ELSIF p_step IS NULL OR NOT (p_step = ANY (pl.needs)) THEN out := 'not_needed';
  ELSIF emergency_active(d.tenant_id) THEN out := 'emergency_stop';
  ELSIF pl.expires_at <= now() THEN out := 'expired';
  ELSE
    INSERT INTO pc_plan_step_approvals (plan_id, tenant_id, step_id, approved_by, via) VALUES (pl.id, d.tenant_id, p_step, p_user, 'mobile') ON CONFLICT DO NOTHING;
    out := 'ok';
  END IF;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN out;
END $$;

-- Agent pull: the oldest approved, unexpired plan of this device (marks it running) with signatures, or NULL.
-- Signed messages (all bound to device and plan, expiring 5 minutes after the pull):
--   plan : 'canai-plan|<device>|<plan>|<sha256 hex of plan_text>|<exp>'
--   step : 'canai-step|<device>|<plan>|<step id>|<sha256 hex of that step's exact text>|<exp>'   (only for steps the person approved separately)
CREATE FUNCTION pc_plan_next(p_tenant char(26), p_device char(26)) RETURNS jsonb
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
  SELECT * INTO pl FROM pc_plans WHERE device_id = p_device AND status = 'approved' ORDER BY created_at LIMIT 1 FOR UPDATE SKIP LOCKED;
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

-- The agent reports how the plan ended. Only a running plan can be reported, once.
CREATE FUNCTION pc_plan_report(p_tenant char(26), p_device char(26), p_plan char(26), p_state text, p_result text) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); n int;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_state IS NULL OR p_state NOT IN ('done', 'failed', 'stopped') THEN RETURN 'bad_input'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE pc_plans SET status = p_state, result = left(coalesce(p_result, ''), 8000), finished_at = now()
   WHERE id = p_plan AND tenant_id = p_tenant AND device_id = p_device AND status = 'running';
  GET DIAGNOSTICS n = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN n = 1 THEN 'ok' ELSE 'not_running' END;
END $$;

DO $$ DECLARE f text; BEGIN
  FOREACH f IN ARRAY ARRAY[
    'pc_hmac_sha256(text, text)', 'pc_plan_submit(char, char, char, text, jsonb)', 'pc_plan_decide(char, char, char, text)',
    'pc_plan_step_approve(char, char, char, text)', 'mobile_pc_plan_step_approve(char, char, char, text)', 'pc_plan_next(char, char)', 'pc_plan_report(char, char, char, text, text)'] LOOP
    EXECUTE format('REVOKE ALL ON FUNCTION %s FROM PUBLIC, jarvis_app_rw, jarvis_admin', f);
    EXECUTE format('GRANT EXECUTE ON FUNCTION %s TO jarvis_app_rw, jarvis_admin', f);
  END LOOP;
END $$;
