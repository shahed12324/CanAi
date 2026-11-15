-- Phase 10: payments, subscriptions, invoices, coupons, refunds.
-- Money rules live in the database, not only in PHP:
--   * prices, coupons, proration and VAT are computed by ONE function (billing_quote) from the catalog tables,
--     so a browser can never choose its own amount;
--   * an order is paid only through billing_settle(), which checks gateway, currency and the exact amount,
--     is idempotent (a repeated IPN is a no-op), and in one transaction activates the subscription or grants
--     credits, books the coupon and issues a numbered VAT invoice;
--   * orders / invoices / refunds / payment events are never edited or deleted afterwards.
-- All amounts are integer minor units (paisa / cents) together with a currency. Percentages are basis points.

-- ---------------------------------------------------------------- subscriptions: downgrade + refill bookkeeping
ALTER TABLE subscriptions
  ADD COLUMN pending_plan_id  char(26) REFERENCES plans (id),
  ADD COLUMN pending_interval text CHECK (pending_interval IN ('month', 'year')),
  ADD COLUMN last_refill_at   timestamptz,
  ADD COLUMN cancelled_at     timestamptz,
  ADD CONSTRAINT subscriptions_pending_pair CHECK ((pending_plan_id IS NULL) = (pending_interval IS NULL));

-- ---------------------------------------------------------------- settings (admin edits them in the admin panel)
INSERT INTO system_settings (key, value, is_encrypted) VALUES
  ('billing.vat_bp',            '1500'::jsonb, false),                                  -- 15 % VAT, applied to BDT orders only
  ('billing.grace_days',        '7'::jsonb,    false),
  ('billing.order_ttl_minutes', '120'::jsonb,  false),
  ('billing.seller',            '{"name":"","address":"","bin":"","email":""}'::jsonb, false)
ON CONFLICT (key) DO NOTHING;

CREATE FUNCTION billing_setting_int(p_key text, p_default integer) RETURNS integer LANGUAGE sql STABLE AS $$
  SELECT coalesce((SELECT CASE WHEN jsonb_typeof(value) = 'number' THEN (value #>> '{}')::numeric::integer END
                     FROM system_settings WHERE key = p_key), p_default)
$$;

-- ---------------------------------------------------------------- tables
CREATE TABLE coupons (
  id               char(26)     PRIMARY KEY,
  code             varchar(32)  NOT NULL UNIQUE CHECK (code ~ '^[A-Z0-9_-]{3,32}$'),
  description      text,
  kind             text         NOT NULL CHECK (kind IN ('percent', 'fixed')),
  percent_bp       integer      CHECK (percent_bp BETWEEN 1 AND 10000),
  fixed_minor      bigint       CHECK (fixed_minor > 0),
  currency         char(3)      CHECK (currency ~ '^[A-Z]{3}$'),
  applies_to       text         NOT NULL DEFAULT 'any' CHECK (applies_to IN ('any', 'subscription', 'credits')),
  max_redemptions  integer      CHECK (max_redemptions > 0),
  per_tenant_limit integer      NOT NULL DEFAULT 1 CHECK (per_tenant_limit > 0),
  redeemed_count   integer      NOT NULL DEFAULT 0 CHECK (redeemed_count >= 0),
  valid_from       timestamptz,
  valid_until      timestamptz,
  status           text         NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'disabled')),
  created_at       timestamptz  NOT NULL DEFAULT now(),
  updated_at       timestamptz  NOT NULL DEFAULT now(),
  CHECK ((kind = 'percent' AND percent_bp IS NOT NULL AND fixed_minor IS NULL)
      OR (kind = 'fixed' AND fixed_minor IS NOT NULL AND currency IS NOT NULL AND percent_bp IS NULL)),
  CHECK (valid_from IS NULL OR valid_until IS NULL OR valid_until > valid_from)
);

CREATE TABLE billing_profiles (
  tenant_id  char(26)     PRIMARY KEY REFERENCES tenants (id),
  legal_name varchar(200) NOT NULL CHECK (length(btrim(legal_name)) > 0),
  address    varchar(500),
  tax_id     varchar(40),                                  -- BIN / TIN, printed on the invoice
  email      varchar(254),
  updated_at timestamptz  NOT NULL DEFAULT now()
);

CREATE TABLE orders (
  id                  char(26)     PRIMARY KEY,
  tenant_id           char(26)     NOT NULL REFERENCES tenants (id),
  kind                text         NOT NULL CHECK (kind IN ('subscription', 'credits')),
  change_kind         text         NOT NULL CHECK (change_kind IN ('new', 'renew', 'upgrade', 'credits')),
  plan_id             char(26)     REFERENCES plans (id),
  interval            text         CHECK (interval IN ('month', 'year')),
  package_id          char(26)     REFERENCES credit_packages (id),
  credits             numeric(20,4) CHECK (credits > 0),
  credit_expires_days integer      CHECK (credit_expires_days > 0),
  item_name           varchar(200) NOT NULL,
  currency            char(3)      NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
  list_minor          bigint       NOT NULL CHECK (list_minor >= 0),
  proration_minor     bigint       NOT NULL DEFAULT 0 CHECK (proration_minor >= 0),
  discount_minor      bigint       NOT NULL DEFAULT 0 CHECK (discount_minor >= 0),
  vat_bp              integer      NOT NULL DEFAULT 0 CHECK (vat_bp BETWEEN 0 AND 10000),
  vat_minor           bigint       NOT NULL DEFAULT 0 CHECK (vat_minor >= 0),
  total_minor         bigint       NOT NULL CHECK (total_minor >= 0),
  coupon_id           char(26)     REFERENCES coupons (id),
  coupon_code         varchar(32),
  gateway             varchar(32)  NOT NULL CHECK (gateway ~ '^[a-z][a-z0-9_]{1,31}$'),
  gateway_ref         varchar(128),                         -- the gateway's own session / payment id
  gateway_txn_id      varchar(128),                         -- the confirmed transaction; unique per gateway
  status              text         NOT NULL DEFAULT 'pending'
                      CHECK (status IN ('pending', 'paid', 'failed', 'cancelled', 'expired', 'review', 'partially_refunded', 'refunded')),
  refunded_minor      bigint       NOT NULL DEFAULT 0 CHECK (refunded_minor >= 0),
  created_by          char(26)     REFERENCES users (id),
  created_at          timestamptz  NOT NULL DEFAULT now(),
  expires_at          timestamptz  NOT NULL,
  paid_at             timestamptz,
  CHECK (total_minor = list_minor - proration_minor - discount_minor + vat_minor),
  CHECK (proration_minor + discount_minor <= list_minor),
  CHECK ((kind = 'subscription' AND plan_id IS NOT NULL AND interval IS NOT NULL AND package_id IS NULL AND credits IS NULL)
      OR (kind = 'credits' AND package_id IS NOT NULL AND credits IS NOT NULL AND plan_id IS NULL AND interval IS NULL)),
  CHECK (status NOT IN ('paid', 'partially_refunded', 'refunded') OR (paid_at IS NOT NULL AND gateway_txn_id IS NOT NULL)),
  CHECK (refunded_minor <= total_minor),
  CHECK ((gateway = 'free') = (total_minor = 0))
);
CREATE UNIQUE INDEX orders_gateway_txn ON orders (gateway, gateway_txn_id) WHERE gateway_txn_id IS NOT NULL;
CREATE UNIQUE INDEX orders_gateway_ref ON orders (gateway, gateway_ref) WHERE gateway_ref IS NOT NULL;
CREATE INDEX orders_tenant_time ON orders (tenant_id, created_at DESC);
CREATE INDEX orders_pending ON orders (expires_at) WHERE status = 'pending';

