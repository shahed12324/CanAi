DROP FUNCTION IF EXISTS api_key_resolve(text);
DROP FUNCTION IF EXISTS api_key_revoke(char, char, char);
DROP FUNCTION IF EXISTS api_key_create(char, char, char, text, text, text, text[], integer);
DROP TABLE IF EXISTS api_keys;
