DROP FUNCTION IF EXISTS effective_feature(char, text);
DROP TABLE IF EXISTS system_settings, feature_flags, subscriptions, tenant_feature_overrides,
  plan_features, features, plan_prices, plans CASCADE;
