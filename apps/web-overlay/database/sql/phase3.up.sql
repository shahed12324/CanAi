-- Phase 3: plans, features/entitlements, feature flags, system settings.
-- Reads are open to the app role; writes to catalog/config tables need the jarvis_admin group
-- (the admin panel's separate DB login), so a bug in the normal app cannot change plans.

CREATE TABLE plans (
  id                    char(26)      PRIMARY KEY,
  code                  varchar(32)   NOT NULL UNIQUE CHECK (code ~ '^[a-z][a-z0-9_]{1,31}$'),
  name                  varchar(100)  NOT NULL,
  description           text,
  is_lifetime_free      boolean       NOT NULL DEFAULT false,
  is_public             boolean       NOT NULL DEFAULT true,
  trial_days            integer       NOT NULL DEFAULT 0 CHECK (trial_days >= 0),
  included_credits      numeric(20,4) NOT NULL DEFAULT 0 CHECK (included_credits >= 0),
  monthly_credit_refill boolean       NOT NULL DEFAULT false,
  sort                  integer       NOT NULL DEFAULT 0,
  status                text          NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'hidden', 'retired')),
  created_at            timestamptz   NOT NULL DEFAULT now(),
  updated_at            timestamptz   NOT NULL DEFAULT now()
);

CREATE TABLE plan_prices (
  plan_id  char(26)      NOT NULL REFERENCES plans (id) ON DELETE CASCADE,
  currency char(3)       NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
  interval text          NOT NULL CHECK (interval IN ('month', 'year')),
  amount   numeric(20,4) NOT NULL CHECK (amount >= 0),
  PRIMARY KEY (plan_id, currency, interval)
);

CREATE TABLE features (
  key         varchar(100) PRIMARY KEY CHECK (key ~ '^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$'),
  category    varchar(50)  NOT NULL,
  type        text         NOT NULL CHECK (type IN ('boolean', 'limit', 'meter')),
  description text,
  created_at  timestamptz  NOT NULL DEFAULT now(),
  updated_at  timestamptz  NOT NULL DEFAULT now()
);

CREATE TABLE plan_features (
  plan_id     char(26)      NOT NULL REFERENCES plans (id) ON DELETE CASCADE,
  feature_key varchar(100)  NOT NULL REFERENCES features (key) ON UPDATE CASCADE ON DELETE RESTRICT,
  enabled     boolean       NOT NULL DEFAULT true,
  limit_value numeric(20,4) CHECK (limit_value IS NULL OR limit_value >= 0),   -- NULL = unlimited
  PRIMARY KEY (plan_id, feature_key)
);

CREATE TABLE tenant_feature_overrides (
  tenant_id   char(26)      NOT NULL REFERENCES tenants (id),
  feature_key varchar(100)  NOT NULL REFERENCES features (key) ON UPDATE CASCADE ON DELETE RESTRICT,
  enabled     boolean       NOT NULL,
  limit_value numeric(20,4) CHECK (limit_value IS NULL OR limit_value >= 0),
  reason      text          NOT NULL CHECK (length(btrim(reason)) > 0),
  set_by      char(26)      NOT NULL REFERENCES users (id),
  created_at  timestamptz   NOT NULL DEFAULT now(),
  updated_at  timestamptz   NOT NULL DEFAULT now(),
  PRIMARY KEY (tenant_id, feature_key)
);

CREATE TABLE subscriptions (
  id                   char(26)    PRIMARY KEY,
  tenant_id            char(26)    NOT NULL REFERENCES tenants (id),
  plan_id              char(26)    NOT NULL REFERENCES plans (id),
  status               text        NOT NULL CHECK (status IN ('active', 'trialing', 'past_due', 'grace', 'cancelled', 'expired')),
  interval             text        CHECK (interval IN ('month', 'year')),
  current_period_start timestamptz,
  current_period_end   timestamptz,
  cancel_at            timestamptz,
  grace_ends_at        timestamptz,
  gateway              varchar(32),
  gateway_ref          varchar(128),
  created_at           timestamptz NOT NULL DEFAULT now(),
  updated_at           timestamptz NOT NULL DEFAULT now()
);
-- One live subscription per tenant.
CREATE UNIQUE INDEX subscriptions_one_live ON subscriptions (tenant_id)
  WHERE status IN ('active', 'trialing', 'past_due', 'grace');
