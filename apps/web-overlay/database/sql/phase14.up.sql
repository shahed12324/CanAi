-- Phase 14a: orders. The rules live in the database so the web, the AI sales agent (14b) and the couriers (14c) cannot bypass them:
--   * prices are NEVER taken from the caller: they come from the approved live copy of a published product;
--   * stock is held (decremented) when the order is created and returned on cancel / return — through an append-only ledger;
--   * an AI-created order cannot carry a discount above the workspace's limit (staff may, and it is recorded);
--   * every order gets a fake-order risk score + reasons; risky ones are flagged for a person before confirmation;
--   * status moves only along a fixed path; every move is logged; confirmation is blocked during the emergency stop.
-- Orders are created by cash-on-delivery only for now (prepaid is Phase 10 billing's concern, not the shop's).

CREATE TABLE order_settings (
  tenant_id            char(26)  PRIMARY KEY REFERENCES tenants (id),
  max_discount_pct     smallint  NOT NULL DEFAULT 0  CHECK (max_discount_pct BETWEEN 0 AND 50),     -- the most the AI agent may give
  delivery_inside_minor  bigint  NOT NULL DEFAULT 6000  CHECK (delivery_inside_minor BETWEEN 0 AND 10000000),
  delivery_outside_minor bigint  NOT NULL DEFAULT 12000 CHECK (delivery_outside_minor BETWEEN 0 AND 10000000),
  review_score         smallint  NOT NULL DEFAULT 50 CHECK (review_score BETWEEN 1 AND 100),        -- at/above this a person must look before confirming
  updated_at           timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE store_orders (
  id               char(26)     PRIMARY KEY,
  tenant_id        char(26)     NOT NULL REFERENCES tenants (id),
  number           integer      NOT NULL CHECK (number > 0),
  conversation_id  char(26),
  source           text         NOT NULL CHECK (source IN ('staff', 'agent')),
  status           text         NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'confirmed', 'packed', 'shipped', 'delivered', 'cancelled', 'returned')),
  payment_method   text         NOT NULL DEFAULT 'cod' CHECK (payment_method IN ('cod')),
  customer_name    varchar(120) NOT NULL CHECK (btrim(customer_name) <> ''),
  phone            varchar(20)  NOT NULL CHECK (phone ~ '^\+8801[3-9][0-9]{8}$'),
  address          varchar(500) NOT NULL CHECK (char_length(btrim(address)) >= 10),
  zone             text         NOT NULL CHECK (zone IN ('inside', 'outside')),
  note             varchar(300),
  currency         char(3)      NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
  subtotal_minor   bigint       NOT NULL CHECK (subtotal_minor >= 0),
  discount_pct     numeric(5,2) NOT NULL DEFAULT 0 CHECK (discount_pct >= 0 AND discount_pct <= 100),
  discount_minor   bigint       NOT NULL DEFAULT 0 CHECK (discount_minor >= 0),
  delivery_minor   bigint       NOT NULL CHECK (delivery_minor >= 0),
  total_minor      bigint       NOT NULL CHECK (total_minor >= 0),
  risk_score       smallint     NOT NULL DEFAULT 0 CHECK (risk_score BETWEEN 0 AND 100),
  risk_reasons     text[]       NOT NULL DEFAULT '{}',
  needs_review     boolean      NOT NULL DEFAULT false,
  created_by       char(26)     REFERENCES users (id),
  confirmed_by     char(26)     REFERENCES users (id),
  confirmed_at     timestamptz,
  cancel_reason    varchar(200),
  created_at       timestamptz  NOT NULL DEFAULT now(),
  updated_at       timestamptz  NOT NULL DEFAULT now(),
  UNIQUE (id, tenant_id),
  UNIQUE (tenant_id, number),
  CONSTRAINT order_total_adds_up CHECK (total_minor = subtotal_minor - discount_minor + delivery_minor AND discount_minor <= subtotal_minor),
  CONSTRAINT order_staff_has_user CHECK (source <> 'staff' OR created_by IS NOT NULL),
  FOREIGN KEY (conversation_id, tenant_id) REFERENCES inbox_conversations (id, tenant_id)
);
CREATE INDEX store_orders_list ON store_orders (tenant_id, status, created_at DESC);
CREATE INDEX store_orders_phone ON store_orders (tenant_id, phone, created_at DESC);

