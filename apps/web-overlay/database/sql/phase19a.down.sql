DROP FUNCTION IF EXISTS memory_suggestion_dismiss(char, char, char);
DROP FUNCTION IF EXISTS memory_suggestion_accept(char, char, char, char);
DROP FUNCTION IF EXISTS memory_suggest_save(char, char, char, jsonb, text[]);
DROP FUNCTION IF EXISTS memory_setting_save(char, char, boolean);
DROP FUNCTION IF EXISTS memory_clear(char, char);
DROP FUNCTION IF EXISTS memory_delete(char, char, char, boolean);
DROP FUNCTION IF EXISTS memory_update(char, char, char, boolean, text, text, boolean, boolean);
DROP FUNCTION IF EXISTS memory_add(char, char, char, text, text, text, boolean, text, char);
DROP FUNCTION IF EXISTS memory_check_text(text);
DROP TABLE IF EXISTS memory_suggestions, memory_settings, memories;
ALTER TABLE usage_records DROP CONSTRAINT usage_records_source_check;
ALTER TABLE usage_records ADD CONSTRAINT usage_records_source_check CHECK (source IN
  ('chat', 'agent', 'workflow', 'image', 'video', 'voice', 'email', 'storage', 'render', 'catalog', 'bill_scan', 'inbox', 'analyst')) NOT VALID;
