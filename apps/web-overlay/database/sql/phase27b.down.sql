DROP FUNCTION IF EXISTS webhook_result(char, boolean, integer, text);
DROP FUNCTION IF EXISTS webhook_claim(integer);
DROP FUNCTION IF EXISTS webhook_enqueue();
DROP FUNCTION IF EXISTS webhook_set_active(char, char, char, boolean);
DROP FUNCTION IF EXISTS webhook_delete(char, char, char);
DROP FUNCTION IF EXISTS webhook_create(char, char, char, text, text, text, integer);
DROP TABLE IF EXISTS webhook_deliveries;
DROP TABLE IF EXISTS webhook_endpoints;
