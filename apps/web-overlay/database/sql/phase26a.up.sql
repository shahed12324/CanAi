-- Phase 26a: Computer Control rules (server side). A paired PC ("device") runs a desktop agent that PULLS approved actions.
-- Nothing here executes anything: the database decides whether an action is blocked / needs approval / auto-approved, keeps the audit
-- trail and honours the workspace emergency stop. The app role is SELECT-only; every write goes through the functions below.

-- Risk class of an action kind (NULL = unknown kind).
CREATE FUNCTION pc_kind_risk(p_kind text) RETURNS text LANGUAGE sql IMMUTABLE AS $$
  SELECT CASE p_kind
    WHEN 'read_file' THEN 'low' WHEN 'list_dir' THEN 'low'
    WHEN 'write_file' THEN 'medium' WHEN 'open_app' THEN 'medium' WHEN 'browser_navigate' THEN 'medium' WHEN 'system_setting' THEN 'medium'
    WHEN 'delete_file' THEN 'high' WHEN 'run_command' THEN 'high' WHEN 'input_control' THEN 'high'
    WHEN 'power' THEN 'critical' END
$$;

CREATE TABLE pc_devices (
  id           char(26)    PRIMARY KEY,
  tenant_id    char(26)    NOT NULL REFERENCES tenants (id),
  user_id      char(26)    NOT NULL REFERENCES users (id),
  name         varchar(60) NOT NULL CHECK (length(btrim(name)) > 0),
  platform     text        NOT NULL CHECK (platform IN ('windows', 'macos', 'linux')),
  token_hash   char(64)    UNIQUE,                       -- sha256 of the agent token; the token itself is shown once and never stored
  paired_at    timestamptz NOT NULL DEFAULT now(),
  last_seen_at timestamptz,
  revoked_at   timestamptz,
  CHECK (revoked_at IS NULL OR token_hash IS NULL)
);
CREATE INDEX pc_devices_user ON pc_devices (tenant_id, user_id) WHERE revoked_at IS NULL;

CREATE TABLE pc_pairings (
  id         char(26)    PRIMARY KEY,
  tenant_id  char(26)    NOT NULL REFERENCES tenants (id),
  user_id    char(26)    NOT NULL REFERENCES users (id),
  name       varchar(60) NOT NULL,
  platform   text        NOT NULL CHECK (platform IN ('windows', 'macos', 'linux')),
  code_hash  char(64)    NOT NULL UNIQUE,                -- sha256 of the one-time pairing code (shown as QR/text for 10 minutes)
  expires_at timestamptz NOT NULL,
  used_at    timestamptz,
  created_at timestamptz NOT NULL DEFAULT now()
);

