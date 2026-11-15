-- Phase 9c: Gmail / Microsoft Graph accounts (OAuth) and automatic reply / bounce detection.

ALTER TABLE email_accounts ADD COLUMN provider text NOT NULL DEFAULT 'smtp' CHECK (provider IN ('smtp', 'gmail', 'graph'));
ALTER TABLE email_accounts ALTER COLUMN host DROP NOT NULL;
ALTER TABLE email_accounts ALTER COLUMN port DROP NOT NULL;
ALTER TABLE email_accounts ALTER COLUMN username DROP NOT NULL;
ALTER TABLE email_accounts ALTER COLUMN secret_ct DROP NOT NULL;
ALTER TABLE email_accounts ADD COLUMN refresh_ct text;                       -- vault ciphertext of the OAuth refresh token (access tokens are never stored)
ALTER TABLE email_accounts ADD COLUMN last_error varchar(40);                -- why the account stopped working, shown in the UI
ALTER TABLE email_accounts ADD COLUMN last_error_at timestamptz;
ALTER TABLE email_accounts ADD COLUMN inbox_checked_at timestamptz;          -- replies/bounces are looked for after this moment
ALTER TABLE email_accounts ADD CONSTRAINT email_accounts_provider_fields CHECK (
  (provider = 'smtp' AND host IS NOT NULL AND port IS NOT NULL AND username IS NOT NULL AND secret_ct IS NOT NULL)
  OR (provider <> 'smtp' AND refresh_ct IS NOT NULL));

ALTER TABLE outreach_messages ADD COLUMN provider_message_id varchar(200);
ALTER TABLE outreach_messages ADD COLUMN thread_id varchar(200);

-- Every inbound mail is looked at once: (account, provider id) is the key, so a repeated poll changes nothing.
CREATE TABLE outreach_inbound (
  account_id        char(26)     NOT NULL REFERENCES email_accounts (id),
  provider_msg_id   varchar(200) NOT NULL,
  tenant_id         char(26)     NOT NULL REFERENCES tenants (id),
  kind              text         NOT NULL CHECK (kind IN ('reply', 'bounce', 'auto', 'other')),
  message_id        char(26)     REFERENCES outreach_messages (id),
  received_at       timestamptz,
  created_at        timestamptz  NOT NULL DEFAULT now(),
  PRIMARY KEY (account_id, provider_msg_id)
);
CREATE INDEX outreach_inbound_recent ON outreach_inbound (tenant_id, created_at DESC);

CREATE FUNCTION outreach_inbound_guard() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE t char(26); prev text := coalesce(current_setting('app.bypass_rls', true), ''); bad boolean := false;
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT tenant_id INTO t FROM email_accounts WHERE id = NEW.account_id;
  IF t IS DISTINCT FROM NEW.tenant_id THEN bad := true; END IF;
  IF NOT bad AND NEW.message_id IS NOT NULL THEN
    SELECT tenant_id INTO t FROM outreach_messages WHERE id = NEW.message_id;
    IF t IS DISTINCT FROM NEW.tenant_id THEN bad := true; END IF;
  END IF;
  PERFORM set_config('app.bypass_rls', prev, true);
  IF bad THEN RAISE EXCEPTION 'inbound row belongs to another tenant'; END IF;
  RETURN NEW;
END $$;
REVOKE ALL ON FUNCTION outreach_inbound_guard() FROM PUBLIC;
CREATE TRIGGER outreach_inbound_same_tenant BEFORE INSERT ON outreach_inbound FOR EACH ROW EXECUTE FUNCTION outreach_inbound_guard();

ALTER TABLE outreach_inbound ENABLE ROW LEVEL SECURITY;
ALTER TABLE outreach_inbound FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON outreach_inbound USING (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()))
  WITH CHECK (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()));
REVOKE ALL ON outreach_inbound FROM jarvis_app_rw, jarvis_admin;
GRANT SELECT, INSERT ON outreach_inbound TO jarvis_app_rw;
GRANT SELECT ON outreach_inbound TO jarvis_admin;
