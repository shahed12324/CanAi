-- Phase 7: messaging gateway (Telegram first). Platform bot -> many tenants.
-- Webhooks arrive with NO tenant context: the only way in is through the SECURITY DEFINER functions below,
-- which resolve an external identity to (tenant, user) and refuse anyone who is not an active member.

CREATE TABLE channel_accounts (            -- the platform's bots (platform-owned, not tenant data)
  id                   char(26)     PRIMARY KEY,
  channel              text         NOT NULL CHECK (channel IN ('telegram')),
  name                 varchar(100) NOT NULL,
  bot_username         varchar(64),
  token_encrypted      text         NOT NULL,          -- CredentialVault ciphertext; app role cannot read this column
  token_key_version    integer      NOT NULL,
  webhook_secret_hash  char(64)     NOT NULL,          -- sha256 of the secret Telegram echoes in a header
  status               text         NOT NULL DEFAULT 'disabled' CHECK (status IN ('active', 'disabled')),
  last_webhook_at      timestamptz,
  last_error           varchar(200),
  last_error_at        timestamptz,
  created_at           timestamptz  NOT NULL DEFAULT now(),
  updated_at           timestamptz  NOT NULL DEFAULT now()
);

CREATE TABLE channel_link_codes (          -- one-time codes a signed-in user generates on the web
  id          char(26)    PRIMARY KEY,
  tenant_id   char(26)    NOT NULL REFERENCES tenants (id),
  user_id     char(26)    NOT NULL REFERENCES users (id),
  channel     text        NOT NULL CHECK (channel IN ('telegram')),
  code_hash   char(64)    NOT NULL,
  expires_at  timestamptz NOT NULL,
  used_at     timestamptz,
  created_at  timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX channel_link_codes_hash ON channel_link_codes (code_hash);

CREATE TABLE channel_links (
  id                char(26)    PRIMARY KEY,
  tenant_id         char(26)    NOT NULL REFERENCES tenants (id),
  user_id           char(26)    NOT NULL REFERENCES users (id),
  account_id        char(26)    NOT NULL REFERENCES channel_accounts (id),
  external_user_id  varchar(64) NOT NULL,
  external_chat_id  varchar(64) NOT NULL,
  external_username varchar(64),
  conversation_id   char(26)    REFERENCES conversations (id),   -- the chat this Telegram thread continues
  verified_at       timestamptz NOT NULL DEFAULT now(),
  last_seen_at      timestamptz,
  revoked_at        timestamptz,
  created_at        timestamptz NOT NULL DEFAULT now()
);
-- one Telegram identity belongs to exactly one JARVIS user at a time (across ALL tenants)
CREATE UNIQUE INDEX channel_links_active_identity ON channel_links (account_id, external_user_id) WHERE revoked_at IS NULL;
CREATE UNIQUE INDEX channel_links_active_user ON channel_links (account_id, user_id) WHERE revoked_at IS NULL;

CREATE TABLE channel_events (              -- metadata only: dedupe + audit trail. NEVER message content.
  id                  bigint      GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  account_id          char(26)    NOT NULL REFERENCES channel_accounts (id),
  external_update_id  bigint      NOT NULL,
  kind                varchar(20) NOT NULL,
  outcome             text        NOT NULL DEFAULT 'processing' CHECK (outcome IN ('processing', 'processed', 'ignored', 'error')),
  error_code          varchar(40),
  tenant_id           char(26),
  link_id             char(26),
  attempts            integer     NOT NULL DEFAULT 1,
  received_at         timestamptz NOT NULL DEFAULT now(),
  finished_at         timestamptz,
  UNIQUE (account_id, external_update_id)
);
CREATE INDEX channel_events_recent ON channel_events (received_at DESC);

DO $$
DECLARE t text;
BEGIN
  FOREACH t IN ARRAY ARRAY['channel_link_codes', 'channel_links', 'channel_events'] LOOP
    EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', t);
    EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY', t);
    EXECUTE format('CREATE POLICY tenant_isolation ON %I USING (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()))
                    WITH CHECK (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()))', t);
  END LOOP;
END $$;

-- ---------------------------------------------------------------- privileges
REVOKE ALL ON channel_accounts, channel_link_codes, channel_links, channel_events FROM jarvis_app_rw, jarvis_admin;
GRANT SELECT (id, channel, name, bot_username, token_key_version, status, last_webhook_at, last_error, last_error_at, created_at, updated_at)
  ON channel_accounts TO jarvis_app_rw, jarvis_admin;
GRANT INSERT, UPDATE, DELETE ON channel_accounts TO jarvis_admin;     -- admins write the token but cannot read it back
GRANT SELECT, INSERT ON channel_link_codes TO jarvis_app_rw;          -- redeeming happens in a function
GRANT SELECT ON channel_link_codes TO jarvis_admin;
GRANT SELECT ON channel_links TO jarvis_app_rw, jarvis_admin;
GRANT UPDATE (conversation_id, last_seen_at, revoked_at) ON channel_links TO jarvis_app_rw;   -- unlink / switch chat
GRANT SELECT ON channel_events TO jarvis_admin;                       -- admin log view; written only by functions

-- ---------------------------------------------------------------- functions (all SECURITY DEFINER, no tenant context needed)

-- Atomically claim an update id. 'new' = process it; 'duplicate' = already handled / in progress; a crashed run
-- (still 'processing' after 2 minutes) may be claimed again so a Telegram retry is not lost.
CREATE FUNCTION channel_event_claim(p_account char(26), p_update bigint, p_kind text) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE n integer; prev text := coalesce(current_setting('app.bypass_rls', true), '');
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  INSERT INTO channel_events (account_id, external_update_id, kind) VALUES (p_account, p_update, left(p_kind, 20))
  ON CONFLICT DO NOTHING;
  GET DIAGNOSTICS n = ROW_COUNT;
  IF n = 1 THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'new'; END IF;
  UPDATE channel_events SET attempts = attempts + 1, received_at = now()
   WHERE account_id = p_account AND external_update_id = p_update AND outcome = 'processing' AND received_at < now() - interval '2 minutes';
  GET DIAGNOSTICS n = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN n = 1 THEN 'new' ELSE 'duplicate' END;
END $$;

CREATE FUNCTION channel_event_finish(p_account char(26), p_update bigint, p_outcome text, p_error text, p_tenant char(26), p_link char(26)) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), '');
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE channel_events SET outcome = p_outcome, error_code = left(p_error, 40), tenant_id = p_tenant, link_id = p_link, finished_at = now()
   WHERE account_id = p_account AND external_update_id = p_update AND p_outcome IN ('processed', 'ignored', 'error');
  PERFORM set_config('app.bypass_rls', prev, true);
