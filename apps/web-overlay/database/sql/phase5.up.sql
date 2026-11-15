-- Phase 5: wallets, credit ledger (append-only), lots (expiry), reservations, usage, packages.
-- See PHASE5_DESIGN.md. Money moves ONLY through the functions below.

CREATE TABLE wallets (
  id                     char(26)      PRIMARY KEY,
  tenant_id              char(26)      NOT NULL UNIQUE REFERENCES tenants (id),
  balance                numeric(20,4) NOT NULL DEFAULT 0 CHECK (balance  >= 0),
  reserved               numeric(20,4) NOT NULL DEFAULT 0 CHECK (reserved >= 0),
  low_balance_threshold  numeric(20,4) CHECK (low_balance_threshold IS NULL OR low_balance_threshold >= 0),
  spending_limit_monthly numeric(20,4) CHECK (spending_limit_monthly IS NULL OR spending_limit_monthly >= 0),
  low_balance_notified_at timestamptz,
  version                bigint        NOT NULL DEFAULT 0,
  created_at             timestamptz   NOT NULL DEFAULT now(),
  updated_at             timestamptz   NOT NULL DEFAULT now(),
  CHECK (reserved <= balance)
);

CREATE TABLE credit_transactions (
  id              bigint        GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  tenant_id       char(26)      NOT NULL REFERENCES tenants (id),
  wallet_id       char(26)      NOT NULL REFERENCES wallets (id),
  type            text          NOT NULL CHECK (type IN ('grant_free', 'grant_plan', 'purchase', 'bonus', 'reservation',
                                  'reservation_release', 'debit', 'refund', 'adjustment', 'expiry')),
  amount          numeric(20,4) NOT NULL,                       -- effect on balance (signed)
  reserved_delta  numeric(20,4) NOT NULL DEFAULT 0,             -- effect on reserved (signed)
  balance_after   numeric(20,4) NOT NULL,
  reserved_after  numeric(20,4) NOT NULL,
  reference_type  varchar(40),
  reference_id    varchar(64),
  idempotency_key varchar(128),
  expires_at      timestamptz,                                  -- for grants that expire
  created_by      char(26)      REFERENCES users (id),
  note            text,
  created_at      timestamptz   NOT NULL DEFAULT now(),
  CHECK (CASE type
    WHEN 'grant_free'          THEN amount > 0 AND reserved_delta = 0
    WHEN 'grant_plan'          THEN amount > 0 AND reserved_delta = 0
    WHEN 'purchase'            THEN amount > 0 AND reserved_delta = 0
    WHEN 'bonus'               THEN amount > 0 AND reserved_delta = 0
    WHEN 'refund'              THEN amount > 0 AND reserved_delta = 0
    WHEN 'reservation'         THEN amount = 0 AND reserved_delta > 0
    WHEN 'reservation_release' THEN amount = 0 AND reserved_delta < 0
    WHEN 'debit'               THEN amount <= 0 AND reserved_delta <= 0
    WHEN 'expiry'              THEN amount < 0 AND reserved_delta = 0
    ELSE amount <> 0 AND reserved_delta = 0                     -- adjustment: either sign
  END)
);
CREATE UNIQUE INDEX credit_tx_idem ON credit_transactions (tenant_id, idempotency_key) WHERE idempotency_key IS NOT NULL;
CREATE INDEX credit_tx_wallet_time ON credit_transactions (wallet_id, created_at);
CREATE INDEX credit_tx_ref ON credit_transactions (reference_type, reference_id);
CREATE TRIGGER credit_transactions_append_only BEFORE UPDATE OR DELETE ON credit_transactions
  FOR EACH ROW EXECUTE FUNCTION reject_mutation();

-- Every credit that enters a wallet is a lot; debits and expiry consume lots.
CREATE TABLE credit_lots (
  id         bigint        GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  wallet_id  char(26)      NOT NULL REFERENCES wallets (id),
  tenant_id  char(26)      NOT NULL REFERENCES tenants (id),
  tx_id      bigint        NOT NULL REFERENCES credit_transactions (id),
  initial    numeric(20,4) NOT NULL CHECK (initial > 0),
  remaining  numeric(20,4) NOT NULL CHECK (remaining >= 0 AND remaining <= initial),
  expires_at timestamptz,
  created_at timestamptz   NOT NULL DEFAULT now()
);
CREATE INDEX credit_lots_wallet ON credit_lots (wallet_id, expires_at) WHERE remaining > 0;