CREATE TABLE coupon_redemptions (
  order_id       char(26)    PRIMARY KEY REFERENCES orders (id),
  coupon_id      char(26)    NOT NULL REFERENCES coupons (id),
  tenant_id      char(26)    NOT NULL REFERENCES tenants (id),
  discount_minor bigint      NOT NULL CHECK (discount_minor >= 0),
  created_at     timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX coupon_redemptions_lookup ON coupon_redemptions (coupon_id, tenant_id);

CREATE TABLE invoice_counters (
  year smallint NOT NULL,
  kind text     NOT NULL CHECK (kind IN ('invoice', 'credit_note')),
  last integer  NOT NULL DEFAULT 0,
  PRIMARY KEY (year, kind)
);

CREATE TABLE invoices (
  id                 char(26)    PRIMARY KEY,
  tenant_id          char(26)    NOT NULL REFERENCES tenants (id),
  order_id           char(26)    NOT NULL REFERENCES orders (id),
  kind               text        NOT NULL CHECK (kind IN ('invoice', 'credit_note')),
  number             varchar(32) NOT NULL UNIQUE,
  related_invoice_id char(26)    REFERENCES invoices (id),
  currency           char(3)     NOT NULL,
  subtotal_minor     bigint      NOT NULL CHECK (subtotal_minor >= 0),
  vat_bp             integer     NOT NULL,
  vat_minor          bigint      NOT NULL CHECK (vat_minor >= 0),
  total_minor        bigint      NOT NULL CHECK (total_minor >= 0),
  lines              jsonb       NOT NULL,
  seller             jsonb       NOT NULL,
  buyer              jsonb       NOT NULL,
  issued_at          timestamptz NOT NULL DEFAULT now(),
  CHECK (total_minor = subtotal_minor + vat_minor),
  CHECK ((kind = 'invoice' AND related_invoice_id IS NULL) OR (kind = 'credit_note' AND related_invoice_id IS NOT NULL))
);
CREATE UNIQUE INDEX invoices_one_per_order ON invoices (order_id) WHERE kind = 'invoice';
CREATE INDEX invoices_tenant_time ON invoices (tenant_id, issued_at DESC);

CREATE TABLE refunds (
  id                     char(26)      PRIMARY KEY,
  tenant_id              char(26)      NOT NULL REFERENCES tenants (id),
  order_id               char(26)      NOT NULL REFERENCES orders (id),
  amount_minor           bigint        NOT NULL CHECK (amount_minor > 0),
  currency               char(3)       NOT NULL,
  reason                 text          NOT NULL CHECK (length(btrim(reason)) > 0),
  gateway_refund_ref     varchar(128),
  credit_note_id         char(26)      REFERENCES invoices (id),
  credits_clawed_back    numeric(20,4) NOT NULL DEFAULT 0 CHECK (credits_clawed_back >= 0),
  subscription_cancelled boolean       NOT NULL DEFAULT false,
  created_by             char(26)      NOT NULL REFERENCES users (id),
  created_at             timestamptz   NOT NULL DEFAULT now()
);
CREATE INDEX refunds_order ON refunds (order_id);

-- Every gateway callback that reached us, whatever came of it. Raw payloads are small and never hold secrets.
CREATE TABLE payment_events (
  id           bigint       GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  gateway      varchar(32)  NOT NULL,
  event_id     varchar(128) NOT NULL,
  order_id     char(26)     REFERENCES orders (id),
  tenant_id    char(26)     REFERENCES tenants (id),
  outcome      text         NOT NULL CHECK (outcome IN ('applied', 'duplicate', 'double_payment', 'amount_mismatch', 'currency_mismatch',
                              'gateway_mismatch', 'duplicate_txn', 'review', 'failed', 'cancelled', 'ignored')),
  amount_minor bigint,
  currency     char(3),
  raw          jsonb        NOT NULL DEFAULT '{}'::jsonb,
  received_at  timestamptz  NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX payment_events_applied_once ON payment_events (gateway, event_id) WHERE outcome = 'applied';
CREATE INDEX payment_events_order ON payment_events (order_id);
CREATE INDEX payment_events_time ON payment_events (received_at DESC);

-- ---------------------------------------------------------------- immutability
CREATE TRIGGER invoices_append_only BEFORE UPDATE OR DELETE ON invoices FOR EACH ROW EXECUTE FUNCTION reject_mutation();
CREATE TRIGGER refunds_append_only BEFORE UPDATE OR DELETE ON refunds FOR EACH ROW EXECUTE FUNCTION reject_mutation();
CREATE TRIGGER payment_events_append_only BEFORE UPDATE OR DELETE ON payment_events FOR EACH ROW EXECUTE FUNCTION reject_mutation();
CREATE TRIGGER coupon_redemptions_append_only BEFORE UPDATE OR DELETE ON coupon_redemptions FOR EACH ROW EXECUTE FUNCTION reject_mutation();

CREATE FUNCTION orders_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'orders cannot be deleted' USING ERRCODE = '42501'; END IF;
  IF (NEW.id, NEW.tenant_id, NEW.kind, NEW.change_kind, NEW.plan_id, NEW.interval, NEW.package_id, NEW.credits, NEW.currency,
      NEW.list_minor, NEW.proration_minor, NEW.discount_minor, NEW.vat_bp, NEW.vat_minor, NEW.total_minor, NEW.coupon_id, NEW.gateway, NEW.created_at)
     IS DISTINCT FROM
     (OLD.id, OLD.tenant_id, OLD.kind, OLD.change_kind, OLD.plan_id, OLD.interval, OLD.package_id, OLD.credits, OLD.currency,
      OLD.list_minor, OLD.proration_minor, OLD.discount_minor, OLD.vat_bp, OLD.vat_minor, OLD.total_minor, OLD.coupon_id, OLD.gateway, OLD.created_at) THEN
    RAISE EXCEPTION 'order amounts and items cannot change' USING ERRCODE = '42501';
  END IF;
  IF (OLD.gateway_ref IS NOT NULL AND NEW.gateway_ref IS DISTINCT FROM OLD.gateway_ref)
     OR (OLD.gateway_txn_id IS NOT NULL AND NEW.gateway_txn_id IS DISTINCT FROM OLD.gateway_txn_id) THEN
    RAISE EXCEPTION 'gateway references are write-once' USING ERRCODE = '42501';
  END IF;
  IF NEW.status IS DISTINCT FROM OLD.status AND NOT (
       (OLD.status = 'pending' AND NEW.status IN ('paid', 'failed', 'cancelled', 'expired', 'review'))
    OR (OLD.status IN ('failed', 'cancelled', 'expired') AND NEW.status = 'paid')
    OR (OLD.status = 'review' AND NEW.status = 'cancelled')
    OR (OLD.status IN ('paid', 'partially_refunded') AND NEW.status IN ('partially_refunded', 'refunded'))) THEN
    RAISE EXCEPTION 'order status % -> % is not allowed', OLD.status, NEW.status USING ERRCODE = '42501';
  END IF;
  RETURN NEW;
END
$$;
CREATE TRIGGER orders_guard_trg BEFORE UPDATE OR DELETE ON orders FOR EACH ROW EXECUTE FUNCTION orders_guard();

-- ---------------------------------------------------------------- RLS
DO $$
DECLARE t text;
BEGIN
  FOREACH t IN ARRAY ARRAY['billing_profiles', 'orders', 'coupon_redemptions', 'invoices', 'refunds'] LOOP
    EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', t);
    EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY', t);
    EXECUTE format('CREATE POLICY tenant_isolation ON %I USING (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()))
                    WITH CHECK (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()))', t);
  END LOOP;
  FOREACH t IN ARRAY ARRAY['coupons', 'payment_events'] LOOP
    EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', t);
    EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY', t);
    EXECUTE format('CREATE POLICY admin_only ON %I USING ((SELECT app_is_admin())) WITH CHECK ((SELECT app_is_admin()))', t);
  END LOOP;
END $$;

-- ---------------------------------------------------------------- grace-aware entitlements
CREATE FUNCTION billing_grace_days() RETURNS integer LANGUAGE sql STABLE AS $$
  SELECT greatest(0, billing_setting_int('billing.grace_days', 7))
$$;

-- Same precedence as before. A paid period that just ended still counts for the grace window, even before the
-- scheduler has moved the row to 'grace', so entitlements never flip off between two scheduler runs.
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
                 AND (CASE WHEN s.status IN ('past_due', 'grace')
                           THEN s.grace_ends_at IS NULL OR s.grace_ends_at > now()
                           ELSE s.current_period_end IS NULL
                                OR s.current_period_end + make_interval(days => billing_grace_days()) > now() END)
               LIMIT 1),
             (SELECT p.id FROM plans p WHERE p.code = 'free'))
    UNION ALL
    SELECT false, 0::numeric, 'none'::text, 3
  ) r
  ORDER BY r.rnk
  LIMIT 1
