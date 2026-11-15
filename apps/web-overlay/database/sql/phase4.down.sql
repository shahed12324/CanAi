DROP FUNCTION IF EXISTS provider_secret_for_call(char);
DROP FUNCTION IF EXISTS provider_secret_for_test(char);
DROP FUNCTION IF EXISTS available_models(char);
DROP FUNCTION IF EXISTS tenant_plan_id(char);
DROP VIEW IF EXISTS model_current_pricing;
DROP TABLE IF EXISTS tenant_model_preferences, provider_health_checks, routing_rules,
  model_access_rules, model_pricing, model_capabilities, ai_models, provider_credentials,
  ai_providers CASCADE;
DROP FUNCTION IF EXISTS ai_providers_no_cycle();
