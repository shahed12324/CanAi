-- Phase 12b: WooCommerce / Shopify connections. Secrets are readable only through store_credentials(); one sync per store at a time;
-- bookkeeping columns let the sync tell "changed on their side" from "changed on ours" (so it never overwrites silently).

ALTER TABLE store_connections ADD COLUMN currency char(3) NOT NULL DEFAULT 'BDT' CHECK (currency ~ '^[A-Z]{3}$');
ALTER TABLE products ADD COLUMN remote_hash text, ADD COLUMN remote_synced_at timestamptz;
ALTER TABLE product_variants ADD COLUMN remote_stock integer CHECK (remote_stock IS NULL OR remote_stock >= 0);

-- The app can no longer read the ciphertext columns directly (the sync reads them through store_credentials()).
REVOKE SELECT ON store_connections FROM jarvis_app_rw, jarvis_admin;
GRANT SELECT (id, tenant_id, platform, name, base_url, status, last_sync_at, last_error, created_at, currency) ON store_connections TO jarvis_app_rw, jarvis_admin;
REVOKE INSERT, UPDATE ON store_connections FROM jarvis_app_rw;
GRANT INSERT (id, tenant_id, platform, name, base_url, credentials_ct, key_version, currency) ON store_connections TO jarvis_app_rw;
GRANT UPDATE (name, base_url, credentials_ct, key_version, currency, status, last_sync_at, last_error) ON store_connections TO jarvis_app_rw;
GRANT UPDATE (remote_hash, remote_synced_at) ON products TO jarvis_app_rw;
GRANT UPDATE (remote_stock) ON product_variants TO jarvis_app_rw;

-- Only the sync's own columns may be written by an INSERT of an imported product.
GRANT INSERT (external_id) ON product_variants TO jarvis_app_rw;

CREATE TABLE store_sync_runs (
  id           char(26)     PRIMARY KEY,
  tenant_id    char(26)     NOT NULL REFERENCES tenants (id),
  store_id     char(26)     NOT NULL,
  trigger      text         NOT NULL CHECK (trigger IN ('manual', 'schedule')),
  user_id      char(26)     REFERENCES users (id),
  status       text         NOT NULL DEFAULT 'running' CHECK (status IN ('running', 'ok', 'partial', 'failed')),
  pulled       integer      NOT NULL DEFAULT 0,
  created      integer      NOT NULL DEFAULT 0,
  updated      integer      NOT NULL DEFAULT 0,
  pushed       integer      NOT NULL DEFAULT 0,
  failed       integer      NOT NULL DEFAULT 0,
  note         varchar(200),
  errors       jsonb        NOT NULL DEFAULT '[]'::jsonb CHECK (jsonb_typeof(errors) = 'array' AND jsonb_array_length(errors) <= 50),
  started_at   timestamptz  NOT NULL DEFAULT now(),
  finished_at  timestamptz,
  FOREIGN KEY (store_id, tenant_id) REFERENCES store_connections (id, tenant_id)
);
CREATE UNIQUE INDEX store_sync_one_running ON store_sync_runs (store_id) WHERE status = 'running';
CREATE INDEX store_sync_recent ON store_sync_runs (store_id, started_at DESC);
ALTER TABLE store_sync_runs ENABLE ROW LEVEL SECURITY;
ALTER TABLE store_sync_runs FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON store_sync_runs USING (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()))
  WITH CHECK (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()));
REVOKE ALL ON store_sync_runs FROM jarvis_app_rw, jarvis_admin;
GRANT SELECT ON store_sync_runs TO jarvis_app_rw, jarvis_admin;
GRANT INSERT ON store_sync_runs TO jarvis_app_rw;
GRANT UPDATE (status, pulled, created, updated, pushed, failed, note, errors, finished_at) ON store_sync_runs TO jarvis_app_rw;

-- A crashed run must not block the store forever.
CREATE FUNCTION store_sync_reap(p_store char(26)) RETURNS integer LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); t char(26); n integer;
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT tenant_id INTO t FROM store_connections WHERE id = p_store;
  PERFORM set_config('app.bypass_rls', prev, true);
  IF t IS NULL THEN RAISE EXCEPTION 'forbidden' USING ERRCODE = '42501'; END IF;
  PERFORM credit_authorize(t, false);
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE store_sync_runs SET status = 'failed', note = 'interrupted', finished_at = now() WHERE store_id = p_store AND status = 'running' AND started_at < now() - interval '15 minutes';
  GET DIAGNOSTICS n = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN n;
END $$;

-- The stored secret, for the caller's own workspace only.
CREATE FUNCTION store_credentials(p_store char(26)) RETURNS TABLE (credentials_ct text, key_version smallint) LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); s store_connections%ROWTYPE;
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO s FROM store_connections WHERE id = p_store;
  PERFORM set_config('app.bypass_rls', prev, true);
  IF NOT FOUND THEN RAISE EXCEPTION 'forbidden' USING ERRCODE = '42501'; END IF;
  PERFORM credit_authorize(s.tenant_id, false);
  RETURN QUERY SELECT s.credentials_ct, s.key_version;
END $$;

REVOKE ALL ON FUNCTION store_sync_reap(char), store_credentials(char) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION store_sync_reap(char), store_credentials(char) TO jarvis_app_rw, jarvis_admin;
