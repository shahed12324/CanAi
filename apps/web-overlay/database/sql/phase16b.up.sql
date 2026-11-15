-- Phase 16b: fixed assets with straight-line depreciation, and inventory valuation.
-- Everything that reaches the books goes through journal_post (so the period lock and the balance rules apply). No table here is written directly by the app role.
-- NB: PERFORM sets FOUND, so existence is tested on a row's id, never on FOUND after a set_config.

-- ---------------------------------------------------------------------------------------------------------------------
-- Fixed assets. Depreciation is straight-line by month, starting in the month of purchase (a full month), over life_months, down to the salvage value.
-- Month k (0-based) = floor(base*(k+1)/life) - floor(base*k/life), base = cost - salvage: the months always add up to exactly `base`, no poisha lost to rounding.
CREATE TABLE fixed_assets (
  id                 char(26) PRIMARY KEY,
  tenant_id          char(26)     NOT NULL REFERENCES tenants (id),
  name               varchar(120) NOT NULL CHECK (btrim(name) <> ''),
  asset_account_id   char(26)     NOT NULL,
  accum_account_id   char(26)     NOT NULL,
  expense_account_id char(26)     NOT NULL,
  purchase_date      date         NOT NULL CHECK (purchase_date >= DATE '2000-01-01'),
  cost_minor         bigint       NOT NULL CHECK (cost_minor > 0 AND cost_minor <= 999999999999),
  salvage_minor      bigint       NOT NULL DEFAULT 0 CHECK (salvage_minor >= 0),
  life_months        integer      NOT NULL CHECK (life_months BETWEEN 1 AND 600),
  status             text         NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'disposed')),
  disposed_date      date,
  proceeds_minor     bigint       CHECK (proceeds_minor IS NULL OR proceeds_minor >= 0),
  disposal_entry_id  char(26),
  created_by         char(26)     NOT NULL,
  created_at         timestamptz  NOT NULL DEFAULT now(),
  UNIQUE (id, tenant_id),
  FOREIGN KEY (asset_account_id, tenant_id)   REFERENCES accounts (id, tenant_id),
  FOREIGN KEY (accum_account_id, tenant_id)   REFERENCES accounts (id, tenant_id),
  FOREIGN KEY (expense_account_id, tenant_id) REFERENCES accounts (id, tenant_id),
  FOREIGN KEY (disposal_entry_id, tenant_id)  REFERENCES journal_entries (id, tenant_id),
  CONSTRAINT salvage_below_cost CHECK (salvage_minor < cost_minor),
  CONSTRAINT disposal_shape CHECK ((status = 'disposed') = (disposed_date IS NOT NULL AND disposal_entry_id IS NOT NULL AND proceeds_minor IS NOT NULL) AND (status = 'disposed' OR (disposed_date IS NULL AND disposal_entry_id IS NULL AND proceeds_minor IS NULL))),
  CONSTRAINT disposal_after_purchase CHECK (disposed_date IS NULL OR disposed_date >= purchase_date)
);
CREATE INDEX fixed_assets_tenant ON fixed_assets (tenant_id, status, purchase_date);

CREATE TABLE fa_dep_lines (
  asset_id     char(26)    NOT NULL,
  tenant_id    char(26)    NOT NULL REFERENCES tenants (id),
  period       date        NOT NULL CHECK (period = date_trunc('month', period)::date),
  amount_minor bigint      NOT NULL CHECK (amount_minor > 0),
  entry_id     char(26)    NOT NULL,
  created_at   timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY (asset_id, period),
  FOREIGN KEY (asset_id, tenant_id) REFERENCES fixed_assets (id, tenant_id),
  FOREIGN KEY (entry_id, tenant_id) REFERENCES journal_entries (id, tenant_id)
);
CREATE TRIGGER fa_dep_lines_no_change BEFORE UPDATE OR DELETE ON fa_dep_lines FOR EACH ROW EXECUTE FUNCTION catalog_log_immutable();

