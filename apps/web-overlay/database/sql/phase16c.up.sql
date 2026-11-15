-- Phase 16c: automatic posting of shop sales into the books, and courier cash settlement.
--   * Turning a shop order into `delivered` only ADDS A ROW to autopost_queue (a trigger that swallows its own errors) — the books can never block, slow or fail the order itself.
--   * A separate step (autopost_run: the button, or the accounting:autopost command) posts the queued rows through journal_post. Each row is its own sub-transaction:
--     one that fails (period locked, account missing ...) is marked `failed` with the reason and stays in the "post pending" list; the rest carry on. Retry = run again.
--   * delivered -> sale: Dr courier receivable (the order went through a courier) or Cash (own delivery) / Cr Sales + Delivery income; and, when the unit cost is known,
--     Dr Cost of goods sold / Cr Inventory. delivered -> returned: the sale entry is reversed (once). Orders delivered before the switch was turned on are never posted.
--   * The courier pays later: courier_settle records the money received (net of the courier's charge) against the courier receivable.
-- NB: PERFORM sets FOUND, so existence is tested on a row's id, never on FOUND after a set_config.

CREATE TABLE autopost_settings (
  tenant_id   char(26)    PRIMARY KEY REFERENCES tenants (id),
  enabled     boolean     NOT NULL DEFAULT false,
  enabled_at  timestamptz,
  updated_by  char(26)    REFERENCES users (id),
  updated_at  timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE autopost_queue (
  seq          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  tenant_id    char(26)    NOT NULL REFERENCES tenants (id),
  kind         text        NOT NULL CHECK (kind IN ('sale', 'sale_return')),
  order_id     char(26)    NOT NULL,
  status       text        NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'failed', 'posted', 'skipped')),
  attempts     smallint    NOT NULL DEFAULT 0,
  last_error   varchar(120),
  note         varchar(200),
  entry_id     char(26),
  courier      text        CHECK (courier IN ('steadfast', 'pathao', 'redx')),
  amount_minor bigint      CHECK (amount_minor >= 0),
  created_at   timestamptz NOT NULL DEFAULT now(),
  done_at      timestamptz,
  UNIQUE (tenant_id, kind, order_id),
  FOREIGN KEY (order_id, tenant_id) REFERENCES store_orders (id, tenant_id),
  FOREIGN KEY (entry_id, tenant_id) REFERENCES journal_entries (id, tenant_id),
  CONSTRAINT autopost_entry_iff_posted CHECK ((status = 'posted') = (entry_id IS NOT NULL)),
  CONSTRAINT autopost_done_iff_closed CHECK ((status IN ('posted', 'skipped')) = (done_at IS NOT NULL))
);
CREATE INDEX autopost_queue_open ON autopost_queue (tenant_id, seq) WHERE status IN ('pending', 'failed');
CREATE INDEX autopost_queue_order ON autopost_queue (order_id);

CREATE TABLE courier_settlements (
  id            char(26)    PRIMARY KEY,
  tenant_id     char(26)    NOT NULL REFERENCES tenants (id),
  courier       text        NOT NULL CHECK (courier IN ('steadfast', 'pathao', 'redx')),
  settle_date   date        NOT NULL,
  gross_minor   bigint      NOT NULL CHECK (gross_minor > 0 AND gross_minor <= 999999999999),   -- the COD the courier collected for us
  fee_minor     bigint      NOT NULL CHECK (fee_minor >= 0),                                    -- what the courier kept (delivery / COD charge)
  account_id    char(26)    NOT NULL,                                                           -- cash / bank / wallet that received (gross - fee)
  note          varchar(200),
  entry_id      char(26)    NOT NULL,
  void_entry_id char(26),
  voided_at     timestamptz,
  created_by    char(26)    NOT NULL REFERENCES users (id),
  created_at    timestamptz NOT NULL DEFAULT now(),
  UNIQUE (id, tenant_id),
  FOREIGN KEY (account_id, tenant_id)    REFERENCES accounts (id, tenant_id),
  FOREIGN KEY (entry_id, tenant_id)      REFERENCES journal_entries (id, tenant_id),
  FOREIGN KEY (void_entry_id, tenant_id) REFERENCES journal_entries (id, tenant_id),
  CONSTRAINT settlement_fee_within_gross CHECK (fee_minor <= gross_minor),
  CONSTRAINT settlement_void_rule CHECK ((voided_at IS NULL) = (void_entry_id IS NULL))
);
CREATE INDEX courier_settlements_list ON courier_settlements (tenant_id, settle_date DESC);

