-- Phase 14b: taking orders in chat. The AI only extracts details; the flow state lives here so a restart or a second worker cannot lose or double it.
ALTER TABLE order_settings ADD COLUMN chat_orders boolean NOT NULL DEFAULT false;      -- the owner must switch chat ordering on; off = order requests go to a person (as in 13c)
GRANT UPDATE (chat_orders) ON order_settings TO jarvis_app_rw;

CREATE TABLE inbox_order_drafts (
  conversation_id char(26)      PRIMARY KEY,
  tenant_id       char(26)      NOT NULL REFERENCES tenants (id),
  state           text          NOT NULL DEFAULT 'collecting' CHECK (state IN ('collecting', 'awaiting_confirm')),
  items           jsonb         NOT NULL DEFAULT '[]'::jsonb CHECK (jsonb_typeof(items) = 'array' AND jsonb_array_length(items) <= 10),
  customer_name   varchar(120),
  phone           varchar(30),
  address         varchar(500),
  zone            text          CHECK (zone IN ('inside', 'outside')),
  summary_hash    char(16),                                       -- fingerprint of what the customer was shown; a confirmation only counts for this exact summary
  upsold          boolean       NOT NULL DEFAULT false,
  turns           smallint      NOT NULL DEFAULT 0 CHECK (turns BETWEEN 0 AND 100),
  updated_at      timestamptz   NOT NULL DEFAULT now(),
  FOREIGN KEY (conversation_id, tenant_id) REFERENCES inbox_conversations (id, tenant_id)
);
ALTER TABLE inbox_order_drafts ENABLE ROW LEVEL SECURITY;
ALTER TABLE inbox_order_drafts FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON inbox_order_drafts USING (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin())) WITH CHECK (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()));
REVOKE ALL ON inbox_order_drafts FROM jarvis_app_rw, jarvis_admin;
GRANT SELECT, INSERT, DELETE ON inbox_order_drafts TO jarvis_app_rw;
GRANT UPDATE (state, items, customer_name, phone, address, zone, summary_hash, upsold, turns, updated_at) ON inbox_order_drafts TO jarvis_app_rw;
GRANT SELECT ON inbox_order_drafts TO jarvis_admin;
