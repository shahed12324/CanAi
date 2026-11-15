-- Phase 4: AI providers, credentials, models, capabilities, pricing, access rules, routing, health.
-- Catalog tables are global: the app login can read them, only the admin group writes.
-- provider_credentials.secret_encrypted is NOT readable by the app role at all (column privileges);
-- the single read path is provider_secret_for_call(), so there is exactly one place to audit.

CREATE TABLE ai_providers (
  id                   char(26)     PRIMARY KEY,
  name                 varchar(100) NOT NULL UNIQUE,
  adapter              text         NOT NULL CHECK (adapter IN
                         ('openai', 'anthropic', 'gemini', 'openrouter', 'groq', 'ollama', 'openai_compatible')),
  type                 text         NOT NULL DEFAULT 'llm' CHECK (type IN ('llm', 'voice', 'image', 'video', 'embedding')),
  base_url             text         CHECK (base_url IS NULL OR base_url ~ '^https?://'),
  auth_type            text         NOT NULL DEFAULT 'bearer' CHECK (auth_type IN ('bearer', 'header', 'none')),
  timeout_ms           integer      NOT NULL DEFAULT 60000 CHECK (timeout_ms BETWEEN 1000 AND 300000),
  retry_policy         jsonb        NOT NULL DEFAULT '{}'::jsonb,
  rate_limits          jsonb        NOT NULL DEFAULT '{}'::jsonb,
  priority             integer      NOT NULL DEFAULT 100,
  fallback_provider_id char(26)     REFERENCES ai_providers (id) ON DELETE SET NULL,
  allow_private        boolean      NOT NULL DEFAULT false,   -- may point at localhost/private hosts (Ollama)
  status               text         NOT NULL DEFAULT 'disabled' CHECK (status IN ('active', 'disabled', 'degraded')),
  created_at           timestamptz  NOT NULL DEFAULT now(),
  updated_at           timestamptz  NOT NULL DEFAULT now(),
  CHECK (fallback_provider_id IS NULL OR fallback_provider_id <> id)
);

-- A fallback chain must never loop (A -> B -> A would retry forever).
CREATE FUNCTION ai_providers_no_cycle() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE cur char(26) := NEW.fallback_provider_id; hops integer := 0;
BEGIN
  WHILE cur IS NOT NULL LOOP
    IF cur = NEW.id THEN RAISE EXCEPTION 'fallback_cycle' USING ERRCODE = '23514'; END IF;
    hops := hops + 1;
    IF hops > 20 THEN RAISE EXCEPTION 'fallback_chain_too_long' USING ERRCODE = '23514'; END IF;
    SELECT fallback_provider_id INTO cur FROM ai_providers WHERE id = cur;
  END LOOP;
  RETURN NEW;
END
$$;
CREATE TRIGGER ai_providers_no_cycle BEFORE INSERT OR UPDATE OF fallback_provider_id ON ai_providers
  FOR EACH ROW EXECUTE FUNCTION ai_providers_no_cycle();

CREATE TABLE provider_credentials (
  id              char(26)    PRIMARY KEY,
  provider_id     char(26)    NOT NULL REFERENCES ai_providers (id) ON DELETE CASCADE,
  label           varchar(100) NOT NULL,
  secret_encrypted text       NOT NULL CHECK (length(secret_encrypted) > 0),   -- app-level ciphertext only
  key_version     integer     NOT NULL DEFAULT 1,
  last_rotated_at timestamptz,
  status          text        NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'revoked')),
  created_at      timestamptz NOT NULL DEFAULT now()
);
-- At most one active credential per provider (rotation = revoke old, add new).
CREATE UNIQUE INDEX provider_credentials_one_active ON provider_credentials (provider_id) WHERE status = 'active';

CREATE TABLE ai_models (
  id             char(26)     PRIMARY KEY,
  provider_id    char(26)     NOT NULL REFERENCES ai_providers (id) ON DELETE RESTRICT,
  model_key      varchar(100) NOT NULL CHECK (model_key ~ '^[A-Za-z0-9][A-Za-z0-9._:/-]{0,99}$'),
  display_name   varchar(150) NOT NULL,
  category       text         NOT NULL DEFAULT 'chat' CHECK (category IN
                   ('chat', 'reasoning', 'embedding', 'image', 'video', 'speech', 'transcription')),
  is_local       boolean      NOT NULL DEFAULT false,
  is_free_tier   boolean      NOT NULL DEFAULT false,
  context_window integer      CHECK (context_window IS NULL OR context_window > 0),
  max_output     integer      CHECK (max_output IS NULL OR max_output > 0),
  quality_rank   integer      NOT NULL DEFAULT 50 CHECK (quality_rank BETWEEN 1 AND 100),
  status         text         NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'hidden', 'deprecated')),
  deprecated_at  timestamptz,
  created_at     timestamptz  NOT NULL DEFAULT now(),
  updated_at     timestamptz  NOT NULL DEFAULT now(),
  UNIQUE (provider_id, model_key)
);