$$;

-- ---------------------------------------------------------------- quote
CREATE TYPE billing_quote_t AS (
  kind text, plan_id char(26), bill_interval text, package_id char(26), item_name text, change_kind text,
  currency char(3), list_minor bigint, proration_minor bigint, discount_minor bigint,
  vat_bp integer, vat_minor bigint, total_minor bigint,
  coupon_id char(26), coupon_code varchar(32), credits numeric, credit_expires_days integer
);

-- The one place that turns "what the customer wants" into "what it costs". Raises a short machine code on a bad request.
CREATE FUNCTION billing_quote(p_tenant char(26), p_kind text, p_ref char(26), p_interval text, p_currency char(3), p_coupon text DEFAULT NULL)
RETURNS billing_quote_t LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE
  prev text := coalesce(current_setting('app.bypass_rls', true), '');
  q billing_quote_t; pl plans%ROWTYPE; pk credit_packages%ROWTYPE; s subscriptions%ROWTYPE; c coupons%ROWTYPE;
  oldp bigint; neweq numeric; oldeq numeric; base bigint; taxable bigint; v_code text; total_secs numeric;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  PERFORM set_config('app.bypass_rls', 'on', true);
  IF p_currency IS NULL OR p_currency !~ '^[A-Z]{3}$' THEN RAISE EXCEPTION 'invalid_currency' USING ERRCODE = 'P0001'; END IF;
  q.kind := p_kind; q.currency := p_currency; q.proration_minor := 0; q.discount_minor := 0; q.vat_bp := 0; q.vat_minor := 0;

  IF p_kind = 'subscription' THEN
    IF p_interval IS NULL OR p_interval NOT IN ('month', 'year') THEN RAISE EXCEPTION 'invalid_interval' USING ERRCODE = 'P0001'; END IF;
    SELECT * INTO pl FROM plans WHERE id = p_ref AND status = 'active' AND NOT is_lifetime_free;
    IF NOT FOUND THEN RAISE EXCEPTION 'plan_unavailable' USING ERRCODE = 'P0001'; END IF;
    SELECT round(pp.amount * 100)::bigint INTO q.list_minor FROM plan_prices pp
     WHERE pp.plan_id = pl.id AND pp.currency = p_currency AND pp.interval = p_interval;
    IF q.list_minor IS NULL OR q.list_minor <= 0 THEN RAISE EXCEPTION 'price_not_set' USING ERRCODE = 'P0001'; END IF;
    q.plan_id := pl.id; q.bill_interval := p_interval;
    q.item_name := pl.name || CASE p_interval WHEN 'month' THEN ' - monthly' ELSE ' - yearly' END;
    q.change_kind := 'new';

    SELECT * INTO s FROM subscriptions WHERE tenant_id = p_tenant AND status IN ('active', 'trialing', 'past_due', 'grace');
    IF FOUND THEN
      IF s.plan_id = pl.id AND s.interval = p_interval THEN
        q.change_kind := 'renew';
      ELSIF s.status IN ('active', 'trialing') AND s.current_period_end IS NOT NULL AND s.current_period_end > now() THEN
        SELECT round(pp.amount * 100)::bigint INTO oldp FROM plan_prices pp
         WHERE pp.plan_id = s.plan_id AND pp.currency = p_currency AND pp.interval = s.interval;
        oldp := coalesce(oldp, 0);
        IF s.plan_id = pl.id THEN
          IF s.interval = 'year' AND p_interval = 'month' THEN RAISE EXCEPTION 'use_schedule_downgrade' USING ERRCODE = 'P0001'; END IF;
        ELSE
          neweq := q.list_minor::numeric / CASE p_interval WHEN 'year' THEN 12 ELSE 1 END;
          oldeq := oldp::numeric / CASE s.interval WHEN 'year' THEN 12 ELSE 1 END;
          IF neweq < oldeq THEN RAISE EXCEPTION 'use_schedule_downgrade' USING ERRCODE = 'P0001'; END IF;
        END IF;
        q.change_kind := 'upgrade';
        total_secs := extract(epoch FROM (s.current_period_end - s.current_period_start));
        IF oldp > 0 AND s.current_period_start IS NOT NULL AND total_secs > 0 THEN
          q.proration_minor := least(q.list_minor,
            floor(oldp::numeric * least(1, extract(epoch FROM (s.current_period_end - now())) / total_secs))::bigint);
        END IF;
      END IF;
    END IF;
  ELSIF p_kind = 'credits' THEN
    SELECT * INTO pk FROM credit_packages WHERE id = p_ref AND status = 'active' AND currency = p_currency;
    IF NOT FOUND THEN RAISE EXCEPTION 'package_unavailable' USING ERRCODE = 'P0001'; END IF;
    q.list_minor := round(pk.price * 100)::bigint;
    IF q.list_minor <= 0 THEN RAISE EXCEPTION 'price_not_set' USING ERRCODE = 'P0001'; END IF;
    q.package_id := pk.id; q.item_name := pk.name; q.change_kind := 'credits';
    q.credits := pk.credits + pk.bonus_credits; q.credit_expires_days := pk.expires_after_days;
  ELSE
    RAISE EXCEPTION 'invalid_kind' USING ERRCODE = 'P0001';
  END IF;

  base := q.list_minor - q.proration_minor;
  v_code := upper(btrim(coalesce(p_coupon, '')));
  IF v_code <> '' THEN
    SELECT * INTO c FROM coupons WHERE code = v_code;
    IF NOT FOUND OR c.status <> 'active'
       OR (c.valid_from IS NOT NULL AND c.valid_from > now()) OR (c.valid_until IS NOT NULL AND c.valid_until <= now())
       OR (c.applies_to <> 'any' AND c.applies_to <> p_kind)
       OR (c.kind = 'fixed' AND c.currency <> p_currency) THEN
      RAISE EXCEPTION 'coupon_invalid' USING ERRCODE = 'P0001';
    END IF;
    IF c.max_redemptions IS NOT NULL AND c.redeemed_count >= c.max_redemptions THEN RAISE EXCEPTION 'coupon_exhausted' USING ERRCODE = 'P0001'; END IF;
    IF (SELECT count(*) FROM coupon_redemptions r WHERE r.coupon_id = c.id AND r.tenant_id = p_tenant) >= c.per_tenant_limit THEN
      RAISE EXCEPTION 'coupon_used' USING ERRCODE = 'P0001';
    END IF;
    q.discount_minor := least(base, CASE c.kind WHEN 'percent' THEN (base * c.percent_bp + 5000) / 10000 ELSE c.fixed_minor END);   -- half-up
    q.coupon_id := c.id; q.coupon_code := c.code;
  END IF;

  taxable := base - q.discount_minor;
  IF p_currency = 'BDT' THEN q.vat_bp := least(10000, greatest(0, billing_setting_int('billing.vat_bp', 1500))); END IF;
  q.vat_minor := (taxable * q.vat_bp + 5000) / 10000;
  q.total_minor := taxable + q.vat_minor;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN q;