CREATE TABLE credit_reservations (
  id              char(26)      PRIMARY KEY,
  tenant_id       char(26)      NOT NULL REFERENCES tenants (id),
  wallet_id       char(26)      NOT NULL REFERENCES wallets (id),
  amount          numeric(20,4) NOT NULL CHECK (amount > 0),
  status          text          NOT NULL DEFAULT 'held' CHECK (status IN ('held', 'settled', 'released', 'expired')),
  request_id      varchar(128),
  settled_amount  numeric(20,4),
  shortfall       numeric(20,4) NOT NULL DEFAULT 0 CHECK (shortfall >= 0),
  expires_at      timestamptz   NOT NULL,
  created_at      timestamptz   NOT NULL DEFAULT now(),
  updated_at      timestamptz   NOT NULL DEFAULT now()
);
CREATE INDEX credit_reservations_held ON credit_reservations (expires_at) WHERE status = 'held';

CREATE TABLE usage_records (
  id               bigint        GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  tenant_id        char(26)      NOT NULL REFERENCES tenants (id),
  user_id          char(26)      REFERENCES users (id),
  request_id       varchar(128),
  source           text          NOT NULL CHECK (source IN ('chat', 'agent', 'workflow', 'image', 'video', 'voice', 'email', 'storage', 'render')),
  provider_id      char(26)      REFERENCES ai_providers (id),
  model_id         char(26)      REFERENCES ai_models (id),
  agent_id         char(26),
  department       varchar(100),
  input_tokens     bigint        CHECK (input_tokens  IS NULL OR input_tokens  >= 0),
  output_tokens    bigint        CHECK (output_tokens IS NULL OR output_tokens >= 0),
  units            numeric(20,4),
  unit_type        varchar(30),
  provider_cost    numeric(20,6) CHECK (provider_cost IS NULL OR provider_cost >= 0),
  provider_currency char(3),
  credits_charged  numeric(20,4) NOT NULL CHECK (credits_charged >= 0),
  credits_shortfall numeric(20,4) NOT NULL DEFAULT 0 CHECK (credits_shortfall >= 0),
  tx_id            bigint        REFERENCES credit_transactions (id),
  created_at       timestamptz   NOT NULL DEFAULT now()
);
CREATE INDEX usage_tenant_time ON usage_records (tenant_id, created_at);
CREATE TRIGGER usage_records_append_only BEFORE UPDATE OR DELETE ON usage_records
  FOR EACH ROW EXECUTE FUNCTION reject_mutation();

CREATE TABLE credit_alerts (
  id          bigint        GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  tenant_id   char(26)      NOT NULL REFERENCES tenants (id),
  wallet_id   char(26)      NOT NULL REFERENCES wallets (id),
  kind        text          NOT NULL CHECK (kind IN ('low_balance')),
  available   numeric(20,4) NOT NULL,
  created_at  timestamptz   NOT NULL DEFAULT now(),
  handled_at  timestamptz
);
CREATE INDEX credit_alerts_open ON credit_alerts (created_at) WHERE handled_at IS NULL;

CREATE TABLE credit_packages (
  id            char(26)      PRIMARY KEY,
  name          varchar(100)  NOT NULL UNIQUE,
  credits       numeric(20,4) NOT NULL CHECK (credits > 0),
  bonus_credits numeric(20,4) NOT NULL DEFAULT 0 CHECK (bonus_credits >= 0),
  price         numeric(20,4) NOT NULL CHECK (price >= 0),
  currency      char(3)       NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
  expires_after_days integer  CHECK (expires_after_days IS NULL OR expires_after_days > 0),
  sort          integer       NOT NULL DEFAULT 0,
  status        text          NOT NULL DEFAULT 'hidden' CHECK (status IN ('active', 'hidden', 'retired')),
  created_at    timestamptz   NOT NULL DEFAULT now(),
  updated_at    timestamptz   NOT NULL DEFAULT now()
);

CREATE TABLE provider_cost_records (
  id          bigint        GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  provider_id char(26)      NOT NULL REFERENCES ai_providers (id),
  period      char(7)       NOT NULL CHECK (period ~ '^[0-9]{4}-[0-9]{2}$'),
  amount      numeric(20,6) NOT NULL CHECK (amount >= 0),
  currency    char(3)       NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
  source      text          NOT NULL CHECK (source IN ('computed', 'invoice')),
  notes       text,
  created_by  char(26)      REFERENCES users (id),
  created_at  timestamptz   NOT NULL DEFAULT now()
);
CREATE TRIGGER provider_cost_records_append_only BEFORE UPDATE OR DELETE ON provider_cost_records
  FOR EACH ROW EXECUTE FUNCTION reject_mutation();

-- ---------------------------------------------------------------- RLS (read side)
DO $$
DECLARE t text;
BEGIN
  FOREACH t IN ARRAY ARRAY['wallets', 'credit_transactions', 'credit_lots', 'credit_reservations', 'usage_records', 'credit_alerts'] LOOP
    EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', t);
    EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY', t);
    EXECUTE format('CREATE POLICY tenant_isolation ON %I USING (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()))
                    WITH CHECK (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()))', t);
  END LOOP;
