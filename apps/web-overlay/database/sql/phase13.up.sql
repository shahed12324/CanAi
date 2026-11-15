-- Phase 13a: Unified Inbox core. Channels (web widget now; Messenger/Instagram/WhatsApp/Telegram in 13b), contacts, one rolling conversation per
-- contact, append-style messages. Everything that changes a conversation or sends a message goes through the functions below, so the
-- rules (24h window, emergency stop, human hand-off, flood limits) hold no matter which code path calls them.

CREATE FUNCTION inbox_origins_ok(o text[]) RETURNS boolean LANGUAGE sql IMMUTABLE AS $$
  SELECT cardinality(o) <= 10 AND NOT EXISTS (
    SELECT 1 FROM unnest(o) x WHERE x !~ '^(https://[a-z0-9]([a-z0-9.-]{0,120}[a-z0-9])?(:[0-9]{1,5})?|http://localhost(:[0-9]{1,5})?)$')
$$;

CREATE TABLE inbox_channels (
  id              char(26)     PRIMARY KEY,
  tenant_id       char(26)     NOT NULL REFERENCES tenants (id),
  kind            text         NOT NULL CHECK (kind IN ('web_widget', 'messenger', 'instagram', 'whatsapp', 'telegram')),
  name            varchar(120) NOT NULL,
  status          text         NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'paused')),
  public_key      char(32)     NOT NULL CHECK (public_key ~ '^[0-9a-f]{32}$'),   -- unguessable; the widget embed key / webhook path
  allowed_origins text[]       NOT NULL DEFAULT '{}' CHECK (inbox_origins_ok(allowed_origins)),
  greeting        varchar(300),
  external_ref    varchar(80),                                                    -- page id / phone number id (13b)
  credentials_ct  text,                                                           -- vault ciphertext (13b)
  key_version     smallint,
  created_at      timestamptz  NOT NULL DEFAULT now(),
  UNIQUE (id, tenant_id),
  CONSTRAINT widget_no_secret CHECK (kind <> 'web_widget' OR credentials_ct IS NULL)
);
CREATE UNIQUE INDEX inbox_channels_key ON inbox_channels (public_key);

CREATE TABLE inbox_contacts (
  id            char(26)     PRIMARY KEY,
  tenant_id     char(26)     NOT NULL REFERENCES tenants (id),
  channel_id    char(26)     NOT NULL,
  external_id   varchar(120) NOT NULL,
  display_name  varchar(120),
  token_hash    char(64)     CHECK (token_hash IS NULL OR token_hash ~ '^[0-9a-f]{64}$'),   -- web visitors prove themselves with a secret token
  created_at    timestamptz  NOT NULL DEFAULT now(),
  UNIQUE (id, tenant_id),
  UNIQUE (channel_id, external_id),
  FOREIGN KEY (channel_id, tenant_id) REFERENCES inbox_channels (id, tenant_id)
);
CREATE INDEX inbox_contacts_token ON inbox_contacts (channel_id, token_hash) WHERE token_hash IS NOT NULL;

CREATE TABLE inbox_conversations (
  id               char(26)     PRIMARY KEY,
  tenant_id        char(26)     NOT NULL REFERENCES tenants (id),
  channel_id       char(26)     NOT NULL,
  contact_id       char(26)     NOT NULL,
  status           text         NOT NULL DEFAULT 'open' CHECK (status IN ('open', 'resolved')),
  mode             text         NOT NULL DEFAULT 'bot' CHECK (mode IN ('bot', 'human')),   -- 'human' = the AI agent must stay silent (hand-off)
  handoff_reason   varchar(200),
  assigned_to      char(26)     REFERENCES users (id),
  mode_changed_by  char(26)     REFERENCES users (id),
  mode_changed_at  timestamptz,
  unread           integer      NOT NULL DEFAULT 0 CHECK (unread >= 0),
  preview          varchar(120),
  last_inbound_at  timestamptz,
  last_message_at  timestamptz  NOT NULL DEFAULT now(),
  created_at       timestamptz  NOT NULL DEFAULT now(),
  UNIQUE (id, tenant_id),
  UNIQUE (contact_id),
  FOREIGN KEY (channel_id, tenant_id) REFERENCES inbox_channels (id, tenant_id),
  FOREIGN KEY (contact_id, tenant_id) REFERENCES inbox_contacts (id, tenant_id)
);
CREATE INDEX inbox_conv_list ON inbox_conversations (tenant_id, status, last_message_at DESC);

