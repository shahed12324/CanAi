-- Phase 27.1a: resellers.  A reseller is a person a platform admin has approved; customers join them by TYPING the reseller's code themselves (consent),
-- the workspace owner only, once.  The reseller sees an aggregate report (never customer content): workspace name, when joined, and the money paid.
-- Commission = net paid revenue (total minus VAT minus refunds) x the rate that applied when the customer joined; payouts are recorded by an admin and subtracted.
CREATE TABLE resellers (
  id            char(26)     PRIMARY KEY,
  user_id       char(26)     NOT NULL REFERENCES users (id),
  name          varchar(120) NOT NULL CHECK (char_length(btrim(name)) BETWEEN 1 AND 120),
  code          citext       NOT NULL CHECK (code ~ '^[A-Za-z0-9]{6,12}$'),
  commission_bp integer      NOT NULL CHECK (commission_bp BETWEEN 0 AND 5000),
  status        text         NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'suspended')),
  created_at    timestamptz  NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX resellers_code ON resellers (code);
CREATE UNIQUE INDEX resellers_user ON resellers (user_id);

CREATE TABLE reseller_payouts (
  id           char(26)     PRIMARY KEY,
  reseller_id  char(26)     NOT NULL REFERENCES resellers (id),
  currency     char(3)      NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
  amount_minor bigint       NOT NULL CHECK (amount_minor > 0),
  note         varchar(200) NOT NULL DEFAULT '',
  created_at   timestamptz  NOT NULL DEFAULT now()
);
CREATE INDEX reseller_payouts_r ON reseller_payouts (reseller_id, created_at DESC);

ALTER TABLE tenants ADD COLUMN reseller_bp integer CHECK (reseller_bp BETWEEN 0 AND 5000);
ALTER TABLE tenants ADD COLUMN reseller_joined_at timestamptz;
ALTER TABLE tenants ADD CONSTRAINT tenants_reseller_fk FOREIGN KEY (reseller_id) REFERENCES resellers (id);
ALTER TABLE tenants ADD CONSTRAINT tenants_reseller_all CHECK ((reseller_id IS NULL) = (reseller_bp IS NULL) AND (reseller_id IS NULL) = (reseller_joined_at IS NULL));

-- Only functions touch these tables.
ALTER TABLE resellers ENABLE ROW LEVEL SECURITY;
ALTER TABLE resellers FORCE ROW LEVEL SECURITY;
CREATE POLICY admin_only ON resellers USING ((SELECT app_is_admin())) WITH CHECK ((SELECT app_is_admin()));
ALTER TABLE reseller_payouts ENABLE ROW LEVEL SECURITY;
ALTER TABLE reseller_payouts FORCE ROW LEVEL SECURITY;
CREATE POLICY admin_only ON reseller_payouts USING ((SELECT app_is_admin())) WITH CHECK ((SELECT app_is_admin()));
REVOKE ALL ON resellers, reseller_payouts FROM jarvis_app_rw, jarvis_admin;
GRANT SELECT ON resellers, reseller_payouts TO jarvis_admin;

-- ADMIN: create / change a reseller.
CREATE FUNCTION reseller_create(p_id char(26), p_user char(26), p_name text, p_code text, p_bp integer) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); rc integer; nm text := btrim(coalesce(p_name, ''));
BEGIN
  IF p_id IS NULL OR p_user IS NULL OR nm = '' OR char_length(nm) > 120 OR p_code IS NULL OR p_code !~ '^[A-Za-z0-9]{6,12}$' OR p_bp IS NULL OR p_bp NOT BETWEEN 0 AND 5000 THEN RETURN 'bad_input'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  IF NOT EXISTS (SELECT 1 FROM users WHERE id = p_user) THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'no_user'; END IF;
  IF EXISTS (SELECT 1 FROM resellers WHERE user_id = p_user) THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'already'; END IF;
  INSERT INTO resellers (id, user_id, name, code, commission_bp) VALUES (p_id, p_user, nm, p_code, p_bp) ON CONFLICT DO NOTHING;
  GET DIAGNOSTICS rc = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN rc = 0 THEN 'code_taken' ELSE 'ok' END;
END $$;

