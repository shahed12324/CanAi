-- Phase 19.2b: "Create" - the assistant writes a document / spreadsheet / presentation as a checked, structured spec; the file is built from the spec on download
-- (so downloading again costs nothing).  Personal to the person who asked.  The AI run is charged as source 'docgen'.
ALTER TABLE usage_records DROP CONSTRAINT usage_records_source_check;
ALTER TABLE usage_records ADD CONSTRAINT usage_records_source_check CHECK (source IN
  ('chat', 'agent', 'workflow', 'image', 'video', 'voice', 'email', 'storage', 'render', 'catalog', 'bill_scan', 'inbox', 'analyst', 'memory', 'vision', 'docgen'));

CREATE TABLE generated_files (
  id         char(26)      PRIMARY KEY,
  tenant_id  char(26)      NOT NULL REFERENCES tenants (id),
  user_id    char(26)      NOT NULL REFERENCES users (id),
  kind       text          NOT NULL CHECK (kind IN ('docx', 'xlsx', 'pptx')),
  title      varchar(200)  NOT NULL CHECK (btrim(title) <> ''),
  request    varchar(2000) NOT NULL DEFAULT '',
  spec       jsonb         NOT NULL CHECK (jsonb_typeof(spec) = 'object'),
  created_at timestamptz   NOT NULL DEFAULT now(),
  UNIQUE (id, tenant_id)
);
CREATE INDEX generated_files_person ON generated_files (tenant_id, user_id, created_at DESC);

ALTER TABLE generated_files ENABLE ROW LEVEL SECURITY;
ALTER TABLE generated_files FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON generated_files USING (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin())) WITH CHECK (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()));
REVOKE ALL ON generated_files FROM jarvis_app_rw, jarvis_admin;
GRANT SELECT ON generated_files TO jarvis_app_rw, jarvis_admin;

-- result: ok | bad_input | limit.  At most 100 saved files per person; a spec is at most 1,000,000 characters.
CREATE FUNCTION genfile_save(p_id char(26), p_tenant char(26), p_user char(26), p_kind text, p_title text, p_request text, p_spec jsonb) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); t text := regexp_replace(btrim(coalesce(p_title, '')), '[[:space:]]+', ' ', 'g'); n integer;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  IF p_id IS NULL OR p_kind IS NULL OR p_kind NOT IN ('docx', 'xlsx', 'pptx') OR t = '' OR char_length(t) > 200 OR char_length(coalesce(p_request, '')) > 2000
     OR p_spec IS NULL OR jsonb_typeof(p_spec) IS DISTINCT FROM 'object' OR length(p_spec::text) > 1000000 THEN RETURN 'bad_input'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT count(*) INTO n FROM generated_files WHERE tenant_id = p_tenant AND user_id = p_user;
  IF n >= 100 THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'limit'; END IF;
  INSERT INTO generated_files (id, tenant_id, user_id, kind, title, request, spec) VALUES (p_id, p_tenant, p_user, p_kind, t, coalesce(p_request, ''), p_spec) ON CONFLICT DO NOTHING;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

CREATE FUNCTION genfile_delete(p_id char(26), p_tenant char(26), p_user char(26)) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); rc integer;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  DELETE FROM generated_files WHERE id = p_id AND tenant_id = p_tenant AND user_id = p_user;
  GET DIAGNOSTICS rc = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN rc = 0 THEN 'not_found' ELSE 'ok' END;
END $$;

DO $$ DECLARE f text; BEGIN
  FOREACH f IN ARRAY ARRAY['genfile_save(char, char, char, text, text, text, jsonb)', 'genfile_delete(char, char, char)'] LOOP
    EXECUTE format('REVOKE ALL ON FUNCTION %s FROM PUBLIC, jarvis_app_rw, jarvis_admin', f);
    EXECUTE format('GRANT EXECUTE ON FUNCTION %s TO jarvis_app_rw, jarvis_admin', f);
  END LOOP;
END $$;
