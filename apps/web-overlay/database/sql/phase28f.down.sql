DROP FUNCTION IF EXISTS webhook_send_message(char, char, text, text);
DELETE FROM webhook_deliveries WHERE event = 'workflow.message';
UPDATE webhook_endpoints SET events = array_remove(events, 'workflow.message') WHERE 'workflow.message' = ANY (events);
UPDATE webhook_endpoints SET events = ARRAY['run.finished'] WHERE cardinality(events) = 0;
ALTER TABLE webhook_deliveries DROP CONSTRAINT IF EXISTS webhook_deliveries_event_check;
ALTER TABLE webhook_deliveries ADD CONSTRAINT webhook_deliveries_event_check CHECK (event IN ('run.finished', 'run.waiting_approval', 'pc.action.pending'));
ALTER TABLE webhook_endpoints DROP CONSTRAINT IF EXISTS webhook_endpoints_events_check;
ALTER TABLE webhook_endpoints ADD CONSTRAINT webhook_endpoints_events_check CHECK (cardinality(events) BETWEEN 1 AND 3 AND events <@ ARRAY['run.finished', 'run.waiting_approval', 'pc.action.pending']::text[]);
CREATE OR REPLACE FUNCTION webhook_set_events(p_id char(26), p_tenant char(26), p_user char(26), p_events text[]) RETURNS text
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
