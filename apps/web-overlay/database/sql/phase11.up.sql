-- Phase 11: Mobile app v1 — devices, PIN step-up, approval inbox decisions, emergency stop, in-app notifications.
-- Money-like and trust-critical rules live here, so the PHP/Expo layers cannot skip them:
--   * approving anything from a phone needs a PIN confirmation made in the last 5 minutes (step-up) on that same device;
--   * resuming after an emergency stop needs the same; stopping never does (it must be instant);
--   * while a workspace is stopped nothing is approved and the outreach sender claims nothing.

CREATE TABLE mobile_devices (
  id               char(26)     PRIMARY KEY,
  tenant_id        char(26)     NOT NULL REFERENCES tenants (id),
  user_id          char(26)     NOT NULL REFERENCES users (id),
  token_id         bigint       NOT NULL,                                  -- the Sanctum token this device logged in with
  name             varchar(100) NOT NULL,
  platform         text         NOT NULL CHECK (platform IN ('android', 'ios')),
  push_token       varchar(200),
  app_version      varchar(30),
  pin_hash         varchar(255),                                           -- bcrypt/argon of the 6-digit PIN; never the PIN
  pin_failures     smallint     NOT NULL DEFAULT 0,
  pin_locked_until timestamptz,
  stepup_until     timestamptz,
  last_seen_at     timestamptz,
  revoked_at       timestamptz,
  created_at       timestamptz  NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX mobile_devices_token ON mobile_devices (tenant_id, user_id, token_id);
CREATE INDEX mobile_devices_push ON mobile_devices (tenant_id, user_id) WHERE revoked_at IS NULL AND push_token IS NOT NULL;

CREATE TABLE inbox_notifications (
  id          char(26)     PRIMARY KEY,
  tenant_id   char(26)     NOT NULL REFERENCES tenants (id),
  user_id     char(26)     NOT NULL REFERENCES users (id),
  kind        varchar(40)  NOT NULL,
  title       varchar(160) NOT NULL,
  body        varchar(500) NOT NULL DEFAULT '',
  ref_kind    varchar(40),
  ref_id      varchar(40),
  created_at  timestamptz  NOT NULL DEFAULT now(),
  read_at     timestamptz,
  pushed_at   timestamptz,
  push_error  varchar(80)
);
CREATE INDEX inbox_notifications_user ON inbox_notifications (tenant_id, user_id, created_at DESC);
CREATE INDEX inbox_notifications_unpushed ON inbox_notifications (created_at) WHERE pushed_at IS NULL AND push_error IS NULL;

-- Append-only: the newest row says whether the workspace is stopped.
CREATE TABLE emergency_events (
  seq         bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  tenant_id   char(26)     NOT NULL REFERENCES tenants (id),
  action      text         NOT NULL CHECK (action IN ('stop', 'resume')),
  by_user     char(26)     NOT NULL REFERENCES users (id),
  device_id   char(26)     REFERENCES mobile_devices (id),
  reason      varchar(300) NOT NULL DEFAULT '',
  created_at  timestamptz  NOT NULL DEFAULT now()
);
CREATE INDEX emergency_events_tenant ON emergency_events (tenant_id, seq DESC);

CREATE FUNCTION emergency_events_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'emergency_events is append-only'; END $$;
CREATE TRIGGER emergency_events_no_change BEFORE UPDATE OR DELETE ON emergency_events FOR EACH ROW EXECUTE FUNCTION emergency_events_immutable();

DO $$
DECLARE t text;
BEGIN
  FOREACH t IN ARRAY ARRAY['mobile_devices', 'inbox_notifications', 'emergency_events'] LOOP
    EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', t);
    EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY', t);
    EXECUTE format('CREATE POLICY tenant_isolation ON %I USING (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()))
                    WITH CHECK (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()))', t);
    EXECUTE format('REVOKE ALL ON %I FROM jarvis_app_rw, jarvis_admin', t);
  END LOOP;
END $$;

-- The app may register a device and tidy its own fields, but never touch PIN / step-up columns directly.
GRANT SELECT ON mobile_devices TO jarvis_app_rw, jarvis_admin;
GRANT INSERT (id, tenant_id, user_id, token_id, name, platform, push_token, app_version, last_seen_at) ON mobile_devices TO jarvis_app_rw;
GRANT UPDATE (name, push_token, app_version, last_seen_at, revoked_at) ON mobile_devices TO jarvis_app_rw;
GRANT UPDATE (push_token, revoked_at) ON mobile_devices TO jarvis_admin;      -- the push worker retires phones whose login no longer exists
GRANT SELECT, INSERT, UPDATE ON inbox_notifications TO jarvis_app_rw, jarvis_admin;
GRANT SELECT ON emergency_events TO jarvis_app_rw, jarvis_admin;

-- True while the workspace is stopped. Plain SQL, runs under the caller's RLS (a tenant only sees its own events).
CREATE FUNCTION emergency_active(p_tenant char(26)) RETURNS boolean LANGUAGE sql STABLE AS $$
  SELECT coalesce((SELECT action = 'stop' FROM emergency_events WHERE tenant_id = p_tenant ORDER BY seq DESC LIMIT 1), false)
$$;

-- Loads and authorises a device for the current tenant + user; raises 'forbidden' otherwise. Caller must hold bypass.
CREATE FUNCTION mobile_device_guard(p_device char(26), p_user char(26)) RETURNS mobile_devices LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE d mobile_devices%ROWTYPE; prev text := coalesce(current_setting('app.bypass_rls', true), '');
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO d FROM mobile_devices WHERE id = p_device;
  PERFORM set_config('app.bypass_rls', prev, true);
  IF NOT FOUND OR d.user_id IS DISTINCT FROM p_user OR d.revoked_at IS NOT NULL THEN
    RAISE EXCEPTION 'forbidden' USING ERRCODE = '42501';
  END IF;
  PERFORM credit_authorize(d.tenant_id, false);
  RETURN d;
END $$;

-- Stores the (already hashed) PIN. Changing it clears any lock and step-up.
CREATE FUNCTION mobile_set_pin(p_device char(26), p_user char(26), p_hash text) RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE d mobile_devices%ROWTYPE; prev text := coalesce(current_setting('app.bypass_rls', true), '');
BEGIN
  d := mobile_device_guard(p_device, p_user);
  IF p_hash IS NULL OR length(p_hash) < 20 THEN RAISE EXCEPTION 'bad_pin_hash' USING ERRCODE = 'P0001'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE mobile_devices SET pin_hash = p_hash, pin_failures = 0, pin_locked_until = NULL, stepup_until = NULL WHERE id = p_device;
  PERFORM set_config('app.bypass_rls', prev, true);
END $$;

-- Records one PIN check done by PHP (it compared the hash). 5 wrong tries lock the device for 15 minutes;
-- a right one opens a 5 minute step-up window. Returns ok | bad | locked | no_pin.
CREATE FUNCTION mobile_pin_attempt(p_device char(26), p_user char(26), p_ok boolean) RETURNS text LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE d mobile_devices%ROWTYPE; prev text := coalesce(current_setting('app.bypass_rls', true), ''); res text;
BEGIN
  d := mobile_device_guard(p_device, p_user);
  IF d.pin_hash IS NULL THEN RETURN 'no_pin'; END IF;
  IF d.pin_locked_until IS NOT NULL AND d.pin_locked_until > now() THEN RETURN 'locked'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  IF p_ok THEN
    UPDATE mobile_devices SET pin_failures = 0, pin_locked_until = NULL, stepup_until = now() + interval '5 minutes' WHERE id = p_device;
    res := 'ok';
  ELSE
    UPDATE mobile_devices SET pin_locked_until = CASE WHEN pin_failures + 1 >= 5 THEN now() + interval '15 minutes' ELSE NULL END,
           pin_failures = CASE WHEN pin_failures + 1 >= 5 THEN 0 ELSE pin_failures + 1 END,
           stepup_until = NULL
     WHERE id = p_device;
    SELECT CASE WHEN pin_locked_until IS NOT NULL AND pin_locked_until > now() THEN 'locked' ELSE 'bad' END INTO res FROM mobile_devices WHERE id = p_device;
  END IF;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN res;
END $$;

CREATE FUNCTION mobile_stepup_active(p_device char(26), p_user char(26)) RETURNS boolean LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE d mobile_devices%ROWTYPE;
BEGIN
  d := mobile_device_guard(p_device, p_user);
  RETURN d.stepup_until IS NOT NULL AND d.stepup_until > now();
END $$;

-- Everything that waits for a human decision in this workspace. New kinds are added as UNION branches in later phases.
CREATE FUNCTION mobile_approvals(p_limit integer DEFAULT 50)
RETURNS TABLE (kind text, ref char(26), title text, summary text, created_at timestamptz) LANGUAGE sql STABLE AS $$
  SELECT 'outreach_message'::text, m.id, m.subject::text, ('To ' || m.to_email || ' — ' || left(regexp_replace(m.body, '\s+', ' ', 'g'), 160))::text, m.created_at
    FROM outreach_messages m WHERE m.status = 'pending_approval'
   ORDER BY m.created_at DESC LIMIT least(greatest(p_limit, 1), 100)
$$;

-- Approve / reject one item from the phone. Approving needs a live step-up and no emergency stop.
-- Returns approved | rejected | not_pending | suppressed | step_up_required | emergency | unknown_kind
CREATE FUNCTION mobile_decide(p_device char(26), p_user char(26), p_kind text, p_ref char(26), p_decision text) RETURNS text LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE d mobile_devices%ROWTYPE; prev text := coalesce(current_setting('app.bypass_rls', true), ''); n integer; tid char(26);
BEGIN
  d := mobile_device_guard(p_device, p_user);
  IF p_decision NOT IN ('approve', 'reject') THEN RAISE EXCEPTION 'bad_decision' USING ERRCODE = 'P0001'; END IF;
  IF p_kind <> 'outreach_message' THEN RETURN 'unknown_kind'; END IF;
  tid := d.tenant_id;
  IF p_decision = 'approve' THEN
    IF d.stepup_until IS NULL OR d.stepup_until <= now() THEN RETURN 'step_up_required'; END IF;
    PERFORM set_config('app.bypass_rls', 'on', true);
    IF emergency_active(tid) THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'emergency'; END IF;
    BEGIN
      UPDATE outreach_messages SET status = 'approved', approved_by = p_user, approved_at = now()
       WHERE id = p_ref AND tenant_id = tid AND status = 'pending_approval';
      GET DIAGNOSTICS n = ROW_COUNT;
    EXCEPTION WHEN raise_exception THEN
      PERFORM set_config('app.bypass_rls', prev, true);
      IF SQLERRM LIKE '%suppressed%' THEN RETURN 'suppressed'; END IF;
      RAISE;
    END;
    PERFORM set_config('app.bypass_rls', prev, true);
    RETURN CASE WHEN n = 1 THEN 'approved' ELSE 'not_pending' END;
  END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE outreach_messages SET status = 'rejected' WHERE id = p_ref AND tenant_id = tid AND status = 'pending_approval';
  GET DIAGNOSTICS n = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN n = 1 THEN 'rejected' ELSE 'not_pending' END;
END $$;

-- Stop / resume the workspace. Stop is instant; resume needs a live step-up. Returns stopped | resumed | unchanged | step_up_required
CREATE FUNCTION mobile_emergency(p_device char(26), p_user char(26), p_stop boolean, p_reason text DEFAULT '') RETURNS text LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE d mobile_devices%ROWTYPE; prev text := coalesce(current_setting('app.bypass_rls', true), ''); cur boolean;
BEGIN
  d := mobile_device_guard(p_device, p_user);
  PERFORM set_config('app.bypass_rls', 'on', true);
  cur := emergency_active(d.tenant_id);
  IF p_stop AND cur THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'unchanged'; END IF;
  IF NOT p_stop AND NOT cur THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'unchanged'; END IF;
  IF NOT p_stop AND (d.stepup_until IS NULL OR d.stepup_until <= now()) THEN
    PERFORM set_config('app.bypass_rls', prev, true); RETURN 'step_up_required';
  END IF;
  INSERT INTO emergency_events (tenant_id, action, by_user, device_id, reason)
  VALUES (d.tenant_id, CASE WHEN p_stop THEN 'stop' ELSE 'resume' END, p_user, p_device, left(coalesce(p_reason, ''), 300));
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN p_stop THEN 'stopped' ELSE 'resumed' END;
END $$;

-- Logout / token revocation: the phone stops being a device in every workspace and forgets its push address.
CREATE FUNCTION mobile_revoke_token(p_user char(26), p_token bigint) RETURNS integer LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); n integer;
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE mobile_devices SET revoked_at = now(), push_token = NULL, stepup_until = NULL WHERE user_id = p_user AND token_id = p_token AND revoked_at IS NULL;
  GET DIAGNOSTICS n = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN n;
END $$;

REVOKE ALL ON FUNCTION emergency_events_immutable(), mobile_device_guard(char, char), mobile_set_pin(char, char, text), mobile_pin_attempt(char, char, boolean),
  mobile_stepup_active(char, char), mobile_decide(char, char, text, char, text), mobile_emergency(char, char, boolean, text), mobile_revoke_token(char, bigint) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION emergency_active(char), mobile_approvals(integer), mobile_set_pin(char, char, text), mobile_pin_attempt(char, char, boolean),
  mobile_stepup_active(char, char), mobile_decide(char, char, text, char, text), mobile_emergency(char, char, boolean, text), mobile_revoke_token(char, bigint) TO jarvis_app_rw, jarvis_admin;
