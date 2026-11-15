-- Back to run.finished only (messages of the other events are dropped).
DROP FUNCTION IF EXISTS webhook_set_events(char, char, char, text[]);
DELETE FROM webhook_deliveries WHERE event <> 'run.finished';
ALTER TABLE webhook_deliveries DROP CONSTRAINT IF EXISTS webhook_deliveries_endpoint_event_subject_key;
ALTER TABLE webhook_deliveries ADD CONSTRAINT webhook_deliveries_endpoint_id_run_id_key UNIQUE (endpoint_id, run_id);
ALTER TABLE webhook_deliveries DROP CONSTRAINT IF EXISTS webhook_deliveries_event_check;
ALTER TABLE webhook_deliveries ADD CONSTRAINT webhook_deliveries_event_check CHECK (event IN ('run.finished'));
-- Worker (admin only): one message per (address, finished run) - the UNIQUE key makes this safe to run any number of times.  Only the owner's own runs, only since the address was added / switched on.
CREATE OR REPLACE FUNCTION webhook_enqueue() RETURNS integer
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
ALTER TABLE webhook_endpoints DROP COLUMN IF EXISTS events_at;
ALTER TABLE webhook_endpoints DROP COLUMN IF EXISTS events;