CREATE INDEX subscriptions_plan_idx ON subscriptions (plan_id);

CREATE TABLE feature_flags (
  key         varchar(100) PRIMARY KEY CHECK (key ~ '^[a-z][a-z0-9_.]*$'),
  enabled     boolean      NOT NULL DEFAULT false,
  rollout     jsonb        NOT NULL DEFAULT '{}'::jsonb,
  description text,
  updated_by  char(26)     REFERENCES users (id),
  created_at  timestamptz  NOT NULL DEFAULT now(),
  updated_at  timestamptz  NOT NULL DEFAULT now()
);

CREATE TABLE system_settings (
  key          varchar(100) PRIMARY KEY CHECK (key ~ '^[a-z][a-z0-9_.]*$'),
  value        jsonb        NOT NULL,
  is_encrypted boolean      NOT NULL DEFAULT false,   -- value is then an app-level ciphertext string
  updated_by   char(26)     REFERENCES users (id),
  created_at   timestamptz  NOT NULL DEFAULT now(),
  updated_at   timestamptz  NOT NULL DEFAULT now()
);

ALTER TABLE tenant_feature_overrides ENABLE ROW LEVEL SECURITY;
ALTER TABLE tenant_feature_overrides FORCE  ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON tenant_feature_overrides
  USING      (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()))
  WITH CHECK (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()));

ALTER TABLE subscriptions ENABLE ROW LEVEL SECURITY;
ALTER TABLE subscriptions FORCE  ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON subscriptions
  USING      (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()))
  WITH CHECK (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()));

-- Effective entitlement of one feature for one tenant. Precedence:
--   1. tenant override   2. plan of the live subscription (else the 'free' plan)   3. not available
-- Runs with the caller's rights, so under a tenant context it only ever sees that tenant's rows.
-- An unlisted feature is NOT available (enabled=false, limit 0): fail closed.
CREATE FUNCTION effective_feature(p_tenant char(26), p_key text)
RETURNS TABLE (enabled boolean, limit_value numeric, source text)
LANGUAGE sql STABLE AS $$
  SELECT r.enabled, r.limit_value, r.source FROM (
    SELECT o.enabled, o.limit_value, 'override'::text AS source, 1 AS rnk
      FROM tenant_feature_overrides o
     WHERE o.tenant_id = p_tenant AND o.feature_key = p_key
    UNION ALL
    SELECT pf.enabled, pf.limit_value, 'plan'::text, 2
      FROM plan_features pf
     WHERE pf.feature_key = p_key
       AND pf.plan_id = COALESCE(
             (SELECT s.plan_id FROM subscriptions s
               WHERE s.tenant_id = p_tenant
                 AND s.status IN ('active', 'trialing', 'past_due', 'grace')
                 AND (s.status IN ('past_due', 'grace') OR s.current_period_end IS NULL OR s.current_period_end > now())
               LIMIT 1),
             (SELECT p.id FROM plans p WHERE p.code = 'free'))
    UNION ALL
    SELECT false, 0::numeric, 'none'::text, 3
  ) r
  ORDER BY r.rnk
  LIMIT 1
$$;

-- Catalog/config: app reads, only the admin group writes.
REVOKE INSERT, UPDATE, DELETE ON plans, plan_prices, features, plan_features,
  tenant_feature_overrides, feature_flags, system_settings FROM jarvis_app_rw;
GRANT SELECT ON plans, plan_prices, features, plan_features,
  tenant_feature_overrides, feature_flags, system_settings TO jarvis_app_rw;
GRANT INSERT, UPDATE, DELETE ON plans, plan_prices, features, plan_features,
  tenant_feature_overrides, feature_flags, system_settings TO jarvis_admin;
-- subscriptions: written by the app (payment flow, Phase 10) under tenant context, and by admins.
