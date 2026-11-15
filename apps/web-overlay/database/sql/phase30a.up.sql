-- phase30a: shop profile (name, address, logo), support tickets, admin broadcasts.

CREATE TABLE shop_profiles (
  tenant_id   char(26)     PRIMARY KEY REFERENCES tenants (id),
  shop_name   varchar(150) NOT NULL CHECK (length(btrim(shop_name)) > 0),
  address     varchar(500),
  city        varchar(100),
  country     varchar(100),
  phone       varchar(32)  CHECK (phone IS NULL OR phone ~ '^[0-9+()\- ]{5,32}$'),
  email       varchar(254),
  website     varchar(200) CHECK (website IS NULL OR website ~ '^https?://'),
  description varchar(1000),
  logo_path   varchar(200),
  logo_mime   varchar(20)  CHECK (logo_mime IS NULL OR logo_mime IN ('image/png', 'image/jpeg', 'image/webp')),
  updated_at  timestamptz  NOT NULL DEFAULT now(),
  CHECK ((logo_path IS NULL) = (logo_mime IS NULL))
);

CREATE TABLE support_tickets (
  id              char(26)     PRIMARY KEY,
  tenant_id       char(26)     NOT NULL REFERENCES tenants (id),
  user_id         char(26)     NOT NULL REFERENCES users (id),
  subject         varchar(200) NOT NULL CHECK (length(btrim(subject)) > 0),
  category        text         NOT NULL DEFAULT 'other' CHECK (category IN ('billing', 'technical', 'account', 'other')),
  priority        text         NOT NULL DEFAULT 'normal' CHECK (priority IN ('low', 'normal', 'high')),
  status          text         NOT NULL DEFAULT 'open' CHECK (status IN ('open', 'pending', 'solved', 'closed')),
  assigned_to     char(26)     REFERENCES users (id),
  created_at      timestamptz  NOT NULL DEFAULT now(),
  updated_at      timestamptz  NOT NULL DEFAULT now(),
  last_message_at timestamptz  NOT NULL DEFAULT now(),
  closed_at       timestamptz
);
CREATE INDEX support_tickets_tenant ON support_tickets (tenant_id, last_message_at DESC);
CREATE INDEX support_tickets_status ON support_tickets (status, last_message_at DESC);

-- Append-only conversation: a reply is never edited or removed.
CREATE TABLE support_messages (
  id          char(26)    PRIMARY KEY,
  ticket_id   char(26)    NOT NULL REFERENCES support_tickets (id),
  tenant_id   char(26)    NOT NULL REFERENCES tenants (id),
  author_kind text        NOT NULL CHECK (author_kind IN ('shop', 'staff', 'ai')),
  author_id   char(26)    REFERENCES users (id),
  body        text        NOT NULL CHECK (length(btrim(body)) > 0 AND length(body) <= 5000),
  created_at  timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX support_messages_ticket ON support_messages (ticket_id, created_at);
CREATE TRIGGER support_messages_append_only BEFORE UPDATE OR DELETE ON support_messages
  FOR EACH ROW EXECUTE FUNCTION reject_mutation();

-- A shop can only ever write as "shop"; staff and AI replies come from the admin connection.
CREATE FUNCTION support_messages_author_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF NEW.author_kind <> 'shop' AND NOT (SELECT app_is_admin()) THEN
    RAISE EXCEPTION 'only staff may reply as staff' USING ERRCODE = '42501';
  END IF;
  RETURN NEW;
END
$$;
CREATE TRIGGER support_messages_author_trg BEFORE INSERT ON support_messages
  FOR EACH ROW EXECUTE FUNCTION support_messages_author_guard();

-- Platform announcements to shops (each shop member also gets an inbox_notifications row).
CREATE TABLE admin_broadcasts (
  id          char(26)     PRIMARY KEY,
  title       varchar(160) NOT NULL CHECK (length(btrim(title)) > 0),
  body        varchar(500) NOT NULL DEFAULT '',
  audience    text         NOT NULL CHECK (audience IN ('all', 'tenant', 'plan')),
  audience_ref varchar(64),
  recipients  integer      NOT NULL DEFAULT 0,
  created_by  char(26)     REFERENCES users (id),
  created_at  timestamptz  NOT NULL DEFAULT now()
);

DO $$
DECLARE t text;
BEGIN
  FOREACH t IN ARRAY ARRAY['shop_profiles', 'support_tickets', 'support_messages'] LOOP
    EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', t);
    EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY', t);
    EXECUTE format('CREATE POLICY tenant_isolation ON %I USING (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()))
                    WITH CHECK (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()))', t);
    EXECUTE format('REVOKE ALL ON %I FROM jarvis_app_rw, jarvis_admin', t);
  END LOOP;
  ALTER TABLE admin_broadcasts ENABLE ROW LEVEL SECURITY;
  ALTER TABLE admin_broadcasts FORCE ROW LEVEL SECURITY;
  CREATE POLICY admin_only ON admin_broadcasts USING ((SELECT app_is_admin())) WITH CHECK ((SELECT app_is_admin()));
  REVOKE ALL ON admin_broadcasts FROM jarvis_app_rw, jarvis_admin;
END $$;

GRANT SELECT, INSERT, UPDATE ON shop_profiles TO jarvis_app_rw;
GRANT SELECT, UPDATE ON shop_profiles TO jarvis_admin;
GRANT SELECT, INSERT ON support_tickets TO jarvis_app_rw;
GRANT UPDATE (status, updated_at, last_message_at, closed_at) ON support_tickets TO jarvis_app_rw;
GRANT SELECT, INSERT, UPDATE ON support_tickets TO jarvis_admin;
GRANT SELECT, INSERT ON support_messages TO jarvis_app_rw, jarvis_admin;
GRANT SELECT, INSERT ON admin_broadcasts TO jarvis_admin;
