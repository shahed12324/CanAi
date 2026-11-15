DROP FUNCTION IF EXISTS genfile_delete(char, char, char);
DROP FUNCTION IF EXISTS genfile_save(char, char, char, text, text, text, jsonb);
DROP TABLE IF EXISTS generated_files;
-- usage_records is append-only: the old CHECK is put back as NOT VALID so existing 'docgen' rows stay.
ALTER TABLE usage_records DROP CONSTRAINT usage_records_source_check;
ALTER TABLE usage_records ADD CONSTRAINT usage_records_source_check CHECK (source IN
  ('chat', 'agent', 'workflow', 'image', 'video', 'voice', 'email', 'storage', 'render', 'catalog', 'bill_scan', 'inbox', 'analyst', 'memory', 'vision')) NOT VALID;
