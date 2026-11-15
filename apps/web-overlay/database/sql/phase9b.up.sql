-- Phase 9b: email outreach core (SMTP). Safety rules live in the database, not only in PHP:
--   * a message cannot be approved/sending/sent without an approver and a timestamp (CHECK),
--   * a suppressed address can never be queued, approved or sent (trigger),
--   * unsubscribe works without a login through one SECURITY DEFINER function and a 128-bit token.

CREATE TABLE email_accounts (
  id             char(26)     PRIMARY KEY,
  tenant_id      char(26)     NOT NULL REFERENCES tenants (id),
  name           varchar(80)  NOT NULL CHECK (length(btrim(name)) > 0),
  from_email     varchar(254) NOT NULL CHECK (from_email = lower(from_email) AND from_email ~ '^[^@\s]+@[^@\s]+\.[^@\s]+$'),
  from_name      varchar(120),
  host           varchar(253) NOT NULL CHECK (host = lower(host) AND host !~ '\s'),
  port           integer      NOT NULL CHECK (port BETWEEN 1 AND 65535),
  security       text         NOT NULL DEFAULT 'tls' CHECK (security IN ('tls', 'starttls')),   -- plaintext SMTP is not offered
  username       varchar(254) NOT NULL,
  secret_ct      text         NOT NULL,                                                         -- vault ciphertext, never the password
  key_version    smallint     NOT NULL DEFAULT 1,
  daily_limit    integer      NOT NULL DEFAULT 20 CHECK (daily_limit BETWEEN 1 AND 500),
  warmup_started_on date      NOT NULL DEFAULT current_date,
  status         text         NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'paused')),
  created_at     timestamptz  NOT NULL DEFAULT now(),
  updated_at     timestamptz  NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX email_accounts_tenant_from ON email_accounts (tenant_id, from_email);

CREATE TABLE outreach_campaigns (
  id                  char(26)     PRIMARY KEY,
  tenant_id           char(26)     NOT NULL REFERENCES tenants (id),
  account_id          char(26)     NOT NULL REFERENCES email_accounts (id),
  name                varchar(120) NOT NULL CHECK (length(btrim(name)) > 0),
  subject_tpl         varchar(200) NOT NULL CHECK (length(btrim(subject_tpl)) > 0),
  body_tpl            text         NOT NULL CHECK (length(btrim(body_tpl)) > 0 AND length(body_tpl) <= 20000),
  followup_after_days smallint     CHECK (followup_after_days BETWEEN 1 AND 30),
  followup_body_tpl   text         CHECK (followup_body_tpl IS NULL OR length(followup_body_tpl) <= 20000),
  status              text         NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'active', 'paused', 'archived')),
  created_by          char(26)     REFERENCES users (id),
  created_at          timestamptz  NOT NULL DEFAULT now(),
  updated_at          timestamptz  NOT NULL DEFAULT now(),
  CHECK (followup_after_days IS NULL OR followup_body_tpl IS NOT NULL)
);
CREATE INDEX outreach_campaigns_tenant ON outreach_campaigns (tenant_id, created_at DESC);

CREATE TABLE outreach_suppressions (
  tenant_id   char(26)     NOT NULL REFERENCES tenants (id),
  email       varchar(254) NOT NULL CHECK (email = lower(email)),
  reason      text         NOT NULL CHECK (reason IN ('unsubscribed', 'bounced', 'complaint', 'manual')),
  created_at  timestamptz  NOT NULL DEFAULT now(),
  PRIMARY KEY (tenant_id, email)
);