CREATE TABLE store_order_items (
  seq              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  tenant_id        char(26)     NOT NULL REFERENCES tenants (id),
  order_id         char(26)     NOT NULL,
  product_id       char(26)     NOT NULL,
  variant_id       char(26)     NOT NULL,
  title            varchar(400) NOT NULL,                                    -- snapshot: what the customer was shown
  unit_price_minor bigint       NOT NULL CHECK (unit_price_minor >= 0),
  qty              integer      NOT NULL CHECK (qty BETWEEN 1 AND 100),
  tracked          boolean      NOT NULL,                                    -- stock was held for this line
  line_total_minor bigint       NOT NULL CHECK (line_total_minor = unit_price_minor * qty),
  FOREIGN KEY (order_id, tenant_id) REFERENCES store_orders (id, tenant_id),
  FOREIGN KEY (variant_id, tenant_id) REFERENCES product_variants (id, tenant_id)
);
CREATE INDEX store_order_items_order ON store_order_items (order_id);

CREATE TABLE store_order_events (
  seq          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  tenant_id    char(26)     NOT NULL REFERENCES tenants (id),
  order_id     char(26)     NOT NULL,
  from_status  text,
  to_status    text         NOT NULL,
  user_id      char(26)     REFERENCES users (id),
  note         varchar(200),
  created_at   timestamptz  NOT NULL DEFAULT now(),
  FOREIGN KEY (order_id, tenant_id) REFERENCES store_orders (id, tenant_id)
);
CREATE INDEX store_order_events_order ON store_order_events (order_id, seq);
CREATE TRIGGER store_order_events_no_change BEFORE UPDATE OR DELETE ON store_order_events FOR EACH ROW EXECUTE FUNCTION catalog_log_immutable();
CREATE TRIGGER store_order_items_no_change BEFORE UPDATE OR DELETE ON store_order_items FOR EACH ROW EXECUTE FUNCTION catalog_log_immutable();

DO $$ DECLARE t text; BEGIN
  FOREACH t IN ARRAY ARRAY['order_settings', 'store_orders', 'store_order_items', 'store_order_events'] LOOP
    EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', t);
    EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY', t);
    EXECUTE format('CREATE POLICY tenant_isolation ON %I USING (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin())) WITH CHECK (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()))', t);
    EXECUTE format('REVOKE ALL ON %I FROM jarvis_app_rw, jarvis_admin', t);
    EXECUTE format('GRANT SELECT ON %I TO jarvis_app_rw, jarvis_admin', t);
  END LOOP;
END $$;
-- Orders, items and the log are written only by the functions below. Settings: the owner screen edits them.
GRANT INSERT ON order_settings TO jarvis_app_rw;
GRANT UPDATE (max_discount_pct, delivery_inside_minor, delivery_outside_minor, review_score, updated_at) ON order_settings TO jarvis_app_rw;

-- ---------------------------------------------------------------------------------------------------------------------
-- A Bangladeshi mobile number in any common spelling -> +8801XXXXXXXXX, or NULL.
CREATE FUNCTION order_phone_norm(p text) RETURNS text LANGUAGE sql IMMUTABLE AS $$
  SELECT CASE WHEN d ~ '^8801[3-9][0-9]{8}$' THEN '+' || d
              WHEN d ~ '^01[3-9][0-9]{8}$' THEN '+88' || d
              WHEN d ~ '^1[3-9][0-9]{8}$' THEN '+880' || d END
  FROM (SELECT regexp_replace(translate(coalesce(p, ''), '০১২৩৪৫৬৭৮৯', '0123456789'), '[^0-9]', '', 'g') AS d) s
$$;