END
$$;

-- ---------------------------------------------------------------- orders
CREATE FUNCTION billing_create_order(p_tenant char(26), p_kind text, p_ref char(26), p_interval text, p_currency char(3),
                                     p_coupon text, p_gateway text, p_actor char(26))
RETURNS char(26) LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); q billing_quote_t; oid char(26) := credit_new_id();
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_gateway IS NULL OR p_gateway !~ '^[a-z][a-z0-9_]{1,31}$' THEN RAISE EXCEPTION 'invalid_gateway' USING ERRCODE = 'P0001'; END IF;
  q := billing_quote(p_tenant, p_kind, p_ref, p_interval, p_currency, p_coupon);
  IF (q.total_minor = 0) <> (p_gateway = 'free') THEN RAISE EXCEPTION 'invalid_gateway' USING ERRCODE = 'P0001'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE orders SET status = 'expired' WHERE tenant_id = p_tenant AND status = 'pending' AND expires_at <= now();
  IF (SELECT count(*) FROM orders WHERE tenant_id = p_tenant AND status = 'pending') >= 10 THEN
    RAISE EXCEPTION 'too_many_pending' USING ERRCODE = 'P0001';
  END IF;
  INSERT INTO orders (id, tenant_id, kind, change_kind, plan_id, interval, package_id, credits, credit_expires_days, item_name, currency,
                      list_minor, proration_minor, discount_minor, vat_bp, vat_minor, total_minor, coupon_id, coupon_code, gateway,
                      created_by, expires_at)
  VALUES (oid, p_tenant, q.kind, q.change_kind, q.plan_id, q.bill_interval, q.package_id, q.credits, q.credit_expires_days, q.item_name, q.currency,
          q.list_minor, q.proration_minor, q.discount_minor, q.vat_bp, q.vat_minor, q.total_minor, q.coupon_id, q.coupon_code, p_gateway,
          p_actor, now() + make_interval(mins => greatest(10, billing_setting_int('billing.order_ttl_minutes', 120))));
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN oid;
END
$$;

-- Webhooks arrive without a login. The order id is 26 random characters; knowing it lets the caller learn only the tenant id.
CREATE FUNCTION billing_order_tenant(p_order char(26)) RETURNS char(26)
LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); t char(26);
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT tenant_id INTO t FROM orders WHERE id = p_order;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN t;
END
$$;

CREATE FUNCTION billing_order_by_ref(p_gateway text, p_ref text) RETURNS char(26)
LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); o char(26);
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT id INTO o FROM orders WHERE gateway = p_gateway AND gateway_ref = p_ref;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN o;
END
$$;