END $$;

-- identity -> (tenant, user). Only ACTIVE members of ACTIVE tenants resolve; everyone else gets zero rows.
CREATE FUNCTION channel_resolve(p_account char(26), p_external_user varchar) RETURNS TABLE (link_id char(26), tenant_id char(26), user_id char(26), external_chat_id varchar, conversation_id char(26))
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), '');
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  RETURN QUERY
  SELECT l.id, l.tenant_id, l.user_id, l.external_chat_id, l.conversation_id
    FROM channel_links l
    JOIN tenants t ON t.id = l.tenant_id AND t.status = 'active' AND t.deleted_at IS NULL
    JOIN users u ON u.id = l.user_id AND u.status = 'active' AND u.deleted_at IS NULL
    JOIN tenant_users m ON m.tenant_id = l.tenant_id AND m.user_id = l.user_id AND m.status = 'active'
   WHERE l.account_id = p_account AND l.external_user_id = p_external_user AND l.revoked_at IS NULL;
  PERFORM set_config('app.bypass_rls', prev, true);
END $$;

-- Redeem a link code sent from Telegram. Returns tenant/user on success, otherwise a reason code.
CREATE FUNCTION channel_link_redeem(p_account char(26), p_code_hash char(64), p_ext_user varchar, p_ext_chat varchar, p_username varchar, p_new_id char(26))
RETURNS TABLE (r_ok boolean, r_reason text, r_tenant char(26), r_user char(26))
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE c channel_link_codes%ROWTYPE; prev text := coalesce(current_setting('app.bypass_rls', true), '');
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO c FROM channel_link_codes WHERE code_hash = p_code_hash FOR UPDATE;
  IF NOT FOUND OR c.used_at IS NOT NULL OR c.expires_at < now() THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN QUERY SELECT false, 'invalid_code', NULL::char(26), NULL::char(26); RETURN; END IF;
  IF NOT EXISTS (SELECT 1 FROM tenant_users m JOIN tenants t ON t.id = m.tenant_id JOIN users u ON u.id = m.user_id
                  WHERE m.tenant_id = c.tenant_id AND m.user_id = c.user_id AND m.status = 'active'
                    AND t.status = 'active' AND t.deleted_at IS NULL AND u.status = 'active' AND u.deleted_at IS NULL) THEN
    PERFORM set_config('app.bypass_rls', prev, true); RETURN QUERY SELECT false, 'not_member', NULL::char(26), NULL::char(26); RETURN;
  END IF;
  IF EXISTS (SELECT 1 FROM channel_links WHERE account_id = p_account AND external_user_id = p_ext_user AND revoked_at IS NULL) THEN
    PERFORM set_config('app.bypass_rls', prev, true); RETURN QUERY SELECT false, 'identity_already_linked', NULL::char(26), NULL::char(26); RETURN;
  END IF;
  IF EXISTS (SELECT 1 FROM channel_links WHERE account_id = p_account AND user_id = c.user_id AND revoked_at IS NULL) THEN
    PERFORM set_config('app.bypass_rls', prev, true); RETURN QUERY SELECT false, 'user_already_linked', NULL::char(26), NULL::char(26); RETURN;
  END IF;
  UPDATE channel_link_codes SET used_at = now() WHERE id = c.id;
  INSERT INTO channel_links (id, tenant_id, user_id, account_id, external_user_id, external_chat_id, external_username)
  VALUES (p_new_id, c.tenant_id, c.user_id, p_account, p_ext_user, p_ext_chat, left(p_username, 64));
  PERFORM set_config('app.bypass_rls', prev, true); RETURN QUERY SELECT true, 'ok', c.tenant_id, c.user_id;
