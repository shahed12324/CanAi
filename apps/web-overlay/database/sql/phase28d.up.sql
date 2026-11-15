-- Phase 28d: more webhook events.  Besides run.finished an address can now also get run.waiting_approval (one of the owner's runs is waiting for a yes/no)
-- and pc.action.pending (a computer action the owner requested is waiting for approval).  Messages still carry only ids and status words - never params, paths, outputs or texts.
-- Every address keeps the old behaviour (run.finished only) until its owner picks more.  A newly picked event only fires for things that happen AFTER it was picked.

ALTER TABLE webhook_endpoints ADD COLUMN events text[] NOT NULL DEFAULT ARRAY['run.finished']::text[]
  CHECK (cardinality(events) BETWEEN 1 AND 3 AND events <@ ARRAY['run.finished', 'run.waiting_approval', 'pc.action.pending']::text[]);
ALTER TABLE webhook_endpoints ADD COLUMN events_at timestamptz NOT NULL DEFAULT now();
GRANT SELECT (events, events_at) ON webhook_endpoints TO jarvis_app_rw, jarvis_admin;

-- run_id now means "the id of the thing the event is about" (a run, or a computer action).
ALTER TABLE webhook_deliveries DROP CONSTRAINT webhook_deliveries_event_check;
ALTER TABLE webhook_deliveries ADD CONSTRAINT webhook_deliveries_event_check CHECK (event IN ('run.finished', 'run.waiting_approval', 'pc.action.pending'));
ALTER TABLE webhook_deliveries DROP CONSTRAINT webhook_deliveries_endpoint_id_run_id_key;
ALTER TABLE webhook_deliveries ADD CONSTRAINT webhook_deliveries_endpoint_event_subject_key UNIQUE (endpoint_id, event, run_id);

CREATE FUNCTION webhook_set_events(p_id char(26), p_tenant char(26), p_user char(26), p_events text[]) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); ev text[]; rc integer;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  IF p_events IS NULL OR cardinality(p_events) = 0 OR NOT (p_events <@ ARRAY['run.finished', 'run.waiting_approval', 'pc.action.pending']::text[]) THEN RETURN 'bad_input'; END IF;
  SELECT array_agg(DISTINCT x ORDER BY x) INTO ev FROM unnest(p_events) AS x;
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE webhook_endpoints SET events = ev, events_at = CASE WHEN events IS DISTINCT FROM ev THEN now() ELSE events_at END
  WHERE id = p_id AND tenant_id = p_tenant AND user_id = p_user;
  GET DIAGNOSTICS rc = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN rc = 0 THEN 'not_found' ELSE 'ok' END;
END $$;

-- Worker (admin only), now for all three events.  UNIQUE (address, event, subject) keeps it safe to run any number of times.
CREATE OR REPLACE FUNCTION webhook_enqueue() RETURNS integer
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); n integer := 0; k integer;
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  WITH ins AS (
    INSERT INTO webhook_deliveries (id, endpoint_id, tenant_id, run_id, event, payload)
    SELECT substr(md5(e.id || r.id) || md5(r.id || e.id), 1, 26), e.id, e.tenant_id, r.id, 'run.finished',
           jsonb_build_object('event', 'run.finished', 'run_id', trim(r.id), 'workflow_id', trim(r.workflow_id), 'status', r.status, 'spent', r.spent::text, 'finished_at', r.finished_at)
    FROM webhook_endpoints e
    JOIN wf_runs r ON r.tenant_id = e.tenant_id AND r.user_id = e.user_id
    WHERE e.active AND 'run.finished' = ANY (e.events) AND r.status IN ('done', 'failed', 'cancelled') AND r.finished_at IS NOT NULL AND r.finished_at > e.since_at AND r.finished_at > now() - interval '1 day'
    ON CONFLICT (endpoint_id, event, run_id) DO NOTHING RETURNING 1
  ) SELECT count(*) INTO k FROM ins;
  n := n + k;
  WITH ins AS (
    INSERT INTO webhook_deliveries (id, endpoint_id, tenant_id, run_id, event, payload)
    SELECT substr(md5('w' || e.id || r.id) || md5(r.id || 'w' || e.id), 1, 26), e.id, e.tenant_id, r.id, 'run.waiting_approval',
           jsonb_build_object('event', 'run.waiting_approval', 'run_id', trim(r.id), 'workflow_id', trim(r.workflow_id), 'status', r.status, 'node', r.current_node, 'created_at', r.created_at)
    FROM webhook_endpoints e
    JOIN wf_runs r ON r.tenant_id = e.tenant_id AND r.user_id = e.user_id
    WHERE e.active AND 'run.waiting_approval' = ANY (e.events) AND r.status = 'waiting_approval' AND r.created_at > e.events_at AND r.created_at > now() - interval '1 day'
    ON CONFLICT (endpoint_id, event, run_id) DO NOTHING RETURNING 1
  ) SELECT count(*) INTO k FROM ins;
  n := n + k;
  WITH ins AS (
    INSERT INTO webhook_deliveries (id, endpoint_id, tenant_id, run_id, event, payload)
    SELECT substr(md5('p' || e.id || a.id) || md5(a.id || 'p' || e.id), 1, 26), e.id, e.tenant_id, a.id, 'pc.action.pending',
           jsonb_build_object('event', 'pc.action.pending', 'action_id', trim(a.id), 'device_id', trim(a.device_id), 'kind', a.kind, 'risk', a.risk, 'expires_at', a.expires_at)
    FROM webhook_endpoints e
    JOIN pc_actions a ON a.tenant_id = e.tenant_id AND a.requested_by = e.user_id
    WHERE e.active AND 'pc.action.pending' = ANY (e.events) AND a.status = 'pending' AND a.expires_at > now() AND a.created_at > e.events_at AND a.created_at > now() - interval '1 day'
    ON CONFLICT (endpoint_id, event, run_id) DO NOTHING RETURNING 1
  ) SELECT count(*) INTO k FROM ins;
  n := n + k;
  DELETE FROM webhook_deliveries WHERE status IN ('sent', 'dead') AND created_at < now() - interval '14 days';
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN n;
END $$;

REVOKE ALL ON FUNCTION webhook_set_events(char, char, char, text[]) FROM PUBLIC, jarvis_app_rw, jarvis_admin;
GRANT EXECUTE ON FUNCTION webhook_set_events(char, char, char, text[]) TO jarvis_app_rw, jarvis_admin;
