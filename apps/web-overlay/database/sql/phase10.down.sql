DROP FUNCTION IF EXISTS billing_close_review(char, char);
DROP FUNCTION IF EXISTS billing_record_refund(char, bigint, text, text, boolean, boolean, char);
DROP FUNCTION IF EXISTS billing_lifecycle();
DROP FUNCTION IF EXISTS billing_clear_downgrade(char);
DROP FUNCTION IF EXISTS billing_schedule_downgrade(char, char, text);
DROP FUNCTION IF EXISTS billing_resume_subscription(char);
DROP FUNCTION IF EXISTS billing_cancel_subscription(char, boolean);
DROP FUNCTION IF EXISTS billing_note_event(char, text, text, jsonb);
DROP FUNCTION IF EXISTS billing_fail_order(char, text, text, text, jsonb);
DROP FUNCTION IF EXISTS billing_settle(char, text, text, bigint, text, text, jsonb);
DROP FUNCTION IF EXISTS billing_apply_subscription(orders);
DROP FUNCTION IF EXISTS billing_issue_invoice(orders, text, char, bigint, bigint, jsonb);
DROP FUNCTION IF EXISTS billing_add_interval(timestamptz, text);
DROP FUNCTION IF EXISTS billing_log(text, text, char, char, text, bigint, text, jsonb);
DROP FUNCTION IF EXISTS billing_set_gateway_ref(char, text);
DROP FUNCTION IF EXISTS billing_order_by_ref(text, text);
DROP FUNCTION IF EXISTS billing_order_tenant(char);
DROP FUNCTION IF EXISTS billing_create_order(char, text, char, text, char, text, text, char);
DROP FUNCTION IF EXISTS billing_quote(char, text, char, text, char, text);
DROP TYPE IF EXISTS billing_quote_t;
CREATE OR REPLACE FUNCTION effective_feature(p_tenant char(26), p_key text)
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
DROP FUNCTION IF EXISTS billing_grace_days();
DROP TABLE IF EXISTS payment_events, refunds, invoices, invoice_counters, coupon_redemptions, orders, billing_profiles, coupons CASCADE;
DROP FUNCTION IF EXISTS orders_guard();
DELETE FROM system_settings WHERE key IN ('billing.vat_bp', 'billing.grace_days', 'billing.order_ttl_minutes', 'billing.seller');
DROP FUNCTION IF EXISTS billing_setting_int(text, integer);
GRANT INSERT, UPDATE, DELETE ON subscriptions TO jarvis_app_rw;
ALTER TABLE subscriptions DROP CONSTRAINT IF EXISTS subscriptions_pending_pair;
ALTER TABLE subscriptions DROP COLUMN IF EXISTS cancelled_at, DROP COLUMN IF EXISTS last_refill_at, DROP COLUMN IF EXISTS pending_interval, DROP COLUMN IF EXISTS pending_plan_id;