-- Fake-order risk. Pure rules, no AI. Caller holds bypass. Returns score 0..100 + reason codes.
CREATE FUNCTION order_risk_calc(p_tenant char(26), p_phone text, p_address text, p_qty integer, p_total bigint, p_order char(26)) RETURNS TABLE (score smallint, reasons text[])
LANGUAGE plpgsql STABLE AS $$
DECLARE s integer := 0; r text[] := '{}'; bad integer; good integer; opn integer;
BEGIN
  SELECT count(*) FILTER (WHERE status IN ('cancelled', 'returned')), count(*) FILTER (WHERE status = 'delivered'),
         count(*) FILTER (WHERE status IN ('pending', 'confirmed', 'packed', 'shipped') AND created_at > now() - interval '24 hours')
    INTO bad, good, opn FROM store_orders WHERE tenant_id = p_tenant AND phone = p_phone AND id <> p_order;
  IF bad > 0 THEN s := s + least(50, bad * 20); r := array_append(r, 'prev_bad'); END IF;
  IF opn > 0 THEN s := s + 20; r := array_append(r, 'dup_open'); END IF;
  IF p_phone ~ '(\d)\1{6,}' THEN s := s + 25; r := array_append(r, 'fake_phone'); END IF;
  IF char_length(btrim(p_address)) < 20 THEN s := s + 10; r := array_append(r, 'short_address'); END IF;
  IF p_qty >= 10 THEN s := s + 15; r := array_append(r, 'big_qty'); END IF;
  IF p_total >= 5000000 THEN s := s + 10; r := array_append(r, 'big_value'); END IF;
  IF good > 0 AND s > 0 THEN s := greatest(0, s - 15); r := array_append(r, 'trusted'); END IF;
  RETURN QUERY SELECT least(100, s)::smallint, r;
END $$;

-- Loads an order and authorises the caller for its workspace (404-style 'forbidden' for foreign ids).
CREATE FUNCTION order_guard(p_order char(26)) RETURNS store_orders LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); o store_orders%ROWTYPE;
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO o FROM store_orders WHERE id = p_order;
  PERFORM set_config('app.bypass_rls', prev, true);
  IF NOT FOUND THEN RAISE EXCEPTION 'forbidden' USING ERRCODE = '42501'; END IF;
  PERFORM credit_authorize(o.tenant_id, false);
  RETURN o;
END $$;

-- Create an order. p_items = [{"variant_id": "...", "qty": 2}, ...]. Money comes from the live copy, never from the caller.
-- result: ok | emergency | bad_name | bad_phone | bad_address | no_items | bad_item | unavailable | insufficient_stock | mixed_currency
--         | discount_too_high | too_many_open | bad_zone | conversation_not_found
CREATE FUNCTION order_create(p_id char(26), p_tenant char(26), p_user char(26), p_source text, p_conv char(26), p_name text, p_phone text, p_address text,
                             p_zone text, p_note text, p_items jsonb, p_discount_pct numeric)