-- What a device may touch. Empty lists = nothing allowed. modes: {kind: deny|ask|allow}; unlisted kinds default to ask.
CREATE TABLE pc_policies (
  device_id  char(26)    PRIMARY KEY REFERENCES pc_devices (id),
  tenant_id  char(26)    NOT NULL REFERENCES tenants (id),
  roots      text[]      NOT NULL DEFAULT '{}',
  apps       text[]      NOT NULL DEFAULT '{}',
  commands   text[]      NOT NULL DEFAULT '{}',
  hosts      text[]      NOT NULL DEFAULT '{}',
  modes      jsonb       NOT NULL DEFAULT '{}',
  updated_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE pc_actions (
  id          char(26)    PRIMARY KEY,
  tenant_id   char(26)    NOT NULL REFERENCES tenants (id),
  device_id   char(26)    NOT NULL REFERENCES pc_devices (id),
  requested_by char(26)   NOT NULL REFERENCES users (id),
  kind        text        NOT NULL CHECK (pc_kind_risk(kind) IS NOT NULL),
  risk        text        NOT NULL CHECK (risk IN ('low', 'medium', 'high', 'critical')),
  params      jsonb       NOT NULL CHECK (jsonb_typeof(params) = 'object' AND length(params::text) <= 4000),
  status      text        NOT NULL CHECK (status IN ('blocked', 'pending', 'approved', 'rejected', 'running', 'done', 'failed', 'expired', 'stopped')),
  reason      text,
  decided_by  char(26)    REFERENCES users (id),
  decided_at  timestamptz,
  result      text        CHECK (length(result) <= 2000),
  expires_at  timestamptz NOT NULL,
  started_at  timestamptz,
  finished_at timestamptz,
  created_at  timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX pc_actions_device ON pc_actions (device_id, status, created_at);
CREATE INDEX pc_actions_tenant ON pc_actions (tenant_id, created_at DESC);

CREATE TABLE pc_metrics (
  id         bigserial   PRIMARY KEY,
  tenant_id  char(26)    NOT NULL REFERENCES tenants (id),
  device_id  char(26)    NOT NULL REFERENCES pc_devices (id),
  cpu        numeric(5,2) NOT NULL CHECK (cpu BETWEEN 0 AND 100),
  mem        numeric(5,2) NOT NULL CHECK (mem BETWEEN 0 AND 100),
  disk       numeric(5,2) NOT NULL CHECK (disk BETWEEN 0 AND 100),
  gpu        numeric(5,2) CHECK (gpu BETWEEN 0 AND 100),
  battery    numeric(5,2) CHECK (battery BETWEEN 0 AND 100),
  temp_c     numeric(5,1) CHECK (temp_c BETWEEN -50 AND 150),
  charging   boolean,
  created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX pc_metrics_device ON pc_metrics (device_id, created_at DESC);

DO $$ DECLARE t text; BEGIN
  FOREACH t IN ARRAY ARRAY['pc_devices', 'pc_pairings', 'pc_policies', 'pc_actions', 'pc_metrics'] LOOP
    EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', t);
    EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY', t);
    EXECUTE format('CREATE POLICY tenant_isolation ON %I USING (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()))
                    WITH CHECK (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()))', t);
    EXECUTE format('REVOKE INSERT, UPDATE, DELETE, TRUNCATE ON %I FROM jarvis_app_rw', t);
  END LOOP;
END $$;
REVOKE SELECT ON pc_devices, pc_pairings FROM jarvis_app_rw;          -- secrets (hashes) are not readable by the web role
GRANT SELECT (id, tenant_id, user_id, name, platform, paired_at, last_seen_at, revoked_at) ON pc_devices TO jarvis_app_rw;
GRANT SELECT (id, tenant_id, user_id, name, platform, expires_at, used_at, created_at) ON pc_pairings TO jarvis_app_rw;

-- Status machine: actions only move forward, and a finished action never changes again.
CREATE FUNCTION pc_actions_guard() RETURNS trigger LANGUAGE plpgsql SET search_path = public, pg_temp AS $$
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
CREATE TRIGGER pc_actions_guard_trg BEFORE UPDATE OR DELETE ON pc_actions FOR EACH ROW EXECUTE FUNCTION pc_actions_guard();
REVOKE ALL ON FUNCTION pc_actions_guard() FROM PUBLIC;

-- Does the action's target fall inside the device policy?  'ok' | 'bad_params' | 'outside_scope'
CREATE FUNCTION pc_target_ok(p_kind text, p jsonb, p_roots text[], p_apps text[], p_commands text[], p_hosts text[]) RETURNS text
LANGUAGE plpgsql IMMUTABLE AS $$
DECLARE path text; r text; host text; url text; arg jsonb;
BEGIN
  IF p_kind IN ('read_file', 'list_dir', 'write_file', 'delete_file') THEN
    IF jsonb_typeof(p -> 'path') IS DISTINCT FROM 'string' THEN RETURN 'bad_params'; END IF;
    path := replace(p ->> 'path', E'\\', '/');
    IF path ~ '[[:cntrl:]]' OR char_length(path) > 520 OR path !~ '^(/|[A-Za-z]:/)' OR path ~ '(^|/)\.\.(/|$)' THEN RETURN 'bad_params'; END IF;
    path := lower(regexp_replace(path, '/+$', ''));
    FOREACH r IN ARRAY p_roots LOOP
      r := lower(regexp_replace(replace(r, E'\\', '/'), '/+$', ''));
      IF r <> '' AND (path = r OR left(path, char_length(r) + 1) = r || '/') THEN RETURN 'ok'; END IF;
    END LOOP;
    RETURN 'outside_scope';
  ELSIF p_kind = 'open_app' THEN
    IF jsonb_typeof(p -> 'app') IS DISTINCT FROM 'string' THEN RETURN 'bad_params'; END IF;
    RETURN CASE WHEN lower(p ->> 'app') = ANY (SELECT lower(a) FROM unnest(p_apps) a) THEN 'ok' ELSE 'outside_scope' END;
  ELSIF p_kind = 'run_command' THEN
    IF jsonb_typeof(p -> 'command') IS DISTINCT FROM 'string' THEN RETURN 'bad_params'; END IF;
    IF p ? 'args' THEN
      IF jsonb_typeof(p -> 'args') IS DISTINCT FROM 'array' OR jsonb_array_length(p -> 'args') > 20 THEN RETURN 'bad_params'; END IF;
      FOR arg IN SELECT * FROM jsonb_array_elements(p -> 'args') LOOP
        IF jsonb_typeof(arg) IS DISTINCT FROM 'string' OR char_length(arg #>> '{}') > 200 OR (arg #>> '{}') ~ '[[:cntrl:]]' THEN RETURN 'bad_params'; END IF;
      END LOOP;
    END IF;
    RETURN CASE WHEN lower(p ->> 'command') = ANY (SELECT lower(c) FROM unnest(p_commands) c) THEN 'ok' ELSE 'outside_scope' END;
  ELSIF p_kind = 'browser_navigate' THEN
    IF jsonb_typeof(p -> 'url') IS DISTINCT FROM 'string' THEN RETURN 'bad_params'; END IF;
    url := p ->> 'url';
    IF char_length(url) > 2000 OR url ~ '[[:cntrl:][:space:]]' OR url !~* '^https://[a-z0-9]([a-z0-9.-]*[a-z0-9])?(:[0-9]{1,5})?([/?#]|$)' THEN RETURN 'bad_params'; END IF;
    host := lower(substring(url from '^[Hh][Tt][Tt][Pp][Ss]://([A-Za-z0-9.-]+)'));
    RETURN CASE WHEN EXISTS (SELECT 1 FROM unnest(p_hosts) h WHERE host = lower(h) OR host LIKE '%.' || lower(h)) THEN 'ok' ELSE 'outside_scope' END;
  ELSIF p_kind = 'input_control' THEN
    RETURN CASE WHEN p ->> 'action' IN ('type', 'click', 'key') THEN 'ok' ELSE 'bad_params' END;
  ELSIF p_kind = 'system_setting' THEN
    RETURN CASE WHEN p ->> 'setting' IN ('volume', 'brightness', 'wifi', 'bluetooth') AND p ? 'value' THEN 'ok' ELSE 'bad_params' END;
  ELSIF p_kind = 'power' THEN
    RETURN CASE WHEN p ->> 'action' IN ('shutdown', 'restart', 'sleep') THEN 'ok' ELSE 'bad_params' END;
  END IF;
  RETURN 'bad_params';
END $$;

-- ---------- pairing (QR from the web app -> code typed/scanned in the desktop agent) ----------
CREATE FUNCTION pc_pair_create(p_tenant char(26), p_user char(26), p_name text, p_platform text, p_code_hash text) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); nm text := btrim(coalesce(p_name, ''));
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  IF nm = '' OR char_length(nm) > 60 OR nm ~ '[[:cntrl:]<>]' OR p_platform IS NULL OR p_platform NOT IN ('windows', 'macos', 'linux') OR p_code_hash IS NULL OR p_code_hash !~ '^[0-9a-f]{64}$' THEN RETURN 'bad_input'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  IF NOT EXISTS (SELECT 1 FROM tenant_users WHERE tenant_id = p_tenant AND user_id = p_user) THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'forbidden'; END IF;
  IF (SELECT count(*) FROM pc_devices WHERE tenant_id = p_tenant AND user_id = p_user AND revoked_at IS NULL) >= 5
     OR (SELECT count(*) FROM pc_pairings WHERE tenant_id = p_tenant AND user_id = p_user AND used_at IS NULL AND expires_at > now()) >= 5 THEN
    PERFORM set_config('app.bypass_rls', prev, true); RETURN 'limit';
  END IF;
  INSERT INTO pc_pairings (id, tenant_id, user_id, name, platform, code_hash, expires_at)
  VALUES (lower(substr(md5(random()::text || clock_timestamp()::text) || md5(random()::text), 1, 26)), p_tenant, p_user, nm, p_platform, p_code_hash, now() + interval '10 minutes');
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

-- Called by the (unauthenticated) agent with the code; returns the new device or NULL. One use only.
CREATE FUNCTION pc_pair_redeem(p_code_hash text, p_token_hash text) RETURNS jsonb
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); pr pc_pairings%ROWTYPE; did char(26);
BEGIN
  IF p_code_hash IS NULL OR p_code_hash !~ '^[0-9a-f]{64}$' OR p_token_hash IS NULL OR p_token_hash !~ '^[0-9a-f]{64}$' THEN RETURN NULL; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO pr FROM pc_pairings WHERE code_hash = p_code_hash AND used_at IS NULL AND expires_at > now() FOR UPDATE;
  IF NOT FOUND OR (SELECT count(*) FROM pc_devices WHERE tenant_id = pr.tenant_id AND user_id = pr.user_id AND revoked_at IS NULL) >= 5 THEN
    PERFORM set_config('app.bypass_rls', prev, true); RETURN NULL;
  END IF;
  did := lower(substr(md5(random()::text || clock_timestamp()::text) || md5(random()::text), 1, 26));
  INSERT INTO pc_devices (id, tenant_id, user_id, name, platform, token_hash) VALUES (did, pr.tenant_id, pr.user_id, pr.name, pr.platform, p_token_hash);
  INSERT INTO pc_policies (device_id, tenant_id) VALUES (did, pr.tenant_id);
  UPDATE pc_pairings SET used_at = now() WHERE id = pr.id;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN jsonb_build_object('device_id', did, 'tenant_id', pr.tenant_id);
END $$;

-- Agent token -> device (NULL if unknown/revoked). Touches last_seen_at.
CREATE FUNCTION pc_agent_lookup(p_token_hash text) RETURNS jsonb
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); d pc_devices%ROWTYPE;
BEGIN
  IF p_token_hash IS NULL OR p_token_hash !~ '^[0-9a-f]{64}$' THEN RETURN NULL; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE pc_devices SET last_seen_at = now() WHERE token_hash = p_token_hash AND revoked_at IS NULL RETURNING * INTO d;
  IF NOT FOUND THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN NULL; END IF;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN jsonb_build_object('device_id', d.id, 'tenant_id', d.tenant_id, 'user_id', d.user_id);
END $$;

CREATE FUNCTION pc_device_revoke(p_tenant char(26), p_user char(26), p_device char(26)) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), '');
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE pc_devices SET revoked_at = now(), token_hash = NULL WHERE id = p_device AND tenant_id = p_tenant AND user_id = p_user AND revoked_at IS NULL;
  IF NOT FOUND THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'not_found'; END IF;
  UPDATE pc_actions SET status = 'stopped', reason = 'device_revoked', finished_at = now() WHERE device_id = p_device AND status IN ('pending', 'approved', 'running');
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

