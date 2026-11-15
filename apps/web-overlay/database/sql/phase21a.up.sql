-- Phase 21a: the agent engine's foundation - workflows (steps written as data), runs, and a step-by-step log.
-- A workflow belongs to the person who made it and runs AS that person.  A run is queued, picked up by a worker, and may stop at an approval step until its owner decides.
-- Spending is capped per run (max_credits) and the log records every step.  Nothing here can send anything outside: the tools that could (email, posts, payments) are not part of 21a.

CREATE TABLE wf_workflows (
  id               char(26)      PRIMARY KEY,
  tenant_id        char(26)      NOT NULL REFERENCES tenants (id),
  user_id          char(26)      NOT NULL REFERENCES users (id),
  name             varchar(120)  NOT NULL CHECK (btrim(name) <> ''),
  description      varchar(500)  NOT NULL DEFAULT '',
  category         text          NOT NULL DEFAULT 'personal' CHECK (category IN ('personal', 'sales', 'marketing', 'ecommerce', 'finance', 'developer')),
  definition       jsonb         NOT NULL CHECK (jsonb_typeof(definition) = 'object' AND length(definition::text) <= 100000),
  version          integer       NOT NULL DEFAULT 1 CHECK (version >= 1),
  enabled          boolean       NOT NULL DEFAULT true,
  schedule_time    time,                                                     -- every day at this local time (NULL = manual only)
  schedule_zone    text          NOT NULL DEFAULT 'Asia/Dhaka',
  last_scheduled_on date,
  max_credits      numeric(12,4) NOT NULL DEFAULT 5 CHECK (max_credits >= 0 AND max_credits <= 1000),
  created_at       timestamptz   NOT NULL DEFAULT now(),
  updated_at       timestamptz   NOT NULL DEFAULT now(),
  UNIQUE (id, tenant_id)
);
CREATE INDEX wf_workflows_tenant ON wf_workflows (tenant_id, user_id);
CREATE INDEX wf_workflows_scheduled ON wf_workflows (schedule_time) WHERE enabled AND schedule_time IS NOT NULL;

CREATE TABLE wf_runs (
  id           char(26)      PRIMARY KEY,
  tenant_id    char(26)      NOT NULL REFERENCES tenants (id),
  workflow_id  char(26)      NOT NULL,
  user_id      char(26)      NOT NULL REFERENCES users (id),
  version      integer       NOT NULL,
  definition   jsonb         NOT NULL,                                       -- the exact steps this run follows (later edits never change a run in progress)
  trigger      text          NOT NULL CHECK (trigger IN ('manual', 'schedule')),
  status       text          NOT NULL DEFAULT 'queued' CHECK (status IN ('queued', 'running', 'waiting_approval', 'done', 'failed', 'cancelled')),
  vars         jsonb         NOT NULL DEFAULT '{}' CHECK (jsonb_typeof(vars) = 'object' AND length(vars::text) <= 200000),
  current_node text,
  spent        numeric(14,4) NOT NULL DEFAULT 0 CHECK (spent >= 0),
  max_credits  numeric(12,4) NOT NULL,
  error        varchar(300),
  created_at   timestamptz   NOT NULL DEFAULT now(),
  started_at   timestamptz,
  finished_at  timestamptz,
  FOREIGN KEY (workflow_id, tenant_id) REFERENCES wf_workflows (id, tenant_id) ON DELETE CASCADE,
  UNIQUE (id, tenant_id)
);
CREATE INDEX wf_runs_workflow ON wf_runs (tenant_id, workflow_id, created_at DESC);
CREATE INDEX wf_runs_queue ON wf_runs (created_at) WHERE status = 'queued';
CREATE INDEX wf_runs_active ON wf_runs (workflow_id) WHERE status IN ('queued', 'running', 'waiting_approval');

CREATE TABLE wf_steps (
  run_id     char(26)      NOT NULL,
  tenant_id  char(26)      NOT NULL,
  seq        integer       NOT NULL CHECK (seq >= 1),
  node_id    varchar(40)   NOT NULL,
  node_type  varchar(20)   NOT NULL,
  status     text          NOT NULL CHECK (status IN ('ok', 'failed', 'waiting', 'skipped')),
  output     varchar(4000) NOT NULL DEFAULT '',
  credits    numeric(14,4) NOT NULL DEFAULT 0 CHECK (credits >= 0),
  created_at timestamptz   NOT NULL DEFAULT now(),
  PRIMARY KEY (run_id, seq),
  FOREIGN KEY (run_id, tenant_id) REFERENCES wf_runs (id, tenant_id) ON DELETE CASCADE
);

