-- Phase 19.2c: the writing assistant (meeting summaries, email summaries and drafts) is charged to the tenant like any other AI use, as source 'assist'. Nothing is stored.
ALTER TABLE usage_records DROP CONSTRAINT usage_records_source_check;
ALTER TABLE usage_records ADD CONSTRAINT usage_records_source_check CHECK (source IN
  ('chat', 'agent', 'workflow', 'image', 'video', 'voice', 'email', 'storage', 'render', 'catalog', 'bill_scan', 'inbox', 'analyst', 'memory', 'vision', 'docgen', 'assist'));
