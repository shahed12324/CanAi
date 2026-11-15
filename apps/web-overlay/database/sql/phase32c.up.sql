-- Phase 32c (Agents & Workflows): managing workflows.  Everything here is ADDITIVE and backward compatible:
--   * wf_workflows.id never changes, so scheduled jobs (wf_due works from the id + schedule_time) and run history keep pointing at the same workflow after a rename;
--   * template_key remembers which ready-made template a workflow was copied from (set once, never changed, never shown as a name);
--   * paused = "the daily schedule is suspended, but it can still be run by hand" (enabled = false stops everything);
--   * model_id = the model this workflow's AI steps prefer (NULL = automatic);
--   * new categories (productivity, communication, knowledge, desktop, development) are allowed next to the old ones; old rows are NOT rewritten.
-- Test runs (trigger 'test') run the real steps that only look and SKIP everything that writes, sends, waits for a person or touches the computer; they are logged step by step.

ALTER TABLE wf_workflows
  ADD COLUMN template_key text CHECK (template_key IS NULL OR template_key ~ '^[a-z0-9_]{1,40}$'),
  ADD COLUMN paused       boolean NOT NULL DEFAULT false,
  ADD COLUMN model_id     char(26) REFERENCES ai_models (id) ON DELETE SET NULL;

ALTER TABLE wf_workflows DROP CONSTRAINT wf_workflows_category_check;
ALTER TABLE wf_workflows ADD CONSTRAINT wf_workflows_category_check CHECK (category IN
  ('personal', 'sales', 'marketing', 'ecommerce', 'finance', 'developer', 'productivity', 'communication', 'knowledge', 'desktop', 'development'));

ALTER TABLE wf_runs DROP CONSTRAINT wf_runs_trigger_check;
ALTER TABLE wf_runs ADD CONSTRAINT wf_runs_trigger_check CHECK (trigger IN ('manual', 'schedule', 'test'));

DROP INDEX IF EXISTS wf_workflows_scheduled;
CREATE INDEX wf_workflows_scheduled ON wf_workflows (schedule_time) WHERE enabled AND NOT paused AND schedule_time IS NOT NULL;

-- wf_save with the wider category list (same signature, same behaviour otherwise).
CREATE OR REPLACE FUNCTION wf_save(p_id char(26), p_tenant char(26), p_user char(26), p_name text, p_desc text, p_category text, p_def jsonb, p_enabled boolean, p_schedule time, p_max numeric, p_new boolean) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); nm text := regexp_replace(btrim(coalesce(p_name, '')), '[[:space:]]+', ' ', 'g'); n integer; rc integer;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  IF p_id IS NULL OR nm = '' OR char_length(nm) > 120 OR char_length(coalesce(p_desc, '')) > 500
     OR p_category IS NULL OR p_category NOT IN ('personal', 'sales', 'marketing', 'ecommerce', 'finance', 'developer', 'productivity', 'communication', 'knowledge', 'desktop', 'development')
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

-- Remember the template a workflow came from.  Only ever sets it once (a rename or edit can never change it).  result: ok | not_found | bad_input
CREATE FUNCTION wf_mark_template(p_id char(26), p_tenant char(26), p_user char(26), p_key text) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); rc integer;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_key IS NULL OR p_key !~ '^[a-z0-9_]{1,40}$' THEN RETURN 'bad_input'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE wf_workflows SET template_key = p_key WHERE id = p_id AND tenant_id = p_tenant AND user_id = p_user AND template_key IS NULL;
  GET DIAGNOSTICS rc = ROW_COUNT;
  IF rc = 0 AND NOT EXISTS (SELECT 1 FROM wf_workflows WHERE id = p_id AND tenant_id = p_tenant AND user_id = p_user) THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'not_found'; END IF;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