-- ---------------------------------------------------------------------------------------------------------------------
-- Inventory: unit cost per variant, kept as a dated history (append-only); the latest one on or before a day is that day's cost.
CREATE TABLE inv_costs (
  seq             bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  tenant_id       char(26)    NOT NULL REFERENCES tenants (id),
  variant_id      char(26)    NOT NULL,
  unit_cost_minor bigint      NOT NULL CHECK (unit_cost_minor >= 0 AND unit_cost_minor <= 100000000000),
  effective_date  date        NOT NULL CHECK (effective_date >= DATE '2000-01-01'),
  set_by          char(26)    NOT NULL,
  created_at      timestamptz NOT NULL DEFAULT now(),
  FOREIGN KEY (variant_id, tenant_id) REFERENCES product_variants (id, tenant_id)
);
CREATE INDEX inv_costs_variant ON inv_costs (variant_id, effective_date DESC, seq DESC);
CREATE TRIGGER inv_costs_no_change BEFORE UPDATE OR DELETE ON inv_costs FOR EACH ROW EXECUTE FUNCTION catalog_log_immutable();

DO $$ DECLARE t text; BEGIN
  FOREACH t IN ARRAY ARRAY['fixed_assets', 'fa_dep_lines', 'inv_costs'] LOOP
    EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', t);
    EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY', t);
    EXECUTE format('CREATE POLICY tenant_isolation ON %I USING (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin())) WITH CHECK (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()))', t);
    EXECUTE format('REVOKE ALL ON %I FROM jarvis_app_rw, jarvis_admin', t);
    EXECUTE format('GRANT SELECT ON %I TO jarvis_app_rw, jarvis_admin', t);
  END LOOP;
END $$;

-- ---------------------------------------------------------------------------------------------------------------------
-- Depreciation for month k (0-based) of an asset.
CREATE FUNCTION fa_month_amount(p_cost bigint, p_salvage bigint, p_life integer, p_k integer) RETURNS bigint LANGUAGE sql IMMUTABLE AS $$
  SELECT CASE WHEN p_k < 0 OR p_k >= p_life THEN 0::bigint
              ELSE floor(((p_cost - p_salvage)::numeric * (p_k + 1)) / p_life)::bigint - floor(((p_cost - p_salvage)::numeric * p_k) / p_life)::bigint END
$$;

-- Register an asset bought earlier (the purchase itself is a bill/expense/entry on the asset account). Accumulated-depreciation and depreciation-expense accounts are the chart's system accounts.
-- result: ok | bad_input | bad_account | no_system_account
CREATE FUNCTION fa_register(p_id char(26), p_tenant char(26), p_user char(26), p_name text, p_asset_account char(26), p_date date, p_cost bigint, p_salvage bigint, p_life integer) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); a accounts%ROWTYPE; acc_id char(26); exp_id char(26);
BEGIN
  PERFORM acct_open(p_tenant);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  IF p_name IS NULL OR btrim(p_name) = '' OR p_date IS NULL OR p_date < DATE '2000-01-01' OR p_date > (now() AT TIME ZONE 'Asia/Dhaka')::date
     OR p_cost IS NULL OR p_cost <= 0 OR p_cost > 999999999999 OR p_salvage IS NULL OR p_salvage < 0 OR p_salvage >= p_cost OR p_life IS NULL OR p_life < 1 OR p_life > 600 THEN RETURN 'bad_input'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO a FROM accounts WHERE id = p_asset_account AND tenant_id = p_tenant AND active AND postable AND type = 'asset' AND NOT cash_account
     AND coalesce(system_key, '') NOT IN ('receivable', 'courier_receivable', 'payable', 'inventory', 'accum_depreciation', 'vat_input', 'prepaid');
  IF a.id IS NULL THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'bad_account'; END IF;
  SELECT id INTO acc_id FROM accounts WHERE tenant_id = p_tenant AND system_key = 'accum_depreciation' AND active AND postable;
  SELECT id INTO exp_id FROM accounts WHERE tenant_id = p_tenant AND system_key = 'depreciation' AND active AND postable;
  IF acc_id IS NULL OR exp_id IS NULL THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'no_system_account'; END IF;
  INSERT INTO fixed_assets (id, tenant_id, name, asset_account_id, accum_account_id, expense_account_id, purchase_date, cost_minor, salvage_minor, life_months, created_by)
  VALUES (p_id, p_tenant, left(btrim(p_name), 120), a.id, acc_id, exp_id, p_date, p_cost, p_salvage, p_life, p_user);
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