END $$;

-- Everything the Laravel sender needs and nothing more: the encrypted token (decrypted in PHP by the vault).
CREATE FUNCTION channel_account_for_send(p_account char(26)) RETURNS TABLE (token_encrypted text, token_key_version integer)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
  SELECT token_encrypted, token_key_version FROM channel_accounts WHERE id = p_account AND status = 'active'
$$;

-- Webhook auth: returns the account id when the secret matches an ACTIVE account (compare hashes, not secrets).
CREATE FUNCTION channel_account_authenticate(p_account char(26), p_secret_hash char(64)) RETURNS boolean
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
  SELECT EXISTS (SELECT 1 FROM channel_accounts WHERE id = p_account AND status = 'active' AND webhook_secret_hash = p_secret_hash)
$$;

CREATE FUNCTION channel_account_health(p_account char(26), p_error text) RETURNS void
LANGUAGE sql SECURITY DEFINER SET search_path = public, pg_temp AS $$
  UPDATE channel_accounts SET last_webhook_at = CASE WHEN p_error IS NULL THEN now() ELSE last_webhook_at END,
         last_error = left(p_error, 200), last_error_at = CASE WHEN p_error IS NULL THEN last_error_at ELSE now() END
   WHERE id = p_account
$$;

CREATE FUNCTION channel_events_purge(p_older_than_days integer DEFAULT 30) RETURNS integer
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE n integer; prev text := coalesce(current_setting('app.bypass_rls', true), '');
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  DELETE FROM channel_events WHERE received_at < now() - make_interval(days => p_older_than_days) AND outcome <> 'processing';
  GET DIAGNOSTICS n = ROW_COUNT; PERFORM set_config('app.bypass_rls', prev, true); RETURN n;
END $$;

REVOKE ALL ON FUNCTION channel_event_claim(char, bigint, text), channel_event_finish(char, bigint, text, text, char, char),
  channel_resolve(char, varchar), channel_link_redeem(char, char, varchar, varchar, varchar, char),
  channel_account_for_send(char), channel_account_authenticate(char, char), channel_account_health(char, text),
  channel_events_purge(integer) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION channel_event_claim(char, bigint, text), channel_event_finish(char, bigint, text, text, char, char),
  channel_resolve(char, varchar), channel_link_redeem(char, char, varchar, varchar, varchar, char),
  channel_account_for_send(char), channel_account_authenticate(char, char), channel_account_health(char, text),
  channel_events_purge(integer) TO jarvis_app_rw;

-- Platform admins (admin panel login only) may decrypt-on-demand to run setWebhook/getMe. Not available to the web app role.
CREATE FUNCTION channel_account_token_for_admin(p_account char(26)) RETURNS TABLE (token_encrypted text, token_key_version integer)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
  SELECT token_encrypted, token_key_version FROM channel_accounts WHERE id = p_account
$$;
REVOKE ALL ON FUNCTION channel_account_token_for_admin(char) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION channel_account_token_for_admin(char) TO jarvis_admin;
