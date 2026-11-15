DROP FUNCTION IF EXISTS bill_scan_post(char, char, char, char, text, char, date, date, text, jsonb, bigint, char), bill_scan_discard(char, char), bill_scan_fail(char, text, text, integer, integer, numeric),
  bill_scan_ready(char, jsonb, text[], text, integer, integer, numeric), bill_scan_start(char, char, char, text, text, integer, text);
DROP TABLE IF EXISTS bill_scans;