END $$;

-- ---------------------------------------------------------------- helpers
CREATE FUNCTION credit_new_id() RETURNS char(26) LANGUAGE sql VOLATILE AS $$
  SELECT substr(replace(gen_random_uuid()::text, '-', ''), 1, 26)
$$;

-- True only for a LOGIN role in jarvis_admin that also switched the bypass flag on. Uses session_user,
-- so it stays correct inside SECURITY DEFINER functions (where current_user is the owner).
CREATE FUNCTION credit_caller_is_admin() RETURNS boolean LANGUAGE sql STABLE AS $$
  SELECT coalesce(current_setting('app.bypass_rls', true), '') = 'on'
     AND pg_has_role(session_user, 'jarvis_admin', 'member')
$$;

CREATE FUNCTION credit_authorize(p_tenant char(26), p_admin_only boolean) RETURNS void LANGUAGE plpgsql STABLE AS $$
BEGIN
  IF credit_caller_is_admin() THEN RETURN; END IF;
  IF p_admin_only OR p_tenant IS NULL OR p_tenant IS DISTINCT FROM (SELECT app_tenant_id()) THEN
    RAISE EXCEPTION 'forbidden' USING ERRCODE = '42501';
  END IF;
END
$$;

-- Credits that can be spent right now (unexpired lots), ignoring reservations.
CREATE FUNCTION credit_usable(p_wallet char(26)) RETURNS numeric LANGUAGE sql STABLE AS $$
  SELECT coalesce(sum(remaining), 0) FROM credit_lots
   WHERE wallet_id = p_wallet AND remaining > 0 AND (expires_at IS NULL OR expires_at > now())
$$;

CREATE FUNCTION credit_wallet_ensure(p_tenant char(26)) RETURNS void LANGUAGE sql AS $$
  INSERT INTO wallets (id, tenant_id) VALUES (credit_new_id(), p_tenant) ON CONFLICT (tenant_id) DO NOTHING
$$;

-- Take `p_amount` out of lots: not-yet-expired ones first (soonest expiry first, never-expiring last),
-- expired-but-unswept ones only as a last resort.
CREATE FUNCTION credit_consume_lots(p_wallet char(26), p_amount numeric) RETURNS void LANGUAGE plpgsql AS $$
DECLARE need numeric := p_amount; lot record; take numeric;
BEGIN
  FOR lot IN
    SELECT id, remaining FROM credit_lots
     WHERE wallet_id = p_wallet AND remaining > 0
     ORDER BY (expires_at IS NOT NULL AND expires_at <= now()), expires_at NULLS LAST, id
     FOR UPDATE
  LOOP
    EXIT WHEN need <= 0;
    take := LEAST(lot.remaining, need);
    UPDATE credit_lots SET remaining = remaining - take WHERE id = lot.id;
    need := need - take;
  END LOOP;
  IF need > 0 THEN RAISE EXCEPTION 'lots_inconsistent' USING ERRCODE = 'XX001'; END IF;
END
$$;

-- Fires one low-balance alert when available credits fall below the threshold; re-arms when they recover.
CREATE FUNCTION credit_check_low(p_wallet char(26)) RETURNS void LANGUAGE plpgsql AS $$
DECLARE w wallets%ROWTYPE; avail numeric;
BEGIN
  SELECT * INTO w FROM wallets WHERE id = p_wallet;
  IF w.low_balance_threshold IS NULL THEN RETURN; END IF;
  avail := credit_usable(p_wallet) - w.reserved;
  IF avail < w.low_balance_threshold AND w.low_balance_notified_at IS NULL THEN
    INSERT INTO credit_alerts (tenant_id, wallet_id, kind, available) VALUES (w.tenant_id, w.id, 'low_balance', avail);
    UPDATE wallets SET low_balance_notified_at = now() WHERE id = w.id;
  ELSIF avail >= w.low_balance_threshold AND w.low_balance_notified_at IS NOT NULL THEN
    UPDATE wallets SET low_balance_notified_at = NULL WHERE id = w.id;
  END IF;
END
$$;

-- Appends a ledger row and moves the wallet in one step. Caller holds the wallet lock.
CREATE FUNCTION credit_post(p_wallet char(26), p_type text, p_amount numeric, p_reserved_delta numeric,
  p_ref_type text, p_ref_id text, p_idem text, p_expires timestamptz, p_actor char(26), p_note text)