CREATE TABLE inbox_messages (
  seq              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  tenant_id        char(26)     NOT NULL REFERENCES tenants (id),
  conversation_id  char(26)     NOT NULL,
  direction        text         NOT NULL CHECK (direction IN ('in', 'out')),
  sender           text         NOT NULL CHECK (sender IN ('customer', 'staff', 'agent', 'system')),
  user_id          char(26)     REFERENCES users (id),
  body             varchar(4000) NOT NULL CHECK (btrim(body) <> ''),
  external_id      varchar(120),
  status           text         NOT NULL CHECK (status IN ('received', 'queued', 'sent', 'failed')),
  error            varchar(200),
  created_at       timestamptz  NOT NULL DEFAULT now(),
  CONSTRAINT dir_sender CHECK ((direction = 'in' AND sender = 'customer' AND status = 'received') OR (direction = 'out' AND sender <> 'customer' AND status <> 'received')),
  FOREIGN KEY (conversation_id, tenant_id) REFERENCES inbox_conversations (id, tenant_id)
);
CREATE INDEX inbox_msg_conv ON inbox_messages (conversation_id, seq);
CREATE UNIQUE INDEX inbox_msg_dedupe ON inbox_messages (conversation_id, external_id) WHERE external_id IS NOT NULL;
CREATE INDEX inbox_msg_queue ON inbox_messages (tenant_id, seq) WHERE status = 'queued';

DO $$ DECLARE t text; BEGIN
  FOREACH t IN ARRAY ARRAY['inbox_channels', 'inbox_contacts', 'inbox_conversations', 'inbox_messages'] LOOP
    EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', t);
    EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY', t);
    EXECUTE format('CREATE POLICY tenant_isolation ON %I USING (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin())) WITH CHECK (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()))', t);
    EXECUTE format('REVOKE ALL ON %I FROM jarvis_app_rw, jarvis_admin', t);
  END LOOP;
END $$;

-- Channel settings are the only thing the app writes directly. Conversations, contacts and messages change only through the functions.
GRANT SELECT (id, tenant_id, kind, name, status, public_key, allowed_origins, greeting, external_ref, created_at) ON inbox_channels TO jarvis_app_rw, jarvis_admin;
GRANT INSERT (id, tenant_id, kind, name, public_key, allowed_origins, greeting, external_ref, credentials_ct, key_version) ON inbox_channels TO jarvis_app_rw;
GRANT UPDATE (name, status, allowed_origins, greeting, external_ref, credentials_ct, key_version) ON inbox_channels TO jarvis_app_rw;
GRANT SELECT ON inbox_contacts, inbox_conversations, inbox_messages TO jarvis_app_rw, jarvis_admin;

-- Loads a conversation and proves the caller works for its tenant ('forbidden' otherwise).
CREATE FUNCTION inbox_guard(p_conv char(26)) RETURNS inbox_conversations LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); c inbox_conversations%ROWTYPE;
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO c FROM inbox_conversations WHERE id = p_conv;
  PERFORM set_config('app.bypass_rls', prev, true);
  IF NOT FOUND THEN RAISE EXCEPTION 'forbidden' USING ERRCODE = '42501'; END IF;
  PERFORM credit_authorize(c.tenant_id, false);
  RETURN c;
END $$;

-- The ONLY lookup that works without a tenant: the public key (embed key / webhook path) -> which channel. Returns no secrets.
CREATE FUNCTION inbox_channel_by_key(p_key char(32)) RETURNS TABLE (id char(26), tenant_id char(26), kind text, status text, allowed_origins text[], greeting varchar, name varchar)
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), '');
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  RETURN QUERY SELECT c.id, c.tenant_id, c.kind, c.status, c.allowed_origins, c.greeting, c.name FROM inbox_channels c WHERE c.public_key = p_key;
  PERFORM set_config('app.bypass_rls', prev, true);
END $$;

