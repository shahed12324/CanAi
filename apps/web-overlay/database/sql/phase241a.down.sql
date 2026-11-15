DROP FUNCTION IF EXISTS ads_sync_fail(char, char, text, boolean);
DROP FUNCTION IF EXISTS ads_sync_store(char, char, jsonb, jsonb);
DROP FUNCTION IF EXISTS ads_sync_due(integer, integer);
DROP FUNCTION IF EXISTS ads_account_delete(char, char);
DROP FUNCTION IF EXISTS ads_account_save(char, char, text, text, text, text, smallint);
DROP TABLE IF EXISTS ads_insights_daily;
DROP TABLE IF EXISTS ads_campaigns;
DROP TABLE IF EXISTS ads_accounts;
