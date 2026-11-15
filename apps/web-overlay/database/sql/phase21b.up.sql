-- Phase 21b: a "delay" step.  A run that reaches it goes back to 'queued' with a not_before time and continues from the next step when a worker finds it due.
-- `claimed_at` is when a worker last took the run (the 15-minute "stuck" check uses it, so a run that waited a day is not failed for having an old started_at).

ALTER TABLE wf_runs ADD COLUMN not_before timestamptz, ADD COLUMN claimed_at timestamptz;

CREATE OR REPLACE FUNCTION wf_run_claim(p_limit integer) RETURNS TABLE (run_id char(26), tenant_id char(26), user_id char(26))
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), '');
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE wf_runs SET status = 'failed', error = 'সময় পেরিয়ে গেছে (আটকে ছিল)', finished_at = now() WHERE status = 'running' AND coalesce(claimed_at, started_at) < now() - interval '15 minutes';
  RETURN QUERY
  WITH picked AS (SELECT r.id FROM wf_runs r WHERE r.status = 'queued' AND (r.not_before IS NULL OR r.not_before <= now()) ORDER BY coalesce(r.not_before, r.created_at), r.created_at LIMIT greatest(1, least(coalesce(p_limit, 10), 100)) FOR UPDATE SKIP LOCKED)
  UPDATE wf_runs r SET status = 'running', started_at = coalesce(r.started_at, now()), claimed_at = now(), not_before = NULL FROM picked WHERE r.id = picked.id RETURNING r.id, r.tenant_id, r.user_id;
  PERFORM set_config('app.bypass_rls', prev, true);
END $$;

-- The runner reaches a delay step: records it, saves the variables, points at the next step, and parks the run for p_minutes (1 to 1440).  result: ok | bad_input | not_running.
CREATE FUNCTION wf_step_delay(p_run char(26), p_tenant char(26), p_node text, p_next text, p_minutes integer, p_vars jsonb) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); r wf_runs%ROWTYPE;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_minutes IS NULL OR p_minutes < 1 OR p_minutes > 1440 OR coalesce(p_next, '') = '' THEN RETURN 'bad_input'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO r FROM wf_runs WHERE id = p_run AND tenant_id = p_tenant FOR UPDATE;
  IF r.id IS NULL OR r.status <> 'running' THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'not_running'; END IF;
  INSERT INTO wf_steps (run_id, tenant_id, seq, node_id, node_type, status, output, credits)
  VALUES (p_run, p_tenant, coalesce((SELECT max(seq) FROM wf_steps WHERE run_id = p_run), 0) + 1, left(coalesce(p_node, ''), 40), 'delay', 'ok', p_minutes || ' মিনিট অপেক্ষা', 0);
  UPDATE wf_runs SET vars = coalesce(p_vars, vars), current_node = p_next, status = 'queued', not_before = now() + make_interval(mins => p_minutes), claimed_at = NULL WHERE id = p_run;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

REVOKE ALL ON FUNCTION wf_step_delay(char, char, text, text, integer, jsonb) FROM PUBLIC, jarvis_app_rw, jarvis_admin;
GRANT EXECUTE ON FUNCTION wf_step_delay(char, char, text, text, integer, jsonb) TO jarvis_app_rw, jarvis_admin;
