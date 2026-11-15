DROP FUNCTION IF EXISTS order_report_store(char, date, jsonb), order_report_calc(date), shipment_check_failed(char, text), shipment_apply(char, text, text), shipment_fail(char, text),
  shipment_booked(char, text, text, text), shipment_reserve(char, char, char, char), courier_credentials(char), courier_account_status(char, text), courier_account_save(char, char, text, text, smallint, jsonb);
DROP TABLE IF EXISTS order_reports, order_shipment_events, order_shipments, courier_accounts;
