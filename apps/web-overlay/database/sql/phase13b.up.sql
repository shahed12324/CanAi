-- Phase 13b: Messenger / Instagram / WhatsApp / Telegram channels. Channel secrets are readable only through inbox_credentials();
-- one platform account can belong to only one workspace; queued messages get a bounded number of delivery attempts.

ALTER TABLE inbox_channels ADD CONSTRAINT channel_shape CHECK (kind = 'web_widget' OR (credentials_ct IS NOT NULL AND key_version IS NOT NULL AND external_ref IS NOT NULL));
CREATE UNIQUE INDEX inbox_channels_account ON inbox_channels (kind, external_ref) WHERE external_ref IS NOT NULL;
ALTER TABLE inbox_messages ADD COLUMN attempts smallint NOT NULL DEFAULT 0;

-- The public-key lookup now also tells which platform account the channel is (webhooks must match it).
DROP FUNCTION inbox_channel_by_key(char);
CREATE FUNCTION inbox_channel_by_key(p_key char(32)) RETURNS TABLE (id char(26), tenant_id char(26), kind text, status text, allowed_origins text[], greeting varchar, name varchar, external_ref varchar)
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), '');
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  RETURN QUERY SELECT c.id, c.tenant_id, c.kind, c.status, c.allowed_origins, c.greeting, c.name, c.external_ref FROM inbox_channels c WHERE c.public_key = p_key;
  PERFORM set_config('app.bypass_rls', prev, true);
END $$;
REVOKE ALL ON FUNCTION inbox_channel_by_key(char) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION inbox_channel_by_key(char) TO jarvis_app_rw, jarvis_admin;

CREATE FUNCTION inbox_credentials(p_channel char(26)) RETURNS TABLE (credentials_ct text, key_version smallint, kind text, external_ref varchar)
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); c inbox_channels%ROWTYPE;
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO c FROM inbox_channels WHERE id = p_channel;
  PERFORM set_config('app.bypass_rls', prev, true);
  IF NOT FOUND THEN RAISE EXCEPTION 'forbidden' USING ERRCODE = '42501'; END IF;
  PERFORM credit_authorize(c.tenant_id, false);
  RETURN QUERY SELECT c.credentials_ct, c.key_version, c.kind, c.external_ref;
END $$;

-- Everything the dispatcher needs to deliver one queued message, including the rules that must hold AT SEND TIME (not only when it was queued).
CREATE FUNCTION inbox_dispatch_ctx(p_seq bigint) RETURNS TABLE (seq bigint, channel_id char(26), kind text, external_ref varchar, to_external varchar, body varchar, status text, attempts smallint,
  window_ok boolean, emergency boolean, channel_active boolean)
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); m inbox_messages%ROWTYPE;
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO m FROM inbox_messages WHERE inbox_messages.seq = p_seq;
  PERFORM set_config('app.bypass_rls', prev, true);
  IF NOT FOUND THEN RAISE EXCEPTION 'forbidden' USING ERRCODE = '42501'; END IF;
  PERFORM credit_authorize(m.tenant_id, false);
  PERFORM set_config('app.bypass_rls', 'on', true);
  RETURN QUERY SELECT m.seq, ch.id, ch.kind, ch.external_ref, c.external_id, m.body, m.status, m.attempts,
      (ch.kind NOT IN ('messenger', 'instagram', 'whatsapp') OR (v.last_inbound_at IS NOT NULL AND v.last_inbound_at > now() - interval '24 hours')),
      emergency_active(m.tenant_id), ch.status = 'active'
    FROM inbox_conversations v JOIN inbox_channels ch ON ch.id = v.channel_id JOIN inbox_contacts c ON c.id = v.contact_id WHERE v.id = m.conversation_id;
  PERFORM set_config('app.bypass_rls', prev, true);
END $$;

-- A delivery attempt failed in a way worth retrying. After 5 attempts the message is marked failed. Returns retry | failed | not_queued
CREATE FUNCTION inbox_message_retry(p_seq bigint, p_error text) RETURNS text LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); t char(26); a smallint; st text;
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT m.tenant_id INTO t FROM inbox_messages m WHERE m.seq = p_seq;
  PERFORM set_config('app.bypass_rls', prev, true);
  IF t IS NULL THEN RAISE EXCEPTION 'forbidden' USING ERRCODE = '42501'; END IF;
  PERFORM credit_authorize(t, false);
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE inbox_messages m SET attempts = m.attempts + 1, error = left(p_error, 200),
      status = CASE WHEN m.attempts + 1 >= 5 THEN 'failed' ELSE 'queued' END
   WHERE m.seq = p_seq AND m.status = 'queued' RETURNING m.attempts, m.status INTO a, st;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN st IS NULL THEN 'not_queued' WHEN st = 'failed' THEN 'failed' ELSE 'retry' END;
END $$;

REVOKE ALL ON FUNCTION inbox_credentials(char), inbox_dispatch_ctx(bigint), inbox_message_retry(bigint, text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION inbox_credentials(char), inbox_dispatch_ctx(bigint), inbox_message_retry(bigint, text) TO jarvis_app_rw, jarvis_admin;
