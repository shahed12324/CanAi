-- Phase 28e: Phase 9 leftovers.
--  * IMAP: a plain SMTP account can also name its IMAP server, so replies and bounces are found without Gmail/Graph (same login as SMTP).
--  * WhatsApp button: a campaign can add a "talk on WhatsApp" link (built from the default brand kit's phone) to its mails.
--  * Proposal attachment: a campaign can attach the lead's own proposal as a PDF.  The mail is pinned to the exact proposal version
--    (proposal_stamp = updated_at when it was queued); if the proposal changes before sending, the sender refuses (PHP) - the approver approved THAT version.

ALTER TABLE email_accounts ADD COLUMN imap_host varchar(253), ADD COLUMN imap_port integer, ADD COLUMN imap_security text;
ALTER TABLE email_accounts ADD CONSTRAINT email_accounts_imap CHECK (
  (imap_host IS NULL AND imap_port IS NULL AND imap_security IS NULL)
  OR (provider = 'smtp' AND imap_host IS NOT NULL AND imap_port IS NOT NULL AND imap_security IS NOT NULL AND imap_host = lower(imap_host) AND btrim(imap_host) <> '' AND imap_host !~ '\s' AND imap_port BETWEEN 1 AND 65535 AND imap_security IN ('tls', 'starttls')));

ALTER TABLE outreach_campaigns ADD COLUMN whatsapp_button boolean NOT NULL DEFAULT false, ADD COLUMN attach_proposal boolean NOT NULL DEFAULT false;

ALTER TABLE outreach_messages ADD COLUMN proposal_id char(26) REFERENCES proposals (id), ADD COLUMN proposal_stamp timestamptz;
ALTER TABLE outreach_messages ADD CONSTRAINT outreach_proposal_pair CHECK ((proposal_id IS NULL) = (proposal_stamp IS NULL));

-- A mail may only carry a proposal of its own workspace that belongs to the very lead the mail goes to (never another client's proposal).
CREATE FUNCTION outreach_proposal_guard() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); ok boolean;
BEGIN
  IF NEW.proposal_id IS NULL THEN RETURN NEW; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT EXISTS (SELECT 1 FROM proposals p WHERE p.id = NEW.proposal_id AND p.tenant_id = NEW.tenant_id AND p.lead_id IS NOT NULL AND p.lead_id = NEW.lead_id AND p.deleted_at IS NULL) INTO ok;
  PERFORM set_config('app.bypass_rls', prev, true);
  IF NOT ok THEN RAISE EXCEPTION 'proposal does not belong to this lead' USING ERRCODE = '23514'; END IF;
  RETURN NEW;
END $$;
REVOKE ALL ON FUNCTION outreach_proposal_guard() FROM PUBLIC;
CREATE TRIGGER messages_proposal_guard BEFORE INSERT OR UPDATE OF proposal_id, lead_id, tenant_id ON outreach_messages FOR EACH ROW EXECUTE FUNCTION outreach_proposal_guard();