-- ---------- policy ----------
CREATE FUNCTION pc_policy_save(p_tenant char(26), p_user char(26), p_device char(26), p_roots text[], p_apps text[], p_commands text[], p_hosts text[], p_modes jsonb) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); x text; k text; v text;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  p_roots := coalesce(p_roots, '{}'); p_apps := coalesce(p_apps, '{}'); p_commands := coalesce(p_commands, '{}'); p_hosts := coalesce(p_hosts, '{}'); p_modes := coalesce(p_modes, '{}');
  IF cardinality(p_roots) > 20 OR cardinality(p_apps) > 50 OR cardinality(p_commands) > 50 OR cardinality(p_hosts) > 50 OR jsonb_typeof(p_modes) <> 'object' THEN RETURN 'bad_input'; END IF;
  FOREACH x IN ARRAY p_roots LOOP
    -- absolute, no control chars, no '..', and never a bare filesystem/drive root
    IF x IS NULL OR char_length(x) > 260 OR x ~ '[[:cntrl:]]' OR replace(x, E'\\', '/') !~ '^(/|[A-Za-z]:/)' OR replace(x, E'\\', '/') ~ '(^|/)\.\.(/|$)'
       OR regexp_replace(replace(x, E'\\', '/'), '/+$', '') ~ '^([A-Za-z]:)?$' THEN RETURN 'bad_input'; END IF;
  END LOOP;
  FOREACH x IN ARRAY p_apps LOOP IF x IS NULL OR char_length(x) NOT BETWEEN 1 AND 100 OR x ~ '[[:cntrl:]/\\<>]' THEN RETURN 'bad_input'; END IF; END LOOP;
  FOREACH x IN ARRAY p_commands LOOP IF x IS NULL OR x !~ '^[A-Za-z0-9._-]{1,64}$' THEN RETURN 'bad_input'; END IF; END LOOP;
  FOREACH x IN ARRAY p_hosts LOOP IF x IS NULL OR x !~ '^[a-z0-9]([a-z0-9.-]{0,251}[a-z0-9])?$' THEN RETURN 'bad_input'; END IF; END LOOP;
  FOR k, v IN SELECT key, value #>> '{}' FROM jsonb_each(p_modes) LOOP
    IF pc_kind_risk(k) IS NULL OR v IS NULL OR v NOT IN ('deny', 'ask', 'allow') OR (v = 'allow' AND pc_kind_risk(k) IN ('high', 'critical')) THEN RETURN 'bad_input'; END IF;
  END LOOP;
  PERFORM set_config('app.bypass_rls', 'on', true);
  IF NOT EXISTS (SELECT 1 FROM pc_devices WHERE id = p_device AND tenant_id = p_tenant AND user_id = p_user AND revoked_at IS NULL) THEN
    PERFORM set_config('app.bypass_rls', prev, true); RETURN 'not_found';
  END IF;
  INSERT INTO pc_policies (device_id, tenant_id, roots, apps, commands, hosts, modes) VALUES (p_device, p_tenant, p_roots, p_apps, p_commands, p_hosts, p_modes)
  ON CONFLICT (device_id) DO UPDATE SET roots = EXCLUDED.roots, apps = EXCLUDED.apps, commands = EXCLUDED.commands, hosts = EXCLUDED.hosts, modes = EXCLUDED.modes, updated_at = now();
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

