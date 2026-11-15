-- Phase 27.2b: outgoing webhooks.  A person adds an https address; when one of THEIR workflow runs finishes (done / failed / cancelled) JARVIS posts a small signed JSON message there.
-- The message carries only ids, the status and the credits spent - never outputs, texts or customer data.  Delivery is retried with a growing delay (1, 5, 15, 60, 240 minutes),
-- then given up; an address that fails 10 times in a row is switched off.  The signing secret is stored encrypted and shown to the person once.

CREATE TABLE webhook_endpoints (
  id            char(26)     PRIMARY KEY,
  tenant_id     char(26)     NOT NULL REFERENCES tenants (id),
  user_id       char(26)     NOT NULL REFERENCES users (id),
  name          varchar(80)  NOT NULL CHECK (btrim(name) <> ''),
  url           varchar(500) NOT NULL CHECK (url ~ '^https://[^[:space:]/@]+(/[^[:space:]]*)?$'),
  secret_cipher text         NOT NULL,
  secret_version integer     NOT NULL,
  active        boolean      NOT NULL DEFAULT true,
  since_at      timestamptz  NOT NULL DEFAULT now(),
  failures      integer      NOT NULL DEFAULT 0,
  last_ok_at    timestamptz,
  last_error    varchar(120),
  created_at    timestamptz  NOT NULL DEFAULT now(),
  UNIQUE (id, tenant_id)
);
CREATE INDEX webhook_endpoints_user ON webhook_endpoints (tenant_id, user_id, created_at DESC);

CREATE TABLE webhook_deliveries (
  id          char(26)    PRIMARY KEY,
  endpoint_id char(26)    NOT NULL,
  tenant_id   char(26)    NOT NULL,
  run_id      char(26)    NOT NULL,
  event       text        NOT NULL CHECK (event IN ('run.finished')),
  payload     jsonb       NOT NULL CHECK (jsonb_typeof(payload) = 'object' AND length(payload::text) <= 2000),
  status      text        NOT NULL DEFAULT 'queued' CHECK (status IN ('queued', 'sent', 'dead')),
  attempts    integer     NOT NULL DEFAULT 0,
  next_at     timestamptz NOT NULL DEFAULT now(),
  last_code   integer,
  created_at  timestamptz NOT NULL DEFAULT now(),
  FOREIGN KEY (endpoint_id, tenant_id) REFERENCES webhook_endpoints (id, tenant_id) ON DELETE CASCADE,
  UNIQUE (endpoint_id, run_id)
);
CREATE INDEX webhook_deliveries_due ON webhook_deliveries (next_at) WHERE status = 'queued';

DO $$ DECLARE t text; BEGIN
  FOREACH t IN ARRAY ARRAY['webhook_endpoints', 'webhook_deliveries'] LOOP
    EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', t);
    EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY', t);
    EXECUTE format('CREATE POLICY tenant_isolation ON %I USING (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin())) WITH CHECK (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()))', t);
    EXECUTE format('REVOKE ALL ON %I FROM jarvis_app_rw, jarvis_admin', t);
  END LOOP;
END $$;
-- The app reads everything except the secret; the secret is only reachable through webhook_claim().
GRANT SELECT (id, tenant_id, user_id, name, url, active, since_at, failures, last_ok_at, last_error, created_at) ON webhook_endpoints TO jarvis_app_rw, jarvis_admin;
GRANT SELECT (id, endpoint_id, tenant_id, run_id, event, status, attempts, next_at, last_code, created_at) ON webhook_deliveries TO jarvis_app_rw, jarvis_admin;

CREATE FUNCTION webhook_create(p_id char(26), p_tenant char(26), p_user char(26), p_name text, p_url text, p_cipher text, p_version integer) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); n integer; rc integer; nm text := btrim(coalesce(p_name, ''));
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  IF p_id IS NULL OR nm = '' OR char_length(nm) > 80 OR p_url IS NULL OR char_length(p_url) > 500 OR p_url !~ '^https://[^[:space:]/@]+(/[^[:space:]]*)?$'
     OR coalesce(p_cipher, '') = '' OR p_version IS NULL THEN RETURN 'bad_input'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT count(*) INTO n FROM webhook_endpoints WHERE tenant_id = p_tenant AND user_id = p_user;
  IF n >= 5 THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'limit'; END IF;
  INSERT INTO webhook_endpoints (id, tenant_id, user_id, name, url, secret_cipher, secret_version) VALUES (p_id, p_tenant, p_user, nm, p_url, p_cipher, p_version) ON CONFLICT DO NOTHING;
  GET DIAGNOSTICS rc = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN rc = 0 THEN 'duplicate' ELSE 'ok' END;
END $$;

CREATE FUNCTION webhook_delete(p_id char(26), p_tenant char(26), p_user char(26)) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); rc integer;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  DELETE FROM webhook_endpoints WHERE id = p_id AND tenant_id = p_tenant AND user_id = p_user;
  GET DIAGNOSTICS rc = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN rc = 0 THEN 'not_found' ELSE 'ok' END;
END $$;

-- Switching on again clears the failure count and starts "from now" (runs that finished while it was off are not sent late).
CREATE FUNCTION webhook_set_active(p_id char(26), p_tenant char(26), p_user char(26), p_active boolean) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); rc integer;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  IF p_active IS NULL THEN RETURN 'not_found'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE webhook_endpoints SET active = p_active, failures = CASE WHEN p_active THEN 0 ELSE failures END, since_at = CASE WHEN p_active AND NOT active THEN now() ELSE since_at END,
    last_error = CASE WHEN p_active THEN NULL ELSE last_error END
  WHERE id = p_id AND tenant_id = p_tenant AND user_id = p_user;
  GET DIAGNOSTICS rc = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN rc = 0 THEN 'not_found' ELSE 'ok' END;