-- Months still to depreciate up to a day (the month containing it included): one row per (asset, month) with its amount.
CREATE FUNCTION fa_pending(p_asof date) RETURNS TABLE (asset_id char(26), period date, amount_minor bigint)
LANGUAGE sql STABLE AS $$
  SELECT f.id, m.period, fa_month_amount(f.cost_minor, f.salvage_minor, f.life_months, ((extract(year FROM m.period) - extract(year FROM f.purchase_date)) * 12 + extract(month FROM m.period) - extract(month FROM f.purchase_date))::integer)
    FROM fixed_assets f
    CROSS JOIN LATERAL generate_series(date_trunc('month', f.purchase_date::timestamp), date_trunc('month', LEAST(p_asof, coalesce(f.disposed_date - 1, p_asof))::timestamp), interval '1 month') AS g(d)
    CROSS JOIN LATERAL (SELECT g.d::date AS period) m
   WHERE (f.status = 'active' OR date_trunc('month', f.disposed_date::timestamp)::date > m.period)
     AND ((extract(year FROM m.period) - extract(year FROM f.purchase_date)) * 12 + extract(month FROM m.period) - extract(month FROM f.purchase_date)) < f.life_months
     AND NOT EXISTS (SELECT 1 FROM fa_dep_lines l WHERE l.asset_id = f.id AND l.period = m.period)
   ORDER BY m.period, f.id
$$;

-- Post one month's depreciation for every asset that still lacks it (a late-registered asset is caught up by running its earlier months). One journal entry per month,
-- dated the last day of the month (or today for the current month). Months can be run in any order. result: ok | nothing_to_do | bad_month | <journal_post result: locked ...>
CREATE FUNCTION fa_run(p_entry char(26), p_tenant char(26), p_user char(26), p_month date) RETURNS TABLE (result text, assets integer, total_minor bigint)
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); mon date; today date := (now() AT TIME ZONE 'Asia/Dhaka')::date; edate date; jl jsonb := '[]'::jsonb; r record; jr record; n integer; tot bigint; rows jsonb;
BEGIN
  PERFORM acct_open(p_tenant);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  IF p_month IS NULL OR p_month < DATE '2000-01-01' THEN RETURN QUERY SELECT 'bad_month'::text, 0, 0::bigint; RETURN; END IF;
  mon := date_trunc('month', p_month::timestamp)::date;
  IF mon > date_trunc('month', today::timestamp)::date THEN RETURN QUERY SELECT 'bad_month'::text, 0, 0::bigint; RETURN; END IF;
  edate := LEAST((mon + interval '1 month - 1 day')::date, today);
  PERFORM set_config('app.bypass_rls', 'on', true);
  rows := (SELECT jsonb_agg(jsonb_build_object('a', q.id, 'amt', q.amt, 'e', q.exp_id, 'c', q.acc_id)) FROM (
    SELECT f.id, f.expense_account_id AS exp_id, f.accum_account_id AS acc_id,
           fa_month_amount(f.cost_minor, f.salvage_minor, f.life_months, ((extract(year FROM mon) - extract(year FROM f.purchase_date)) * 12 + extract(month FROM mon) - extract(month FROM f.purchase_date))::integer) AS amt
      FROM fixed_assets f
     WHERE f.tenant_id = p_tenant AND date_trunc('month', f.purchase_date::timestamp)::date <= mon
       AND (f.status = 'active' OR date_trunc('month', f.disposed_date::timestamp)::date > mon)
       AND NOT EXISTS (SELECT 1 FROM fa_dep_lines l WHERE l.asset_id = f.id AND l.period = mon)) q WHERE q.amt > 0);
  IF rows IS NULL THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN QUERY SELECT 'nothing_to_do'::text, 0, 0::bigint; RETURN; END IF;
  n := jsonb_array_length(rows);
  SELECT sum((x->>'amt')::bigint) INTO tot FROM jsonb_array_elements(rows) x;
  FOR r IN SELECT x->>'e' AS exp_id, x->>'c' AS acc_id, sum((x->>'amt')::bigint) AS amt FROM jsonb_array_elements(rows) x GROUP BY 1, 2 ORDER BY 1, 2 LOOP
    jl := jl || jsonb_build_object('account_id', r.exp_id, 'debit_minor', r.amt, 'credit_minor', 0) || jsonb_build_object('account_id', r.acc_id, 'debit_minor', 0, 'credit_minor', r.amt);
  END LOOP;
  PERFORM set_config('app.bypass_rls', prev, true);
  SELECT * INTO jr FROM journal_post(p_entry, p_tenant, p_user, edate, 'Depreciation ' || to_char(mon, 'YYYY-MM'), 'system', 'depr:' || to_char(mon, 'YYYY-MM'), jl);
  IF jr.result <> 'ok' THEN RETURN QUERY SELECT jr.result, 0, 0::bigint; RETURN; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  INSERT INTO fa_dep_lines (asset_id, tenant_id, period, amount_minor, entry_id) SELECT x->>'a', p_tenant, mon, (x->>'amt')::bigint, p_entry FROM jsonb_array_elements(rows) x;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN QUERY SELECT 'ok'::text, n, tot;