RETURNS bigint LANGUAGE plpgsql AS $$
DECLARE w wallets%ROWTYPE; tx bigint;
BEGIN
  UPDATE wallets SET balance = balance + p_amount, reserved = reserved + p_reserved_delta,
                     version = version + 1, updated_at = now()
   WHERE id = p_wallet RETURNING * INTO w;
  INSERT INTO credit_transactions (tenant_id, wallet_id, type, amount, reserved_delta, balance_after, reserved_after,
                                   reference_type, reference_id, idempotency_key, expires_at, created_by, note)
  VALUES (w.tenant_id, w.id, p_type, p_amount, p_reserved_delta, w.balance, w.reserved,
          p_ref_type, p_ref_id, p_idem, p_expires, p_actor, p_note)
  RETURNING id INTO tx;
  RETURN tx;
END
$$;

-- ---------------------------------------------------------------- grants (credits in)
-- Plan grants, free grants, purchases and refunds. Purchases/refunds MUST cite a payment reference.
CREATE FUNCTION credit_grant(p_tenant char(26), p_type text, p_amount numeric, p_idem text,
  p_ref_type text DEFAULT NULL, p_ref_id text DEFAULT NULL, p_expires timestamptz DEFAULT NULL,
  p_actor char(26) DEFAULT NULL, p_note text DEFAULT NULL)
RETURNS TABLE (tx_id bigint, replayed boolean)
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); w wallets%ROWTYPE; t credit_transactions%ROWTYPE; tx bigint;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_type NOT IN ('grant_free', 'grant_plan', 'purchase', 'refund') THEN RAISE EXCEPTION 'invalid_type' USING ERRCODE = 'P0001'; END IF;
  IF p_amount IS NULL OR p_amount <= 0 THEN RAISE EXCEPTION 'invalid_amount' USING ERRCODE = 'P0001'; END IF;
  IF p_idem IS NULL OR length(p_idem) < 8 THEN RAISE EXCEPTION 'idempotency_key_required' USING ERRCODE = 'P0001'; END IF;
  IF p_type IN ('purchase', 'refund') AND (p_ref_type IS NULL OR p_ref_id IS NULL) THEN
    RAISE EXCEPTION 'reference_required' USING ERRCODE = 'P0001';
  END IF;
  IF p_expires IS NOT NULL AND p_expires <= now() THEN RAISE EXCEPTION 'already_expired' USING ERRCODE = 'P0001'; END IF;

  PERFORM set_config('app.bypass_rls', 'on', true);
  PERFORM credit_wallet_ensure(p_tenant);
  SELECT * INTO w FROM wallets WHERE tenant_id = p_tenant FOR UPDATE;

  SELECT * INTO t FROM credit_transactions WHERE tenant_id = p_tenant AND idempotency_key = p_idem;
  IF FOUND THEN
    IF t.type <> p_type OR t.amount <> p_amount THEN RAISE EXCEPTION 'idempotency_conflict' USING ERRCODE = 'P0001'; END IF;
    PERFORM set_config('app.bypass_rls', prev, true);
    RETURN QUERY SELECT t.id, true; RETURN;
  END IF;

  tx := credit_post(w.id, p_type, p_amount, 0, p_ref_type, p_ref_id, p_idem, p_expires, p_actor, p_note);
  INSERT INTO credit_lots (wallet_id, tenant_id, tx_id, initial, remaining, expires_at) VALUES (w.id, p_tenant, tx, p_amount, p_amount, p_expires);
  PERFORM credit_check_low(w.id);
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN QUERY SELECT tx, false;
END
$$;

-- Admin-only manual correction or bonus. Negative adjustments cannot dig into reserved credits.
CREATE FUNCTION credit_adjust(p_tenant char(26), p_type text, p_amount numeric, p_reason text, p_idem text, p_actor char(26))
RETURNS TABLE (tx_id bigint, replayed boolean)
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); w wallets%ROWTYPE; t credit_transactions%ROWTYPE; tx bigint;
BEGIN
  PERFORM credit_authorize(p_tenant, true);
  IF p_type NOT IN ('adjustment', 'bonus') THEN RAISE EXCEPTION 'invalid_type' USING ERRCODE = 'P0001'; END IF;
  IF p_amount IS NULL OR p_amount = 0 OR (p_type = 'bonus' AND p_amount < 0) THEN RAISE EXCEPTION 'invalid_amount' USING ERRCODE = 'P0001'; END IF;
  IF p_reason IS NULL OR length(btrim(p_reason)) < 3 THEN RAISE EXCEPTION 'reason_required' USING ERRCODE = 'P0001'; END IF;
  IF p_idem IS NULL OR length(p_idem) < 8 THEN RAISE EXCEPTION 'idempotency_key_required' USING ERRCODE = 'P0001'; END IF;

  PERFORM set_config('app.bypass_rls', 'on', true);
  PERFORM credit_wallet_ensure(p_tenant);
  SELECT * INTO w FROM wallets WHERE tenant_id = p_tenant FOR UPDATE;
  SELECT * INTO t FROM credit_transactions WHERE tenant_id = p_tenant AND idempotency_key = p_idem;
  IF FOUND THEN
    IF t.type <> p_type OR t.amount <> p_amount THEN RAISE EXCEPTION 'idempotency_conflict' USING ERRCODE = 'P0001'; END IF;
    PERFORM set_config('app.bypass_rls', prev, true);
    RETURN QUERY SELECT t.id, true; RETURN;
  END IF;

  IF p_amount < 0 THEN
    IF w.balance + p_amount < w.reserved THEN RAISE EXCEPTION 'insufficient_credits' USING ERRCODE = 'P0001'; END IF;
    PERFORM credit_consume_lots(w.id, -p_amount);
    tx := credit_post(w.id, p_type, p_amount, 0, 'admin', NULL, p_idem, NULL, p_actor, p_reason);
  ELSE
    tx := credit_post(w.id, p_type, p_amount, 0, 'admin', NULL, p_idem, NULL, p_actor, p_reason);
    INSERT INTO credit_lots (wallet_id, tenant_id, tx_id, initial, remaining) VALUES (w.id, p_tenant, tx, p_amount, p_amount);
  END IF;
  PERFORM credit_check_low(w.id);
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN QUERY SELECT tx, false;
END
$$;