DO $$ DECLARE t text; BEGIN
  FOREACH t IN ARRAY ARRAY['autopost_settings', 'autopost_queue', 'courier_settlements'] LOOP
    EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', t);
    EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY', t);
    EXECUTE format('CREATE POLICY tenant_isolation ON %I USING (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin())) WITH CHECK (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()))', t);
    EXECUTE format('REVOKE ALL ON %I FROM jarvis_app_rw, jarvis_admin', t);
    EXECUTE format('GRANT SELECT ON %I TO jarvis_app_rw, jarvis_admin', t);
  END LOOP;
END $$;

-- ---------------------------------------------------------------------------------------------------------------------
-- The only thing the order path does: remember that an order was delivered / returned-after-delivery. Never raises (a failure is a WARNING and the order goes on).
CREATE FUNCTION autopost_enqueue() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); k text;
BEGIN
  BEGIN
    k := CASE WHEN NEW.status = 'delivered' THEN 'sale' WHEN NEW.status = 'returned' AND OLD.status = 'delivered' THEN 'sale_return' END;
    IF k IS NOT NULL THEN
      PERFORM set_config('app.bypass_rls', 'on', true);
      INSERT INTO autopost_queue (tenant_id, kind, order_id) SELECT NEW.tenant_id, k, NEW.id FROM autopost_settings WHERE tenant_id = NEW.tenant_id AND enabled ON CONFLICT DO NOTHING;
      PERFORM set_config('app.bypass_rls', prev, true);
    END IF;
  EXCEPTION WHEN others THEN
    PERFORM set_config('app.bypass_rls', prev, true);
    RAISE WARNING 'autopost_enqueue: %', SQLERRM;
  END;
  RETURN NULL;
END $$;
CREATE TRIGGER store_orders_autopost AFTER UPDATE OF status ON store_orders FOR EACH ROW
  WHEN (OLD.status IS DISTINCT FROM NEW.status AND NEW.status IN ('delivered', 'returned')) EXECUTE FUNCTION autopost_enqueue();

-- Switch automatic posting on / off for the workspace. On needs the chart of accounts and the accounts used below.
-- result: ok | not_seeded | no_system_account
CREATE FUNCTION autopost_set(p_tenant char(26), p_user char(26), p_on boolean) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); s acct_settings%ROWTYPE; missing integer; was boolean;
BEGIN
  s := acct_open(p_tenant);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  IF p_on THEN
    IF s.seeded_at IS NULL THEN RETURN 'not_seeded'; END IF;
    PERFORM set_config('app.bypass_rls', 'on', true);
    SELECT 7 - count(*) INTO missing FROM accounts WHERE tenant_id = p_tenant AND active AND postable
       AND system_key IN ('cash', 'courier_receivable', 'sales', 'delivery_income', 'cogs', 'inventory', 'courier_expense');
    PERFORM set_config('app.bypass_rls', prev, true);
    IF missing > 0 THEN RETURN 'no_system_account'; END IF;
  END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT enabled INTO was FROM autopost_settings WHERE tenant_id = p_tenant;
  INSERT INTO autopost_settings (tenant_id, enabled, enabled_at, updated_by) VALUES (p_tenant, p_on, CASE WHEN p_on THEN now() END, p_user)
    ON CONFLICT (tenant_id) DO UPDATE SET enabled = p_on, enabled_at = CASE WHEN p_on AND NOT coalesce(was, false) THEN now() ELSE autopost_settings.enabled_at END,
                                          updated_by = p_user, updated_at = now();
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

