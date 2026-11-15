-- Phase 28f: a workflow can send a message to one of the person's own webhook addresses (tool webhook.send, an "external" tool: an approval step must come before it).
-- A new event, workflow.message, carries the text the workflow wrote (at most 1000 characters) - unlike the other events, which carry only ids.  An address gets it only when its owner ticked that event.
-- At most 20 such messages per address per hour; the message travels the normal queue (signed, retried, SSRF-checked) and is cleaned up with the others.

ALTER TABLE webhook_endpoints DROP CONSTRAINT webhook_endpoints_events_check;
ALTER TABLE webhook_endpoints ADD CONSTRAINT webhook_endpoints_events_check CHECK (cardinality(events) BETWEEN 1 AND 4
  AND events <@ ARRAY['run.finished', 'run.waiting_approval', 'pc.action.pending', 'workflow.message']::text[]);
ALTER TABLE webhook_deliveries DROP CONSTRAINT webhook_deliveries_event_check;
ALTER TABLE webhook_deliveries ADD CONSTRAINT webhook_deliveries_event_check CHECK (event IN ('run.finished', 'run.waiting_approval', 'pc.action.pending', 'workflow.message'));

CREATE OR REPLACE FUNCTION webhook_set_events(p_id char(26), p_tenant char(26), p_user char(26), p_events text[]) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); ev text[]; rc integer;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  IF p_events IS NULL OR cardinality(p_events) = 0 OR NOT (p_events <@ ARRAY['run.finished', 'run.waiting_approval', 'pc.action.pending', 'workflow.message']::text[]) THEN RETURN 'bad_input'; END IF;
  SELECT array_agg(DISTINCT x ORDER BY x) INTO ev FROM unnest(p_events) AS x;
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE webhook_endpoints SET events = ev, events_at = CASE WHEN events IS DISTINCT FROM ev THEN now() ELSE events_at END
  WHERE id = p_id AND tenant_id = p_tenant AND user_id = p_user;
  GET DIAGNOSTICS rc = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN rc = 0 THEN 'not_found' ELSE 'ok' END;
END $$;

-- ok | not_found (no such address of this person, switched off, or it did not tick workflow.message) | limit | bad_input
CREATE FUNCTION webhook_send_message(p_tenant char(26), p_user char(26), p_name text, p_text text) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); e webhook_endpoints%ROWTYPE; txt text := btrim(coalesce(p_text, '')); n integer; sid char(26);
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  IF txt = '' OR char_length(txt) > 1000 OR p_name IS NULL OR btrim(p_name) = '' THEN RETURN 'bad_input'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO e FROM webhook_endpoints WHERE tenant_id = p_tenant AND user_id = p_user AND name = btrim(p_name) AND active AND 'workflow.message' = ANY (events) ORDER BY created_at LIMIT 1;
  IF NOT FOUND THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'not_found'; END IF;
  SELECT count(*) INTO n FROM webhook_deliveries WHERE endpoint_id = e.id AND event = 'workflow.message' AND created_at > now() - interval '1 hour';
  IF n >= 20 THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'limit'; END IF;
  sid := substr(md5(random()::text || clock_timestamp()::text || e.id), 1, 26);
  INSERT INTO webhook_deliveries (id, endpoint_id, tenant_id, run_id, event, payload)
  VALUES (substr(md5(sid || e.id) || md5(e.id || sid), 1, 26), e.id, e.tenant_id, sid, 'workflow.message', jsonb_build_object('event', 'workflow.message', 'message_id', sid, 'text', txt, 'sent_at', now()));
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

REVOKE ALL ON FUNCTION webhook_send_message(char, char, text, text) FROM PUBLIC, jarvis_app_rw, jarvis_admin;
GRANT EXECUTE ON FUNCTION webhook_send_message(char, char, text, text) TO jarvis_app_rw, jarvis_admin;
