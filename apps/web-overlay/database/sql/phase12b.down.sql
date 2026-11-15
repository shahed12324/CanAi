DROP FUNCTION IF EXISTS store_credentials(char);
DROP FUNCTION IF EXISTS store_sync_reap(char);
DROP TABLE IF EXISTS store_sync_runs;
ALTER TABLE product_variants DROP COLUMN IF EXISTS remote_stock;
ALTER TABLE products DROP COLUMN IF EXISTS remote_synced_at, DROP COLUMN IF EXISTS remote_hash;
GRANT SELECT ON store_connections TO jarvis_app_rw, jarvis_admin;
GRANT INSERT, UPDATE ON store_connections TO jarvis_app_rw;
ALTER TABLE store_connections DROP COLUMN IF EXISTS currency;
