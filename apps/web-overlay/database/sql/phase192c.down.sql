-- usage_records is append-only: the old CHECK is put back as NOT VALID so existing 'assist' rows stay.
ALTER TABLE usage_records DROP CONSTRAINT usage_records_source_check;
ALTER TABLE usage_records ADD CONSTRAINT usage_records_source_check CHECK (source IN
  ('chat', 'agent', 'workflow', 'image', 'video', 'voice', 'email', 'storage', 'render', 'catalog', 'bill_scan', 'inbox', 'analyst', 'memory', 'vision', 'docgen')) NOT VALID;