CREATE TABLE model_capabilities (
  model_id   char(26) NOT NULL REFERENCES ai_models (id) ON DELETE CASCADE,
  capability text     NOT NULL CHECK (capability IN
               ('text', 'vision', 'audio_in', 'audio_out', 'image_gen', 'video_gen', 'embedding', 'tool_use', 'json_mode')),
  PRIMARY KEY (model_id, capability)
);

-- Price history is insert-only: past usage must stay explainable. A change is a new row.
CREATE TABLE model_pricing (
  model_id          char(26)      NOT NULL REFERENCES ai_models (id) ON DELETE RESTRICT,
  effective_from    timestamptz   NOT NULL DEFAULT now(),
  input_per_1m      numeric(20,6) CHECK (input_per_1m  IS NULL OR input_per_1m  >= 0),
  output_per_1m     numeric(20,6) CHECK (output_per_1m IS NULL OR output_per_1m >= 0),
  per_request       numeric(20,6) CHECK (per_request   IS NULL OR per_request   >= 0),
  per_image         numeric(20,6) CHECK (per_image     IS NULL OR per_image     >= 0),
  per_second        numeric(20,6) CHECK (per_second    IS NULL OR per_second    >= 0),
  currency          char(3)       NOT NULL DEFAULT 'USD' CHECK (currency ~ '^[A-Z]{3}$'),
  credit_multiplier numeric(10,4) NOT NULL DEFAULT 1 CHECK (credit_multiplier > 0),
  set_by            char(26)      REFERENCES users (id),
  PRIMARY KEY (model_id, effective_from)
);
CREATE TRIGGER model_pricing_append_only BEFORE UPDATE OR DELETE ON model_pricing
  FOR EACH ROW EXECUTE FUNCTION reject_mutation();

CREATE VIEW model_current_pricing AS
  SELECT DISTINCT ON (model_id) model_id, effective_from, input_per_1m, output_per_1m, per_request,
         per_image, per_second, currency, credit_multiplier
    FROM model_pricing WHERE effective_from <= now()
   ORDER BY model_id, effective_from DESC;

CREATE TABLE model_access_rules (
  model_id char(26) NOT NULL REFERENCES ai_models (id) ON DELETE CASCADE,
  plan_id  char(26) NOT NULL REFERENCES plans (id) ON DELETE CASCADE,
  allowed  boolean  NOT NULL,
  PRIMARY KEY (model_id, plan_id)
);

CREATE TABLE routing_rules (
  id                 char(26)    PRIMARY KEY,
  task_type          varchar(50) NOT NULL,
  mode               text        NOT NULL CHECK (mode IN ('cheapest', 'fastest', 'best_quality', 'balanced', 'manual')),
  preferred_model_ids jsonb      NOT NULL DEFAULT '[]'::jsonb CHECK (jsonb_typeof(preferred_model_ids) = 'array'),
  conditions         jsonb       NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(conditions) = 'object'),
  priority           integer     NOT NULL DEFAULT 100,
  status             text        NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'disabled')),
  created_at         timestamptz NOT NULL DEFAULT now(),
  updated_at         timestamptz NOT NULL DEFAULT now()
);

-- Telemetry (not audit): inserted by the health job, never edited; only admins may purge old rows.
CREATE TABLE provider_health_checks (
  id          bigint      GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  provider_id char(26)    NOT NULL REFERENCES ai_providers (id) ON DELETE CASCADE,
  model_id    char(26)    REFERENCES ai_models (id) ON DELETE SET NULL,
  status      text        NOT NULL CHECK (status IN ('ok', 'failed')),
  latency_ms  integer     CHECK (latency_ms IS NULL OR latency_ms >= 0),
  error_code  varchar(40),
  checked_at  timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX provider_health_idx ON provider_health_checks (provider_id, checked_at DESC);

CREATE TABLE tenant_model_preferences (
  tenant_id        char(26)    PRIMARY KEY REFERENCES tenants (id),
  default_model_id char(26)    REFERENCES ai_models (id) ON DELETE SET NULL,
  routing_mode     text        NOT NULL DEFAULT 'balanced' CHECK (routing_mode IN
                     ('cheapest', 'fastest', 'best_quality', 'balanced', 'manual')),
  updated_at       timestamptz NOT NULL DEFAULT now()
);
ALTER TABLE tenant_model_preferences ENABLE ROW LEVEL SECURITY;
ALTER TABLE tenant_model_preferences FORCE  ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON tenant_model_preferences
  USING      (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()))
  WITH CHECK (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()));