-- First contact: creates the contact and its one rolling conversation. At most 300 new contacts per channel per hour.
CREATE FUNCTION inbox_contact_ensure(p_channel char(26), p_external text, p_name text, p_token_hash char(64), p_new_contact char(26), p_new_conv char(26))
RETURNS TABLE (result text, contact_id char(26), conversation_id char(26)) LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); ch inbox_channels%ROWTYPE; cid char(26); vid char(26); n integer;
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO ch FROM inbox_channels WHERE id = p_channel;
  PERFORM set_config('app.bypass_rls', prev, true);
  IF NOT FOUND THEN RAISE EXCEPTION 'forbidden' USING ERRCODE = '42501'; END IF;
  PERFORM credit_authorize(ch.tenant_id, false);
  IF ch.status <> 'active' THEN RETURN QUERY SELECT 'channel_paused'::text, NULL::char(26), NULL::char(26); RETURN; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT c.id INTO cid FROM inbox_contacts c WHERE c.channel_id = p_channel AND c.external_id = p_external;
  IF cid IS NULL THEN
    SELECT count(*) INTO n FROM inbox_contacts c WHERE c.channel_id = p_channel AND c.created_at > now() - interval '1 hour';
    IF n >= 300 THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN QUERY SELECT 'rate_limited'::text, NULL::char(26), NULL::char(26); RETURN; END IF;
    cid := p_new_contact; vid := p_new_conv;
    INSERT INTO inbox_contacts (id, tenant_id, channel_id, external_id, display_name, token_hash) VALUES (cid, ch.tenant_id, p_channel, p_external, left(nullif(btrim(p_name), ''), 120), p_token_hash);
    INSERT INTO inbox_conversations (id, tenant_id, channel_id, contact_id) VALUES (vid, ch.tenant_id, p_channel, cid);
  END IF;
  SELECT v.id INTO vid FROM inbox_conversations v WHERE v.contact_id = cid;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN QUERY SELECT 'ok'::text, cid, vid;
END $$;

-- A returning web visitor proves who they are with their secret token (stored only as a hash). Empty result = unknown.
CREATE FUNCTION inbox_contact_verify(p_channel char(26), p_external text, p_token_hash char(64)) RETURNS char(26)
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); t char(26); v char(26);
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT c.tenant_id INTO t FROM inbox_channels c WHERE c.id = p_channel;
  PERFORM set_config('app.bypass_rls', prev, true);
  IF t IS NULL THEN RAISE EXCEPTION 'forbidden' USING ERRCODE = '42501'; END IF;
  PERFORM credit_authorize(t, false);
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT v2.id INTO v FROM inbox_contacts c JOIN inbox_conversations v2 ON v2.contact_id = c.id
   WHERE c.channel_id = p_channel AND c.external_id = p_external AND c.token_hash = p_token_hash;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN v;
END $$;

-- A customer message arrives. Returns received | duplicate | empty | too_long | rate_limited | channel_paused
CREATE FUNCTION inbox_receive(p_conv char(26), p_body text, p_ext text DEFAULT NULL) RETURNS TABLE (result text, seq bigint)
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); c inbox_conversations%ROWTYPE; st text; n integer; s bigint; b text := btrim(coalesce(p_body, ''));
BEGIN
  c := inbox_guard(p_conv);
  IF b = '' THEN RETURN QUERY SELECT 'empty'::text, NULL::bigint; RETURN; END IF;
  IF length(b) > 4000 THEN RETURN QUERY SELECT 'too_long'::text, NULL::bigint; RETURN; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT ch.status INTO st FROM inbox_channels ch WHERE ch.id = c.channel_id;
  IF st <> 'active' THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN QUERY SELECT 'channel_paused'::text, NULL::bigint; RETURN; END IF;
  SELECT count(*) INTO n FROM inbox_messages m WHERE m.conversation_id = p_conv AND m.direction = 'in' AND m.created_at > now() - interval '1 minute';
  IF n >= 20 THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN QUERY SELECT 'rate_limited'::text, NULL::bigint; RETURN; END IF;
  INSERT INTO inbox_messages (tenant_id, conversation_id, direction, sender, body, external_id, status) VALUES (c.tenant_id, p_conv, 'in', 'customer', b, p_ext, 'received')
    ON CONFLICT (conversation_id, external_id) WHERE external_id IS NOT NULL DO NOTHING RETURNING inbox_messages.seq INTO s;
  IF s IS NULL THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN QUERY SELECT 'duplicate'::text, NULL::bigint; RETURN; END IF;
  UPDATE inbox_conversations SET status = 'open', unread = unread + 1, preview = left(b, 120), last_inbound_at = now(), last_message_at = now() WHERE id = p_conv;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN QUERY SELECT 'received'::text, s;
END $$;