-- ADMIN: suspend (no new customers can join) / reactivate, and change the rate for FUTURE customers.
CREATE FUNCTION reseller_update(p_id char(26), p_status text, p_bp integer) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); rc integer;
BEGIN
  IF p_status IS NULL OR p_status NOT IN ('active', 'suspended') OR p_bp IS NULL OR p_bp NOT BETWEEN 0 AND 5000 THEN RETURN 'bad_input'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE resellers SET status = p_status, commission_bp = p_bp WHERE id = p_id;
  GET DIAGNOSTICS rc = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN rc = 0 THEN 'not_found' ELSE 'ok' END;
END $$;

-- ADMIN: record money actually paid out to the reseller.  Cannot pay out more than is due in that currency.
CREATE FUNCTION reseller_payout(p_id char(26), p_reseller char(26), p_currency text, p_amount bigint, p_note text) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); due bigint; nt text := btrim(coalesce(p_note, ''));
BEGIN
  IF p_id IS NULL OR p_currency IS NULL OR p_currency !~ '^[A-Z]{3}$' OR p_amount IS NULL OR p_amount <= 0 OR char_length(nt) > 200 THEN RETURN 'bad_input'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  PERFORM 1 FROM resellers WHERE id = p_reseller FOR UPDATE;           -- serialises payouts per reseller
  IF NOT FOUND THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'not_found'; END IF;
  SELECT coalesce(sum(floor(((o.total_minor - o.vat_minor - o.refunded_minor)::numeric) * t.reseller_bp / 10000)::bigint), 0)
         - coalesce((SELECT sum(amount_minor) FROM reseller_payouts WHERE reseller_id = p_reseller AND currency = p_currency), 0)
    INTO due
    FROM orders o JOIN tenants t ON t.id = o.tenant_id
   WHERE t.reseller_id = p_reseller AND o.currency = p_currency AND o.status IN ('paid', 'partially_refunded', 'refunded') AND o.paid_at >= t.reseller_joined_at;
  IF p_amount > due THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'exceeds_due'; END IF;
  INSERT INTO reseller_payouts (id, reseller_id, currency, amount_minor, note) VALUES (p_id, p_reseller, p_currency, p_amount, nt) ON CONFLICT DO NOTHING;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

-- CUSTOMER (workspace owner): join a reseller by code, once.  Cannot join your own reseller account, nor a suspended one.
CREATE FUNCTION reseller_join(p_tenant char(26), p_user char(26), p_code text) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); r resellers%ROWTYPE; t tenants%ROWTYPE;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  IF p_code IS NULL OR p_code !~ '^[A-Za-z0-9]{6,12}$' THEN RETURN 'not_found'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO t FROM tenants WHERE id = p_tenant FOR UPDATE;
  IF NOT FOUND OR t.owner_id <> p_user THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'not_owner'; END IF;
  IF t.reseller_id IS NOT NULL THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'already'; END IF;
  SELECT * INTO r FROM resellers WHERE code = p_code::citext AND status = 'active';
  IF NOT FOUND THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'not_found'; END IF;
  IF r.user_id = p_user THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'self'; END IF;
  UPDATE tenants SET reseller_id = r.id, reseller_bp = r.commission_bp, reseller_joined_at = now() WHERE id = p_tenant;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

-- RESELLER: who am I (null when this person is not a reseller).
CREATE FUNCTION reseller_me(p_tenant char(26), p_user char(26)) RETURNS jsonb
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); out jsonb;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT jsonb_build_object('id', trim(id), 'name', name, 'code', upper(code::text), 'commission_bp', commission_bp, 'status', status) INTO out FROM resellers WHERE user_id = p_user;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN out;
END $$;