CREATE FUNCTION billing_set_gateway_ref(p_order char(26), p_ref text) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); o orders%ROWTYPE;
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO o FROM orders WHERE id = p_order FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'order_not_found' USING ERRCODE = 'P0001'; END IF;
  PERFORM credit_authorize(o.tenant_id, false);
  IF o.status <> 'pending' THEN RAISE EXCEPTION 'order_closed' USING ERRCODE = 'P0001'; END IF;
  IF p_ref IS NULL OR length(p_ref) NOT BETWEEN 1 AND 128 THEN RAISE EXCEPTION 'invalid_reference' USING ERRCODE = 'P0001'; END IF;
  IF o.gateway_ref IS NOT NULL AND o.gateway_ref <> p_ref THEN RAISE EXCEPTION 'reference_already_set' USING ERRCODE = 'P0001'; END IF;
  UPDATE orders SET gateway_ref = p_ref WHERE id = p_order;
  PERFORM set_config('app.bypass_rls', prev, true);
END
$$;

-- ---------------------------------------------------------------- internals (no grants)
CREATE FUNCTION billing_log(p_gateway text, p_event text, p_order char(26), p_tenant char(26), p_outcome text,
                            p_amount bigint, p_currency text, p_raw jsonb) RETURNS void LANGUAGE plpgsql AS $$
BEGIN
  INSERT INTO payment_events (gateway, event_id, order_id, tenant_id, outcome, amount_minor, currency, raw)
  VALUES (left(p_gateway, 32), left(coalesce(p_event, ''), 128), p_order, p_tenant, p_outcome, p_amount,
          CASE WHEN p_currency ~ '^[A-Z]{3}$' THEN p_currency END,
          CASE WHEN jsonb_typeof(p_raw) = 'object' AND pg_column_size(p_raw) < 20000 THEN p_raw ELSE '{"truncated":true}'::jsonb END)
  ON CONFLICT (gateway, event_id) WHERE outcome = 'applied' DO NOTHING;
END
$$;

CREATE FUNCTION billing_add_interval(p_from timestamptz, p_interval text) RETURNS timestamptz LANGUAGE sql IMMUTABLE AS $$
  SELECT CASE p_interval WHEN 'year' THEN p_from + interval '1 year' ELSE p_from + interval '1 month' END
$$;

CREATE FUNCTION billing_issue_invoice(o orders, p_kind text, p_related char(26), p_total bigint, p_vat bigint, p_lines jsonb)
RETURNS char(26) LANGUAGE plpgsql AS $$
DECLARE y smallint := extract(year FROM (now() AT TIME ZONE 'Asia/Dhaka'))::smallint; n integer; iid char(26) := credit_new_id();
        seller jsonb; buyer jsonb; bp billing_profiles%ROWTYPE;
BEGIN
  INSERT INTO invoice_counters (year, kind, last) VALUES (y, p_kind, 1)
  ON CONFLICT (year, kind) DO UPDATE SET last = invoice_counters.last + 1 RETURNING last INTO n;      -- row lock => gap-free numbering
  seller := coalesce((SELECT value FROM system_settings WHERE key = 'billing.seller'), '{}'::jsonb);
  SELECT * INTO bp FROM billing_profiles WHERE tenant_id = o.tenant_id;
  IF FOUND THEN
    buyer := jsonb_build_object('name', bp.legal_name, 'address', bp.address, 'tax_id', bp.tax_id, 'email', bp.email);
  ELSE
    buyer := jsonb_build_object('name', (SELECT name FROM tenants WHERE id = o.tenant_id));
  END IF;
  INSERT INTO invoices (id, tenant_id, order_id, kind, number, related_invoice_id, currency, subtotal_minor, vat_bp, vat_minor, total_minor, lines, seller, buyer)
  VALUES (iid, o.tenant_id, o.id, p_kind, CASE p_kind WHEN 'invoice' THEN 'INV-' ELSE 'CN-' END || y || '-' || lpad(n::text, 6, '0'),
          p_related, o.currency, p_total - p_vat, o.vat_bp, p_vat, p_total, p_lines, seller, buyer);
  RETURN iid;
END
$$;

-- Starts, renews or changes the tenant's subscription after a confirmed payment.
CREATE FUNCTION billing_apply_subscription(o orders) RETURNS void LANGUAGE plpgsql AS $$
DECLARE s subscriptions%ROWTYPE; pl plans%ROWTYPE; base timestamptz; pstart timestamptz; pend timestamptz; is_same boolean; refill_exp timestamptz;
BEGIN
  SELECT * INTO pl FROM plans WHERE id = o.plan_id;
  SELECT * INTO s FROM subscriptions WHERE tenant_id = o.tenant_id AND status IN ('active', 'trialing', 'past_due', 'grace') FOR UPDATE;
  IF NOT FOUND THEN
    pstart := now(); pend := billing_add_interval(now(), o.interval);
    INSERT INTO subscriptions (id, tenant_id, plan_id, status, interval, current_period_start, current_period_end, gateway, gateway_ref, last_refill_at)
    VALUES (credit_new_id(), o.tenant_id, o.plan_id, 'active', o.interval, pstart, pend, o.gateway, o.id, now());
  ELSE
    is_same := s.plan_id = o.plan_id AND s.interval = o.interval;
    IF is_same AND s.status IN ('active', 'trialing') AND s.current_period_end IS NOT NULL AND s.current_period_end > now() THEN
      pstart := s.current_period_start; base := s.current_period_end;           -- early renewal: the new period starts where the old one ends
    ELSE
      pstart := now(); base := now();
    END IF;
    pend := billing_add_interval(base, o.interval);
    UPDATE subscriptions SET plan_id = o.plan_id, interval = o.interval, status = 'active', current_period_start = pstart, current_period_end = pend,
           grace_ends_at = NULL, cancel_at = NULL, cancelled_at = NULL, gateway = o.gateway, gateway_ref = o.id, last_refill_at = now(),
           pending_plan_id = CASE WHEN is_same THEN pending_plan_id END, pending_interval = CASE WHEN is_same THEN pending_interval END,
           updated_at = now()
     WHERE id = s.id;
  END IF;
  IF pl.included_credits > 0 THEN
    refill_exp := CASE WHEN pl.monthly_credit_refill THEN least(pend, now() + interval '1 month') ELSE pend END;
    PERFORM credit_grant(o.tenant_id, 'grant_plan', pl.included_credits, 'order:' || o.id || ':plan', 'order', o.id, refill_exp, NULL, 'plan credits');
  END IF;
END
$$;