-- An outgoing message (staff, the AI agent, or the system). Returns queued | sent | emergency | window_closed | human_mode | channel_paused | empty | too_long | rate_limited
--  * 24h rule: Messenger / Instagram / WhatsApp only allow a reply within 24h of the customer's last message.
--  * a staff reply moves the chat to 'human' (the AI agent then stays silent until someone hands it back).
CREATE FUNCTION inbox_send(p_conv char(26), p_user char(26), p_sender text, p_body text) RETURNS TABLE (result text, seq bigint)
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

-- Hand a chat to people (the agent may call it with no user) or give it back to the AI. mode = human | bot
CREATE FUNCTION inbox_set_mode(p_conv char(26), p_user char(26), p_mode text, p_reason text DEFAULT NULL) RETURNS boolean
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); c inbox_conversations%ROWTYPE;
BEGIN
  IF p_mode NOT IN ('bot', 'human') THEN RAISE EXCEPTION 'bad_mode' USING ERRCODE = 'P0001'; END IF;
  c := inbox_guard(p_conv);
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE inbox_conversations SET mode = p_mode, handoff_reason = CASE WHEN p_mode = 'human' THEN left(nullif(btrim(p_reason), ''), 200) END,
      assigned_to = CASE WHEN p_mode = 'human' THEN coalesce(assigned_to, p_user) ELSE NULL END, mode_changed_by = p_user, mode_changed_at = now(),
      status = CASE WHEN p_mode = 'human' THEN 'open' ELSE status END
   WHERE id = p_conv;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN true;
END $$;

CREATE FUNCTION inbox_resolve(p_conv char(26), p_user char(26), p_resolved boolean) RETURNS boolean LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); c inbox_conversations%ROWTYPE;
BEGIN
  c := inbox_guard(p_conv);
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE inbox_conversations SET status = CASE WHEN p_resolved THEN 'resolved' ELSE 'open' END, unread = CASE WHEN p_resolved THEN 0 ELSE unread END WHERE id = p_conv;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN true;
END $$;

CREATE FUNCTION inbox_mark_read(p_conv char(26)) RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); c inbox_conversations%ROWTYPE;
BEGIN
  c := inbox_guard(p_conv);
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE inbox_conversations SET unread = 0 WHERE id = p_conv AND unread <> 0;
  PERFORM set_config('app.bypass_rls', prev, true);
END $$;

-- The channel dispatcher (13b) reports what happened to a queued message. queued -> sent | failed only.
CREATE FUNCTION inbox_message_status(p_seq bigint, p_status text, p_error text DEFAULT NULL) RETURNS boolean LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); t char(26); n integer;
BEGIN
  IF p_status NOT IN ('sent', 'failed') THEN RAISE EXCEPTION 'bad_status' USING ERRCODE = 'P0001'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT m.tenant_id INTO t FROM inbox_messages m WHERE m.seq = p_seq;
  PERFORM set_config('app.bypass_rls', prev, true);
  IF t IS NULL THEN RAISE EXCEPTION 'forbidden' USING ERRCODE = '42501'; END IF;
  PERFORM credit_authorize(t, false);
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE inbox_messages SET status = p_status, error = CASE WHEN p_status = 'failed' THEN left(p_error, 200) END WHERE seq = p_seq AND status = 'queued';
  GET DIAGNOSTICS n = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN n = 1;
END $$;

REVOKE ALL ON FUNCTION inbox_origins_ok(text[]), inbox_guard(char), inbox_channel_by_key(char), inbox_contact_ensure(char, text, text, char, char, char), inbox_contact_verify(char, text, char),
  inbox_receive(char, text, text), inbox_send(char, char, text, text), inbox_set_mode(char, char, text, text), inbox_resolve(char, char, boolean), inbox_mark_read(char),
  inbox_message_status(bigint, text, text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION inbox_origins_ok(text[]) TO jarvis_app_rw, jarvis_admin, jarvis_owner;
REVOKE ALL ON FUNCTION inbox_guard(char) FROM jarvis_app_rw, jarvis_admin;
GRANT EXECUTE ON FUNCTION inbox_channel_by_key(char), inbox_contact_ensure(char, text, text, char, char, char), inbox_contact_verify(char, text, char), inbox_receive(char, text, text),
  inbox_send(char, char, text, text), inbox_set_mode(char, char, text, text), inbox_resolve(char, char, boolean), inbox_mark_read(char), inbox_message_status(bigint, text, text)
  TO jarvis_app_rw, jarvis_admin;