-- Post (or skip) ONE queued row. Returns 'posted' | 'skipped' | an error code (nothing was changed for an error code). Internal: called by autopost_run inside a sub-transaction.
CREATE FUNCTION autopost_one(p_seq bigint, p_user char(26)) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); q autopost_queue%ROWTYPE; o store_orders%ROWTYPE; s autopost_queue%ROWTYPE;
        today date := (now() AT TIME ZONE 'Asia/Dhaka')::date; edate date; ev timestamptz; cour text; recv_key text; recv char(26); sales char(26); dlv char(26); cogs char(26); inv char(26);
        sales_amt bigint; cogs_amt bigint := 0; miss integer := 0; jl jsonb := '[]'::jsonb; jr record; nid char(26) := credit_new_id(); usr char(26); res text; nt text;
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO q FROM autopost_queue WHERE seq = p_seq;
  SELECT * INTO o FROM store_orders WHERE id = q.order_id;
  usr := coalesce(o.confirmed_by, o.created_by, p_user);
  IF q.kind = 'sale' THEN
    SELECT courier INTO cour FROM order_shipments WHERE order_id = o.id AND status NOT IN ('failed', 'cancelled') ORDER BY created_at DESC LIMIT 1;
    recv_key := CASE WHEN cour IS NULL THEN 'cash' ELSE 'courier_receivable' END;
    SELECT id INTO recv  FROM accounts WHERE tenant_id = q.tenant_id AND system_key = recv_key AND active AND postable;
    SELECT id INTO sales FROM accounts WHERE tenant_id = q.tenant_id AND system_key = 'sales' AND active AND postable;
    SELECT id INTO dlv   FROM accounts WHERE tenant_id = q.tenant_id AND system_key = 'delivery_income' AND active AND postable;
    SELECT id INTO cogs  FROM accounts WHERE tenant_id = q.tenant_id AND system_key = 'cogs' AND active AND postable;
    SELECT id INTO inv   FROM accounts WHERE tenant_id = q.tenant_id AND system_key = 'inventory' AND active AND postable;
    IF recv IS NULL OR sales IS NULL OR dlv IS NULL OR cogs IS NULL OR inv IS NULL THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'no_system_account'; END IF;
    SELECT max(e.created_at) INTO ev FROM store_order_events e WHERE e.order_id = o.id AND e.to_status = 'delivered';
    edate := LEAST((coalesce(ev, o.updated_at) AT TIME ZONE 'Asia/Dhaka')::date, today);
    SELECT coalesce(sum(i.qty * c.unit_cost_minor), 0), count(*) FILTER (WHERE c.unit_cost_minor IS NULL) INTO cogs_amt, miss
      FROM store_order_items i LEFT JOIN LATERAL (SELECT unit_cost_minor FROM inv_costs k WHERE k.variant_id = i.variant_id AND k.tenant_id = i.tenant_id AND k.effective_date <= edate ORDER BY k.effective_date DESC, k.seq DESC LIMIT 1) c ON true
     WHERE i.order_id = o.id AND i.tracked;
    sales_amt := o.subtotal_minor - o.discount_minor;
    IF o.total_minor > 0 THEN
      jl := jl || jsonb_build_object('account_id', recv, 'debit_minor', o.total_minor, 'credit_minor', 0);
      IF sales_amt > 0 THEN jl := jl || jsonb_build_object('account_id', sales, 'debit_minor', 0, 'credit_minor', sales_amt); END IF;
      IF o.delivery_minor > 0 THEN jl := jl || jsonb_build_object('account_id', dlv, 'debit_minor', 0, 'credit_minor', o.delivery_minor); END IF;
    END IF;
    IF cogs_amt > 0 THEN jl := jl || jsonb_build_object('account_id', cogs, 'debit_minor', cogs_amt, 'credit_minor', 0) || jsonb_build_object('account_id', inv, 'debit_minor', 0, 'credit_minor', cogs_amt); END IF;
    nt := CASE WHEN miss > 0 THEN miss || ' item(s) without a unit cost: cost of goods not posted for them' END;
    IF jsonb_array_length(jl) < 2 THEN
      UPDATE autopost_queue SET status = 'skipped', note = 'nothing to post', attempts = attempts + 1, last_error = NULL, done_at = now() WHERE seq = p_seq;
      PERFORM set_config('app.bypass_rls', prev, true); RETURN 'skipped';
    END IF;
    PERFORM set_config('app.bypass_rls', prev, true);
    SELECT * INTO jr FROM journal_post(nid, q.tenant_id, usr, edate, 'Order #' || o.number || ' delivered', 'system', 'order:' || o.id, jl);
    IF jr.result <> 'ok' THEN RETURN jr.result; END IF;
    PERFORM set_config('app.bypass_rls', 'on', true);
    UPDATE autopost_queue SET status = 'posted', entry_id = nid, courier = cour, amount_minor = o.total_minor, note = nt, attempts = attempts + 1, last_error = NULL, done_at = now() WHERE seq = p_seq;
    PERFORM set_config('app.bypass_rls', prev, true);
    RETURN 'posted';
  END IF;
  -- sale_return: undo the sale entry, if there is one to undo
  SELECT * INTO s FROM autopost_queue WHERE tenant_id = q.tenant_id AND kind = 'sale' AND order_id = q.order_id;
  IF s.seq IS NULL OR s.status = 'skipped' THEN
    UPDATE autopost_queue SET status = 'skipped', note = 'no sale entry to reverse', attempts = attempts + 1, last_error = NULL, done_at = now() WHERE seq = p_seq;
    PERFORM set_config('app.bypass_rls', prev, true); RETURN 'skipped';
  END IF;
  IF s.status IN ('pending', 'failed') THEN
    UPDATE autopost_queue SET status = 'skipped', note = 'returned before it was posted', attempts = attempts + 1, last_error = NULL, done_at = now() WHERE seq IN (s.seq, p_seq);
    PERFORM set_config('app.bypass_rls', prev, true); RETURN 'skipped';
  END IF;
  PERFORM set_config('app.bypass_rls', prev, true);
  res := journal_reverse(nid, s.entry_id, usr, today, 'Order #' || o.number || ' returned');
  IF res = 'already_reversed' THEN
    PERFORM set_config('app.bypass_rls', 'on', true);
    UPDATE autopost_queue SET status = 'skipped', note = 'already reversed by hand', attempts = attempts + 1, last_error = NULL, done_at = now() WHERE seq = p_seq;
    PERFORM set_config('app.bypass_rls', prev, true); RETURN 'skipped';
  END IF;
  IF res <> 'ok' THEN RETURN res; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE autopost_queue SET status = 'posted', entry_id = nid, amount_minor = s.amount_minor, courier = s.courier, attempts = attempts + 1, last_error = NULL, done_at = now() WHERE seq = p_seq;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'posted';
