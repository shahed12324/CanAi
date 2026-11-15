-- Phase 29a: WhatsApp as a second messaging channel (owner talks to the AI by text or voice note).
-- Only widens two CHECK constraints and adds one read function; no data changes.

ALTER TABLE channel_accounts DROP CONSTRAINT IF EXISTS channel_accounts_channel_check;
ALTER TABLE channel_accounts ADD CONSTRAINT channel_accounts_channel_check CHECK (channel IN ('telegram', 'whatsapp'));
ALTER TABLE channel_link_codes DROP CONSTRAINT IF EXISTS channel_link_codes_channel_check;
ALTER TABLE channel_link_codes ADD CONSTRAINT channel_link_codes_channel_check CHECK (channel IN ('telegram', 'whatsapp'));

-- The webhook must be able to answer Meta's verification handshake while the account is still disabled, and it needs the
-- app secret to check the signature. Returns the encrypted credential bundle only; decrypted in PHP by the vault.
CREATE FUNCTION channel_account_for_webhook(p_account char(26)) RETURNS TABLE (channel text, status text, token_encrypted text, token_key_version integer)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
  SELECT channel, status, token_encrypted, token_key_version FROM channel_accounts WHERE id = p_account AND channel = 'whatsapp'
$$;
REVOKE ALL ON FUNCTION channel_account_for_webhook(char) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION channel_account_for_webhook(char) TO jarvis_app_rw;
