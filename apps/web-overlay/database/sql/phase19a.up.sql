-- Phase 19a: Memory Center. What the assistant may remember about a user / the business.
-- Rules enforced here (not only in the UI): nothing sensitive is stored (passwords, PINs, OTPs, API keys, card / ID numbers); a person only ever changes their own memories
-- (business-wide ones need the caller to say it is a manager - the app decides that from the permission); the assistant can only SUGGEST - a suggestion becomes a memory only when the person accepts it.

-- 'memory' joins the allowed usage sources (the suggestion run is charged).
ALTER TABLE usage_records DROP CONSTRAINT usage_records_source_check;
ALTER TABLE usage_records ADD CONSTRAINT usage_records_source_check CHECK (source IN
  ('chat', 'agent', 'workflow', 'image', 'video', 'voice', 'email', 'storage', 'render', 'catalog', 'bill_scan', 'inbox', 'analyst', 'memory'));

CREATE TABLE memories (
  id              char(26)     PRIMARY KEY,
  tenant_id       char(26)     NOT NULL REFERENCES tenants (id),
  user_id         char(26)     NOT NULL REFERENCES users (id),                       -- owner of a 'user' memory; author of a 'business' one
  scope           text         NOT NULL CHECK (scope IN ('user', 'business')),
  category        text         NOT NULL CHECK (category IN ('preference', 'fact', 'business', 'person', 'other')),
  content         varchar(500) NOT NULL CHECK (btrim(content) <> ''),
  pinned          boolean      NOT NULL DEFAULT false,
  active          boolean      NOT NULL DEFAULT true,
  source          text         NOT NULL DEFAULT 'user' CHECK (source IN ('user', 'suggested')),
  conversation_id char(26),                                                          -- where a suggestion came from (no FK: the chat may be deleted later)
  created_at      timestamptz  NOT NULL DEFAULT now(),
  updated_at      timestamptz  NOT NULL DEFAULT now(),
  UNIQUE (id, tenant_id)
);
-- the same text twice (ignoring case / outer spaces) is one memory per person (or one per business)
CREATE UNIQUE INDEX memories_no_dupes ON memories (tenant_id, scope, (CASE WHEN scope = 'user' THEN user_id::text ELSE '' END), md5(lower(btrim(content))));
CREATE INDEX memories_for_chat ON memories (tenant_id, user_id) WHERE active;

CREATE TABLE memory_settings (
  tenant_id   char(26)    NOT NULL REFERENCES tenants (id),
  user_id     char(26)    NOT NULL REFERENCES users (id),
  use_in_chat boolean     NOT NULL DEFAULT true,
  updated_at  timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY (tenant_id, user_id)
);

CREATE TABLE memory_suggestions (
  id              char(26)     PRIMARY KEY,
  tenant_id       char(26)     NOT NULL REFERENCES tenants (id),
  user_id         char(26)     NOT NULL REFERENCES users (id),
  conversation_id char(26),
  category        text         NOT NULL CHECK (category IN ('preference', 'fact', 'business', 'person', 'other')),
  content         varchar(500) NOT NULL CHECK (btrim(content) <> ''),
  status          text         NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'accepted', 'dismissed')),
  created_at      timestamptz  NOT NULL DEFAULT now()
);
CREATE INDEX memory_suggestions_pending ON memory_suggestions (tenant_id, user_id) WHERE status = 'pending';

DO $$ DECLARE t text; BEGIN
  FOREACH t IN ARRAY ARRAY['memories', 'memory_settings', 'memory_suggestions'] LOOP
    EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', t);
    EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY', t);
    EXECUTE format('CREATE POLICY tenant_isolation ON %I USING (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin())) WITH CHECK (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()))', t);
    EXECUTE format('REVOKE ALL ON %I FROM jarvis_app_rw, jarvis_admin', t);
    EXECUTE format('GRANT SELECT ON %I TO jarvis_app_rw, jarvis_admin', t);
  END LOOP;
END $$;

-- ok | empty | too_long | sensitive.  The Bengali digits are folded to 0-9 first so a card number written in Bengali digits is caught too.
CREATE FUNCTION memory_check_text(p_text text) RETURNS text LANGUAGE plpgsql IMMUTABLE AS $$
DECLARE t text := btrim(coalesce(p_text, '')); n text;
BEGIN
  IF t = '' THEN RETURN 'empty'; END IF;
  IF char_length(t) > 500 THEN RETURN 'too_long'; END IF;
  n := translate(t, '০১২৩৪৫৬৭৮৯', '0123456789');
  IF n ~* '(password|passwd|passcode|\mpwd\M|\motp\M|\mcvv\M|\mcvc\M|secret|api[ _-]?key|private[ _-]?key|access[ _-]?token|\mtoken\M|\mpin\M|পাসওয়ার্ড|পাসকোড|পিন নম্বর|পিন নাম্বার|ওটিপি|সিক্রেট|টোকেন)'
     OR n ~ '[0-9]([ -]?[0-9]){12,}'
     OR n ~ '(^|[^A-Za-z0-9])(sk|pk|rk|AKIA|AIza|ghp|gho|xox[abp])[-_A-Za-z0-9]{16,}'
     OR n ~ '[A-Za-z0-9_-]{32,}' THEN RETURN 'sensitive'; END IF;
  RETURN 'ok';
