-- Phase 27.1b: white-label.  A platform admin switches branding ON for a workspace (a paid/partner feature); then its OWNER sets a brand name, an accent colour,
-- a support email and whether to hide the "powered by" line.  Branding only changes what that workspace's own people see - names and colours, never links or scripts.
CREATE TABLE tenant_brands (
  tenant_id       char(26)     PRIMARY KEY REFERENCES tenants (id),
  enabled         boolean      NOT NULL DEFAULT false,
  brand_name      varchar(40)  CHECK (brand_name IS NULL OR (char_length(btrim(brand_name)) BETWEEN 1 AND 40 AND brand_name !~ '[[:cntrl:]<>]')),
  color           char(7)      CHECK (color IS NULL OR color ~ '^#[0-9a-fA-F]{6}$'),
  support_email   varchar(254) CHECK (support_email IS NULL OR support_email ~ '^[^@\s<>]+@[^@\s<>]+\.[^@\s<>]+$'),
  hide_powered_by boolean      NOT NULL DEFAULT false,
  updated_at      timestamptz  NOT NULL DEFAULT now(),
  CHECK (enabled OR (brand_name IS NULL AND color IS NULL AND support_email IS NULL AND NOT hide_powered_by))
);
ALTER TABLE tenant_brands ENABLE ROW LEVEL SECURITY;
ALTER TABLE tenant_brands FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON tenant_brands USING (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin())) WITH CHECK (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()));
REVOKE ALL ON tenant_brands FROM jarvis_app_rw, jarvis_admin;
GRANT SELECT ON tenant_brands TO jarvis_app_rw, jarvis_admin;

-- ADMIN: turn branding on/off for a workspace.  Turning it off clears the brand.
CREATE FUNCTION brand_set_enabled(p_tenant char(26), p_enabled boolean) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), '');
BEGIN
  IF p_tenant IS NULL OR p_enabled IS NULL THEN RETURN 'bad_input'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  IF NOT EXISTS (SELECT 1 FROM tenants WHERE id = p_tenant AND deleted_at IS NULL) THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'not_found'; END IF;
  INSERT INTO tenant_brands (tenant_id, enabled) VALUES (p_tenant, p_enabled)
  ON CONFLICT (tenant_id) DO UPDATE SET enabled = p_enabled, updated_at = now(),
    brand_name = CASE WHEN p_enabled THEN tenant_brands.brand_name END, color = CASE WHEN p_enabled THEN tenant_brands.color END,
    support_email = CASE WHEN p_enabled THEN tenant_brands.support_email END, hide_powered_by = p_enabled AND tenant_brands.hide_powered_by;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

-- OWNER: set the brand (only while enabled).  Empty name/colour/email mean "use the default".
CREATE FUNCTION brand_save(p_tenant char(26), p_user char(26), p_name text, p_color text, p_email text, p_hide boolean) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); nm text := nullif(btrim(coalesce(p_name, '')), ''); cl text := nullif(btrim(coalesce(p_color, '')), ''); em text := nullif(btrim(coalesce(p_email, '')), '');
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  IF p_hide IS NULL OR (nm IS NOT NULL AND (char_length(nm) > 40 OR nm ~ '[[:cntrl:]<>]')) OR (cl IS NOT NULL AND cl !~ '^#[0-9a-fA-F]{6}$')
     OR (em IS NOT NULL AND (char_length(em) > 254 OR em !~ '^[^@\s<>]+@[^@\s<>]+\.[^@\s<>]+$')) THEN RETURN 'bad_input'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  IF NOT EXISTS (SELECT 1 FROM tenants WHERE id = p_tenant AND owner_id = p_user) THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'not_owner'; END IF;
  UPDATE tenant_brands SET brand_name = nm, color = lower(cl), support_email = em, hide_powered_by = p_hide, updated_at = now() WHERE tenant_id = p_tenant AND enabled;
  IF NOT FOUND THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'not_enabled'; END IF;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

DO $$ DECLARE f text; BEGIN
  FOREACH f IN ARRAY ARRAY['brand_set_enabled(char, boolean)', 'brand_save(char, char, text, text, text, boolean)'] LOOP
    EXECUTE format('REVOKE ALL ON FUNCTION %s FROM PUBLIC, jarvis_app_rw, jarvis_admin', f);
    IF f LIKE 'brand_save%' THEN EXECUTE format('GRANT EXECUTE ON FUNCTION %s TO jarvis_app_rw, jarvis_admin', f);
    ELSE EXECUTE format('GRANT EXECUTE ON FUNCTION %s TO jarvis_admin', f); END IF;
  END LOOP;
END $$;