CREATE TABLE outreach_messages (
  id            char(26)     PRIMARY KEY,
  tenant_id     char(26)     NOT NULL REFERENCES tenants (id),
  campaign_id   char(26)     NOT NULL REFERENCES outreach_campaigns (id),
  lead_id       char(26)     REFERENCES leads (id),
  contact_id    char(26)     REFERENCES contacts (id),
  to_email      varchar(254) NOT NULL CHECK (to_email = lower(to_email) AND to_email ~ '^[^@\s]+@[^@\s]+\.[^@\s]+$'),
  step          smallint     NOT NULL DEFAULT 1 CHECK (step IN (1, 2)),                      -- 1 first mail, 2 follow-up
  subject       varchar(200) NOT NULL,
  body          text         NOT NULL CHECK (length(body) <= 20000),
  status        text         NOT NULL DEFAULT 'pending_approval'
                CHECK (status IN ('pending_approval', 'approved', 'rejected', 'sending', 'sent', 'failed', 'bounced', 'replied', 'cancelled')),
  approved_by   char(26)     REFERENCES users (id),
  approved_at   timestamptz,
  sent_at       timestamptz,
  error_code    varchar(40),
  attempts      smallint     NOT NULL DEFAULT 0,
  rfc_message_id varchar(200),
  unsub_token   char(32)     NOT NULL DEFAULT substr(replace(gen_random_uuid()::text || gen_random_uuid()::text, '-', ''), 1, 32) CHECK (unsub_token ~ '^[0-9a-f]{32}$'),
  created_at    timestamptz  NOT NULL DEFAULT now(),
  -- the core promise: nothing leaves without a named human approval
  CONSTRAINT outreach_needs_approval CHECK (status NOT IN ('approved', 'sending', 'sent', 'bounced', 'replied') OR (approved_by IS NOT NULL AND approved_at IS NOT NULL)),
  CONSTRAINT outreach_sent_has_time CHECK (status NOT IN ('sent', 'bounced', 'replied') OR sent_at IS NOT NULL)
);
CREATE UNIQUE INDEX outreach_messages_token ON outreach_messages (unsub_token);
CREATE UNIQUE INDEX outreach_messages_once ON outreach_messages (campaign_id, to_email, step);        -- the same person never gets the same step twice
CREATE INDEX outreach_messages_queue ON outreach_messages (tenant_id, status, created_at);
CREATE INDEX outreach_messages_sent ON outreach_messages (campaign_id, sent_at) WHERE sent_at IS NOT NULL;

-- Parents must belong to the same tenant, and a suppressed address can never move toward sending.
CREATE FUNCTION outreach_guard() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE t char(26); prev text := coalesce(current_setting('app.bypass_rls', true), ''); bad text := NULL; blocked boolean := false;
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  IF TG_TABLE_NAME = 'outreach_campaigns' THEN
    SELECT tenant_id INTO t FROM email_accounts WHERE id = NEW.account_id;
    IF t IS DISTINCT FROM NEW.tenant_id THEN bad := 'account'; END IF;
  ELSE
    SELECT tenant_id INTO t FROM outreach_campaigns WHERE id = NEW.campaign_id;
    IF t IS DISTINCT FROM NEW.tenant_id THEN bad := 'campaign'; END IF;
    IF bad IS NULL AND NEW.lead_id IS NOT NULL THEN
      SELECT tenant_id INTO t FROM leads WHERE id = NEW.lead_id;
      IF t IS DISTINCT FROM NEW.tenant_id THEN bad := 'lead'; END IF;
    END IF;
    IF bad IS NULL AND NEW.contact_id IS NOT NULL THEN
      SELECT tenant_id INTO t FROM contacts WHERE id = NEW.contact_id;
      IF t IS DISTINCT FROM NEW.tenant_id THEN bad := 'contact'; END IF;
    END IF;
    IF bad IS NULL AND NEW.status IN ('pending_approval', 'approved', 'sending') THEN
      SELECT EXISTS (SELECT 1 FROM outreach_suppressions WHERE tenant_id = NEW.tenant_id AND email = NEW.to_email) INTO blocked;
    END IF;
  END IF;
  PERFORM set_config('app.bypass_rls', prev, true);
  IF bad IS NOT NULL THEN RAISE EXCEPTION '% belongs to another tenant', bad; END IF;
  IF blocked THEN RAISE EXCEPTION 'address is suppressed' USING ERRCODE = 'P0001'; END IF;
  RETURN NEW;