END $$;

-- result: ok | bad_input | sensitive | duplicate | limit
CREATE FUNCTION memory_add(p_id char(26), p_tenant char(26), p_user char(26), p_scope text, p_category text, p_content text, p_pinned boolean, p_source text, p_conv char(26)) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); chk text; c text; n integer; rc integer; lim integer;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  IF p_id IS NULL OR p_scope IS NULL OR p_scope NOT IN ('user', 'business') OR p_category IS NULL OR p_category NOT IN ('preference', 'fact', 'business', 'person', 'other')
     OR p_source IS NULL OR p_source NOT IN ('user', 'suggested') THEN RETURN 'bad_input'; END IF;
  chk := memory_check_text(p_content);
  IF chk IN ('empty', 'too_long') THEN RETURN 'bad_input'; END IF;
  IF chk = 'sensitive' THEN RETURN 'sensitive'; END IF;
  c := regexp_replace(btrim(p_content), '[[:space:]]+', ' ', 'g');
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT count(*) INTO n FROM memories WHERE tenant_id = p_tenant AND scope = p_scope AND (p_scope = 'business' OR user_id = p_user);
  lim := CASE WHEN p_scope = 'user' THEN 200 ELSE 100 END;
  IF n >= lim THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'limit'; END IF;
  INSERT INTO memories (id, tenant_id, user_id, scope, category, content, pinned, source, conversation_id)
  VALUES (p_id, p_tenant, p_user, p_scope, p_category, c, coalesce(p_pinned, false), p_source, p_conv) ON CONFLICT DO NOTHING;
  GET DIAGNOSTICS rc = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN rc = 0 THEN 'duplicate' ELSE 'ok' END;
END $$;

-- Change a memory. Any argument left NULL stays as it was.  result: ok | not_found | forbidden | bad_input | sensitive | duplicate
CREATE FUNCTION memory_update(p_id char(26), p_tenant char(26), p_user char(26), p_is_manager boolean, p_content text, p_category text, p_pinned boolean, p_active boolean) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); m memories%ROWTYPE; chk text; c text;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO m FROM memories WHERE id = p_id AND tenant_id = p_tenant FOR UPDATE;
  IF m.id IS NULL OR (m.scope = 'user' AND m.user_id <> p_user) THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'not_found'; END IF;
  IF m.scope = 'business' AND NOT coalesce(p_is_manager, false) THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'forbidden'; END IF;
  IF p_category IS NOT NULL AND p_category NOT IN ('preference', 'fact', 'business', 'person', 'other') THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'bad_input'; END IF;
  IF p_content IS NOT NULL THEN
    chk := memory_check_text(p_content);
    IF chk <> 'ok' THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN CASE WHEN chk = 'sensitive' THEN 'sensitive' ELSE 'bad_input' END; END IF;
    c := regexp_replace(btrim(p_content), '[[:space:]]+', ' ', 'g');
  END IF;
  BEGIN
    UPDATE memories SET content = coalesce(c, content), category = coalesce(p_category, category), pinned = coalesce(p_pinned, pinned), active = coalesce(p_active, active), updated_at = now() WHERE id = p_id;
  EXCEPTION WHEN unique_violation THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'duplicate';
  END;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

-- result: ok | not_found | forbidden
CREATE FUNCTION memory_delete(p_id char(26), p_tenant char(26), p_user char(26), p_is_manager boolean) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); m memories%ROWTYPE;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO m FROM memories WHERE id = p_id AND tenant_id = p_tenant FOR UPDATE;
  IF m.id IS NULL OR (m.scope = 'user' AND m.user_id <> p_user) THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'not_found'; END IF;
  IF m.scope = 'business' AND NOT coalesce(p_is_manager, false) THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'forbidden'; END IF;
  DELETE FROM memories WHERE id = p_id;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

-- "Forget everything about me": deletes the caller's own memories and suggestions (never the business-wide ones). Returns how many memories went.
CREATE FUNCTION memory_clear(p_tenant char(26), p_user char(26)) RETURNS integer
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); n integer;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  DELETE FROM memories WHERE tenant_id = p_tenant AND scope = 'user' AND user_id = p_user;
  GET DIAGNOSTICS n = ROW_COUNT;
  DELETE FROM memory_suggestions WHERE tenant_id = p_tenant AND user_id = p_user;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN n;
END $$;