-- The plan a tenant is effectively on (same rule as effective_feature in phase 3).
CREATE FUNCTION tenant_plan_id(p_tenant char(26)) RETURNS char(26)
LANGUAGE sql STABLE AS $$
  SELECT COALESCE(
    (SELECT s.plan_id FROM subscriptions s
      WHERE s.tenant_id = p_tenant
        AND s.status IN ('active', 'trialing', 'past_due', 'grace')
        AND (s.status IN ('past_due', 'grace') OR s.current_period_end IS NULL OR s.current_period_end > now())
      LIMIT 1),
    (SELECT p.id FROM plans p WHERE p.code = 'free'))
$$;

-- Models a tenant may use: active model on an active provider, and
--   explicit plan rule  >  is_free_tier  >  denied (fail closed).
CREATE FUNCTION available_models(p_tenant char(26)) RETURNS TABLE (model_id char(26))
LANGUAGE sql STABLE AS $$
  SELECT m.id
    FROM ai_models m
    JOIN ai_providers p ON p.id = m.provider_id
   WHERE m.status = 'active' AND p.status IN ('active', 'degraded')
     AND COALESCE(
           (SELECT r.allowed FROM model_access_rules r
             WHERE r.model_id = m.id AND r.plan_id = tenant_plan_id(p_tenant)),
           m.is_free_tier)
$$;

-- The ONLY way the app role can obtain a credential. Returns nothing unless the provider is
-- usable. Callers decrypt with APP_KEY and must never log or return the value.
CREATE FUNCTION provider_secret_for_call(p_provider char(26))
RETURNS TABLE (secret_encrypted text, key_version integer)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
  SELECT c.secret_encrypted, c.key_version
    FROM provider_credentials c JOIN ai_providers p ON p.id = c.provider_id
   WHERE c.provider_id = p_provider AND c.status = 'active' AND p.status IN ('active', 'degraded')
$$;
REVOKE ALL ON FUNCTION provider_secret_for_call(char) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION provider_secret_for_call(char) TO jarvis_app_rw;

-- Admin-only variant for the "Test connection" button: works while the provider is still
-- disabled (that is when you test it). Not executable by the app login.
CREATE FUNCTION provider_secret_for_test(p_provider char(26))
RETURNS TABLE (secret_encrypted text, key_version integer)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
  SELECT c.secret_encrypted, c.key_version
    FROM provider_credentials c
   WHERE c.provider_id = p_provider AND c.status = 'active'
$$;
REVOKE ALL ON FUNCTION provider_secret_for_test(char) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION provider_secret_for_test(char) TO jarvis_admin;

-- ---- privileges ----
REVOKE INSERT, UPDATE, DELETE ON ai_providers, ai_models, model_capabilities, model_pricing,
  model_access_rules, routing_rules FROM jarvis_app_rw;
GRANT SELECT ON ai_providers, ai_models, model_capabilities, model_pricing, model_access_rules,
  routing_rules TO jarvis_app_rw;
GRANT SELECT ON model_current_pricing TO jarvis_app_rw;
GRANT INSERT, UPDATE, DELETE ON ai_providers, ai_models, model_capabilities, model_access_rules,
  routing_rules TO jarvis_admin;
GRANT INSERT ON model_pricing TO jarvis_admin;

-- credentials: no secret column for the app role; admins can write it but not read it back.
REVOKE ALL ON provider_credentials FROM jarvis_app_rw;
GRANT SELECT (id, provider_id, label, key_version, last_rotated_at, status, created_at)
  ON provider_credentials TO jarvis_app_rw;
GRANT SELECT (id, provider_id, label, key_version, last_rotated_at, status, created_at)
  ON provider_credentials TO jarvis_admin;
GRANT INSERT, UPDATE, DELETE ON provider_credentials TO jarvis_admin;

-- health: telemetry rows are written by the app (health job), never updated; admins purge.
REVOKE UPDATE, DELETE ON provider_health_checks FROM jarvis_app_rw;
GRANT SELECT, INSERT ON provider_health_checks TO jarvis_app_rw;
GRANT DELETE ON provider_health_checks TO jarvis_admin;