END $$;

-- Worker (admin only): one message per (address, finished run) - the UNIQUE key makes this safe to run any number of times.  Only the owner's own runs, only since the address was added / switched on.
CREATE FUNCTION webhook_enqueue() RETURNS integer
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); n integer;
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  WITH ins AS (
    INSERT INTO webhook_deliveries (id, endpoint_id, tenant_id, run_id, event, payload)
    SELECT substr(md5(e.id || r.id) || md5(r.id || e.id), 1, 26), e.id, e.tenant_id, r.id, 'run.finished',
           jsonb_build_object('event', 'run.finished', 'run_id', trim(r.id), 'workflow_id', trim(r.workflow_id), 'status', r.status, 'spent', r.spent::text, 'finished_at', r.finished_at)
    FROM webhook_endpoints e
    JOIN wf_runs r ON r.tenant_id = e.tenant_id AND r.user_id = e.user_id
    WHERE e.active AND r.status IN ('done', 'failed', 'cancelled') AND r.finished_at IS NOT NULL AND r.finished_at > e.since_at AND r.finished_at > now() - interval '1 day'
    ON CONFLICT (endpoint_id, run_id) DO NOTHING RETURNING 1
  ) SELECT count(*) INTO n FROM ins;
  DELETE FROM webhook_deliveries WHERE status IN ('sent', 'dead') AND created_at < now() - interval '14 days';
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN n;
END $$;

-- Worker (admin only): hands out due messages and leases them for 5 minutes so two workers never send the same one.
CREATE FUNCTION webhook_claim(p_limit integer) RETURNS TABLE (id char(26), tenant_id char(26), url text, secret_cipher text, secret_version integer, payload jsonb, attempts integer)
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), '');
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  RETURN QUERY
  WITH picked AS (
    SELECT d.id FROM webhook_deliveries d JOIN webhook_endpoints e ON e.id = d.endpoint_id AND e.tenant_id = d.tenant_id
    WHERE d.status = 'queued' AND d.next_at <= now() AND e.active ORDER BY d.next_at LIMIT greatest(1, least(coalesce(p_limit, 20), 100)) FOR UPDATE OF d SKIP LOCKED
  ), upd AS (
    UPDATE webhook_deliveries d SET attempts = d.attempts + 1, next_at = now() + interval '5 minutes' FROM picked WHERE d.id = picked.id RETURNING d.id, d.endpoint_id, d.tenant_id, d.payload, d.attempts
  )
  SELECT upd.id, upd.tenant_id, e.url::text, e.secret_cipher, e.secret_version, upd.payload, upd.attempts FROM upd JOIN webhook_endpoints e ON e.id = upd.endpoint_id AND e.tenant_id = upd.tenant_id;
  PERFORM set_config('app.bypass_rls', prev, true);
END $$;

-- Worker (admin only): records how a send went.  Success: sent.  Failure: try again later, give up after 6 tries; 10 failures in a row switch the address off.
CREATE FUNCTION webhook_result(p_id char(26), p_ok boolean, p_code integer, p_error text) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); d webhook_deliveries%ROWTYPE; backoff integer[] := ARRAY[1, 5, 15, 60, 240];
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO d FROM webhook_deliveries WHERE id = p_id FOR UPDATE;
  IF NOT FOUND OR d.status <> 'queued' THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'not_found'; END IF;
  IF p_ok THEN
    UPDATE webhook_deliveries SET status = 'sent', last_code = p_code WHERE id = d.id;
    UPDATE webhook_endpoints SET failures = 0, last_ok_at = now(), last_error = NULL WHERE id = d.endpoint_id AND tenant_id = d.tenant_id;
  ELSE
    IF d.attempts >= 6 THEN
      UPDATE webhook_deliveries SET status = 'dead', last_code = p_code WHERE id = d.id;
    ELSE
      UPDATE webhook_deliveries SET next_at = now() + make_interval(mins => backoff[greatest(1, d.attempts)]), last_code = p_code WHERE id = d.id;
    END IF;
    UPDATE webhook_endpoints SET failures = failures + 1, last_error = left(coalesce(p_error, 'error'), 120), active = CASE WHEN failures + 1 >= 10 THEN false ELSE active END
    WHERE id = d.endpoint_id AND tenant_id = d.tenant_id;
  END IF;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

DO $$ DECLARE f text; BEGIN
  FOREACH f IN ARRAY ARRAY['webhook_create(char, char, char, text, text, text, integer)', 'webhook_delete(char, char, char)', 'webhook_set_active(char, char, char, boolean)', 'webhook_enqueue()', 'webhook_claim(integer)', 'webhook_result(char, boolean, integer, text)'] LOOP
    EXECUTE format('REVOKE ALL ON FUNCTION %s FROM PUBLIC, jarvis_app_rw, jarvis_admin', f);
  END LOOP;
  FOREACH f IN ARRAY ARRAY['webhook_create(char, char, char, text, text, text, integer)', 'webhook_delete(char, char, char)', 'webhook_set_active(char, char, char, boolean)'] LOOP
    EXECUTE format('GRANT EXECUTE ON FUNCTION %s TO jarvis_app_rw, jarvis_admin', f);
  END LOOP;
  FOREACH f IN ARRAY ARRAY['webhook_enqueue()', 'webhook_claim(integer)', 'webhook_result(char, boolean, integer, text)'] LOOP
    EXECUTE format('GRANT EXECUTE ON FUNCTION %s TO jarvis_admin', f);
  END LOOP;
END $$;