CREATE FUNCTION memory_setting_save(p_tenant char(26), p_user char(26), p_use boolean) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), '');
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  IF p_use IS NULL THEN RETURN 'bad_input'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  INSERT INTO memory_settings (tenant_id, user_id, use_in_chat) VALUES (p_tenant, p_user, p_use)
  ON CONFLICT (tenant_id, user_id) DO UPDATE SET use_in_chat = EXCLUDED.use_in_chat, updated_at = now();
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

-- Store what the assistant proposes (p_items = [{"content": "...", "category": "..."}]).  Nothing becomes a memory here.
-- Skips items that are sensitive, empty, too long, already remembered, or already pending; keeps at most 5 per call and 20 pending per person.  Returns how many were stored.
CREATE FUNCTION memory_suggest_save(p_tenant char(26), p_user char(26), p_conv char(26), p_items jsonb, p_ids text[]) RETURNS integer
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); it jsonb; stored integer := 0; pending integer; txt text; cat text;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  IF p_items IS NULL OR jsonb_typeof(p_items) <> 'array' OR p_ids IS NULL THEN RETURN 0; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT count(*) INTO pending FROM memory_suggestions WHERE tenant_id = p_tenant AND user_id = p_user AND status = 'pending';
  FOR it IN SELECT * FROM jsonb_array_elements(p_items) LOOP
    EXIT WHEN stored >= 5 OR pending + stored >= 20 OR p_ids[stored + 1] IS NULL;
    CONTINUE WHEN jsonb_typeof(it) <> 'object' OR jsonb_typeof(it -> 'content') <> 'string';
    txt := regexp_replace(btrim(it ->> 'content'), '[[:space:]]+', ' ', 'g');
    cat := coalesce(it ->> 'category', 'other');
    CONTINUE WHEN memory_check_text(txt) <> 'ok' OR cat NOT IN ('preference', 'fact', 'business', 'person', 'other');
    CONTINUE WHEN EXISTS (SELECT 1 FROM memories WHERE tenant_id = p_tenant AND md5(lower(btrim(content))) = md5(lower(txt)) AND (scope = 'business' OR user_id = p_user));
    CONTINUE WHEN EXISTS (SELECT 1 FROM memory_suggestions WHERE tenant_id = p_tenant AND user_id = p_user AND status = 'pending' AND md5(lower(btrim(content))) = md5(lower(txt)));
    INSERT INTO memory_suggestions (id, tenant_id, user_id, conversation_id, category, content) VALUES (p_ids[stored + 1], p_tenant, p_user, p_conv, cat, txt);
    stored := stored + 1;
  END LOOP;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN stored;
END $$;

-- Accept a suggestion: it becomes a 'user' memory (source 'suggested').  result: as memory_add, or not_found
CREATE FUNCTION memory_suggestion_accept(p_id char(26), p_tenant char(26), p_user char(26), p_memory_id char(26)) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); s memory_suggestions%ROWTYPE; r text;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO s FROM memory_suggestions WHERE id = p_id AND tenant_id = p_tenant AND user_id = p_user AND status = 'pending' FOR UPDATE;
  IF s.id IS NULL THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'not_found'; END IF;
  r := memory_add(p_memory_id, p_tenant, p_user, 'user', s.category, s.content, false, 'suggested', s.conversation_id);
  IF r IN ('ok', 'duplicate') THEN UPDATE memory_suggestions SET status = 'accepted' WHERE id = p_id; END IF;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN r;
END $$;

CREATE FUNCTION memory_suggestion_dismiss(p_id char(26), p_tenant char(26), p_user char(26)) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); rc integer;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE memory_suggestions SET status = 'dismissed' WHERE id = p_id AND tenant_id = p_tenant AND user_id = p_user AND status = 'pending';
  GET DIAGNOSTICS rc = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN rc = 0 THEN 'not_found' ELSE 'ok' END;
END $$;

REVOKE ALL ON FUNCTION memory_check_text(text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION memory_check_text(text) TO jarvis_app_rw, jarvis_admin;
DO $$ DECLARE f text; BEGIN
  FOREACH f IN ARRAY ARRAY['memory_add(char, char, char, text, text, text, boolean, text, char)', 'memory_update(char, char, char, boolean, text, text, boolean, boolean)', 'memory_delete(char, char, char, boolean)',
    'memory_clear(char, char)', 'memory_setting_save(char, char, boolean)', 'memory_suggest_save(char, char, char, jsonb, text[])', 'memory_suggestion_accept(char, char, char, char)', 'memory_suggestion_dismiss(char, char, char)'] LOOP
    EXECUTE format('REVOKE ALL ON FUNCTION %s FROM PUBLIC, jarvis_app_rw, jarvis_admin', f);
    EXECUTE format('GRANT EXECUTE ON FUNCTION %s TO jarvis_app_rw, jarvis_admin', f);
  END LOOP;
END $$;