-- ---------------------------------------------------------------- spending (credits out)
CREATE FUNCTION credit_reserve(p_tenant char(26), p_amount numeric, p_idem text, p_request_id text DEFAULT NULL, p_ttl_seconds integer DEFAULT 900)
RETURNS TABLE (reservation_id char(26), replayed boolean)
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); w wallets%ROWTYPE; t credit_transactions%ROWTYPE;
        rid char(26); spent numeric;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_amount IS NULL OR p_amount <= 0 THEN RAISE EXCEPTION 'invalid_amount' USING ERRCODE = 'P0001'; END IF;
  IF p_idem IS NULL OR length(p_idem) < 8 THEN RAISE EXCEPTION 'idempotency_key_required' USING ERRCODE = 'P0001'; END IF;
  IF p_ttl_seconds IS NULL OR p_ttl_seconds NOT BETWEEN 10 AND 86400 THEN RAISE EXCEPTION 'invalid_ttl' USING ERRCODE = 'P0001'; END IF;

  PERFORM set_config('app.bypass_rls', 'on', true);
  PERFORM credit_wallet_ensure(p_tenant);
  SELECT * INTO w FROM wallets WHERE tenant_id = p_tenant FOR UPDATE;

  SELECT * INTO t FROM credit_transactions WHERE tenant_id = p_tenant AND idempotency_key = p_idem;
  IF FOUND THEN
    IF t.type <> 'reservation' OR t.reserved_delta <> p_amount THEN RAISE EXCEPTION 'idempotency_conflict' USING ERRCODE = 'P0001'; END IF;
    PERFORM set_config('app.bypass_rls', prev, true);
    RETURN QUERY SELECT t.reference_id::char(26), true; RETURN;
  END IF;

  IF credit_usable(w.id) - w.reserved < p_amount THEN RAISE EXCEPTION 'insufficient_credits' USING ERRCODE = 'P0001'; END IF;
  IF w.spending_limit_monthly IS NOT NULL THEN
    SELECT coalesce(-sum(amount), 0) INTO spent FROM credit_transactions
     WHERE wallet_id = w.id AND type = 'debit' AND created_at >= date_trunc('month', now() AT TIME ZONE 'UTC') AT TIME ZONE 'UTC';
    IF spent + w.reserved + p_amount > w.spending_limit_monthly THEN RAISE EXCEPTION 'spending_limit_reached' USING ERRCODE = 'P0001'; END IF;
  END IF;

  rid := credit_new_id();
  INSERT INTO credit_reservations (id, tenant_id, wallet_id, amount, request_id, expires_at)
  VALUES (rid, p_tenant, w.id, p_amount, p_request_id, now() + make_interval(secs => p_ttl_seconds));
  PERFORM credit_post(w.id, 'reservation', 0, p_amount, 'reservation', rid, p_idem, NULL, NULL, NULL);
  PERFORM credit_check_low(w.id);
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN QUERY SELECT rid, false;
END
$$;

-- Final charge. p_usage (jsonb) becomes one usage_records row in the same step.
CREATE FUNCTION credit_settle(p_reservation char(26), p_actual numeric, p_idem text, p_usage jsonb DEFAULT '{}'::jsonb)
RETURNS TABLE (tx_id bigint, charged numeric, shortfall numeric, replayed boolean)
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); r credit_reservations%ROWTYPE; w wallets%ROWTYPE;
        t credit_transactions%ROWTYPE; held numeric; extra_ok numeric; charge numeric; short numeric; tx bigint;