-- ---------- actions ----------
-- Records the request and decides: blocked (reason) | pending (needs a human) | approved (low/medium risk, mode=allow, in scope).
CREATE FUNCTION pc_action_request(p_tenant char(26), p_user char(26), p_device char(26), p_kind text, p_params jsonb) RETURNS jsonb
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

-- The device owner approves or rejects a pending action. Emergency stop wins over everything.
CREATE FUNCTION pc_action_decide(p_tenant char(26), p_user char(26), p_action char(26), p_decision text) RETURNS text
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

-- Agent pull: the oldest approved, unexpired action of this device (marks it running), or NULL. During an emergency stop nothing is handed out and open actions are stopped.
CREATE FUNCTION pc_action_next(p_tenant char(26), p_device char(26)) RETURNS jsonb
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

CREATE FUNCTION pc_action_report(p_tenant char(26), p_device char(26), p_action char(26), p_ok boolean, p_result text) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), '');
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_ok IS NULL THEN RETURN 'bad_input'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE pc_actions SET status = CASE WHEN p_ok THEN 'done' ELSE 'failed' END, result = left(coalesce(p_result, ''), 2000), finished_at = now()
   WHERE id = p_action AND tenant_id = p_tenant AND device_id = p_device AND status = 'running';
  IF NOT FOUND THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'not_running'; END IF;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