-- Rename (name + description only).  Does NOT touch the steps, version, template_key, schedule, last_scheduled_on or run history.  result: ok | bad_input | not_found
CREATE FUNCTION wf_rename(p_id char(26), p_tenant char(26), p_user char(26), p_name text, p_desc text) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); nm text := regexp_replace(btrim(coalesce(p_name, '')), '[[:space:]]+', ' ', 'g'); rc integer;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF nm = '' OR char_length(nm) > 120 OR char_length(coalesce(p_desc, '')) > 500 THEN RETURN 'bad_input'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE wf_workflows SET name = nm, description = btrim(coalesce(p_desc, '')), updated_at = now() WHERE id = p_id AND tenant_id = p_tenant AND user_id = p_user;
  GET DIAGNOSTICS rc = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN rc = 0 THEN 'not_found' ELSE 'ok' END;
END $$;

-- Settings that are not steps: category, daily time, credit limit, preferred model.  Changing the daily time resets "already ran today" exactly like wf_save does.
-- result: ok | bad_input | not_found
CREATE FUNCTION wf_set_meta(p_id char(26), p_tenant char(26), p_user char(26), p_category text, p_schedule time, p_max numeric, p_model char(26)) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); rc integer;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_category IS NULL OR p_category NOT IN ('personal', 'sales', 'marketing', 'ecommerce', 'finance', 'developer', 'productivity', 'communication', 'knowledge', 'desktop', 'development')
     OR p_max IS NULL OR p_max < 0 OR p_max > 1000 THEN RETURN 'bad_input'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  IF p_model IS NOT NULL AND NOT EXISTS (SELECT 1 FROM ai_models WHERE id = p_model) THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'bad_input'; END IF;
  UPDATE wf_workflows SET category = p_category, max_credits = p_max, model_id = p_model, schedule_time = p_schedule,
    last_scheduled_on = CASE WHEN schedule_time IS DISTINCT FROM p_schedule THEN NULL ELSE last_scheduled_on END, updated_at = now()
  WHERE id = p_id AND tenant_id = p_tenant AND user_id = p_user;
  GET DIAGNOSTICS rc = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN rc = 0 THEN 'not_found' ELSE 'ok' END;
END $$;

-- Replace the steps only (version +1 when they really changed).  The app validated the definition; the runner re-checks every step.  result: ok | bad_input | not_found
CREATE FUNCTION wf_set_definition(p_id char(26), p_tenant char(26), p_user char(26), p_def jsonb) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); rc integer;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_def IS NULL OR jsonb_typeof(p_def) IS DISTINCT FROM 'object' OR length(p_def::text) > 100000 THEN RETURN 'bad_input'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE wf_workflows SET version = CASE WHEN definition IS DISTINCT FROM p_def THEN version + 1 ELSE version END, definition = p_def, updated_at = now()
  WHERE id = p_id AND tenant_id = p_tenant AND user_id = p_user;
  GET DIAGNOSTICS rc = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN rc = 0 THEN 'not_found' ELSE 'ok' END;
END $$;

-- Enable/disable and pause/resume.  NULL = leave as is.  When a workflow becomes runnable again after today's schedule time already passed, today is marked as done so it does not suddenly run
-- by itself (it runs at the next scheduled time).  result: ok | not_found
CREATE FUNCTION wf_set_state(p_id char(26), p_tenant char(26), p_user char(26), p_enabled boolean, p_paused boolean) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); w wf_workflows%ROWTYPE; en boolean; pa boolean; was boolean;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO w FROM wf_workflows WHERE id = p_id AND tenant_id = p_tenant AND user_id = p_user FOR UPDATE;
  IF w.id IS NULL THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'not_found'; END IF;
  en := coalesce(p_enabled, w.enabled); pa := coalesce(p_paused, w.paused); was := w.enabled AND NOT w.paused;
  UPDATE wf_workflows SET enabled = en, paused = pa,
    last_scheduled_on = CASE WHEN en AND NOT pa AND NOT was AND schedule_time IS NOT NULL AND (now() AT TIME ZONE schedule_zone)::time >= schedule_time THEN (now() AT TIME ZONE schedule_zone)::date ELSE last_scheduled_on END,
    updated_at = now()
  WHERE id = w.id;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

