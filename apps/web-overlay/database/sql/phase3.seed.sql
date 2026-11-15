-- Idempotent starter catalog. Prices are NOT seeded: the owner sets them in the admin panel.
-- Limits below are placeholders for the owner to tune; NULL limit = unlimited.
INSERT INTO plans (id, code, name, description, is_lifetime_free, sort) VALUES
  (substr(md5('plan:free'), 1, 26),       'free',       'Free',       'Lifetime free plan', true,  10),
  (substr(md5('plan:pro'), 1, 26),        'pro',        'Pro',        NULL,                 false, 20),
  (substr(md5('plan:ultra'), 1, 26),      'ultra',      'Ultra',      NULL,                 false, 30),
  (substr(md5('plan:business'), 1, 26),   'business',   'Business',   NULL,                 false, 40),
  (substr(md5('plan:enterprise'), 1, 26), 'enterprise', 'Enterprise', NULL,                 false, 50)
ON CONFLICT (code) DO NOTHING;

INSERT INTO features (key, category, type, description) VALUES
  ('module.chat',           'modules', 'boolean', 'AI chat assistant'),
  ('module.telegram',       'modules', 'boolean', 'Telegram channel'),
  ('module.whatsapp',       'modules', 'boolean', 'WhatsApp channel'),
  ('module.lead_extractor', 'modules', 'boolean', 'Website lead extractor'),
  ('module.outreach',       'modules', 'boolean', 'Email outreach'),
  ('module.ecommerce',      'modules', 'boolean', 'E-commerce manager'),
  ('module.accounting',     'modules', 'boolean', 'Accounting, VAT and audit assistant'),
  ('module.ads',            'modules', 'boolean', 'Meta and Google Ads manager'),
  ('module.creative',       'modules', 'boolean', 'Image and video studio'),
  ('module.workflows',      'modules', 'boolean', 'Agents and workflows'),
  ('module.voice',          'modules', 'boolean', 'Voice assistant'),
  ('module.desktop',        'modules', 'boolean', 'Desktop app and computer control'),
  ('module.api_access',     'modules', 'boolean', 'Public API access'),
  ('module.white_label',    'modules', 'boolean', 'White-label and reseller'),
  ('limit.members',         'limits',  'limit',   'Active members per workspace'),
  ('limit.agents',          'limits',  'limit',   'AI agents per workspace'),
  ('limit.storage_mb',      'limits',  'limit',   'File storage (MB)'),
  ('limit.leads_per_month', 'limits',  'limit',   'Extracted leads per month')
ON CONFLICT (key) DO NOTHING;

INSERT INTO plan_features (plan_id, feature_key, enabled, limit_value)
SELECT p.id, v.feature_key, true, v.limit_value
FROM (VALUES
  ('free','module.chat',NULL), ('free','module.telegram',NULL),
  ('free','limit.members',3), ('free','limit.agents',1), ('free','limit.storage_mb',100),

  ('pro','module.chat',NULL), ('pro','module.telegram',NULL), ('pro','module.whatsapp',NULL),
  ('pro','module.lead_extractor',NULL), ('pro','module.outreach',NULL), ('pro','module.ecommerce',NULL),
  ('pro','module.workflows',NULL),
  ('pro','limit.members',10), ('pro','limit.agents',5), ('pro','limit.storage_mb',5000), ('pro','limit.leads_per_month',500),

  ('ultra','module.chat',NULL), ('ultra','module.telegram',NULL), ('ultra','module.whatsapp',NULL),
  ('ultra','module.lead_extractor',NULL), ('ultra','module.outreach',NULL), ('ultra','module.ecommerce',NULL),
  ('ultra','module.workflows',NULL), ('ultra','module.accounting',NULL), ('ultra','module.ads',NULL),
  ('ultra','module.creative',NULL), ('ultra','module.voice',NULL), ('ultra','module.api_access',NULL),
  ('ultra','limit.members',25), ('ultra','limit.agents',20), ('ultra','limit.storage_mb',20000), ('ultra','limit.leads_per_month',5000),

  ('business','module.chat',NULL), ('business','module.telegram',NULL), ('business','module.whatsapp',NULL),
  ('business','module.lead_extractor',NULL), ('business','module.outreach',NULL), ('business','module.ecommerce',NULL),
  ('business','module.workflows',NULL), ('business','module.accounting',NULL), ('business','module.ads',NULL),
  ('business','module.creative',NULL), ('business','module.voice',NULL), ('business','module.api_access',NULL),
  ('business','module.desktop',NULL),
  ('business','limit.members',100), ('business','limit.agents',100), ('business','limit.storage_mb',100000), ('business','limit.leads_per_month',50000),

  ('enterprise','module.chat',NULL), ('enterprise','module.telegram',NULL), ('enterprise','module.whatsapp',NULL),
  ('enterprise','module.lead_extractor',NULL), ('enterprise','module.outreach',NULL), ('enterprise','module.ecommerce',NULL),
  ('enterprise','module.workflows',NULL), ('enterprise','module.accounting',NULL), ('enterprise','module.ads',NULL),
  ('enterprise','module.creative',NULL), ('enterprise','module.voice',NULL), ('enterprise','module.api_access',NULL),
  ('enterprise','module.desktop',NULL), ('enterprise','module.white_label',NULL),
  ('enterprise','limit.members',NULL), ('enterprise','limit.agents',NULL), ('enterprise','limit.storage_mb',NULL), ('enterprise','limit.leads_per_month',NULL)
) AS v(plan_code, feature_key, limit_value)
JOIN plans p ON p.code = v.plan_code
ON CONFLICT (plan_id, feature_key) DO NOTHING;

INSERT INTO system_settings (key, value, is_encrypted) VALUES
  ('system.signup_enabled', 'true'::jsonb,  false),
  ('maintenance.enabled',   'false'::jsonb, false)
ON CONFLICT (key) DO NOTHING;
