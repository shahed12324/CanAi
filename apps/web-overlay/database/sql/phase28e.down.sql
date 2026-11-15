DROP TRIGGER IF EXISTS messages_proposal_guard ON outreach_messages;
DROP FUNCTION IF EXISTS outreach_proposal_guard();
ALTER TABLE outreach_messages DROP CONSTRAINT IF EXISTS outreach_proposal_pair;
ALTER TABLE outreach_messages DROP COLUMN IF EXISTS proposal_stamp, DROP COLUMN IF EXISTS proposal_id;
ALTER TABLE outreach_campaigns DROP COLUMN IF EXISTS attach_proposal, DROP COLUMN IF EXISTS whatsapp_button;
ALTER TABLE email_accounts DROP CONSTRAINT IF EXISTS email_accounts_imap;
ALTER TABLE email_accounts DROP COLUMN IF EXISTS imap_security, DROP COLUMN IF EXISTS imap_port, DROP COLUMN IF EXISTS imap_host;