RETURNS TABLE (result text, order_id char(26), number integer, total_minor bigint, risk_score smallint, needs_review boolean)
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); ph text := order_phone_norm(p_phone); st order_settings%ROWTYPE;
        it record; sub bigint := 0; qty_all integer := 0; cur text := NULL; n integer; disc bigint; dlv bigint; tot bigint; sc smallint; rs text[]; nr boolean;
        pct numeric := coalesce(p_discount_pct, 0); open_n integer; msg text;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_source NOT IN ('staff', 'agent') THEN RAISE EXCEPTION 'bad_source' USING ERRCODE = 'P0001'; END IF;
  IF p_source = 'staff' AND p_user IS NULL THEN RAISE EXCEPTION 'user_required' USING ERRCODE = 'P0001'; END IF;
  IF btrim(coalesce(p_name, '')) = '' OR char_length(p_name) > 120 THEN RETURN QUERY SELECT 'bad_name'::text, NULL::char(26), NULL::integer, NULL::bigint, NULL::smallint, NULL::boolean; RETURN; END IF;
  IF ph IS NULL THEN RETURN QUERY SELECT 'bad_phone'::text, NULL::char(26), NULL::integer, NULL::bigint, NULL::smallint, NULL::boolean; RETURN; END IF;
  IF char_length(btrim(coalesce(p_address, ''))) < 10 OR char_length(p_address) > 500 THEN RETURN QUERY SELECT 'bad_address'::text, NULL::char(26), NULL::integer, NULL::bigint, NULL::smallint, NULL::boolean; RETURN; END IF;
  IF p_zone NOT IN ('inside', 'outside') THEN RETURN QUERY SELECT 'bad_zone'::text, NULL::char(26), NULL::integer, NULL::bigint, NULL::smallint, NULL::boolean; RETURN; END IF;
  IF p_items IS NULL OR jsonb_typeof(p_items) <> 'array' OR jsonb_array_length(p_items) NOT BETWEEN 1 AND 20 THEN RETURN QUERY SELECT 'no_items'::text, NULL::char(26), NULL::integer, NULL::bigint, NULL::smallint, NULL::boolean; RETURN; END IF;
  IF pct < 0 OR pct > 100 THEN RETURN QUERY SELECT 'discount_too_high'::text, NULL::char(26), NULL::integer, NULL::bigint, NULL::smallint, NULL::boolean; RETURN; END IF;

  PERFORM set_config('app.bypass_rls', 'on', true);
  IF emergency_active(p_tenant) THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN QUERY SELECT 'emergency'::text, NULL::char(26), NULL::integer, NULL::bigint, NULL::smallint, NULL::boolean; RETURN; END IF;
  SELECT * INTO st FROM order_settings WHERE tenant_id = p_tenant;
  IF NOT FOUND THEN st.max_discount_pct := 0; st.delivery_inside_minor := 6000; st.delivery_outside_minor := 12000; st.review_score := 50; END IF;
  IF p_source = 'agent' AND pct > st.max_discount_pct THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN QUERY SELECT 'discount_too_high'::text, NULL::char(26), NULL::integer, NULL::bigint, NULL::smallint, NULL::boolean; RETURN; END IF;
  IF p_conv IS NOT NULL AND NOT EXISTS (SELECT 1 FROM inbox_conversations WHERE id = p_conv AND tenant_id = p_tenant) THEN
    PERFORM set_config('app.bypass_rls', prev, true); RETURN QUERY SELECT 'conversation_not_found'::text, NULL::char(26), NULL::integer, NULL::bigint, NULL::smallint, NULL::boolean; RETURN;
  END IF;
  SELECT count(*) INTO open_n FROM store_orders WHERE tenant_id = p_tenant AND phone = ph AND status IN ('pending', 'confirmed', 'packed', 'shipped');
  IF open_n >= 3 THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN QUERY SELECT 'too_many_open'::text, NULL::char(26), NULL::integer, NULL::bigint, NULL::smallint, NULL::boolean; RETURN; END IF;

  PERFORM pg_advisory_xact_lock(hashtextextended(p_tenant, 14));
  SELECT coalesce(max(o.number), 0) + 1 INTO n FROM store_orders o WHERE o.tenant_id = p_tenant;

  BEGIN                                                                    -- one sub-transaction: any failure undoes every stock hold
    INSERT INTO store_orders (id, tenant_id, number, conversation_id, source, customer_name, phone, address, zone, note, currency, subtotal_minor, discount_pct, discount_minor, delivery_minor, total_minor, created_by)
      VALUES (p_id, p_tenant, n, p_conv, p_source, btrim(p_name), ph, btrim(p_address), p_zone, left(p_note, 300), 'BDT', 0, 0, 0, 0, 0, p_user);
    FOR it IN
      SELECT z.vid, sum(z.q)::integer AS qty FROM (
        SELECT (e->>'variant_id')::text AS vid, CASE WHEN (e->>'qty') ~ '^[0-9]{1,3}$' THEN (e->>'qty')::integer END AS q
          FROM jsonb_array_elements(p_items) e
         WHERE jsonb_typeof(e) = 'object' AND (e->>'variant_id') ~ '^[0-9A-Za-z]{26}$') z
       WHERE z.q BETWEEN 1 AND 100 GROUP BY z.vid ORDER BY z.vid
    LOOP
      DECLARE v product_variants%ROWTYPE; p products%ROWTYPE; lv jsonb; base bigint; unit bigint; sale bigint; lbl text; q integer;
      BEGIN
        SELECT * INTO v FROM product_variants WHERE id = it.vid AND tenant_id = p_tenant AND archived_at IS NULL;
        IF NOT FOUND THEN RAISE EXCEPTION 'unavailable' USING ERRCODE = 'P0001'; END IF;
        SELECT * INTO p FROM products WHERE id = v.product_id AND tenant_id = p_tenant AND live IS NOT NULL AND deleted_at IS NULL;      -- on sale = has a live copy (an edited-but-not-yet-approved product keeps selling its approved copy)
        IF NOT FOUND THEN RAISE EXCEPTION 'unavailable' USING ERRCODE = 'P0001'; END IF;
        SELECT x INTO lv FROM jsonb_array_elements(coalesce(p.live->'variants', '[]'::jsonb)) x WHERE x->>'id' = v.id LIMIT 1;
        IF lv IS NULL THEN RAISE EXCEPTION 'unavailable' USING ERRCODE = 'P0001'; END IF;     -- variant is not part of the approved copy
        IF cur IS NULL THEN cur := coalesce(p.live->>'currency', 'BDT'); ELSIF cur <> coalesce(p.live->>'currency', 'BDT') THEN RAISE EXCEPTION 'mixed_currency' USING ERRCODE = 'P0001'; END IF;
        base := coalesce(nullif(lv->>'price_minor', '')::bigint, (p.live->>'price_minor')::bigint);
        sale := nullif(p.live->>'sale_price_minor', '')::bigint;
        unit := CASE WHEN lv->>'price_minor' IS NULL AND sale IS NOT NULL AND sale > 0 AND sale < base THEN sale ELSE base END;
        IF unit IS NULL OR unit < 0 THEN RAISE EXCEPTION 'unavailable' USING ERRCODE = 'P0001'; END IF;
        lbl := left(coalesce(p.live->>'title', p.title) || CASE WHEN v.options = '{}'::jsonb THEN '' ELSE ' (' || (SELECT string_agg(k || ': ' || val, ', ' ORDER BY k) FROM jsonb_each_text(v.options) AS t(k, val)) || ')' END, 400);
        IF v.track_stock THEN
          UPDATE product_variants SET stock_qty = stock_qty - it.qty WHERE id = v.id AND stock_qty - it.qty >= 0 RETURNING stock_qty INTO q;
          IF NOT FOUND THEN RAISE EXCEPTION 'insufficient_stock' USING ERRCODE = 'P0001'; END IF;
          INSERT INTO stock_movements (tenant_id, variant_id, delta, qty_after, reason, note, user_id) VALUES (p_tenant, v.id, -it.qty, q, 'sale', 'order #' || n, p_user);
        END IF;
        INSERT INTO store_order_items (tenant_id, order_id, product_id, variant_id, title, unit_price_minor, qty, tracked, line_total_minor)
          VALUES (p_tenant, p_id, p.id, v.id, lbl, unit, it.qty, v.track_stock, unit * it.qty);
        sub := sub + unit * it.qty; qty_all := qty_all + it.qty;
      END;
    END LOOP;
    IF qty_all = 0 THEN RAISE EXCEPTION 'bad_item' USING ERRCODE = 'P0001'; END IF;
    disc := floor(sub * pct / 100)::bigint;
    dlv := CASE WHEN p_zone = 'inside' THEN st.delivery_inside_minor ELSE st.delivery_outside_minor END;
    tot := sub - disc + dlv;
    SELECT r.score, r.reasons INTO sc, rs FROM order_risk_calc(p_tenant, ph, p_address, qty_all, tot, p_id) r;
    nr := sc >= st.review_score;
    UPDATE store_orders SET currency = cur, subtotal_minor = sub, discount_pct = pct, discount_minor = disc, delivery_minor = dlv, total_minor = tot, risk_score = sc, risk_reasons = rs, needs_review = nr WHERE id = p_id;
    INSERT INTO store_order_events (tenant_id, order_id, from_status, to_status, user_id, note) VALUES (p_tenant, p_id, NULL, 'pending', p_user, p_source);
  EXCEPTION WHEN SQLSTATE 'P0001' THEN
    GET STACKED DIAGNOSTICS msg = MESSAGE_TEXT;
    PERFORM set_config('app.bypass_rls', prev, true);
    IF msg NOT IN ('unavailable', 'insufficient_stock', 'mixed_currency', 'bad_item') THEN RAISE; END IF;
    RETURN QUERY SELECT msg, NULL::char(26), NULL::integer, NULL::bigint, NULL::smallint, NULL::boolean; RETURN;
  END;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN QUERY SELECT 'ok'::text, p_id, n, tot, sc, nr;
