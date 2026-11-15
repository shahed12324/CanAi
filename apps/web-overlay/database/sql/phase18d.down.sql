DROP FUNCTION IF EXISTS analyst_report_save(char, char, char, char, jsonb, jsonb, text, text, text, text, integer, integer, numeric);
DROP TABLE IF EXISTS analyst_reports;
ALTER TABLE usage_records DROP CONSTRAINT usage_records_source_check;
-- NOT VALID: rows already written with the newer sources stay (usage_records is append-only).
ALTER TABLE usage_records ADD CONSTRAINT usage_records_source_check CHECK (source IN ('chat', 'agent', 'workflow', 'image', 'video', 'voice', 'email', 'storage', 'render')) NOT VALID;
