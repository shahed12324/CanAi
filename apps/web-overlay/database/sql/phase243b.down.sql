DROP FUNCTION IF EXISTS client_assign(char, char, text, char, char);
DROP FUNCTION IF EXISTS client_archive(char, char, char);
DROP FUNCTION IF EXISTS client_save(char, char, char, char, boolean, text, text, text, text, text, text, text, text, text, boolean);
ALTER TABLE social_accounts DROP COLUMN IF EXISTS client_id;
ALTER TABLE gads_accounts DROP COLUMN IF EXISTS client_id;
ALTER TABLE ads_accounts DROP COLUMN IF EXISTS client_id;
DROP TABLE IF EXISTS clients;
