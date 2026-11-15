-- Phase 13c: AI support agent. Each channel is off | draft (AI writes a suggestion, a person sends it) | auto (AI replies by itself).
-- The database refuses an 'agent' message unless the channel is in auto mode, and caps the agent at 12 replies per conversation per hour.

ALTER TABLE inbox_channels ADD COLUMN agent_mode text NOT NULL DEFAULT 'off' CHECK (agent_mode IN ('off', 'draft', 'auto'));
GRANT SELECT (agent_mode) ON inbox_channels TO jarvis_app_rw, jarvis_admin;
GRANT UPDATE (agent_mode) ON inbox_channels TO jarvis_app_rw;

CREATE TABLE inbox_agent_settings (
  tenant_id      char(26)      PRIMARY KEY REFERENCES tenants (id),
  instructions   varchar(1000) NOT NULL DEFAULT '',     -- tone / dos and don'ts, written by the owner
  business_info  varchar(3000) NOT NULL DEFAULT '',     -- delivery charge, return policy, hours, payment methods: the only non-catalog facts the agent may use
  updated_at     timestamptz   NOT NULL DEFAULT now()
);

CREATE TABLE inbox_agent_drafts (
  conversation_id char(26)     PRIMARY KEY,
  tenant_id       char(26)     NOT NULL REFERENCES tenants (id),
  body            varchar(1500) NOT NULL CHECK (btrim(body) <> ''),
  in_seq          bigint       NOT NULL,
  created_at      timestamptz  NOT NULL DEFAULT now(),
  FOREIGN KEY (conversation_id, tenant_id) REFERENCES inbox_conversations (id, tenant_id)
);

DO $$ DECLARE t text; BEGIN
  FOREACH t IN ARRAY ARRAY['inbox_agent_settings', 'inbox_agent_drafts'] LOOP
    EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', t);
    EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY', t);
    EXECUTE format('CREATE POLICY tenant_isolation ON %I USING (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin())) WITH CHECK (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()))', t);
    EXECUTE format('REVOKE ALL ON %I FROM jarvis_app_rw, jarvis_admin', t);
  END LOOP;
END $$;
GRANT SELECT, INSERT ON inbox_agent_settings TO jarvis_app_rw;
GRANT UPDATE (instructions, business_info, updated_at) ON inbox_agent_settings TO jarvis_app_rw;
GRANT SELECT ON inbox_agent_settings TO jarvis_admin;
GRANT SELECT, INSERT, DELETE ON inbox_agent_drafts TO jarvis_app_rw;
GRANT UPDATE (body, in_seq, created_at) ON inbox_agent_drafts TO jarvis_app_rw;
GRANT SELECT ON inbox_agent_drafts TO jarvis_admin;

-- Outgoing message. New result codes: agent_off (channel not in auto mode), agent_limit (12 AI replies/hour in this chat).
CREATE OR REPLACE FUNCTION inbox_send(p_conv char(26), p_user char(26), p_sender text, p_body text) RETURNS TABLE (result text, seq bigint)
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); c inbox_conversations%ROWTYPE; k text; st text; am text; n integer; s bigint; b text := btrim(coalesce(p_body, ''));
BEGIN
  IF p_sender NOT IN ('staff', 'agent', 'system') THEN RAISE EXCEPTION 'bad_sender' USING ERRCODE = 'P0001'; END IF;
  IF p_sender = 'staff' AND p_user IS NULL THEN RAISE EXCEPTION 'user_required' USING ERRCODE = 'P0001'; END IF;
  c := inbox_guard(p_conv);
  IF b = '' THEN RETURN QUERY SELECT 'empty'::text, NULL::bigint; RETURN; END IF;
  IF length(b) > 4000 THEN RETURN QUERY SELECT 'too_long'::text, NULL::bigint; RETURN; END IF;
  IF emergency_active(c.tenant_id) THEN RETURN QUERY SELECT 'emergency'::text, NULL::bigint; RETURN; END IF;
  IF p_sender = 'agent' AND c.mode <> 'bot' THEN RETURN QUERY SELECT 'human_mode'::text, NULL::bigint; RETURN; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT ch.kind, ch.status, ch.agent_mode INTO k, st, am FROM inbox_channels ch WHERE ch.id = c.channel_id;
  IF st <> 'active' THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN QUERY SELECT 'channel_paused'::text, NULL::bigint; RETURN; END IF;
  IF p_sender = 'agent' AND am <> 'auto' THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN QUERY SELECT 'agent_off'::text, NULL::bigint; RETURN; END IF;
  IF p_sender = 'agent' THEN
    SELECT count(*) INTO n FROM inbox_messages m WHERE m.conversation_id = p_conv AND m.sender = 'agent' AND m.created_at > now() - interval '1 hour';
    IF n >= 12 THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN QUERY SELECT 'agent_limit'::text, NULL::bigint; RETURN; END IF;
  END IF;
  IF k IN ('messenger', 'instagram', 'whatsapp') AND (c.last_inbound_at IS NULL OR c.last_inbound_at < now() - interval '24 hours') THEN
    PERFORM set_config('app.bypass_rls', prev, true); RETURN QUERY SELECT 'window_closed'::text, NULL::bigint; RETURN;
  END IF;
  SELECT count(*) INTO n FROM inbox_messages m WHERE m.conversation_id = p_conv AND m.direction = 'out' AND m.created_at > now() - interval '1 minute';
  IF n >= 30 THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN QUERY SELECT 'rate_limited'::text, NULL::bigint; RETURN; END IF;
  INSERT INTO inbox_messages (tenant_id, conversation_id, direction, sender, user_id, body, status)
    VALUES (c.tenant_id, p_conv, 'out', p_sender, CASE WHEN p_sender = 'staff' THEN p_user END, b, CASE WHEN k IN ('web_widget') THEN 'sent' ELSE 'queued' END) RETURNING inbox_messages.seq INTO s;
  UPDATE inbox_conversations SET last_message_at = now(), preview = left(b, 120), unread = CASE WHEN p_sender = 'staff' THEN 0 ELSE unread END,
      mode = CASE WHEN p_sender = 'staff' THEN 'human' ELSE mode END,
      assigned_to = CASE WHEN p_sender = 'staff' THEN coalesce(assigned_to, p_user) ELSE assigned_to END,
      mode_changed_by = CASE WHEN p_sender = 'staff' AND mode = 'bot' THEN p_user ELSE mode_changed_by END,
      mode_changed_at = CASE WHEN p_sender = 'staff' AND mode = 'bot' THEN now() ELSE mode_changed_at END
   WHERE id = p_conv;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN QUERY SELECT CASE WHEN k = 'web_widget' THEN 'sent' ELSE 'queued' END, s;
END $$;
