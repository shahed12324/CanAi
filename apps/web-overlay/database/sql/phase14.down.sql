DROP FUNCTION IF EXISTS order_transition(char, char, text, text);
DROP FUNCTION IF EXISTS order_create(char, char, char, text, char, text, text, text, text, text, jsonb, numeric);
DROP FUNCTION IF EXISTS order_guard(char);
DROP FUNCTION IF EXISTS order_risk_calc(char, text, text, integer, bigint, char);
DROP FUNCTION IF EXISTS order_phone_norm(text);
DROP TABLE IF EXISTS store_order_events;
DROP TABLE IF EXISTS store_order_items;
DROP TABLE IF EXISTS store_orders;
DROP TABLE IF EXISTS order_settings;
