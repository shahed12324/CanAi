-- Phase 19c: sources shown under chat answers that used the Knowledge Base.
-- A row keeps a snapshot (title, passage number, short excerpt), so the citation still reads right if the document is later changed or deleted.

CREATE TABLE chat_message_sources (
  id          bigint       GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  tenant_id   char(26)     NOT NULL REFERENCES tenants (id),
  message_id  char(26)     NOT NULL REFERENCES messages (id),
  n           smallint     NOT NULL CHECK (n BETWEEN 1 AND 10),         -- the [n] the model was told to quote
  document_id char(26),                                                  -- no FK on purpose: the citation outlives the document
  title       varchar(200) NOT NULL,
  passage_seq integer      NOT NULL CHECK (passage_seq >= 1),
  excerpt     varchar(400) NOT NULL,
  cited       boolean      NOT NULL,                                     -- the answer really contains [n]
  UNIQUE (message_id, n)
);
CREATE INDEX chat_message_sources_tenant ON chat_message_sources (tenant_id);

ALTER TABLE chat_message_sources ENABLE ROW LEVEL SECURITY;
ALTER TABLE chat_message_sources FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON chat_message_sources USING (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin())) WITH CHECK (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()));
REVOKE ALL ON chat_message_sources FROM jarvis_app_rw, jarvis_admin;
GRANT SELECT ON chat_message_sources TO jarvis_app_rw, jarvis_admin;

-- Store the sources of one assistant answer.  items: [{n, document_id, title, seq, excerpt, cited}, ...] (at most 10)
-- Only the person who owns the conversation may attach sources, and only to an assistant message.  Returns how many rows were stored.
CREATE FUNCTION chat_sources_save(p_tenant char(26), p_user char(26), p_message char(26), p_items jsonb) RETURNS integer
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); it jsonb; stored integer := 0; rc integer; nn integer; sq integer; ttl text; ex text;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  IF p_items IS NULL OR jsonb_typeof(p_items) IS DISTINCT FROM 'array' THEN RETURN 0; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  IF NOT EXISTS (SELECT 1 FROM messages m JOIN conversations c ON c.id = m.conversation_id AND c.tenant_id = m.tenant_id
                  WHERE m.id = p_message AND m.tenant_id = p_tenant AND m.role = 'assistant' AND c.user_id = p_user) THEN
    PERFORM set_config('app.bypass_rls', prev, true);
    RETURN 0;
  END IF;
  FOR it IN SELECT * FROM jsonb_array_elements(p_items) LOOP
    EXIT WHEN stored >= 10;
    CONTINUE WHEN jsonb_typeof(it) IS DISTINCT FROM 'object' OR jsonb_typeof(it -> 'n') IS DISTINCT FROM 'number' OR jsonb_typeof(it -> 'seq') IS DISTINCT FROM 'number'
                  OR jsonb_typeof(it -> 'title') IS DISTINCT FROM 'string' OR jsonb_typeof(it -> 'excerpt') IS DISTINCT FROM 'string' OR jsonb_typeof(it -> 'cited') IS DISTINCT FROM 'boolean';
    nn := (it ->> 'n')::numeric::integer;
    sq := (it ->> 'seq')::numeric::integer;
    CONTINUE WHEN nn NOT BETWEEN 1 AND 10 OR sq < 1;
    ttl := left(btrim(it ->> 'title'), 200);
    ex := left(btrim(it ->> 'excerpt'), 400);
    CONTINUE WHEN ttl = '' OR ex = '';
    INSERT INTO chat_message_sources (tenant_id, message_id, n, document_id, title, passage_seq, excerpt, cited)
    VALUES (p_tenant, p_message, nn, CASE WHEN jsonb_typeof(it -> 'document_id') = 'string' THEN left(it ->> 'document_id', 26) END, ttl, sq, ex, (it ->> 'cited')::boolean)
    ON CONFLICT DO NOTHING;
    GET DIAGNOSTICS rc = ROW_COUNT;
    stored := stored + rc;
  END LOOP;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN stored;
END $$;

REVOKE ALL ON FUNCTION chat_sources_save(char, char, char, jsonb) FROM PUBLIC, jarvis_app_rw, jarvis_admin;
GRANT EXECUTE ON FUNCTION chat_sources_save(char, char, char, jsonb) TO jarvis_app_rw, jarvis_admin;