BEGIN
  IF p_actual IS NULL OR p_actual < 0 THEN RAISE EXCEPTION 'invalid_amount' USING ERRCODE = 'P0001'; END IF;
  IF p_idem IS NULL OR length(p_idem) < 8 THEN RAISE EXCEPTION 'idempotency_key_required' USING ERRCODE = 'P0001'; END IF;

  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO r FROM credit_reservations WHERE id = p_reservation;
  IF NOT FOUND THEN RAISE EXCEPTION 'reservation_not_found' USING ERRCODE = 'P0001'; END IF;
  PERFORM set_config('app.bypass_rls', prev, true);
  PERFORM credit_authorize(r.tenant_id, false);
  PERFORM set_config('app.bypass_rls', 'on', true);

  SELECT * INTO w FROM wallets WHERE id = r.wallet_id FOR UPDATE;
  SELECT * INTO r FROM credit_reservations WHERE id = p_reservation FOR UPDATE;   -- re-read under the wallet lock

  SELECT * INTO t FROM credit_transactions WHERE tenant_id = r.tenant_id AND idempotency_key = p_idem;
  IF FOUND THEN
    IF t.type <> 'debit' OR t.reference_id IS DISTINCT FROM p_reservation THEN RAISE EXCEPTION 'idempotency_conflict' USING ERRCODE = 'P0001'; END IF;
    PERFORM set_config('app.bypass_rls', prev, true);
    RETURN QUERY SELECT t.id, -t.amount, r.shortfall, true; RETURN;
  END IF;
  IF r.status NOT IN ('held', 'expired') THEN RAISE EXCEPTION 'reservation_not_open' USING ERRCODE = 'P0001'; END IF;

  held := CASE WHEN r.status = 'held' THEN r.amount ELSE 0 END;     -- an expired one was already un-reserved
  IF p_actual <= held THEN
    charge := p_actual;
  ELSE
    extra_ok := GREATEST(credit_usable(w.id) - (w.reserved - held) - held, 0);   -- free credits beyond everything reserved
    charge := held + LEAST(p_actual - held, extra_ok);
  END IF;
  charge := LEAST(charge, w.balance);
  short := p_actual - charge;

  PERFORM credit_consume_lots(w.id, charge);
  tx := credit_post(w.id, 'debit', -charge, -held, 'reservation', p_reservation, p_idem, NULL, NULL, NULL);
  UPDATE credit_reservations SET status = 'settled', settled_amount = charge, shortfall = short, updated_at = now() WHERE id = r.id;

  INSERT INTO usage_records (tenant_id, user_id, request_id, source, provider_id, model_id, agent_id, department,
                             input_tokens, output_tokens, units, unit_type, provider_cost, provider_currency,
                             credits_charged, credits_shortfall, tx_id)
  VALUES (r.tenant_id, nullif(p_usage->>'user_id', '')::char(26), coalesce(p_usage->>'request_id', r.request_id),
          coalesce(p_usage->>'source', 'chat'), nullif(p_usage->>'provider_id', '')::char(26), nullif(p_usage->>'model_id', '')::char(26),
          nullif(p_usage->>'agent_id', '')::char(26), nullif(p_usage->>'department', ''),
          nullif(p_usage->>'input_tokens', '')::bigint, nullif(p_usage->>'output_tokens', '')::bigint,
          nullif(p_usage->>'units', '')::numeric, nullif(p_usage->>'unit_type', ''),
          nullif(p_usage->>'provider_cost', '')::numeric, nullif(p_usage->>'provider_currency', ''),
          charge, short, tx);
  PERFORM credit_check_low(w.id);
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN QUERY SELECT tx, charge, short, false;
END
$$;

CREATE FUNCTION credit_release(p_reservation char(26), p_idem text)
RETURNS TABLE (tx_id bigint, replayed boolean)
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); r credit_reservations%ROWTYPE; w wallets%ROWTYPE;
        t credit_transactions%ROWTYPE; tx bigint;
