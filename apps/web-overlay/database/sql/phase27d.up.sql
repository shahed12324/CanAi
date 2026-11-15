-- Phase 27.2e: plugin store.  A plugin is a DECLARATIVE pack of workflows (a manifest) - no outside code ever runs.  A developer submits a version; a platform admin reads it and publishes or rejects it;
-- only published versions can be installed.  Installing makes the person's own copies of the workflows (switched off until they turn them on); the tools a plugin uses are declared up front and
-- checked again at install time, and the workflow rules (approval before anything external, no money tools) apply to them like to any workflow.

CREATE TABLE plugin_versions (
  id          char(26)     PRIMARY KEY,
  slug        varchar(40)  NOT NULL CHECK (slug ~ '^[a-z][a-z0-9-]{2,39}$'),
  version     integer      NOT NULL CHECK (version >= 1),
  name        varchar(80)  NOT NULL CHECK (btrim(name) <> ''),
  description varchar(400) NOT NULL DEFAULT '',
  tenant_id   char(26)     NOT NULL REFERENCES tenants (id),
  user_id     char(26)     NOT NULL REFERENCES users (id),
  manifest    jsonb        NOT NULL CHECK (jsonb_typeof(manifest) = 'object' AND jsonb_typeof(manifest -> 'workflows') = 'array'
                                        AND jsonb_array_length(manifest -> 'workflows') BETWEEN 1 AND 5 AND length(manifest::text) <= 60000),
  tools       text[]       NOT NULL CHECK (cardinality(tools) <= 40),
  status      text         NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'published', 'rejected', 'withdrawn')),
  review_note varchar(300),
  created_at  timestamptz  NOT NULL DEFAULT now(),
  reviewed_at timestamptz,
  UNIQUE (slug, version)
);
CREATE INDEX plugin_versions_status ON plugin_versions (status, created_at);
ALTER TABLE plugin_versions ENABLE ROW LEVEL SECURITY;
ALTER TABLE plugin_versions FORCE ROW LEVEL SECURITY;
-- Everybody may read what is published; an author also reads their own pending / rejected versions.
CREATE POLICY plugin_read ON plugin_versions FOR SELECT USING (status = 'published' OR tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()));
CREATE POLICY plugin_write ON plugin_versions FOR ALL USING (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin())) WITH CHECK (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()));
REVOKE ALL ON plugin_versions FROM jarvis_app_rw, jarvis_admin;
GRANT SELECT ON plugin_versions TO jarvis_app_rw, jarvis_admin;

CREATE TABLE plugin_installs (
  id           char(26)    PRIMARY KEY,
  tenant_id    char(26)    NOT NULL REFERENCES tenants (id),
  user_id      char(26)    NOT NULL REFERENCES users (id),
  slug         varchar(40) NOT NULL,
  version      integer     NOT NULL CHECK (version >= 1),
  workflow_ids text[]      NOT NULL CHECK (cardinality(workflow_ids) BETWEEN 1 AND 5),
  installed_at timestamptz NOT NULL DEFAULT now(),
  updated_at   timestamptz NOT NULL DEFAULT now(),
  UNIQUE (tenant_id, user_id, slug)
);
ALTER TABLE plugin_installs ENABLE ROW LEVEL SECURITY;
ALTER TABLE plugin_installs FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON plugin_installs USING (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin())) WITH CHECK (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()));
REVOKE ALL ON plugin_installs FROM jarvis_app_rw, jarvis_admin;
GRANT SELECT ON plugin_installs TO jarvis_app_rw, jarvis_admin;

-- A developer submits the next version of a plugin.  The version number is given by the database (1, 2, 3 ...).  A name (slug) belongs to the workspace that first used it.
CREATE FUNCTION plugin_submit(p_id char(26), p_tenant char(26), p_user char(26), p_slug text, p_name text, p_desc text, p_manifest jsonb, p_tools text[]) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); n integer; owner_t char(26); v integer; nm text := btrim(coalesce(p_name, ''));
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  IF p_id IS NULL OR p_slug IS NULL OR p_slug !~ '^[a-z][a-z0-9-]{2,39}$' OR nm = '' OR char_length(nm) > 80 OR char_length(coalesce(p_desc, '')) > 400
     OR p_manifest IS NULL OR jsonb_typeof(p_manifest) <> 'object' OR jsonb_typeof(p_manifest -> 'workflows') IS DISTINCT FROM 'array'
     OR jsonb_array_length(p_manifest -> 'workflows') NOT BETWEEN 1 AND 5 OR length(p_manifest::text) > 60000
     OR p_tools IS NULL OR cardinality(p_tools) > 40 THEN RETURN 'bad_input'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  PERFORM pg_advisory_xact_lock(hashtext('plugin:' || p_slug));
  SELECT tenant_id INTO owner_t FROM plugin_versions WHERE slug = p_slug LIMIT 1;
  IF owner_t IS NOT NULL AND owner_t <> p_tenant THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'slug_taken'; END IF;
  SELECT count(*) INTO n FROM plugin_versions WHERE tenant_id = p_tenant AND status = 'pending';
  IF n >= 5 THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'limit'; END IF;
  SELECT coalesce(max(version), 0) + 1 INTO v FROM plugin_versions WHERE slug = p_slug;
  INSERT INTO plugin_versions (id, slug, version, name, description, tenant_id, user_id, manifest, tools) VALUES (p_id, p_slug, v, nm, btrim(coalesce(p_desc, '')), p_tenant, p_user, p_manifest, p_tools);
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
EXCEPTION WHEN unique_violation THEN
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'duplicate';
END $$;