END $$;

-- Sell or scrap an asset. All earlier months must already be depreciated (the disposal month itself is not depreciated). Proceeds go to a cash/bank account; the difference
-- to cost - accumulated is the gain (Other Income) or loss (Miscellaneous Expense). result: ok | not_active | bad_date | depreciate_first | bad_proceeds | bad_pay_account | no_system_account | <journal_post result>
CREATE FUNCTION fa_dispose(p_entry char(26), p_asset char(26), p_user char(26), p_date date, p_proceeds bigint, p_pay_account char(26)) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); f fixed_assets%ROWTYPE; acc bigint; pay accounts%ROWTYPE; gain char(26); loss char(26); diff bigint; jl jsonb := '[]'::jsonb; jr record; pend integer;
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO f FROM fixed_assets WHERE id = p_asset FOR UPDATE;
  PERFORM set_config('app.bypass_rls', prev, true);
  IF f.id IS NULL THEN RAISE EXCEPTION 'forbidden' USING ERRCODE = '42501'; END IF;
  PERFORM credit_authorize(f.tenant_id, false);
  PERFORM acct_open(f.tenant_id);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  IF f.status <> 'active' THEN RETURN 'not_active'; END IF;
  IF p_date IS NULL OR p_date < f.purchase_date OR p_date > (now() AT TIME ZONE 'Asia/Dhaka')::date + 1 THEN RETURN 'bad_date'; END IF;
  IF p_proceeds IS NULL OR p_proceeds < 0 OR p_proceeds > 999999999999 THEN RETURN 'bad_proceeds'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT count(*) INTO pend FROM (
    SELECT g.d FROM generate_series(date_trunc('month', f.purchase_date::timestamp), date_trunc('month', p_date::timestamp) - interval '1 month', interval '1 month') AS g(d)
     WHERE ((extract(year FROM g.d) - extract(year FROM f.purchase_date)) * 12 + extract(month FROM g.d) - extract(month FROM f.purchase_date)) < f.life_months
       AND fa_month_amount(f.cost_minor, f.salvage_minor, f.life_months, ((extract(year FROM g.d) - extract(year FROM f.purchase_date)) * 12 + extract(month FROM g.d) - extract(month FROM f.purchase_date))::integer) > 0
       AND NOT EXISTS (SELECT 1 FROM fa_dep_lines l WHERE l.asset_id = f.id AND l.period = g.d::date)) q;
  IF pend > 0 THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'depreciate_first'; END IF;
  SELECT coalesce(sum(amount_minor), 0) INTO acc FROM fa_dep_lines WHERE asset_id = f.id;
  IF p_proceeds > 0 THEN
    SELECT * INTO pay FROM accounts WHERE id = p_pay_account AND tenant_id = f.tenant_id AND active AND cash_account;
    IF pay.id IS NULL THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'bad_pay_account'; END IF;
  END IF;
  SELECT id INTO gain FROM accounts WHERE tenant_id = f.tenant_id AND system_key = 'other_income' AND active AND postable;
  SELECT id INTO loss FROM accounts WHERE tenant_id = f.tenant_id AND system_key = 'misc_expense' AND active AND postable;
  PERFORM set_config('app.bypass_rls', prev, true);
  diff := f.cost_minor - acc - p_proceeds;                                   -- > 0 loss, < 0 gain
  IF (diff > 0 AND loss IS NULL) OR (diff < 0 AND gain IS NULL) THEN RETURN 'no_system_account'; END IF;
  jl := jsonb_build_array(jsonb_build_object('account_id', f.asset_account_id, 'debit_minor', 0, 'credit_minor', f.cost_minor));
  IF acc > 0 THEN jl := jl || jsonb_build_object('account_id', f.accum_account_id, 'debit_minor', acc, 'credit_minor', 0); END IF;
  IF p_proceeds > 0 THEN jl := jl || jsonb_build_object('account_id', pay.id, 'debit_minor', p_proceeds, 'credit_minor', 0); END IF;
  IF diff > 0 THEN jl := jl || jsonb_build_object('account_id', loss, 'debit_minor', diff, 'credit_minor', 0); END IF;
  IF diff < 0 THEN jl := jl || jsonb_build_object('account_id', gain, 'debit_minor', 0, 'credit_minor', -diff); END IF;
  SELECT * INTO jr FROM journal_post(p_entry, f.tenant_id, p_user, p_date, 'Disposal of ' || f.name, 'system', 'asset:' || f.id, jl);
  IF jr.result <> 'ok' THEN RETURN jr.result; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE fixed_assets SET status = 'disposed', disposed_date = p_date, proceeds_minor = p_proceeds, disposal_entry_id = p_entry WHERE id = f.id;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

