-- Phase 19b: Knowledge Base. Company documents are cut into passages and searched with plain PostgreSQL
-- full-text search (no extension needed, so it also runs on shared hosting). Tenant-isolated by RLS.

CREATE TABLE kb_documents (
  id          char(26)     PRIMARY KEY,
  tenant_id   char(26)     NOT NULL REFERENCES tenants (id),
  created_by  char(26)     NOT NULL REFERENCES users (id),
  title       varchar(200) NOT NULL CHECK (btrim(title) <> ''),
  filename    varchar(200) NOT NULL,
  mime        varchar(100) NOT NULL,
  size_bytes  bigint       NOT NULL CHECK (size_bytes >= 0),
  text_hash   char(32)     NOT NULL,                                  -- md5 of the extracted text: the same document is not stored twice
  char_count  integer      NOT NULL CHECK (char_count > 0),
  chunk_count integer      NOT NULL CHECK (chunk_count > 0),
  active      boolean      NOT NULL DEFAULT true,
  created_at  timestamptz  NOT NULL DEFAULT now(),
  UNIQUE (id, tenant_id),
  UNIQUE (tenant_id, text_hash)
);

CREATE TABLE kb_chunks (
  id          bigint       GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  tenant_id   char(26)     NOT NULL,
  document_id char(26)     NOT NULL,
  seq         integer      NOT NULL CHECK (seq >= 1),
  content     text         NOT NULL CHECK (btrim(content) <> '' AND char_length(content) <= 2000),
  tsv         tsvector     GENERATED ALWAYS AS (to_tsvector('simple', content)) STORED,
  FOREIGN KEY (document_id, tenant_id) REFERENCES kb_documents (id, tenant_id) ON DELETE CASCADE,
  UNIQUE (document_id, seq)
);
CREATE INDEX kb_chunks_tsv ON kb_chunks USING gin (tsv);
CREATE INDEX kb_chunks_tenant ON kb_chunks (tenant_id);

DO $$ DECLARE t text; BEGIN
  FOREACH t IN ARRAY ARRAY['kb_documents', 'kb_chunks'] LOOP
    EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', t);
    EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY', t);
    EXECUTE format('CREATE POLICY tenant_isolation ON %I USING (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin())) WITH CHECK (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()))', t);
    EXECUTE format('REVOKE ALL ON %I FROM jarvis_app_rw, jarvis_admin', t);
    EXECUTE format('GRANT SELECT ON %I TO jarvis_app_rw, jarvis_admin', t);
  END LOOP;
END $$;

-- Save one document with its passages.  result: ok | bad_input | duplicate | limit
-- Limits: 50 documents per tenant, 600 passages per document.
CREATE FUNCTION kb_document_save(p_id char(26), p_tenant char(26), p_user char(26), p_title text, p_filename text, p_mime text, p_size bigint, p_chunks text[]) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); n integer; total integer; hash text; rc integer; t text;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  t := btrim(coalesce(p_title, ''));
  IF p_id IS NULL OR t = '' OR char_length(t) > 200 OR p_filename IS NULL OR char_length(p_filename) > 200 OR p_mime IS NULL OR char_length(p_mime) > 100
     OR p_size IS NULL OR p_size < 0 OR p_chunks IS NULL THEN RETURN 'bad_input'; END IF;
  n := coalesce(array_length(p_chunks, 1), 0);
  IF n < 1 OR n > 600 THEN RETURN 'bad_input'; END IF;
  IF EXISTS (SELECT 1 FROM unnest(p_chunks) c WHERE c IS NULL OR btrim(c) = '' OR char_length(c) > 2000) THEN RETURN 'bad_input'; END IF;
  SELECT sum(char_length(c)) INTO total FROM unnest(p_chunks) c;
  hash := md5(array_to_string(p_chunks, E'\n'));
  PERFORM set_config('app.bypass_rls', 'on', true);
  IF (SELECT count(*) FROM kb_documents WHERE tenant_id = p_tenant) >= 50 THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'limit'; END IF;
  INSERT INTO kb_documents (id, tenant_id, created_by, title, filename, mime, size_bytes, text_hash, char_count, chunk_count)
  VALUES (p_id, p_tenant, p_user, t, p_filename, p_mime, p_size, hash, total, n) ON CONFLICT DO NOTHING;
  GET DIAGNOSTICS rc = ROW_COUNT;
  IF rc = 0 THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'duplicate'; END IF;
  INSERT INTO kb_chunks (tenant_id, document_id, seq, content) SELECT p_tenant, p_id, o, c FROM unnest(p_chunks) WITH ORDINALITY AS u(c, o);
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