-- ---------------------------------------------------------------- settle (the only way an order becomes paid)
CREATE FUNCTION billing_settle(p_order char(26), p_gateway text, p_txn text, p_amount_minor bigint, p_currency text, p_event_id text, p_raw jsonb DEFAULT '{}'::jsonb)
RETURNS TABLE (outcome text, invoice_id char(26)) LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE
  prev text := coalesce(current_setting('app.bypass_rls', true), '');
  o orders%ROWTYPE; inv char(26); lines jsonb; ev text := coalesce(nullif(p_event_id, ''), p_txn);
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO o FROM orders WHERE id = p_order FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'order_not_found' USING ERRCODE = 'P0001'; END IF;
  PERFORM credit_authorize(o.tenant_id, false);
  IF p_txn IS NULL OR length(p_txn) NOT BETWEEN 1 AND 128 THEN RAISE EXCEPTION 'invalid_transaction' USING ERRCODE = 'P0001'; END IF;

  IF o.gateway <> p_gateway THEN
    PERFORM billing_log(p_gateway, ev, o.id, o.tenant_id, 'gateway_mismatch', p_amount_minor, p_currency, p_raw);
    outcome := 'gateway_mismatch'; invoice_id := NULL;
  ELSIF o.status IN ('paid', 'partially_refunded', 'refunded') THEN
    IF o.gateway_txn_id = p_txn THEN
      PERFORM billing_log(p_gateway, ev, o.id, o.tenant_id, 'duplicate', p_amount_minor, p_currency, p_raw);
      outcome := 'duplicate';
    ELSE
      PERFORM billing_log(p_gateway, ev, o.id, o.tenant_id, 'double_payment', p_amount_minor, p_currency, p_raw);
      outcome := 'double_payment';
    END IF;
    SELECT i.id INTO invoice_id FROM invoices i WHERE i.order_id = o.id AND i.kind = 'invoice';
  ELSIF o.status = 'review' THEN
    PERFORM billing_log(p_gateway, ev, o.id, o.tenant_id, 'review', p_amount_minor, p_currency, p_raw);
    outcome := 'review'; invoice_id := NULL;
  ELSIF p_currency IS DISTINCT FROM o.currency THEN
    UPDATE orders SET status = 'review' WHERE id = o.id AND status = 'pending';
    PERFORM billing_log(p_gateway, ev, o.id, o.tenant_id, 'currency_mismatch', p_amount_minor, p_currency, p_raw);
    outcome := 'currency_mismatch'; invoice_id := NULL;
  ELSIF p_amount_minor IS DISTINCT FROM o.total_minor THEN
    UPDATE orders SET status = 'review' WHERE id = o.id AND status = 'pending';
    PERFORM billing_log(p_gateway, ev, o.id, o.tenant_id, 'amount_mismatch', p_amount_minor, p_currency, p_raw);
    outcome := 'amount_mismatch'; invoice_id := NULL;
  ELSE
    BEGIN
      UPDATE orders SET status = 'paid', gateway_txn_id = p_txn, paid_at = now() WHERE id = o.id;
    EXCEPTION WHEN unique_violation THEN
      PERFORM billing_log(p_gateway, ev, o.id, o.tenant_id, 'duplicate_txn', p_amount_minor, p_currency, p_raw);
      outcome := 'duplicate_txn'; invoice_id := NULL;
      PERFORM set_config('app.bypass_rls', prev, true);
      RETURN NEXT; RETURN;
    END;
    IF o.kind = 'credits' THEN
      PERFORM credit_grant(o.tenant_id, 'purchase', o.credits, 'order:' || o.id, 'order', o.id,
                           CASE WHEN o.credit_expires_days IS NOT NULL THEN now() + make_interval(days => o.credit_expires_days) END, NULL, o.item_name);
    ELSE
      PERFORM billing_apply_subscription(o);
    END IF;
    IF o.coupon_id IS NOT NULL THEN
      INSERT INTO coupon_redemptions (order_id, coupon_id, tenant_id, discount_minor) VALUES (o.id, o.coupon_id, o.tenant_id, o.discount_minor);
      UPDATE coupons SET redeemed_count = redeemed_count + 1, updated_at = now() WHERE id = o.coupon_id;
    END IF;
    lines := jsonb_build_array(jsonb_build_object('description', o.item_name, 'amount_minor', o.list_minor));
    IF o.proration_minor > 0 THEN lines := lines || jsonb_build_array(jsonb_build_object('description', 'Credit for unused time on previous plan', 'amount_minor', -o.proration_minor)); END IF;
    IF o.discount_minor > 0 THEN lines := lines || jsonb_build_array(jsonb_build_object('description', 'Coupon ' || coalesce(o.coupon_code, ''), 'amount_minor', -o.discount_minor)); END IF;
    inv := billing_issue_invoice(o, 'invoice', NULL, o.total_minor, o.vat_minor, lines);
    PERFORM billing_log(p_gateway, ev, o.id, o.tenant_id, 'applied', p_amount_minor, p_currency, p_raw);
    outcome := 'applied'; invoice_id := inv;
  END IF;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN NEXT;
END
$$;

-- A gateway said "failed" or "cancelled". Never touches an order that was already paid.
CREATE FUNCTION billing_fail_order(p_order char(26), p_gateway text, p_event_id text, p_outcome text, p_raw jsonb DEFAULT '{}'::jsonb)
RETURNS text LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); o orders%ROWTYPE; res text;
BEGIN
  IF p_outcome NOT IN ('failed', 'cancelled') THEN RAISE EXCEPTION 'invalid_outcome' USING ERRCODE = 'P0001'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO o FROM orders WHERE id = p_order FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'order_not_found' USING ERRCODE = 'P0001'; END IF;
  PERFORM credit_authorize(o.tenant_id, false);
  IF o.gateway <> p_gateway THEN
    res := 'gateway_mismatch';
  ELSIF o.status = 'pending' THEN
    UPDATE orders SET status = p_outcome WHERE id = o.id;
    res := p_outcome;
  ELSE
    res := 'ignored';
  END IF;
  PERFORM billing_log(p_gateway, coalesce(nullif(p_event_id, ''), 'fail:' || o.id || ':' || extract(epoch FROM clock_timestamp())::text), o.id, o.tenant_id,
                      CASE res WHEN 'gateway_mismatch' THEN 'gateway_mismatch' WHEN 'ignored' THEN 'ignored' ELSE res END, NULL, NULL, p_raw);
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN res;
END
$$;

-- A callback we could not verify (bad signature, gateway said "not valid", high risk): kept for the admin, changes nothing else.
CREATE FUNCTION billing_note_event(p_order char(26), p_gateway text, p_event_id text, p_raw jsonb DEFAULT '{}'::jsonb) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); t char(26);
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT tenant_id INTO t FROM orders WHERE id = p_order;
  IF t IS NULL THEN RAISE EXCEPTION 'order_not_found' USING ERRCODE = 'P0001'; END IF;
  PERFORM credit_authorize(t, false);
  PERFORM billing_log(p_gateway, coalesce(nullif(p_event_id, ''), 'note:' || extract(epoch FROM clock_timestamp())::text), p_order, t, 'ignored', NULL, NULL, p_raw);
  PERFORM set_config('app.bypass_rls', prev, true);