BEGIN
  IF p_idem IS NULL OR length(p_idem) < 8 THEN RAISE EXCEPTION 'idempotency_key_required' USING ERRCODE = 'P0001'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO r FROM credit_reservations WHERE id = p_reservation;
  IF NOT FOUND THEN RAISE EXCEPTION 'reservation_not_found' USING ERRCODE = 'P0001'; END IF;
  PERFORM set_config('app.bypass_rls', prev, true);
  PERFORM credit_authorize(r.tenant_id, false);
  PERFORM set_config('app.bypass_rls', 'on', true);

  SELECT * INTO w FROM wallets WHERE id = r.wallet_id FOR UPDATE;
  SELECT * INTO r FROM credit_reservations WHERE id = p_reservation FOR UPDATE;
  SELECT * INTO t FROM credit_transactions WHERE tenant_id = r.tenant_id AND idempotency_key = p_idem;
  IF FOUND THEN
    IF t.type <> 'reservation_release' OR t.reference_id IS DISTINCT FROM p_reservation THEN RAISE EXCEPTION 'idempotency_conflict' USING ERRCODE = 'P0001'; END IF;
    PERFORM set_config('app.bypass_rls', prev, true);
    RETURN QUERY SELECT t.id, true; RETURN;
  END IF;
  IF r.status <> 'held' THEN RAISE EXCEPTION 'reservation_not_open' USING ERRCODE = 'P0001'; END IF;

  tx := credit_post(w.id, 'reservation_release', 0, -r.amount, 'reservation', p_reservation, p_idem, NULL, NULL, NULL);
  UPDATE credit_reservations SET status = 'released', updated_at = now() WHERE id = r.id;
  PERFORM credit_check_low(w.id);
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN QUERY SELECT tx, false;
END
$$;

-- ---------------------------------------------------------------- scheduled sweeps (time-based, safe for anyone to call)
-- Releases reservations whose request never finished (crash/timeout). A late settle can still charge them.
CREATE FUNCTION credit_release_stale() RETURNS integer
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); r record; n integer := 0; w wallets%ROWTYPE; cur credit_reservations%ROWTYPE;
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  FOR r IN SELECT id, wallet_id FROM credit_reservations WHERE status = 'held' AND expires_at < now() ORDER BY wallet_id, id LIMIT 1000 LOOP
    SELECT * INTO w FROM wallets WHERE id = r.wallet_id FOR UPDATE;
    SELECT * INTO cur FROM credit_reservations WHERE id = r.id FOR UPDATE;
    CONTINUE WHEN cur.status <> 'held';     -- settled/released while we waited for the lock
    PERFORM credit_post(w.id, 'reservation_release', 0, -cur.amount, 'reservation', cur.id, 'stale:' || cur.id, NULL, NULL, 'expired reservation');
    UPDATE credit_reservations SET status = 'expired', updated_at = now() WHERE id = cur.id;
    PERFORM credit_check_low(w.id);
    n := n + 1;
  END LOOP;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN n;
END
$$;

-- Removes expired credits (never below what is reserved; the rest is taken on the next sweep).
CREATE FUNCTION credit_expire_lots() RETURNS integer
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); r record; n integer := 0; w wallets%ROWTYPE; lot credit_lots%ROWTYPE; take numeric;
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  FOR r IN SELECT id, wallet_id FROM credit_lots WHERE remaining > 0 AND expires_at <= now() ORDER BY wallet_id, id LIMIT 1000 LOOP
    SELECT * INTO w FROM wallets WHERE id = r.wallet_id FOR UPDATE;
    SELECT * INTO lot FROM credit_lots WHERE id = r.id FOR UPDATE;
    take := LEAST(lot.remaining, w.balance - w.reserved);
    CONTINUE WHEN take <= 0;
    UPDATE credit_lots SET remaining = remaining - take WHERE id = lot.id;
    PERFORM credit_post(w.id, 'expiry', -take, 0, 'credit_lot', lot.id::text, 'expire:' || lot.id || ':' || (lot.remaining - take)::text, NULL, NULL, 'credits expired');
    PERFORM credit_check_low(w.id);
    n := n + 1;
  END LOOP;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN n;
END
$$;

-- Wallet settings the tenant may change (permission is checked by the app).
CREATE FUNCTION credit_wallet_configure(p_tenant char(26), p_threshold numeric, p_limit numeric) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), '');
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF (p_threshold IS NOT NULL AND p_threshold < 0) OR (p_limit IS NOT NULL AND p_limit < 0) THEN RAISE EXCEPTION 'invalid_amount' USING ERRCODE = 'P0001'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  PERFORM credit_wallet_ensure(p_tenant);
  UPDATE wallets SET low_balance_threshold = p_threshold, spending_limit_monthly = p_limit,
                     low_balance_notified_at = NULL, version = version + 1, updated_at = now() WHERE tenant_id = p_tenant;
  PERFORM credit_check_low((SELECT id FROM wallets WHERE tenant_id = p_tenant));
  PERFORM set_config('app.bypass_rls', prev, true);
END
$$;

