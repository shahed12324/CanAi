DROP FUNCTION IF EXISTS gads_sync_fail(char, char, text, boolean);
DROP FUNCTION IF EXISTS gads_sync_store(char, char, jsonb, jsonb);
DROP FUNCTION IF EXISTS gads_sync_due(integer, integer);
DROP FUNCTION IF EXISTS gads_account_delete(char, char);
DROP FUNCTION IF EXISTS gads_account_save(char, char, text, text, text, text, text, smallint);
DROP TABLE IF EXISTS gads_insights_daily;
DROP TABLE IF EXISTS gads_campaigns;
DROP TABLE IF EXISTS gads_accounts;