END $$;

-- Post what is waiting (returns first — a return that finds its sale still waiting cancels both — then oldest first; at most p_limit). A row that fails is marked failed with the reason and does not stop the others.
CREATE FUNCTION autopost_run(p_tenant char(26), p_user char(26), p_limit integer) RETURNS TABLE (posted integer, failed integer, skipped integer)
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); ids bigint[]; i bigint; res text; np integer := 0; nf integer := 0; ns integer := 0;
BEGIN
  PERFORM acct_open(p_tenant);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  ids := ARRAY(SELECT seq FROM autopost_queue WHERE tenant_id = p_tenant AND status IN ('pending', 'failed') ORDER BY (kind = 'sale_return') DESC, seq LIMIT greatest(1, least(coalesce(p_limit, 50), 500)) FOR UPDATE SKIP LOCKED);
  PERFORM set_config('app.bypass_rls', prev, true);
  FOREACH i IN ARRAY ids LOOP
    BEGIN
      -- a skip of the sale by an earlier return in this same run may already have closed this row
      PERFORM set_config('app.bypass_rls', 'on', true);
      IF (SELECT status FROM autopost_queue WHERE seq = i) NOT IN ('pending', 'failed') THEN
        PERFORM set_config('app.bypass_rls', prev, true); CONTINUE;
      END IF;
      PERFORM set_config('app.bypass_rls', prev, true);
      res := autopost_one(i, p_user);
    EXCEPTION WHEN others THEN
      res := 'error:' || SQLSTATE;
    END;
    PERFORM set_config('app.bypass_rls', 'on', true);
    IF res = 'posted' THEN np := np + 1;
    ELSIF res = 'skipped' THEN ns := ns + 1;
    ELSE
      nf := nf + 1;
      UPDATE autopost_queue SET status = 'failed', attempts = attempts + 1, last_error = left(res, 120) WHERE seq = i;
    END IF;
    PERFORM set_config('app.bypass_rls', prev, true);
  END LOOP;
  RETURN QUERY SELECT np, nf, ns;
END $$;