DO $$ DECLARE t text; BEGIN
  FOREACH t IN ARRAY ARRAY['wf_workflows', 'wf_runs', 'wf_steps'] LOOP
    EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', t);
    EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY', t);
    EXECUTE format('CREATE POLICY tenant_isolation ON %I USING (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin())) WITH CHECK (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()))', t);
    EXECUTE format('REVOKE ALL ON %I FROM jarvis_app_rw, jarvis_admin', t);
    EXECUTE format('GRANT SELECT ON %I TO jarvis_app_rw, jarvis_admin', t);
  END LOOP;
END $$;

-- result: ok | bad_input | limit | not_found.  p_new = true creates, false edits (only the owner).  The definition's shape is checked by the app before it gets here;
-- the runner re-checks every step while running, so a bad definition can only fail a run, never do harm.  Editing the steps bumps the version.
CREATE FUNCTION wf_save(p_id char(26), p_tenant char(26), p_user char(26), p_name text, p_desc text, p_category text, p_def jsonb, p_enabled boolean, p_schedule time, p_max numeric, p_new boolean) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); nm text := regexp_replace(btrim(coalesce(p_name, '')), '[[:space:]]+', ' ', 'g'); n integer; rc integer;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  IF p_id IS NULL OR nm = '' OR char_length(nm) > 120 OR char_length(coalesce(p_desc, '')) > 500
     OR p_category IS NULL OR p_category NOT IN ('personal', 'sales', 'marketing', 'ecommerce', 'finance', 'developer')
     OR p_def IS NULL OR jsonb_typeof(p_def) IS DISTINCT FROM 'object' OR length(p_def::text) > 100000
     OR coalesce(p_max, 5) < 0 OR coalesce(p_max, 5) > 1000 THEN RETURN 'bad_input'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  IF coalesce(p_new, true) THEN
    SELECT count(*) INTO n FROM wf_workflows WHERE tenant_id = p_tenant AND user_id = p_user;
    IF n >= 50 THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'limit'; END IF;
    INSERT INTO wf_workflows (id, tenant_id, user_id, name, description, category, definition, enabled, schedule_time, max_credits)
    VALUES (p_id, p_tenant, p_user, nm, btrim(coalesce(p_desc, '')), p_category, p_def, coalesce(p_enabled, true), p_schedule, coalesce(p_max, 5)) ON CONFLICT DO NOTHING;
    rc := 1;
  ELSE
    UPDATE wf_workflows SET name = nm, description = btrim(coalesce(p_desc, '')), category = p_category,
      version = CASE WHEN definition IS DISTINCT FROM p_def THEN version + 1 ELSE version END, definition = p_def,
      enabled = coalesce(p_enabled, enabled), schedule_time = p_schedule,
      last_scheduled_on = CASE WHEN schedule_time IS DISTINCT FROM p_schedule THEN NULL ELSE last_scheduled_on END,
      max_credits = coalesce(p_max, max_credits), updated_at = now()
    WHERE id = p_id AND tenant_id = p_tenant AND user_id = p_user;
    GET DIAGNOSTICS rc = ROW_COUNT;
  END IF;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN rc = 0 THEN 'not_found' ELSE 'ok' END;
END $$;

CREATE FUNCTION wf_delete(p_id char(26), p_tenant char(26), p_user char(26)) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); rc integer;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  DELETE FROM wf_workflows WHERE id = p_id AND tenant_id = p_tenant AND user_id = p_user;
  GET DIAGNOSTICS rc = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN rc = 0 THEN 'not_found' ELSE 'ok' END;
END $$;