END $$;
REVOKE ALL ON FUNCTION outreach_guard() FROM PUBLIC;
CREATE TRIGGER campaigns_guard BEFORE INSERT OR UPDATE OF account_id ON outreach_campaigns FOR EACH ROW EXECUTE FUNCTION outreach_guard();
CREATE TRIGGER messages_guard BEFORE INSERT OR UPDATE OF campaign_id, lead_id, contact_id, status, to_email ON outreach_messages FOR EACH ROW EXECUTE FUNCTION outreach_guard();

-- A bounce suppresses the address for good.
CREATE FUNCTION outreach_bounce_suppress() RETURNS trigger LANGUAGE plpgsql SET search_path = public, pg_temp AS $$
BEGIN
  INSERT INTO outreach_suppressions (tenant_id, email, reason) VALUES (NEW.tenant_id, NEW.to_email, 'bounced') ON CONFLICT DO NOTHING;
  RETURN NEW;
END $$;
CREATE TRIGGER messages_bounce AFTER UPDATE OF status ON outreach_messages FOR EACH ROW WHEN (NEW.status = 'bounced' AND OLD.status IS DISTINCT FROM 'bounced') EXECUTE FUNCTION outreach_bounce_suppress();

-- Public unsubscribe: no session, no tenant context. Knows only the token; returns whether it matched.
CREATE FUNCTION outreach_unsubscribe(p_token text) RETURNS boolean LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE m outreach_messages%ROWTYPE; prev text := coalesce(current_setting('app.bypass_rls', true), '');
BEGIN
  IF p_token IS NULL OR p_token !~ '^[0-9a-f]{32}$' THEN RETURN false; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO m FROM outreach_messages WHERE unsub_token = p_token;
  IF NOT FOUND THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN false; END IF;
  INSERT INTO outreach_suppressions (tenant_id, email, reason) VALUES (m.tenant_id, m.to_email, 'unsubscribed') ON CONFLICT DO NOTHING;
  UPDATE outreach_messages SET status = 'cancelled', error_code = 'unsubscribed'
   WHERE tenant_id = m.tenant_id AND to_email = m.to_email AND status IN ('pending_approval', 'approved');
  UPDATE contacts SET email_status = 'unsubscribed' WHERE tenant_id = m.tenant_id AND email = m.to_email;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN true;
END $$;
REVOKE ALL ON FUNCTION outreach_unsubscribe(text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION outreach_unsubscribe(text) TO jarvis_app_rw;

-- Outreach events show up in the lead's history.
ALTER TABLE lead_activities DROP CONSTRAINT lead_activities_kind_check;
ALTER TABLE lead_activities ADD CONSTRAINT lead_activities_kind_check CHECK (kind IN ('created', 'note', 'stage', 'task', 'follow_up', 'tag', 'import', 'proposal', 'email'));

DO $$
DECLARE t text;
BEGIN
  FOREACH t IN ARRAY ARRAY['email_accounts', 'outreach_campaigns', 'outreach_suppressions', 'outreach_messages'] LOOP
    EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', t);
    EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY', t);
    EXECUTE format('CREATE POLICY tenant_isolation ON %I USING (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()))
                    WITH CHECK (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()))', t);
  END LOOP;
END $$;

REVOKE ALL ON email_accounts, outreach_campaigns, outreach_suppressions, outreach_messages FROM jarvis_app_rw, jarvis_admin;
GRANT SELECT, INSERT, UPDATE ON email_accounts, outreach_campaigns, outreach_messages TO jarvis_app_rw;
GRANT SELECT, INSERT ON outreach_suppressions TO jarvis_app_rw;        -- a suppression is never edited or deleted by the app
GRANT SELECT ON email_accounts, outreach_campaigns, outreach_suppressions, outreach_messages TO jarvis_admin;
