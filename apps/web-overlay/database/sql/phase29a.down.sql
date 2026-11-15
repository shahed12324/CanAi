DROP FUNCTION IF EXISTS channel_account_for_webhook(char);
DELETE FROM channel_events WHERE account_id IN (SELECT id FROM channel_accounts WHERE channel = 'whatsapp');
DELETE FROM channel_links WHERE account_id IN (SELECT id FROM channel_accounts WHERE channel = 'whatsapp');
DELETE FROM channel_link_codes WHERE channel = 'whatsapp';
DELETE FROM channel_accounts WHERE channel = 'whatsapp';
ALTER TABLE channel_link_codes DROP CONSTRAINT IF EXISTS channel_link_codes_channel_check;
ALTER TABLE channel_link_codes ADD CONSTRAINT channel_link_codes_channel_check CHECK (channel IN ('telegram'));
ALTER TABLE channel_accounts DROP CONSTRAINT IF EXISTS channel_accounts_channel_check;
ALTER TABLE channel_accounts ADD CONSTRAINT channel_accounts_channel_check CHECK (channel IN ('telegram'));
