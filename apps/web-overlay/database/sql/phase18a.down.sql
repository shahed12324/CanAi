DROP FUNCTION IF EXISTS audit_health(char), audit_item_seed(char, char), audit_item_undo(char, char, text), audit_item_done(char, char, char, text, date, text), audit_item_active(char, char, boolean),
  audit_item_save(char, char, char, text, integer), audit_review(char, char, char, text, text), audit_scan(char, char), audit_settings_save(char, char, integer, integer, integer, integer, bigint, bigint, bigint);
DROP TABLE IF EXISTS audit_item_done, audit_items, audit_findings, audit_settings;