END $$;

-- Move an order along the fixed path. result: ok | not_found | bad_transition | emergency | user_required
--   pending -> confirmed | cancelled;  confirmed -> packed | cancelled;  packed -> shipped | cancelled;  shipped -> delivered | returned;  delivered -> returned
-- Cancel / return puts the held stock back, exactly once (cancelled and returned are final).
CREATE FUNCTION order_transition(p_order char(26), p_user char(26), p_to text, p_note text) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); o store_orders%ROWTYPE; it record; q integer;
BEGIN
  o := order_guard(p_order);
  IF p_user IS NULL THEN RETURN 'user_required'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO o FROM store_orders WHERE id = p_order FOR UPDATE;
  IF NOT ((o.status = 'pending' AND p_to IN ('confirmed', 'cancelled')) OR (o.status = 'confirmed' AND p_to IN ('packed', 'cancelled'))
       OR (o.status = 'packed' AND p_to IN ('shipped', 'cancelled')) OR (o.status = 'shipped' AND p_to IN ('delivered', 'returned')) OR (o.status = 'delivered' AND p_to = 'returned')) THEN
    PERFORM set_config('app.bypass_rls', prev, true); RETURN 'bad_transition';
  END IF;
  IF p_to = 'confirmed' AND emergency_active(o.tenant_id) THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'emergency'; END IF;
  IF p_to IN ('cancelled', 'returned') THEN
    FOR it IN SELECT variant_id, qty FROM store_order_items WHERE order_id = p_order AND tracked ORDER BY variant_id LOOP
      UPDATE product_variants SET stock_qty = stock_qty + it.qty WHERE id = it.variant_id RETURNING stock_qty INTO q;
      INSERT INTO stock_movements (tenant_id, variant_id, delta, qty_after, reason, note, user_id) VALUES (o.tenant_id, it.variant_id, it.qty, q, 'return', left('order #' || o.number || ' ' || p_to, 200), p_user);
    END LOOP;
  END IF;
  UPDATE store_orders SET status = p_to, updated_at = now(),
         confirmed_by = CASE WHEN p_to = 'confirmed' THEN p_user ELSE confirmed_by END, confirmed_at = CASE WHEN p_to = 'confirmed' THEN now() ELSE confirmed_at END,
         cancel_reason = CASE WHEN p_to IN ('cancelled', 'returned') THEN left(p_note, 200) ELSE cancel_reason END
   WHERE id = p_order;
  INSERT INTO store_order_events (tenant_id, order_id, from_status, to_status, user_id, note) VALUES (o.tenant_id, p_order, o.status, p_to, p_user, left(p_note, 200));
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

REVOKE ALL ON FUNCTION order_guard(char), order_risk_calc(char, text, text, integer, bigint, char) FROM PUBLIC, jarvis_app_rw, jarvis_admin;
GRANT EXECUTE ON FUNCTION order_phone_norm(text), order_create(char, char, char, text, char, text, text, text, text, text, jsonb, numeric), order_transition(char, char, text, text) TO jarvis_app_rw, jarvis_admin;