END
$$;

-- ---------------------------------------------------------------- subscription actions chosen by the customer
CREATE FUNCTION billing_cancel_subscription(p_tenant char(26), p_immediate boolean DEFAULT false) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); s subscriptions%ROWTYPE; res text;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO s FROM subscriptions WHERE tenant_id = p_tenant AND status IN ('active', 'trialing', 'past_due', 'grace') FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'no_subscription' USING ERRCODE = 'P0001'; END IF;
  IF p_immediate OR s.status IN ('past_due', 'grace') OR s.current_period_end IS NULL OR s.current_period_end <= now() THEN
    UPDATE subscriptions SET status = 'cancelled', cancel_at = now(), cancelled_at = now(), grace_ends_at = NULL,
           pending_plan_id = NULL, pending_interval = NULL, updated_at = now() WHERE id = s.id;
    res := 'cancelled';
  ELSE
    UPDATE subscriptions SET cancel_at = s.current_period_end, pending_plan_id = NULL, pending_interval = NULL, updated_at = now() WHERE id = s.id;
    res := 'cancel_scheduled';
  END IF;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN res;
END
$$;

CREATE FUNCTION billing_resume_subscription(p_tenant char(26)) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); n integer;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE subscriptions SET cancel_at = NULL, updated_at = now()
   WHERE tenant_id = p_tenant AND status IN ('active', 'trialing') AND cancel_at IS NOT NULL AND cancel_at > now();
  GET DIAGNOSTICS n = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  IF n = 0 THEN RAISE EXCEPTION 'nothing_to_resume' USING ERRCODE = 'P0001'; END IF;
END
$$;

-- Downgrade takes effect at the end of the paid period; nothing is charged now and nothing is refunded.
CREATE FUNCTION billing_schedule_downgrade(p_tenant char(26), p_plan char(26), p_interval text) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); s subscriptions%ROWTYPE; cur plans%ROWTYPE; nw plans%ROWTYPE;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  PERFORM set_config('app.bypass_rls', 'on', true);
  IF p_interval IS NULL OR p_interval NOT IN ('month', 'year') THEN RAISE EXCEPTION 'invalid_interval' USING ERRCODE = 'P0001'; END IF;
  SELECT * INTO s FROM subscriptions WHERE tenant_id = p_tenant AND status IN ('active', 'trialing') FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'no_subscription' USING ERRCODE = 'P0001'; END IF;
  SELECT * INTO cur FROM plans WHERE id = s.plan_id;
  SELECT * INTO nw FROM plans WHERE id = p_plan AND status = 'active' AND NOT is_lifetime_free;
  IF NOT FOUND THEN RAISE EXCEPTION 'plan_unavailable' USING ERRCODE = 'P0001'; END IF;
  IF NOT (nw.sort < cur.sort OR (nw.id = cur.id AND s.interval = 'year' AND p_interval = 'month')) THEN
    RAISE EXCEPTION 'not_a_downgrade' USING ERRCODE = 'P0001';
  END IF;
  UPDATE subscriptions SET pending_plan_id = nw.id, pending_interval = p_interval, cancel_at = NULL, updated_at = now() WHERE id = s.id;
  PERFORM set_config('app.bypass_rls', prev, true);
END
$$;

CREATE FUNCTION billing_clear_downgrade(p_tenant char(26)) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), '');
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE subscriptions SET pending_plan_id = NULL, pending_interval = NULL, updated_at = now()
   WHERE tenant_id = p_tenant AND pending_plan_id IS NOT NULL AND status IN ('active', 'trialing', 'past_due', 'grace');
  PERFORM set_config('app.bypass_rls', prev, true);
END
$$;

-- ---------------------------------------------------------------- scheduler: expiry, grace, cancellation, monthly refill
CREATE FUNCTION billing_lifecycle()
RETURNS TABLE (orders_expired integer, to_grace integer, subs_expired integer, subs_cancelled integer, refills integer)
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE
  prev text := coalesce(current_setting('app.bypass_rls', true), ''); prev_t text := coalesce(current_setting('app.tenant_id', true), '');
  r record; gd integer := billing_grace_days(); k integer;
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  orders_expired := 0; to_grace := 0; subs_expired := 0; subs_cancelled := 0; refills := 0;

  UPDATE orders SET status = 'expired' WHERE status = 'pending' AND expires_at <= now();
  GET DIAGNOSTICS orders_expired = ROW_COUNT;

  UPDATE subscriptions SET status = 'cancelled', cancelled_at = now(), pending_plan_id = NULL, pending_interval = NULL, updated_at = now()
   WHERE status IN ('active', 'trialing') AND cancel_at IS NOT NULL AND cancel_at <= now();
  GET DIAGNOSTICS subs_cancelled = ROW_COUNT;

  -- the paid period is over: keep access for the grace window; a scheduled downgrade is applied now
  UPDATE subscriptions SET status = 'grace', grace_ends_at = current_period_end + make_interval(days => gd),
         plan_id = coalesce(pending_plan_id, plan_id), interval = coalesce(pending_interval, interval),
         pending_plan_id = NULL, pending_interval = NULL, updated_at = now()
   WHERE status IN ('active', 'trialing') AND current_period_end IS NOT NULL AND current_period_end <= now();
  GET DIAGNOSTICS to_grace = ROW_COUNT;

  UPDATE subscriptions SET status = 'expired', updated_at = now()
   WHERE status IN ('grace', 'past_due') AND coalesce(grace_ends_at, current_period_end + make_interval(days => gd), now()) <= now();
  GET DIAGNOSTICS subs_expired = ROW_COUNT;

  -- yearly plans that refill credits every month
  FOR r IN SELECT s.id, s.tenant_id, s.current_period_end, p.included_credits
             FROM subscriptions s JOIN plans p ON p.id = s.plan_id
            WHERE s.status = 'active' AND s.interval = 'year' AND p.monthly_credit_refill AND p.included_credits > 0
              AND s.current_period_end > now() AND coalesce(s.last_refill_at, s.created_at) <= now() - interval '1 month' LOOP
    PERFORM set_config('app.tenant_id', r.tenant_id, true);
    PERFORM credit_grant(r.tenant_id, 'grant_plan', r.included_credits, 'sub:' || r.id || ':' || to_char(now() AT TIME ZONE 'UTC', 'YYYY-MM'),
                         'subscription', r.id, least(r.current_period_end, now() + interval '1 month'), NULL, 'monthly plan credits');
    UPDATE subscriptions SET last_refill_at = now() WHERE id = r.id;
    refills := refills + 1;
  END LOOP;
  PERFORM set_config('app.tenant_id', prev_t, true);
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN NEXT;
END
$$;

