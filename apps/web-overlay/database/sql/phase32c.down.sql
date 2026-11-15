-- Rolls back 32c.  Refuses when a workflow uses one of the new categories (that data would be lost); test runs are derived logs and are deleted; paused / model / template_key are dropped.
DO $$ BEGIN
  IF EXISTS (SELECT 1 FROM wf_workflows WHERE category IN ('productivity', 'communication', 'knowledge', 'desktop', 'development')) THEN
    RAISE EXCEPTION 'workflows use the new categories; cannot roll back' USING ERRCODE = '22023';
  END IF;
END $$;
DELETE FROM wf_runs WHERE trigger = 'test';
CREATE OR REPLACE FUNCTION wf_due() RETURNS integer
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
CREATE OR REPLACE FUNCTION wf_save(p_id char(26), p_tenant char(26), p_user char(26), p_name text, p_desc text, p_category text, p_def jsonb, p_enabled boolean, p_schedule time, p_max numeric, p_new boolean) RETURNS text
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
DROP FUNCTION IF EXISTS wf_test_run_create(char, char, char, char, jsonb);
DROP FUNCTION IF EXISTS wf_duplicate(char, char, char, char, text);
DROP FUNCTION IF EXISTS wf_set_state(char, char, char, boolean, boolean);
DROP FUNCTION IF EXISTS wf_set_definition(char, char, char, jsonb);
DROP FUNCTION IF EXISTS wf_set_meta(char, char, char, text, time, numeric, char);
DROP FUNCTION IF EXISTS wf_rename(char, char, char, text, text);
DROP FUNCTION IF EXISTS wf_mark_template(char, char, char, text);
ALTER TABLE wf_runs DROP CONSTRAINT wf_runs_trigger_check;
ALTER TABLE wf_runs ADD CONSTRAINT wf_runs_trigger_check CHECK (trigger IN ('manual', 'schedule'));
ALTER TABLE wf_workflows DROP CONSTRAINT wf_workflows_category_check;
ALTER TABLE wf_workflows ADD CONSTRAINT wf_workflows_category_check CHECK (category IN ('personal', 'sales', 'marketing', 'ecommerce', 'finance', 'developer'));
DROP INDEX IF EXISTS wf_workflows_scheduled;
ALTER TABLE wf_workflows DROP COLUMN IF EXISTS model_id, DROP COLUMN IF EXISTS paused, DROP COLUMN IF EXISTS template_key;
CREATE INDEX wf_workflows_scheduled ON wf_workflows (schedule_time) WHERE enabled AND schedule_time IS NOT NULL;