-- Queue a run of the person's own workflow.  result: ok | not_found | disabled | busy | bad_input.
-- 'busy' = a run of this workflow is already queued / running / waiting for approval (no pile-ups).  p_vars = what the person typed into the run form.
CREATE FUNCTION wf_run_create(p_run char(26), p_tenant char(26), p_user char(26), p_workflow char(26), p_vars jsonb) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); w wf_workflows%ROWTYPE; n integer;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  IF p_run IS NULL OR jsonb_typeof(coalesce(p_vars, '{}'::jsonb)) IS DISTINCT FROM 'object' OR length(coalesce(p_vars, '{}'::jsonb)::text) > 50000 THEN RETURN 'bad_input'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO w FROM wf_workflows WHERE id = p_workflow AND tenant_id = p_tenant AND user_id = p_user FOR UPDATE;
  IF w.id IS NULL THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'not_found'; END IF;
  IF NOT w.enabled THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'disabled'; END IF;
  SELECT count(*) INTO n FROM wf_runs WHERE workflow_id = w.id AND status IN ('queued', 'running', 'waiting_approval');
  IF n > 0 THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'busy'; END IF;
  INSERT INTO wf_runs (id, tenant_id, workflow_id, user_id, version, definition, trigger, vars, current_node, max_credits)
  VALUES (p_run, p_tenant, w.id, w.user_id, w.version, w.definition, 'manual', coalesce(p_vars, '{}'::jsonb), w.definition ->> 'start', w.max_credits);
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

-- The owner approves or rejects a run waiting at an approval step.  result: ok | not_found | not_waiting.
-- Approving queues the run again (the worker carries on from the approval step); rejecting cancels it.
CREATE FUNCTION wf_run_decide(p_run char(26), p_tenant char(26), p_user char(26), p_approve boolean) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); r wf_runs%ROWTYPE;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO r FROM wf_runs WHERE id = p_run AND tenant_id = p_tenant AND user_id = p_user FOR UPDATE;
  IF r.id IS NULL THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'not_found'; END IF;
  IF r.status <> 'waiting_approval' THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'not_waiting'; END IF;
  IF coalesce(p_approve, false) THEN
    UPDATE wf_runs SET status = 'queued', vars = vars || jsonb_build_object('__approved:' || current_node, true) WHERE id = p_run;
  ELSE
    UPDATE wf_runs SET status = 'cancelled', error = 'অনুমোদন দেওয়া হয়নি', finished_at = now() WHERE id = p_run;
  END IF;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

-- The owner stops a run that has not finished.  result: ok | not_found | finished.
CREATE FUNCTION wf_run_cancel(p_run char(26), p_tenant char(26), p_user char(26)) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); r wf_runs%ROWTYPE;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO r FROM wf_runs WHERE id = p_run AND tenant_id = p_tenant AND user_id = p_user FOR UPDATE;
  IF r.id IS NULL THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'not_found'; END IF;
  IF r.status NOT IN ('queued', 'running', 'waiting_approval') THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'finished'; END IF;
  UPDATE wf_runs SET status = 'cancelled', error = 'বন্ধ করা হয়েছে', finished_at = now() WHERE id = p_run;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

-- ---- worker functions (admin only) ----

-- Takes up to p_limit queued runs for a worker (each run goes to exactly one worker) and marks them running.  Runs stuck 'running' for 15 minutes are failed.
CREATE FUNCTION wf_run_claim(p_limit integer) RETURNS TABLE (run_id char(26), tenant_id char(26), user_id char(26))
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), '');
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE wf_runs SET status = 'failed', error = 'সময় পেরিয়ে গেছে (আটকে ছিল)', finished_at = now() WHERE status = 'running' AND started_at < now() - interval '15 minutes';
  RETURN QUERY
  WITH picked AS (SELECT r.id FROM wf_runs r WHERE r.status = 'queued' ORDER BY r.created_at LIMIT greatest(1, least(coalesce(p_limit, 10), 100)) FOR UPDATE SKIP LOCKED)
  UPDATE wf_runs r SET status = 'running', started_at = coalesce(r.started_at, now()) FROM picked WHERE r.id = picked.id RETURNING r.id, r.tenant_id, r.user_id;
  PERFORM set_config('app.bypass_rls', prev, true);
END $$;