-- ---------------------------------------------------------------- admin: refunds
-- Records that money went back (the actual transfer is done in the gateway dashboard) and keeps the books straight:
-- a credit note, the order status, optionally taking the purchased credits back and/or ending the subscription.
CREATE FUNCTION billing_record_refund(p_order char(26), p_amount_minor bigint, p_reason text, p_gateway_ref text,
                                      p_clawback boolean, p_cancel_subscription boolean, p_actor char(26))
RETURNS char(26) LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE
  prev text := coalesce(current_setting('app.bypass_rls', true), ''); o orders%ROWTYPE; rid char(26) := credit_new_id();
  inv invoices%ROWTYPE; vat_part bigint; cn char(26); claw numeric := 0; cancelled boolean := false;
BEGIN
  PERFORM credit_authorize(NULL, true);
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO o FROM orders WHERE id = p_order FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'order_not_found' USING ERRCODE = 'P0001'; END IF;
  IF o.status NOT IN ('paid', 'partially_refunded') THEN RAISE EXCEPTION 'order_not_refundable' USING ERRCODE = 'P0001'; END IF;
  IF p_amount_minor IS NULL OR p_amount_minor <= 0 OR p_amount_minor > o.total_minor - o.refunded_minor THEN RAISE EXCEPTION 'invalid_amount' USING ERRCODE = 'P0001'; END IF;
  IF p_reason IS NULL OR length(btrim(p_reason)) = 0 THEN RAISE EXCEPTION 'reason_required' USING ERRCODE = 'P0001'; END IF;
  SELECT * INTO inv FROM invoices WHERE order_id = o.id AND kind = 'invoice';

  vat_part := (p_amount_minor * o.vat_minor + o.total_minor / 2) / o.total_minor;           -- the VAT share of this refund, half-up
  cn := billing_issue_invoice(o, 'credit_note', inv.id, p_amount_minor, vat_part,
          jsonb_build_array(jsonb_build_object('description', 'Refund: ' || o.item_name, 'amount_minor', p_amount_minor)));

  IF p_clawback AND o.kind = 'credits' THEN
    claw := floor(o.credits * p_amount_minor / o.total_minor * 10000) / 10000;
    IF claw > 0 THEN
      PERFORM credit_adjust(o.tenant_id, 'adjustment', -claw, 'refund of order ' || o.id, 'refund:' || rid, p_actor);
    END IF;
  END IF;
  IF p_cancel_subscription AND o.kind = 'subscription' THEN
    UPDATE subscriptions SET status = 'cancelled', cancel_at = now(), cancelled_at = now(), grace_ends_at = NULL,
           pending_plan_id = NULL, pending_interval = NULL, updated_at = now()
     WHERE tenant_id = o.tenant_id AND status IN ('active', 'trialing', 'past_due', 'grace');
    cancelled := FOUND;
  END IF;

  UPDATE orders SET refunded_minor = refunded_minor + p_amount_minor,
         status = CASE WHEN refunded_minor + p_amount_minor >= total_minor THEN 'refunded' ELSE 'partially_refunded' END
   WHERE id = o.id;
  INSERT INTO refunds (id, tenant_id, order_id, amount_minor, currency, reason, gateway_refund_ref, credit_note_id, credits_clawed_back, subscription_cancelled, created_by)
  VALUES (rid, o.tenant_id, o.id, p_amount_minor, o.currency, btrim(p_reason), nullif(p_gateway_ref, ''), cn, claw, cancelled, p_actor);
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN rid;
END
$$;

-- An order parked in 'review' (wrong amount / currency) is closed by an admin after dealing with the money.
CREATE FUNCTION billing_close_review(p_order char(26), p_actor char(26)) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), '');
BEGIN
  PERFORM credit_authorize(NULL, true);
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE orders SET status = 'cancelled' WHERE id = p_order AND status = 'review';
  IF NOT FOUND THEN RAISE EXCEPTION 'not_in_review' USING ERRCODE = 'P0001'; END IF;
  PERFORM set_config('app.bypass_rls', prev, true);
END
$$;

-- ---------------------------------------------------------------- privileges
REVOKE ALL ON coupons, billing_profiles, orders, coupon_redemptions, invoice_counters, invoices, refunds, payment_events FROM jarvis_app_rw, jarvis_admin;
REVOKE INSERT, UPDATE, DELETE, TRUNCATE ON subscriptions FROM jarvis_app_rw;       -- subscriptions change only through the functions above
GRANT SELECT ON orders, invoices, refunds, coupon_redemptions TO jarvis_app_rw;
GRANT SELECT, INSERT, UPDATE ON billing_profiles TO jarvis_app_rw;
GRANT SELECT ON coupons, billing_profiles, orders, coupon_redemptions, invoices, refunds, payment_events TO jarvis_admin;
GRANT INSERT, UPDATE ON coupons TO jarvis_admin;                                    -- coupons are disabled, never deleted

REVOKE ALL ON FUNCTION billing_log(text, text, char, char, text, bigint, text, jsonb), billing_add_interval(timestamptz, text),
  billing_issue_invoice(orders, text, char, bigint, bigint, jsonb), billing_apply_subscription(orders), orders_guard() FROM PUBLIC;
REVOKE ALL ON FUNCTION billing_quote(char, text, char, text, char, text), billing_create_order(char, text, char, text, char, text, text, char),
  billing_order_tenant(char), billing_order_by_ref(text, text), billing_set_gateway_ref(char, text),
  billing_settle(char, text, text, bigint, text, text, jsonb), billing_fail_order(char, text, text, text, jsonb), billing_note_event(char, text, text, jsonb),
  billing_cancel_subscription(char, boolean), billing_resume_subscription(char), billing_schedule_downgrade(char, char, text),
  billing_clear_downgrade(char), billing_lifecycle(), billing_record_refund(char, bigint, text, text, boolean, boolean, char),
  billing_close_review(char, char) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION billing_quote(char, text, char, text, char, text), billing_create_order(char, text, char, text, char, text, text, char),
  billing_order_tenant(char), billing_order_by_ref(text, text), billing_set_gateway_ref(char, text),
  billing_settle(char, text, text, bigint, text, text, jsonb), billing_fail_order(char, text, text, text, jsonb), billing_note_event(char, text, text, jsonb),
  billing_cancel_subscription(char, boolean), billing_resume_subscription(char), billing_schedule_downgrade(char, char, text),
  billing_clear_downgrade(char), billing_lifecycle() TO jarvis_app_rw;
GRANT EXECUTE ON FUNCTION billing_record_refund(char, bigint, text, text, boolean, boolean, char), billing_close_review(char, char) TO jarvis_admin;