-- ---------- system monitor ----------
CREATE FUNCTION pc_metrics_add(p_tenant char(26), p_device char(26), p_cpu numeric, p_mem numeric, p_disk numeric, p_gpu numeric, p_battery numeric, p_temp numeric, p_charging boolean) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), '');
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_cpu IS NULL OR p_mem IS NULL OR p_disk IS NULL OR p_cpu NOT BETWEEN 0 AND 100 OR p_mem NOT BETWEEN 0 AND 100 OR p_disk NOT BETWEEN 0 AND 100
     OR p_gpu NOT BETWEEN 0 AND 100 OR p_battery NOT BETWEEN 0 AND 100 OR p_temp NOT BETWEEN -50 AND 150 THEN RETURN 'bad_input'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  IF NOT EXISTS (SELECT 1 FROM pc_devices WHERE id = p_device AND tenant_id = p_tenant AND revoked_at IS NULL) THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'not_found'; END IF;
  IF EXISTS (SELECT 1 FROM pc_metrics WHERE device_id = p_device AND created_at > now() - interval '5 seconds') THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'too_fast'; END IF;
  INSERT INTO pc_metrics (tenant_id, device_id, cpu, mem, disk, gpu, battery, temp_c, charging) VALUES (p_tenant, p_device, p_cpu, p_mem, p_disk, p_gpu, p_battery, p_temp, p_charging);
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