-- Give up on a waiting row (the person will post it by hand or it does not matter). result: ok | not_open
CREATE FUNCTION autopost_skip(p_seq bigint, p_user char(26), p_reason text) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); q autopost_queue%ROWTYPE;
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO q FROM autopost_queue WHERE seq = p_seq FOR UPDATE;
  PERFORM set_config('app.bypass_rls', prev, true);
  IF q.seq IS NULL THEN RAISE EXCEPTION 'forbidden' USING ERRCODE = '42501'; END IF;
  PERFORM credit_authorize(q.tenant_id, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  IF q.status NOT IN ('pending', 'failed') THEN RETURN 'not_open'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE autopost_queue SET status = 'skipped', done_at = now(), note = left('skipped by hand: ' || coalesce(nullif(btrim(p_reason), ''), '-'), 200) WHERE seq = p_seq;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

-- What each courier still owes: delivered (posted sales) - returned after delivery - settled. Explicit tenant, so no EXECUTE for the app role.
CREATE FUNCTION courier_receivable_rows(p_tenant char(26)) RETURNS TABLE (courier text, orders integer, delivered_minor bigint, returned_minor bigint, settled_minor bigint, outstanding_minor bigint)
LANGUAGE sql STABLE SET search_path = public, pg_temp AS $$
  WITH sales AS (SELECT q.courier, q.amount_minor, q.order_id FROM autopost_queue q WHERE q.tenant_id = p_tenant AND q.kind = 'sale' AND q.status = 'posted' AND q.courier IS NOT NULL),
       rets  AS (SELECT s.courier, s.amount_minor FROM sales s JOIN autopost_queue r ON r.tenant_id = p_tenant AND r.kind = 'sale_return' AND r.status = 'posted' AND r.order_id = s.order_id),
       setl  AS (SELECT c.courier, c.gross_minor FROM courier_settlements c WHERE c.tenant_id = p_tenant AND c.voided_at IS NULL),
       t AS (SELECT k.courier,
                    (SELECT count(*)::integer FROM sales s WHERE s.courier = k.courier) AS orders,
                    (SELECT coalesce(sum(amount_minor), 0)::bigint FROM sales s WHERE s.courier = k.courier) AS delivered,
                    (SELECT coalesce(sum(amount_minor), 0)::bigint FROM rets r WHERE r.courier = k.courier) AS returned,
                    (SELECT coalesce(sum(gross_minor), 0)::bigint FROM setl x WHERE x.courier = k.courier) AS settled
               FROM (SELECT unnest(ARRAY['steadfast', 'pathao', 'redx']) AS courier) k)
  SELECT t.courier, t.orders, t.delivered, t.returned, t.settled, t.delivered - t.returned - t.settled FROM t WHERE t.delivered <> 0 OR t.settled <> 0 ORDER BY t.courier
$$;
CREATE FUNCTION courier_receivable_report() RETURNS TABLE (courier text, orders integer, delivered_minor bigint, returned_minor bigint, settled_minor bigint, outstanding_minor bigint)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
  SELECT * FROM courier_receivable_rows((SELECT app_tenant_id()))
$$;

-- The courier paid us. gross = the COD they collected, fee = what they kept; (gross - fee) lands in a cash / bank / wallet account.
-- Dr account (gross-fee), Dr Delivery & Courier Charges (fee), Cr Courier COD Receivable (gross). Cannot exceed what that courier owes.
-- result: ok | bad_input | bad_date | bad_account | no_system_account | exceeds_receivable | <journal_post result>
CREATE FUNCTION courier_settle(p_id char(26), p_entry char(26), p_tenant char(26), p_user char(26), p_date date, p_courier text, p_gross bigint, p_fee bigint, p_account char(26), p_note text) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); acct accounts%ROWTYPE; recv char(26); fee_acc char(26); owed bigint; jl jsonb := '[]'::jsonb; jr record; net bigint; held bigint;
BEGIN
  PERFORM acct_open(p_tenant);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  IF p_courier NOT IN ('steadfast', 'pathao', 'redx') OR p_gross IS NULL OR p_gross <= 0 OR p_gross > 999999999999 OR p_fee IS NULL OR p_fee < 0 OR p_fee > p_gross THEN RETURN 'bad_input'; END IF;
  IF p_date IS NULL OR p_date > (now() AT TIME ZONE 'Asia/Dhaka')::date + 1 OR p_date < DATE '2000-01-01' THEN RETURN 'bad_date'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO acct FROM accounts WHERE id = p_account AND tenant_id = p_tenant;
  SELECT id INTO recv FROM accounts WHERE tenant_id = p_tenant AND system_key = 'courier_receivable' AND active AND postable;
  SELECT id INTO fee_acc FROM accounts WHERE tenant_id = p_tenant AND system_key = 'courier_expense' AND active AND postable;
  PERFORM set_config('app.bypass_rls', prev, true);
  IF acct.id IS NULL OR NOT acct.cash_account OR NOT acct.active OR NOT acct.postable OR acct.type <> 'asset' THEN RETURN 'bad_account'; END IF;
  IF recv IS NULL OR fee_acc IS NULL THEN RETURN 'no_system_account'; END IF;
  SELECT outstanding_minor INTO owed FROM courier_receivable_rows(p_tenant) WHERE courier = p_courier;
  -- what the courier owes, less parcels already sent back whose reversal is still waiting in the queue
  SELECT coalesce(sum(s.amount_minor), 0) INTO held FROM autopost_queue s
   WHERE s.tenant_id = p_tenant AND s.kind = 'sale' AND s.status = 'posted' AND s.courier = p_courier
     AND EXISTS (SELECT 1 FROM autopost_queue r WHERE r.tenant_id = p_tenant AND r.kind = 'sale_return' AND r.order_id = s.order_id AND r.status IN ('pending', 'failed'));
  IF coalesce(owed, 0) - held < p_gross THEN RETURN 'exceeds_receivable'; END IF;
  net := p_gross - p_fee;
  IF net > 0 THEN jl := jl || jsonb_build_object('account_id', p_account, 'debit_minor', net, 'credit_minor', 0); END IF;
  IF p_fee > 0 THEN jl := jl || jsonb_build_object('account_id', fee_acc, 'debit_minor', p_fee, 'credit_minor', 0); END IF;
  jl := jl || jsonb_build_object('account_id', recv, 'debit_minor', 0, 'credit_minor', p_gross);
  SELECT * INTO jr FROM journal_post(p_entry, p_tenant, p_user, p_date, 'Courier settlement ' || p_courier, 'system', 'settle:' || p_id, jl);
  IF jr.result <> 'ok' THEN RETURN jr.result; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  INSERT INTO courier_settlements (id, tenant_id, courier, settle_date, gross_minor, fee_minor, account_id, note, entry_id, created_by)
    VALUES (p_id, p_tenant, p_courier, p_date, p_gross, p_fee, p_account, nullif(left(btrim(coalesce(p_note, '')), 200), ''), p_entry, p_user);
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