-- result: ok | not_found | bad_input
CREATE FUNCTION kb_document_update(p_id char(26), p_tenant char(26), p_title text, p_active boolean) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); rc integer; t text;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_title IS NOT NULL THEN
    t := btrim(p_title);
    IF t = '' OR char_length(t) > 200 THEN RETURN 'bad_input'; END IF;
  END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE kb_documents SET title = coalesce(t, title), active = coalesce(p_active, active) WHERE id = p_id AND tenant_id = p_tenant;
  GET DIAGNOSTICS rc = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN rc = 0 THEN 'not_found' ELSE 'ok' END;
END $$;

-- result: ok | not_found   (passages go with the document)
CREATE FUNCTION kb_document_delete(p_id char(26), p_tenant char(26)) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); rc integer;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  PERFORM set_config('app.bypass_rls', 'on', true);
  DELETE FROM kb_documents WHERE id = p_id AND tenant_id = p_tenant;
  GET DIAGNOSTICS rc = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN rc = 0 THEN 'not_found' ELSE 'ok' END;
END $$;

-- A question becomes "any of these words" (each also matches longer forms, so ঢাকা finds ঢাকায়).  NULL when nothing searchable is left.
CREATE FUNCTION kb_query(p_text text) RETURNS tsquery LANGUAGE plpgsql IMMUTABLE AS $$
DECLARE parts text;
BEGIN
  SELECT string_agg(
           CASE WHEN char_length(lexeme) >= 3 THEN '''' || lexeme || ''':*' ELSE '''' || lexeme || '''' END, ' | ')
    INTO parts
    FROM (SELECT lexeme FROM unnest(to_tsvector('simple', left(coalesce(p_text, ''), 1000))) WHERE lexeme !~ '[''\\:&|!()<>*]' ORDER BY array_length(positions, 1) DESC, lexeme LIMIT 12) x;
  IF parts IS NULL THEN RETURN NULL; END IF;
  RETURN to_tsquery('simple', parts);
END $$;

-- Best passages from the active documents of the caller's tenant (RLS does the tenant filtering).
CREATE FUNCTION kb_search(p_query text, p_k integer) RETURNS TABLE (document_id char(26), title varchar, seq integer, content text, rank real)
LANGUAGE sql STABLE AS $$
  SELECT c.document_id, d.title, c.seq, c.content, ts_rank_cd(c.tsv, q.v)::real
    FROM (SELECT kb_query(p_query) AS v) q
    JOIN kb_chunks c ON q.v IS NOT NULL AND c.tsv @@ q.v
    JOIN kb_documents d ON d.id = c.document_id AND d.tenant_id = c.tenant_id AND d.active
   ORDER BY 5 DESC, c.document_id, c.seq
   LIMIT greatest(1, least(coalesce(p_k, 5), 20));
$$;

REVOKE ALL ON FUNCTION kb_query(text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION kb_query(text) TO jarvis_app_rw, jarvis_admin;
REVOKE ALL ON FUNCTION kb_search(text, integer) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION kb_search(text, integer) TO jarvis_app_rw, jarvis_admin;
DO $$ DECLARE f text; BEGIN
  FOREACH f IN ARRAY ARRAY['kb_document_save(char, char, char, text, text, text, bigint, text[])', 'kb_document_update(char, char, text, boolean)', 'kb_document_delete(char, char)'] LOOP
    EXECUTE format('REVOKE ALL ON FUNCTION %s FROM PUBLIC, jarvis_app_rw, jarvis_admin', f);
    EXECUTE format('GRANT EXECUTE ON FUNCTION %s TO jarvis_app_rw, jarvis_admin', f);
  END LOOP;
END $$;