-- Housekeeping (admin only): metrics older than N days; finished actions are kept (audit trail).
CREATE FUNCTION pc_metrics_purge(p_days integer DEFAULT 7) RETURNS integer
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); n integer;
BEGIN
  PERFORM credit_authorize(NULL, true);
  PERFORM set_config('app.bypass_rls', 'on', true);
  DELETE FROM pc_metrics WHERE created_at < now() - make_interval(days => greatest(p_days, 1));
  GET DIAGNOSTICS n = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN n;
END $$;

DO $$ DECLARE f text; BEGIN
  FOREACH f IN ARRAY ARRAY[
    'pc_pair_create(char, char, text, text, text)', 'pc_pair_redeem(text, text)', 'pc_agent_lookup(text)', 'pc_device_revoke(char, char, char)',
    'pc_policy_save(char, char, char, text[], text[], text[], text[], jsonb)', 'pc_action_request(char, char, char, text, jsonb)', 'pc_action_decide(char, char, char, text)',
    'pc_action_next(char, char)', 'pc_action_report(char, char, char, boolean, text)', 'pc_metrics_add(char, char, numeric, numeric, numeric, numeric, numeric, numeric, boolean)',
    'pc_metrics_purge(integer)', 'pc_target_ok(text, jsonb, text[], text[], text[], text[])', 'pc_kind_risk(text)'] LOOP
    EXECUTE format('REVOKE ALL ON FUNCTION %s FROM PUBLIC, jarvis_app_rw, jarvis_admin', f);
    IF f LIKE 'pc_metrics_purge%' THEN EXECUTE format('GRANT EXECUTE ON FUNCTION %s TO jarvis_admin', f);
    ELSE EXECUTE format('GRANT EXECUTE ON FUNCTION %s TO jarvis_app_rw, jarvis_admin', f); END IF;
  END LOOP;
END $$;