-- Undo a settlement (reversing entry). result: ok | already_void | <journal_reverse result>
CREATE FUNCTION courier_settle_void(p_new_entry char(26), p_id char(26), p_user char(26), p_date date) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); c courier_settlements%ROWTYPE; res text;
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO c FROM courier_settlements WHERE id = p_id FOR UPDATE;
  PERFORM set_config('app.bypass_rls', prev, true);
  IF c.id IS NULL THEN RAISE EXCEPTION 'forbidden' USING ERRCODE = '42501'; END IF;
  PERFORM acct_open(c.tenant_id);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  IF c.voided_at IS NOT NULL THEN RETURN 'already_void'; END IF;
  res := journal_reverse(p_new_entry, c.entry_id, p_user, p_date, 'Courier settlement voided');
  IF res <> 'ok' THEN RETURN res; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE courier_settlements SET void_entry_id = p_new_entry, voided_at = now() WHERE id = p_id;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

REVOKE ALL ON FUNCTION autopost_enqueue(), autopost_one(bigint, char), courier_receivable_rows(char) FROM PUBLIC, jarvis_app_rw, jarvis_admin;
REVOKE ALL ON FUNCTION autopost_set(char, char, boolean), autopost_run(char, char, integer), autopost_skip(bigint, char, text), courier_receivable_report(),
  courier_settle(char, char, char, char, date, text, bigint, bigint, char, text), courier_settle_void(char, char, char, date) FROM PUBLIC, jarvis_app_rw, jarvis_admin;
GRANT EXECUTE ON FUNCTION autopost_set(char, char, boolean), autopost_run(char, char, integer), autopost_skip(bigint, char, text), courier_receivable_report(),
  courier_settle(char, char, char, char, date, text, bigint, bigint, char, text), courier_settle_void(char, char, char, date) TO jarvis_app_rw, jarvis_admin;
