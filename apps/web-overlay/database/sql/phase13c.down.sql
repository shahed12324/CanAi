DROP TABLE IF EXISTS inbox_agent_drafts;
DROP TABLE IF EXISTS inbox_agent_settings;
-- back to the 13a version of inbox_send
CREATE OR REPLACE FUNCTION inbox_send(p_conv char(26), p_user char(26), p_sender text, p_body text) RETURNS TABLE (result text, seq bigint)
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); c inbox_conversations%ROWTYPE; k text; st text; n integer; s bigint; b text := btrim(coalesce(p_body, ''));
BEGIN
  IF p_sender NOT IN ('staff', 'agent', 'system') THEN RAISE EXCEPTION 'bad_sender' USING ERRCODE = 'P0001'; END IF;
  IF p_sender = 'staff' AND p_user IS NULL THEN RAISE EXCEPTION 'user_required' USING ERRCODE = 'P0001'; END IF;
  c := inbox_guard(p_conv);
  IF b = '' THEN RETURN QUERY SELECT 'empty'::text, NULL::bigint; RETURN; END IF;
  IF length(b) > 4000 THEN RETURN QUERY SELECT 'too_long'::text, NULL::bigint; RETURN; END IF;
  IF emergency_active(c.tenant_id) THEN RETURN QUERY SELECT 'emergency'::text, NULL::bigint; RETURN; END IF;
  IF p_sender = 'agent' AND c.mode <> 'bot' THEN RETURN QUERY SELECT 'human_mode'::text, NULL::bigint; RETURN; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT ch.kind, ch.status INTO k, st FROM inbox_channels ch WHERE ch.id = c.channel_id;
  IF st <> 'active' THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN QUERY SELECT 'channel_paused'::text, NULL::bigint; RETURN; END IF;
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
ALTER TABLE inbox_channels DROP COLUMN IF EXISTS agent_mode;