-- The author takes back a version that is still waiting, or stops new installs of a published one (existing installs keep working).
CREATE FUNCTION plugin_withdraw(p_id char(26), p_tenant char(26), p_user char(26)) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); rc integer;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE plugin_versions SET status = 'withdrawn', reviewed_at = coalesce(reviewed_at, now()) WHERE id = p_id AND tenant_id = p_tenant AND user_id = p_user AND status IN ('pending', 'published');
  GET DIAGNOSTICS rc = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN rc = 0 THEN 'not_found' ELSE 'ok' END;
END $$;

-- A platform admin decides on a waiting version (admin role only).  A rejection must say why.
CREATE FUNCTION plugin_review(p_id char(26), p_decision text, p_note text) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); v plugin_versions%ROWTYPE; note text := btrim(coalesce(p_note, ''));
BEGIN
  IF p_decision IS NULL OR p_decision NOT IN ('published', 'rejected') OR char_length(note) > 300 OR (p_decision = 'rejected' AND note = '') THEN RETURN 'bad_input'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO v FROM plugin_versions WHERE id = p_id FOR UPDATE;
  IF NOT FOUND THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'not_found'; END IF;
  IF v.status <> 'pending' THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'not_pending'; END IF;
  UPDATE plugin_versions SET status = p_decision, review_note = nullif(note, ''), reviewed_at = now() WHERE id = p_id;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

-- Records that the person's copies of a published version now exist (first install, or an update to a NEWER version).  The workflows themselves are made by the app.
CREATE FUNCTION plugin_install_record(p_id char(26), p_tenant char(26), p_user char(26), p_slug text, p_version integer, p_ids text[]) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); cur integer; n integer;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  IF p_id IS NULL OR p_slug IS NULL OR p_version IS NULL OR p_ids IS NULL OR cardinality(p_ids) NOT BETWEEN 1 AND 5 THEN RETURN 'bad_input'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  IF NOT EXISTS (SELECT 1 FROM plugin_versions WHERE slug = p_slug AND version = p_version AND status = 'published') THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'not_found'; END IF;
  SELECT version INTO cur FROM plugin_installs WHERE tenant_id = p_tenant AND user_id = p_user AND slug = p_slug FOR UPDATE;
  IF FOUND THEN
    IF p_version <= cur THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'not_newer'; END IF;
    UPDATE plugin_installs SET version = p_version, workflow_ids = p_ids, updated_at = now() WHERE tenant_id = p_tenant AND user_id = p_user AND slug = p_slug;
  ELSE
    SELECT count(*) INTO n FROM plugin_installs WHERE tenant_id = p_tenant AND user_id = p_user;
    IF n >= 20 THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'limit'; END IF;
    INSERT INTO plugin_installs (id, tenant_id, user_id, slug, version, workflow_ids) VALUES (p_id, p_tenant, p_user, p_slug, p_version, p_ids);
  END IF;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

CREATE FUNCTION plugin_uninstall(p_tenant char(26), p_user char(26), p_slug text) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); rc integer;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  DELETE FROM plugin_installs WHERE tenant_id = p_tenant AND user_id = p_user AND slug = p_slug;
  GET DIAGNOSTICS rc = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN rc = 0 THEN 'not_found' ELSE 'ok' END;
END $$;

DO $$ DECLARE f text; BEGIN
  FOREACH f IN ARRAY ARRAY['plugin_submit(char, char, char, text, text, text, jsonb, text[])', 'plugin_withdraw(char, char, char)', 'plugin_review(char, text, text)',
                           'plugin_install_record(char, char, char, text, integer, text[])', 'plugin_uninstall(char, char, text)'] LOOP
    EXECUTE format('REVOKE ALL ON FUNCTION %s FROM PUBLIC, jarvis_app_rw, jarvis_admin', f);
    IF f LIKE 'plugin_review%' THEN
      EXECUTE format('GRANT EXECUTE ON FUNCTION %s TO jarvis_admin', f);
    ELSE
      EXECUTE format('GRANT EXECUTE ON FUNCTION %s TO jarvis_app_rw, jarvis_admin', f);
    END IF;
  END LOOP;
END $$;
