-- Phase 6: chat. conversations, messages, text attachments. Tenant-isolated by RLS.
-- Money is NOT handled here (see phase5): a message only records what the credit engine charged.

CREATE TABLE conversations (
  id              char(26)     PRIMARY KEY,
  tenant_id       char(26)     NOT NULL REFERENCES tenants (id),
  user_id         char(26)     NOT NULL REFERENCES users (id),
  title           varchar(200) NOT NULL,
  model_id        char(26)     REFERENCES ai_models (id),          -- NULL = automatic routing
  status          text         NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'archived')),
  last_message_at timestamptz  NOT NULL DEFAULT now(),
  created_at      timestamptz  NOT NULL DEFAULT now(),
  updated_at      timestamptz  NOT NULL DEFAULT now(),
  deleted_at      timestamptz
);
CREATE INDEX conversations_user_recent ON conversations (tenant_id, user_id, last_message_at DESC) WHERE deleted_at IS NULL;

CREATE TABLE messages (
  seq             bigint       GENERATED ALWAYS AS IDENTITY UNIQUE,   -- stable ordering (ids are random within a millisecond)
  id              char(26)     PRIMARY KEY,
  tenant_id       char(26)     NOT NULL REFERENCES tenants (id),
  conversation_id char(26)     NOT NULL REFERENCES conversations (id),
  role            text         NOT NULL CHECK (role IN ('user', 'assistant')),
  content         text         NOT NULL DEFAULT '' CHECK (length(content) <= 400000),
  status          text         NOT NULL CHECK (status IN ('complete', 'streaming', 'partial', 'error')),
  error_code      varchar(40),
  model_id        char(26)     REFERENCES ai_models (id),
  provider_id     char(26)     REFERENCES ai_providers (id),
  input_tokens    bigint       CHECK (input_tokens  IS NULL OR input_tokens  >= 0),
  output_tokens   bigint       CHECK (output_tokens IS NULL OR output_tokens >= 0),
  credits_charged numeric(20,4) NOT NULL DEFAULT 0 CHECK (credits_charged >= 0),
  reservation_id  char(26),
  hidden_at       timestamptz,                                       -- "deleted" by regenerate; rows are never removed
  created_at      timestamptz  NOT NULL DEFAULT now(),
  updated_at      timestamptz  NOT NULL DEFAULT now()
);
CREATE INDEX messages_conversation ON messages (conversation_id, seq);

-- A finished message is a record of what was billed: only hidden_at may change afterwards, and nothing is deleted.
CREATE FUNCTION messages_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'messages are never deleted (use hidden_at)'; END IF;
  IF OLD.status <> 'streaming' THEN
    IF (NEW.seq, NEW.id, NEW.tenant_id, NEW.conversation_id, NEW.role, NEW.content, NEW.status, NEW.error_code, NEW.model_id,
        NEW.provider_id, NEW.input_tokens, NEW.output_tokens, NEW.credits_charged, NEW.reservation_id, NEW.created_at)
       IS DISTINCT FROM
       (OLD.seq, OLD.id, OLD.tenant_id, OLD.conversation_id, OLD.role, OLD.content, OLD.status, OLD.error_code, OLD.model_id,
        OLD.provider_id, OLD.input_tokens, OLD.output_tokens, OLD.credits_charged, OLD.reservation_id, OLD.created_at)
    THEN RAISE EXCEPTION 'a finished message is immutable'; END IF;
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER messages_guard_trg BEFORE UPDATE OR DELETE ON messages FOR EACH ROW EXECUTE FUNCTION messages_guard();

CREATE TABLE chat_attachments (
  id              char(26)     PRIMARY KEY,
  tenant_id       char(26)     NOT NULL REFERENCES tenants (id),
  conversation_id char(26)     NOT NULL REFERENCES conversations (id),
  message_id      char(26)     REFERENCES messages (id),             -- NULL until the message is sent
  user_id         char(26)     NOT NULL REFERENCES users (id),
  filename        varchar(255) NOT NULL,
  mime            varchar(100) NOT NULL,
  size_bytes      integer      NOT NULL CHECK (size_bytes >= 0),
  extracted_text  text         NOT NULL CHECK (length(extracted_text) <= 200000),
  created_at      timestamptz  NOT NULL DEFAULT now()
);
CREATE INDEX chat_attachments_conv ON chat_attachments (conversation_id);

DO $$
DECLARE t text;
BEGIN
  FOREACH t IN ARRAY ARRAY['conversations', 'messages', 'chat_attachments'] LOOP
    EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', t);
    EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY', t);
    EXECUTE format('CREATE POLICY tenant_isolation ON %I USING (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()))
                    WITH CHECK (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()))', t);
  END LOOP;
END $$;

-- Rows must not point at another tenant's conversation (RLS alone would let a buggy insert mix tenants).
CREATE FUNCTION chat_same_tenant() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE c char(26); prev text := coalesce(current_setting('app.bypass_rls', true), '');
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT tenant_id INTO c FROM conversations WHERE id = NEW.conversation_id;
  PERFORM set_config('app.bypass_rls', prev, true);
  IF c IS DISTINCT FROM NEW.tenant_id THEN RAISE EXCEPTION 'conversation belongs to another tenant'; END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER messages_same_tenant BEFORE INSERT ON messages FOR EACH ROW EXECUTE FUNCTION chat_same_tenant();
CREATE TRIGGER attachments_same_tenant BEFORE INSERT ON chat_attachments FOR EACH ROW EXECUTE FUNCTION chat_same_tenant();
REVOKE ALL ON FUNCTION chat_same_tenant() FROM PUBLIC;

-- Streams that never finished (worker crash, killed PHP): mark them so the UI stops showing a spinner.
-- Their credit reservation is released separately by credit_release_stale().
CREATE FUNCTION chat_finalize_stale(p_older_than_seconds integer DEFAULT 1200) RETURNS integer
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE n integer; prev text := coalesce(current_setting('app.bypass_rls', true), '');
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE messages SET status = CASE WHEN length(content) > 0 THEN 'partial' ELSE 'error' END,
                      error_code = 'interrupted', updated_at = now()
   WHERE status = 'streaming' AND updated_at < now() - make_interval(secs => p_older_than_seconds);
  GET DIAGNOSTICS n = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN n;
END $$;
REVOKE ALL ON FUNCTION chat_finalize_stale(integer) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION chat_finalize_stale(integer) TO jarvis_app_rw;
