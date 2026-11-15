DROP TABLE IF EXISTS outreach_inbound CASCADE;
DROP FUNCTION IF EXISTS outreach_inbound_guard();
ALTER TABLE outreach_messages DROP COLUMN IF EXISTS thread_id, DROP COLUMN IF EXISTS provider_message_id;
DELETE FROM outreach_campaigns WHERE account_id IN (SELECT id FROM email_accounts WHERE provider <> 'smtp');
DELETE FROM email_accounts WHERE provider <> 'smtp';
ALTER TABLE email_accounts DROP CONSTRAINT IF EXISTS email_accounts_provider_fields;
ALTER TABLE email_accounts DROP COLUMN IF EXISTS inbox_checked_at, DROP COLUMN IF EXISTS last_error_at, DROP COLUMN IF EXISTS last_error, DROP COLUMN IF EXISTS refresh_ct, DROP COLUMN IF EXISTS provider;
ALTER TABLE email_accounts ALTER COLUMN secret_ct SET NOT NULL, ALTER COLUMN username SET NOT NULL, ALTER COLUMN port SET NOT NULL, ALTER COLUMN host SET NOT NULL;
