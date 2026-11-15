-- Phase 24.3b: agency mode.  A workspace can keep several CLIENTS; each client has its own brand kit (a non-default row of brand_kits) and owns some connected accounts
-- (Meta ad accounts, Google Ads accounts, Facebook Pages).  Nothing here touches money or publishes anything: it only labels which account belongs to which client,
-- so reports and (later) the dashboards can be filtered per client.  Clients are archived, never deleted.
--   * at most 100 active clients per workspace; names are unique (case-insensitive) among active clients;
--   * an account belongs to at most one client; archiving a client releases its accounts;
--   * the default brand kit (the workspace's own) is untouched.

CREATE TABLE clients (
  id           char(26)     PRIMARY KEY,
  tenant_id    char(26)     NOT NULL REFERENCES tenants (id),
  name         varchar(120) NOT NULL CHECK (btrim(name) <> ''),
  brand_kit_id char(26)     REFERENCES brand_kits (id),
  notes        varchar(500) NOT NULL DEFAULT '',
  status       text         NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'archived')),
  created_by   char(26)     NOT NULL,
  created_at   timestamptz  NOT NULL DEFAULT now(),
  updated_at   timestamptz  NOT NULL DEFAULT now(),
  UNIQUE (id, tenant_id)
);
CREATE UNIQUE INDEX clients_name_active ON clients (tenant_id, lower(name)) WHERE status = 'active';
ALTER TABLE clients ENABLE ROW LEVEL SECURITY;
ALTER TABLE clients FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON clients USING (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin())) WITH CHECK (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()));
REVOKE ALL ON clients FROM jarvis_app_rw, jarvis_admin;
GRANT SELECT ON clients TO jarvis_app_rw, jarvis_admin;

ALTER TABLE ads_accounts    ADD COLUMN client_id char(26), ADD FOREIGN KEY (client_id, tenant_id) REFERENCES clients (id, tenant_id);
ALTER TABLE gads_accounts   ADD COLUMN client_id char(26), ADD FOREIGN KEY (client_id, tenant_id) REFERENCES clients (id, tenant_id);
ALTER TABLE social_accounts ADD COLUMN client_id char(26), ADD FOREIGN KEY (client_id, tenant_id) REFERENCES clients (id, tenant_id);
GRANT SELECT (client_id) ON ads_accounts, gads_accounts, social_accounts TO jarvis_app_rw, jarvis_admin;

-- Create (p_create) or update a client and its brand kit.  p_logo is a base64 PNG/JPEG (validated by the app) and is only changed when p_set_logo.  result: ok | exists | limit | missing
CREATE FUNCTION client_save(p_id char(26), p_kit char(26), p_tenant char(26), p_user char(26), p_create boolean, p_name text, p_primary text, p_accent text, p_email text, p_phone text, p_website text, p_address text, p_notes text, p_logo text, p_set_logo boolean) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); nm text := left(btrim(coalesce(p_name, '')), 120); c clients%ROWTYPE;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  IF nm = '' THEN RAISE EXCEPTION 'name required' USING ERRCODE = '22023'; END IF;
  IF p_primary !~ '^#[0-9a-f]{6}$' OR p_accent !~ '^#[0-9a-f]{6}$' THEN RAISE EXCEPTION 'bad colour' USING ERRCODE = '22023'; END IF;
  IF p_logo IS NOT NULL AND length(p_logo) > 820000 THEN RAISE EXCEPTION 'logo too large' USING ERRCODE = '22023'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  IF p_create THEN
    IF (SELECT count(*) FROM clients WHERE tenant_id = p_tenant AND status = 'active') >= 100 THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'limit'; END IF;
    IF EXISTS (SELECT 1 FROM clients WHERE tenant_id = p_tenant AND status = 'active' AND lower(name) = lower(nm)) THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'exists'; END IF;
    INSERT INTO brand_kits (id, tenant_id, name, primary_color, accent_color, email, phone, website, address, logo_b64, is_default)
      VALUES (p_kit, p_tenant, left(nm, 80), p_primary, p_accent, nullif(btrim(coalesce(p_email, '')), ''), nullif(btrim(coalesce(p_phone, '')), ''), nullif(btrim(coalesce(p_website, '')), ''), nullif(btrim(coalesce(p_address, '')), ''), CASE WHEN p_set_logo THEN p_logo END, false);
    INSERT INTO clients (id, tenant_id, name, brand_kit_id, notes, created_by) VALUES (p_id, p_tenant, nm, p_kit, left(btrim(coalesce(p_notes, '')), 500), p_user);
  ELSE
    SELECT * INTO c FROM clients WHERE id = p_id AND tenant_id = p_tenant AND status = 'active';
    IF c.id IS NULL THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'missing'; END IF;
    IF EXISTS (SELECT 1 FROM clients WHERE tenant_id = p_tenant AND status = 'active' AND lower(name) = lower(nm) AND id <> p_id) THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'exists'; END IF;
    UPDATE clients SET name = nm, notes = left(btrim(coalesce(p_notes, '')), 500), updated_at = now() WHERE id = p_id;
    UPDATE brand_kits SET name = left(nm, 80), primary_color = p_primary, accent_color = p_accent, email = nullif(btrim(coalesce(p_email, '')), ''), phone = nullif(btrim(coalesce(p_phone, '')), ''),
      website = nullif(btrim(coalesce(p_website, '')), ''), address = nullif(btrim(coalesce(p_address, '')), ''), logo_b64 = CASE WHEN p_set_logo THEN p_logo ELSE logo_b64 END, updated_at = now()
      WHERE id = c.brand_kit_id AND tenant_id = p_tenant AND NOT is_default;
  END IF;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

