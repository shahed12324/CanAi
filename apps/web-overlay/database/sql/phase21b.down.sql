DROP FUNCTION IF EXISTS wf_step_delay(char, char, text, text, integer, jsonb);
CREATE OR REPLACE FUNCTION wf_run_claim(p_limit integer) RETURNS TABLE (run_id char(26), tenant_id char(26), user_id char(26))
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
ALTER TABLE wf_runs DROP COLUMN not_before, DROP COLUMN claimed_at;
