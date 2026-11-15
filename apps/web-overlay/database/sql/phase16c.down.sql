DROP TRIGGER IF EXISTS store_orders_autopost ON store_orders;
DROP FUNCTION IF EXISTS courier_settle_void(char, char, char, date), courier_settle(char, char, char, char, date, text, bigint, bigint, char, text), courier_receivable_report(), courier_receivable_rows(char),
  autopost_skip(bigint, char, text), autopost_run(char, char, integer), autopost_one(bigint, char), autopost_set(char, char, boolean), autopost_enqueue();
DROP TABLE IF EXISTS courier_settlements, autopost_queue, autopost_settings;