-- Archive a client and release its accounts (they stay connected, just unassigned).  result: ok | missing
CREATE FUNCTION client_archive(p_tenant char(26), p_user char(26), p_id char(26)) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), '');
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE clients SET status = 'archived', updated_at = now() WHERE id = p_id AND tenant_id = p_tenant AND status = 'active';
  IF NOT FOUND THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'missing'; END IF;
  UPDATE ads_accounts SET client_id = NULL WHERE tenant_id = p_tenant AND client_id = p_id;
  UPDATE gads_accounts SET client_id = NULL WHERE tenant_id = p_tenant AND client_id = p_id;
  UPDATE social_accounts SET client_id = NULL WHERE tenant_id = p_tenant AND client_id = p_id;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

-- Assign an account to a client, or release it (p_client NULL).  p_kind: meta | google | social.  result: ok | account | client
CREATE FUNCTION client_assign(p_tenant char(26), p_user char(26), p_kind text, p_account char(26), p_client char(26)) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); n integer;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  IF p_kind IS NULL OR p_kind NOT IN ('meta', 'google', 'social') THEN RAISE EXCEPTION 'bad kind' USING ERRCODE = '22023'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  IF p_client IS NOT NULL AND NOT EXISTS (SELECT 1 FROM clients WHERE id = p_client AND tenant_id = p_tenant AND status = 'active') THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'client'; END IF;
  IF p_kind = 'meta' THEN UPDATE ads_accounts SET client_id = p_client WHERE id = p_account AND tenant_id = p_tenant;
  ELSIF p_kind = 'google' THEN UPDATE gads_accounts SET client_id = p_client WHERE id = p_account AND tenant_id = p_tenant;
  ELSE UPDATE social_accounts SET client_id = p_client WHERE id = p_account AND tenant_id = p_tenant;
  END IF;
  GET DIAGNOSTICS n = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN n = 1 THEN 'ok' ELSE 'account' END;
END $$;

DO $$ DECLARE f text; BEGIN
  FOREACH f IN ARRAY ARRAY['client_save(char, char, char, char, boolean, text, text, text, text, text, text, text, text, text, boolean)', 'client_archive(char, char, char)', 'client_assign(char, char, text, char, char)'] LOOP
    EXECUTE format('REVOKE ALL ON FUNCTION %s FROM PUBLIC, jarvis_app_rw, jarvis_admin', f);
    EXECUTE format('GRANT EXECUTE ON FUNCTION %s TO jarvis_app_rw, jarvis_admin', f);
  END LOOP;
END $$;