-- Copy a workflow: same steps / settings, but OFF, no schedule, version 1, no history.  result: ok | not_found | limit | bad_input
CREATE FUNCTION wf_duplicate(p_src char(26), p_new char(26), p_tenant char(26), p_user char(26), p_name text) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); nm text := regexp_replace(btrim(coalesce(p_name, '')), '[[:space:]]+', ' ', 'g'); w wf_workflows%ROWTYPE; n integer;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_new IS NULL OR nm = '' OR char_length(nm) > 120 THEN RETURN 'bad_input'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO w FROM wf_workflows WHERE id = p_src AND tenant_id = p_tenant AND user_id = p_user;
  IF w.id IS NULL THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'not_found'; END IF;
  SELECT count(*) INTO n FROM wf_workflows WHERE tenant_id = p_tenant AND user_id = p_user;
  IF n >= 50 THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'limit'; END IF;
  INSERT INTO wf_workflows (id, tenant_id, user_id, name, description, category, definition, enabled, schedule_time, max_credits, template_key, model_id)
  VALUES (p_new, p_tenant, p_user, nm, w.description, w.category, w.definition, false, NULL, w.max_credits, w.template_key, w.model_id);
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

-- Queue a TEST run (works for a switched-off or paused workflow too).  The worker runs it like any run, but the runner skips every step that writes, sends, waits or uses the computer.
-- result: ok | not_found | busy | bad_input
CREATE FUNCTION wf_test_run_create(p_run char(26), p_tenant char(26), p_user char(26), p_workflow char(26), p_vars jsonb) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); w wf_workflows%ROWTYPE; n integer;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_run IS NULL OR jsonb_typeof(coalesce(p_vars, '{}'::jsonb)) IS DISTINCT FROM 'object' OR length(coalesce(p_vars, '{}'::jsonb)::text) > 50000 THEN RETURN 'bad_input'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO w FROM wf_workflows WHERE id = p_workflow AND tenant_id = p_tenant AND user_id = p_user FOR UPDATE;
  IF w.id IS NULL THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'not_found'; END IF;
  SELECT count(*) INTO n FROM wf_runs WHERE workflow_id = w.id AND status IN ('queued', 'running', 'waiting_approval');
  IF n > 0 THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'busy'; END IF;
  INSERT INTO wf_runs (id, tenant_id, workflow_id, user_id, version, definition, trigger, vars, current_node, max_credits)
  VALUES (p_run, p_tenant, w.id, w.user_id, w.version, w.definition, 'test', coalesce(p_vars, '{}'::jsonb), w.definition ->> 'start', w.max_credits);
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

-- The daily schedule skips paused workflows (everything else is exactly as in 21a).
CREATE OR REPLACE FUNCTION wf_due() RETURNS integer
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); n integer;
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  WITH due AS (
    SELECT w.id, (now() AT TIME ZONE w.schedule_zone)::date AS today FROM wf_workflows w
    WHERE w.enabled AND NOT w.paused AND w.schedule_time IS NOT NULL AND (now() AT TIME ZONE w.schedule_zone)::time >= w.schedule_time
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

DO $$ DECLARE f text; BEGIN
  FOREACH f IN ARRAY ARRAY['wf_mark_template(char, char, char, text)', 'wf_rename(char, char, char, text, text)', 'wf_set_meta(char, char, char, text, time, numeric, char)',
    'wf_set_definition(char, char, char, jsonb)', 'wf_set_state(char, char, char, boolean, boolean)', 'wf_duplicate(char, char, char, char, text)', 'wf_test_run_create(char, char, char, char, jsonb)'] LOOP
    EXECUTE format('REVOKE ALL ON FUNCTION %s FROM PUBLIC, jarvis_app_rw, jarvis_admin', f);
    EXECUTE format('GRANT EXECUTE ON FUNCTION %s TO jarvis_app_rw, jarvis_admin', f);
  END LOOP;
  EXECUTE 'REVOKE ALL ON FUNCTION wf_due() FROM PUBLIC, jarvis_app_rw, jarvis_admin';
  EXECUTE 'GRANT EXECUTE ON FUNCTION wf_due() TO jarvis_admin';
END $$;