-- Daily schedules: every enabled workflow whose local time has come today and that has no active run gets one queued run.  Returns how many.
CREATE FUNCTION wf_due() RETURNS integer
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); n integer;
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  WITH due AS (
    SELECT w.id, (now() AT TIME ZONE w.schedule_zone)::date AS today FROM wf_workflows w
    WHERE w.enabled AND w.schedule_time IS NOT NULL AND (now() AT TIME ZONE w.schedule_zone)::time >= w.schedule_time
      AND w.last_scheduled_on IS DISTINCT FROM (now() AT TIME ZONE w.schedule_zone)::date
    FOR UPDATE SKIP LOCKED
  ), marked AS (
    UPDATE wf_workflows w SET last_scheduled_on = due.today FROM due WHERE w.id = due.id RETURNING w.*
  ), ins AS (
    INSERT INTO wf_runs (id, tenant_id, workflow_id, user_id, version, definition, trigger, vars, current_node, max_credits)
    SELECT substr(md5(m.id || clock_timestamp()::text || random()::text), 1, 26), m.tenant_id, m.id, m.user_id, m.version, m.definition, 'schedule', '{}'::jsonb, m.definition ->> 'start', m.max_credits
    FROM marked m WHERE NOT EXISTS (SELECT 1 FROM wf_runs r WHERE r.workflow_id = m.id AND r.status IN ('queued', 'running', 'waiting_approval'))
    RETURNING 1
  ) SELECT count(*) INTO n FROM ins;
  DELETE FROM wf_runs WHERE finished_at < now() - interval '30 days';
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN n;
END $$;

-- The runner records ONE step and moves the run on, atomically: the step row, the new variables, the next node and the credits spent so far.
-- Only a 'running' run accepts it (a cancelled run stops here).  result: ok | not_running.
CREATE FUNCTION wf_step_record(p_run char(26), p_tenant char(26), p_node text, p_type text, p_status text, p_output text, p_credits numeric, p_vars jsonb, p_next text) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); r wf_runs%ROWTYPE;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_status IS NULL OR p_status NOT IN ('ok', 'failed', 'waiting', 'skipped') OR coalesce(p_credits, 0) < 0 THEN RAISE EXCEPTION 'bad step' USING ERRCODE = '22023'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO r FROM wf_runs WHERE id = p_run AND tenant_id = p_tenant FOR UPDATE;
  IF r.id IS NULL OR r.status <> 'running' THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'not_running'; END IF;
  INSERT INTO wf_steps (run_id, tenant_id, seq, node_id, node_type, status, output, credits)
  VALUES (p_run, p_tenant, coalesce((SELECT max(seq) FROM wf_steps WHERE run_id = p_run), 0) + 1, left(coalesce(p_node, ''), 40), left(coalesce(p_type, ''), 20), p_status, left(coalesce(p_output, ''), 4000), coalesce(p_credits, 0));
  UPDATE wf_runs SET vars = coalesce(p_vars, vars), current_node = p_next, spent = spent + coalesce(p_credits, 0) WHERE id = p_run;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

-- Ends or pauses a running run.  p_status: done | failed | waiting_approval.  result: ok | not_running.
CREATE FUNCTION wf_run_end(p_run char(26), p_tenant char(26), p_status text, p_error text) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); rc integer;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_status IS NULL OR p_status NOT IN ('done', 'failed', 'waiting_approval') THEN RAISE EXCEPTION 'bad status' USING ERRCODE = '22023'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE wf_runs SET status = p_status, error = left(p_error, 300), finished_at = CASE WHEN p_status = 'waiting_approval' THEN NULL ELSE now() END
  WHERE id = p_run AND tenant_id = p_tenant AND status = 'running';
  GET DIAGNOSTICS rc = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN rc = 0 THEN 'not_running' ELSE 'ok' END;
END $$;

DO $$ DECLARE f text; BEGIN
  FOREACH f IN ARRAY ARRAY['wf_save(char, char, char, text, text, text, jsonb, boolean, time, numeric, boolean)', 'wf_delete(char, char, char)', 'wf_run_create(char, char, char, char, jsonb)',
    'wf_run_decide(char, char, char, boolean)', 'wf_run_cancel(char, char, char)', 'wf_step_record(char, char, text, text, text, text, numeric, jsonb, text)', 'wf_run_end(char, char, text, text)'] LOOP
    EXECUTE format('REVOKE ALL ON FUNCTION %s FROM PUBLIC, jarvis_app_rw, jarvis_admin', f);
    EXECUTE format('GRANT EXECUTE ON FUNCTION %s TO jarvis_app_rw, jarvis_admin', f);
  END LOOP;
  FOREACH f IN ARRAY ARRAY['wf_run_claim(integer)', 'wf_due()'] LOOP
    EXECUTE format('REVOKE ALL ON FUNCTION %s FROM PUBLIC, jarvis_app_rw, jarvis_admin', f);
    EXECUTE format('GRANT EXECUTE ON FUNCTION %s TO jarvis_admin', f);
  END LOOP;
END $$;