-- Books must balance. Returns one row per violated invariant (empty = healthy). Admin only.
CREATE FUNCTION credit_reconcile(p_tenant char(26) DEFAULT NULL)
RETURNS TABLE (tenant_id char(26), check_name text, expected numeric, actual numeric)
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), '');
BEGIN
  PERFORM credit_authorize(NULL, true);
  PERFORM set_config('app.bypass_rls', 'on', true);
  RETURN QUERY
  WITH w AS (SELECT * FROM wallets WHERE p_tenant IS NULL OR wallets.tenant_id = p_tenant),
  tx AS (SELECT t.wallet_id, sum(t.amount) AS amt, sum(t.reserved_delta) AS rsv FROM credit_transactions t GROUP BY t.wallet_id),
  lots AS (SELECT l.wallet_id, sum(l.remaining) AS rem FROM credit_lots l GROUP BY l.wallet_id),
  held AS (SELECT r.wallet_id, sum(r.amount) AS amt FROM credit_reservations r WHERE r.status = 'held' GROUP BY r.wallet_id),
  usage AS (SELECT u.tenant_id, sum(u.credits_charged) AS used FROM usage_records u GROUP BY u.tenant_id),
  debits AS (SELECT d.tenant_id, -sum(d.amount) AS spent FROM credit_transactions d
              WHERE d.type = 'debit' AND d.reference_type = 'reservation' GROUP BY d.tenant_id)
  SELECT w.tenant_id, 'balance_vs_ledger', coalesce(tx.amt, 0), w.balance FROM w LEFT JOIN tx ON tx.wallet_id = w.id WHERE w.balance <> coalesce(tx.amt, 0)
  UNION ALL
  SELECT w.tenant_id, 'reserved_vs_ledger', coalesce(tx.rsv, 0), w.reserved FROM w LEFT JOIN tx ON tx.wallet_id = w.id WHERE w.reserved <> coalesce(tx.rsv, 0)
  UNION ALL
  SELECT w.tenant_id, 'reserved_vs_held_reservations', coalesce(held.amt, 0), w.reserved FROM w LEFT JOIN held ON held.wallet_id = w.id WHERE w.reserved <> coalesce(held.amt, 0)
  UNION ALL
  SELECT w.tenant_id, 'balance_vs_lots', coalesce(lots.rem, 0), w.balance FROM w LEFT JOIN lots ON lots.wallet_id = w.id WHERE w.balance <> coalesce(lots.rem, 0)
  UNION ALL
  SELECT w.tenant_id, 'usage_vs_debits', coalesce(debits.spent, 0), coalesce(usage.used, 0) FROM w
    LEFT JOIN usage ON usage.tenant_id = w.tenant_id LEFT JOIN debits ON debits.tenant_id = w.tenant_id
   WHERE coalesce(debits.spent, 0) <> coalesce(usage.used, 0);
  PERFORM set_config('app.bypass_rls', prev, true);
END
$$;

-- ---------------------------------------------------------------- privileges
REVOKE INSERT, UPDATE, DELETE, TRUNCATE ON wallets, credit_transactions, credit_lots, credit_reservations,
  usage_records, credit_alerts, credit_packages, provider_cost_records FROM jarvis_app_rw;
GRANT SELECT ON wallets, credit_transactions, credit_lots, credit_reservations, usage_records, credit_alerts,
  credit_packages, provider_cost_records TO jarvis_app_rw;
GRANT UPDATE (handled_at) ON credit_alerts TO jarvis_admin;      -- the notifier job marks alerts handled
GRANT INSERT, UPDATE, DELETE ON credit_packages TO jarvis_admin;
GRANT INSERT ON provider_cost_records TO jarvis_admin;

REVOKE ALL ON FUNCTION credit_new_id(), credit_caller_is_admin(), credit_authorize(char, boolean), credit_usable(char),
  credit_wallet_ensure(char), credit_consume_lots(char, numeric), credit_check_low(char),
  credit_post(char, text, numeric, numeric, text, text, text, timestamptz, char, text),
  credit_grant(char, text, numeric, text, text, text, timestamptz, char, text),
  credit_adjust(char, text, numeric, text, text, char),
  credit_reserve(char, numeric, text, text, integer), credit_settle(char, numeric, text, jsonb),
  credit_release(char, text), credit_release_stale(), credit_expire_lots(),
  credit_wallet_configure(char, numeric, numeric), credit_reconcile(char) FROM PUBLIC;
-- Public API of the engine (internal helpers stay unexposed):
GRANT EXECUTE ON FUNCTION credit_usable(char), credit_grant(char, text, numeric, text, text, text, timestamptz, char, text),
  credit_reserve(char, numeric, text, text, integer), credit_settle(char, numeric, text, jsonb),
  credit_release(char, text), credit_release_stale(), credit_expire_lots(),
  credit_wallet_configure(char, numeric, numeric), credit_caller_is_admin(), credit_authorize(char, boolean) TO jarvis_app_rw;
GRANT EXECUTE ON FUNCTION credit_adjust(char, text, numeric, text, text, char), credit_reconcile(char) TO jarvis_admin;