-- RESELLER: customers (workspace name, joined, number of paid orders) and money per currency {revenue, commission, paid_out, due}.  Nothing else about a customer.
CREATE FUNCTION reseller_report(p_tenant char(26), p_user char(26)) RETURNS jsonb
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); rid char(26); cust jsonb; money jsonb;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT id INTO rid FROM resellers WHERE user_id = p_user;
  IF rid IS NULL THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN NULL; END IF;
  SELECT coalesce(jsonb_agg(jsonb_build_object('name', t.name, 'joined_at', t.reseller_joined_at,
           'paid_orders', (SELECT count(*) FROM orders o WHERE o.tenant_id = t.id AND o.status IN ('paid', 'partially_refunded', 'refunded') AND o.paid_at >= t.reseller_joined_at)) ORDER BY t.reseller_joined_at DESC), '[]'::jsonb)
    INTO cust FROM (SELECT * FROM tenants WHERE reseller_id = rid AND deleted_at IS NULL ORDER BY reseller_joined_at DESC LIMIT 200) t;
  SELECT coalesce(jsonb_object_agg(cur, jsonb_build_object('revenue', rev, 'commission', com, 'paid_out', paid, 'due', com - paid)), '{}'::jsonb) INTO money FROM (
    SELECT c.cur, c.rev, c.com, coalesce((SELECT sum(amount_minor) FROM reseller_payouts WHERE reseller_id = rid AND currency = c.cur), 0) AS paid FROM (
      SELECT o.currency AS cur, sum(o.total_minor - o.vat_minor - o.refunded_minor) AS rev,
             sum(floor(((o.total_minor - o.vat_minor - o.refunded_minor)::numeric) * t.reseller_bp / 10000)::bigint) AS com
        FROM orders o JOIN tenants t ON t.id = o.tenant_id
       WHERE t.reseller_id = rid AND o.status IN ('paid', 'partially_refunded', 'refunded') AND o.paid_at >= t.reseller_joined_at GROUP BY o.currency) c) m;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN jsonb_build_object('customers', cust, 'money', money);
END $$;

-- ANY MEMBER: which reseller (name only) this workspace joined, or null.
CREATE FUNCTION reseller_of(p_tenant char(26), p_user char(26)) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); out text;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT r.name INTO out FROM tenants t JOIN resellers r ON r.id = t.reseller_id WHERE t.id = p_tenant;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN out;
END $$;

-- ADMIN: every reseller with customer count and money per currency (same numbers the reseller sees).
CREATE FUNCTION reseller_admin_overview() RETURNS jsonb
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); out jsonb;
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT coalesce(jsonb_agg(jsonb_build_object('id', trim(r.id), 'name', r.name, 'code', upper(r.code::text), 'commission_bp', r.commission_bp, 'status', r.status, 'email', u.email,
      'customers', (SELECT count(*) FROM tenants t WHERE t.reseller_id = r.id AND t.deleted_at IS NULL),
      'money', coalesce((SELECT jsonb_object_agg(c.cur, jsonb_build_object('commission', c.com, 'paid_out', c.paid, 'due', c.com - c.paid)) FROM (
          SELECT q.cur, q.com, coalesce((SELECT sum(amount_minor) FROM reseller_payouts WHERE reseller_id = r.id AND currency = q.cur), 0) AS paid FROM (
            SELECT o.currency AS cur, sum(floor(((o.total_minor - o.vat_minor - o.refunded_minor)::numeric) * t.reseller_bp / 10000)::bigint) AS com
              FROM orders o JOIN tenants t ON t.id = o.tenant_id
             WHERE t.reseller_id = r.id AND o.status IN ('paid', 'partially_refunded', 'refunded') AND o.paid_at >= t.reseller_joined_at GROUP BY o.currency) q) c), '{}'::jsonb)
    ) ORDER BY r.created_at DESC), '[]'::jsonb) INTO out FROM resellers r JOIN users u ON u.id = r.user_id;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN out;
END $$;

DO $$ DECLARE f text; BEGIN
  FOREACH f IN ARRAY ARRAY['reseller_create(char, char, text, text, integer)', 'reseller_update(char, text, integer)', 'reseller_payout(char, char, text, bigint, text)',
                           'reseller_join(char, char, text)', 'reseller_me(char, char)', 'reseller_report(char, char)', 'reseller_of(char, char)', 'reseller_admin_overview()'] LOOP
    EXECUTE format('REVOKE ALL ON FUNCTION %s FROM PUBLIC, jarvis_app_rw, jarvis_admin', f);
    IF f LIKE 'reseller_join%' OR f LIKE 'reseller_me%' OR f LIKE 'reseller_report%' OR f LIKE 'reseller_of%' THEN
      EXECUTE format('GRANT EXECUTE ON FUNCTION %s TO jarvis_app_rw, jarvis_admin', f);
    ELSE
      EXECUTE format('GRANT EXECUTE ON FUNCTION %s TO jarvis_admin', f);
    END IF;
  END LOOP;
END $$;