-- ---------------------------------------------------------------------------------------------------------------------
-- Inventory valuation.
-- Set a variant's unit cost from a day on. result: ok | bad_cost | bad_date
CREATE FUNCTION inv_cost_set(p_variant char(26), p_cost bigint, p_date date, p_user char(26)) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); v product_variants%ROWTYPE;
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO v FROM product_variants WHERE id = p_variant;
  PERFORM set_config('app.bypass_rls', prev, true);
  IF v.id IS NULL THEN RAISE EXCEPTION 'forbidden' USING ERRCODE = '42501'; END IF;
  PERFORM credit_authorize(v.tenant_id, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  IF p_cost IS NULL OR p_cost < 0 OR p_cost > 100000000000 THEN RETURN 'bad_cost'; END IF;
  IF p_date IS NULL OR p_date < DATE '2000-01-01' OR p_date > (now() AT TIME ZONE 'Asia/Dhaka')::date THEN RETURN 'bad_date'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  INSERT INTO inv_costs (tenant_id, variant_id, unit_cost_minor, effective_date, set_by) VALUES (v.tenant_id, p_variant, p_cost, p_date, p_user);
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

-- Stock value per variant on a day, for one workspace (explicit tenant: used by the books adjustment, which must never see another workspace).
-- Quantity = qty_after of the last stock movement before the end of that day (Dhaka); unit cost = latest cost effective on or before the day (NULL = no cost yet, valued 0).
CREATE FUNCTION inv_value_rows(p_tenant char(26), p_asof date) RETURNS TABLE (variant_id char(26), product_id char(26), title varchar, sku varchar, options jsonb, qty integer, unit_cost_minor bigint, value_minor bigint)
LANGUAGE sql STABLE AS $$
  SELECT v.id, p.id, p.title, v.sku, v.options, q.qty, c.cost, q.qty::bigint * coalesce(c.cost, 0)
    FROM product_variants v
    JOIN products p ON p.id = v.product_id AND p.tenant_id = v.tenant_id
    CROSS JOIN LATERAL (SELECT coalesce((SELECT m.qty_after FROM stock_movements m WHERE m.variant_id = v.id AND m.tenant_id = v.tenant_id AND m.created_at < ((p_asof + 1)::timestamp AT TIME ZONE 'Asia/Dhaka') ORDER BY m.seq DESC LIMIT 1), 0) AS qty) q
    LEFT JOIN LATERAL (SELECT k.unit_cost_minor AS cost FROM inv_costs k WHERE k.variant_id = v.id AND k.tenant_id = v.tenant_id AND k.effective_date <= p_asof ORDER BY k.effective_date DESC, k.seq DESC LIMIT 1) c ON true
   WHERE v.tenant_id = p_tenant AND v.track_stock AND q.qty > 0
   ORDER BY p.title, v.id
$$;

-- The screen's version: always the caller's own workspace (taken from the session, never from an argument).
CREATE FUNCTION inv_valuation(p_asof date) RETURNS TABLE (variant_id char(26), product_id char(26), title varchar, sku varchar, options jsonb, qty integer, unit_cost_minor bigint, value_minor bigint)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
  SELECT * FROM inv_value_rows((SELECT app_tenant_id()), p_asof)
$$;

-- Bring the books' Inventory account to the stock value on a day, posting the difference against an income/expense account the person picks (usually Cost of Goods Sold).
-- result: ok | no_difference | no_system_account | bad_account | bad_date | <journal_post result: locked ...>
CREATE FUNCTION inv_revalue(p_entry char(26), p_tenant char(26), p_user char(26), p_date date, p_counter char(26)) RETURNS TABLE (result text, value_minor bigint, book_minor bigint, diff_minor bigint)
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); inv char(26); c accounts%ROWTYPE; val bigint; bk bigint; d bigint; jl jsonb; jr record;
BEGIN
  PERFORM acct_open(p_tenant);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  IF p_date IS NULL OR p_date < DATE '2000-01-01' OR p_date > (now() AT TIME ZONE 'Asia/Dhaka')::date THEN RETURN QUERY SELECT 'bad_date'::text, 0::bigint, 0::bigint, 0::bigint; RETURN; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT id INTO inv FROM accounts WHERE tenant_id = p_tenant AND system_key = 'inventory' AND active AND postable;
  SELECT * INTO c FROM accounts WHERE id = p_counter AND tenant_id = p_tenant AND active AND postable AND type IN ('expense', 'income') AND id IS DISTINCT FROM inv;
  SELECT coalesce(sum(r.value_minor), 0) INTO val FROM inv_value_rows(p_tenant, p_date) r;
  SELECT coalesce(sum(l.debit_minor - l.credit_minor), 0) INTO bk FROM journal_lines l JOIN journal_entries e ON e.id = l.entry_id AND e.tenant_id = l.tenant_id WHERE l.tenant_id = p_tenant AND l.account_id = inv AND e.entry_date <= p_date;
  PERFORM set_config('app.bypass_rls', prev, true);
  IF inv IS NULL THEN RETURN QUERY SELECT 'no_system_account'::text, val, bk, 0::bigint; RETURN; END IF;
  IF c.id IS NULL THEN RETURN QUERY SELECT 'bad_account'::text, val, bk, 0::bigint; RETURN; END IF;
  d := val - bk;
  IF d = 0 THEN RETURN QUERY SELECT 'no_difference'::text, val, bk, 0::bigint; RETURN; END IF;
  jl := CASE WHEN d > 0 THEN jsonb_build_array(jsonb_build_object('account_id', inv, 'debit_minor', d, 'credit_minor', 0), jsonb_build_object('account_id', c.id, 'debit_minor', 0, 'credit_minor', d))
             ELSE jsonb_build_array(jsonb_build_object('account_id', c.id, 'debit_minor', -d, 'credit_minor', 0), jsonb_build_object('account_id', inv, 'debit_minor', 0, 'credit_minor', -d)) END;
  SELECT * INTO jr FROM journal_post(p_entry, p_tenant, p_user, p_date, 'Inventory revaluation to stock value', 'system', 'inv:' || to_char(p_date, 'YYYY-MM-DD'), jl);
  RETURN QUERY SELECT jr.result, val, bk, d;
END $$;

REVOKE ALL ON FUNCTION fa_register(char, char, char, text, char, date, bigint, bigint, integer), fa_run(char, char, char, date), fa_dispose(char, char, char, date, bigint, char),
  inv_cost_set(char, bigint, date, char), inv_revalue(char, char, char, date, char), inv_value_rows(char, date) FROM PUBLIC, jarvis_app_rw, jarvis_admin;
GRANT EXECUTE ON FUNCTION fa_register(char, char, char, text, char, date, bigint, bigint, integer), fa_run(char, char, char, date), fa_dispose(char, char, char, date, bigint, char),
  inv_cost_set(char, bigint, date, char), inv_revalue(char, char, char, date, char) TO jarvis_app_rw, jarvis_admin;
REVOKE ALL ON FUNCTION fa_month_amount(bigint, bigint, integer, integer), fa_pending(date), inv_valuation(date) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION fa_month_amount(bigint, bigint, integer, integer), fa_pending(date), inv_valuation(date) TO jarvis_app_rw, jarvis_admin;
