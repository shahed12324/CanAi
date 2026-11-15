-- Phase 14c: couriers (Steadfast / Pathao / RedX) + the daily order report.
--   * courier secrets are sealed by the app; the app role cannot SELECT them — only courier_credentials() returns them;
--   * a parcel is booked in three steps (reserve -> call the courier -> record) so a crash can never book the same order twice;
--   * the courier's status moves the ORDER (shipped -> delivered / returned) only through order_transition(), so stock and the log stay right;
--   * shipment history is append-only; booking is blocked during the emergency stop (it is an outside action with money attached).

CREATE TABLE courier_accounts (
  id             char(26)    PRIMARY KEY,
  tenant_id      char(26)    NOT NULL REFERENCES tenants (id),
  courier        text        NOT NULL CHECK (courier IN ('steadfast', 'pathao', 'redx')),
  credentials_ct text        NOT NULL,
  key_version    smallint    NOT NULL,
  settings       jsonb       NOT NULL DEFAULT '{}'::jsonb,        -- non-secret: sandbox, store_id (Pathao), pickup_store_id (RedX)
  status         text        NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'disabled')),
  created_at     timestamptz NOT NULL DEFAULT now(),
  updated_at     timestamptz NOT NULL DEFAULT now(),
  UNIQUE (id, tenant_id),
  UNIQUE (tenant_id, courier)
);

CREATE TABLE order_shipments (
  id              char(26)    PRIMARY KEY,
  tenant_id       char(26)    NOT NULL REFERENCES tenants (id),
  order_id        char(26)    NOT NULL,
  account_id      char(26)    NOT NULL,
  courier         text        NOT NULL CHECK (courier IN ('steadfast', 'pathao', 'redx')),
  status          text        NOT NULL DEFAULT 'booking' CHECK (status IN ('booking', 'booked', 'in_transit', 'attention', 'delivered', 'returned', 'cancelled', 'failed')),
  consignment_id  varchar(80),
  tracking_code   varchar(80),
  cod_minor       bigint      NOT NULL CHECK (cod_minor >= 0),
  raw_status      varchar(120),
  error           varchar(200),
  check_fails     smallint    NOT NULL DEFAULT 0,
  booked_by       char(26)    NOT NULL REFERENCES users (id),
  last_checked_at timestamptz,
  created_at      timestamptz NOT NULL DEFAULT now(),
  updated_at      timestamptz NOT NULL DEFAULT now(),
  UNIQUE (id, tenant_id),
  FOREIGN KEY (order_id, tenant_id)   REFERENCES store_orders (id, tenant_id),
  FOREIGN KEY (account_id, tenant_id) REFERENCES courier_accounts (id, tenant_id),
  CONSTRAINT shipment_has_consignment CHECK (status IN ('booking', 'failed') OR consignment_id IS NOT NULL)
);
-- One live shipment per order; a failed or courier-cancelled one frees the order for a new booking.
CREATE UNIQUE INDEX order_shipments_one_live ON order_shipments (order_id) WHERE status NOT IN ('failed', 'cancelled');
CREATE UNIQUE INDEX order_shipments_consignment ON order_shipments (account_id, consignment_id) WHERE consignment_id IS NOT NULL;
CREATE INDEX order_shipments_sync ON order_shipments (last_checked_at NULLS FIRST) WHERE status IN ('booked', 'in_transit', 'attention');

CREATE TABLE order_shipment_events (
  seq          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  tenant_id    char(26)     NOT NULL REFERENCES tenants (id),
  shipment_id  char(26)     NOT NULL,
  status       text         NOT NULL,
  raw_status   varchar(120),
  note         varchar(200),
  created_at   timestamptz  NOT NULL DEFAULT now(),
  FOREIGN KEY (shipment_id, tenant_id) REFERENCES order_shipments (id, tenant_id)
);
CREATE INDEX order_shipment_events_s ON order_shipment_events (shipment_id, seq);
CREATE TRIGGER order_shipment_events_no_change BEFORE UPDATE OR DELETE ON order_shipment_events FOR EACH ROW EXECUTE FUNCTION catalog_log_immutable();

CREATE TABLE order_reports (
  tenant_id  char(26)    NOT NULL REFERENCES tenants (id),
  day        date        NOT NULL,                     -- the Asia/Dhaka calendar day
  data       jsonb       NOT NULL,
  created_at timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY (tenant_id, day)
);

DO $$ DECLARE t text; BEGIN
  FOREACH t IN ARRAY ARRAY['courier_accounts', 'order_shipments', 'order_shipment_events', 'order_reports'] LOOP
    EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', t);
    EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY', t);
    EXECUTE format('CREATE POLICY tenant_isolation ON %I USING (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin())) WITH CHECK (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()))', t);
    EXECUTE format('REVOKE ALL ON %I FROM jarvis_app_rw, jarvis_admin', t);
  END LOOP;
END $$;
-- Everything is written by the functions below. Secrets (credentials_ct, key_version) are deliberately not selectable.
GRANT SELECT (id, tenant_id, courier, settings, status, created_at, updated_at) ON courier_accounts TO jarvis_app_rw, jarvis_admin;
GRANT SELECT ON order_shipments, order_shipment_events, order_reports TO jarvis_app_rw, jarvis_admin;

-- ---------------------------------------------------------------------------------------------------------------------
-- Save (create or replace) the workspace's account for one courier. Returns the account id.
CREATE FUNCTION courier_account_save(p_id char(26), p_tenant char(26), p_courier text, p_ct text, p_ver smallint, p_settings jsonb) RETURNS char(26)
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); a char(26);
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_courier NOT IN ('steadfast', 'pathao', 'redx') THEN RAISE EXCEPTION 'bad courier' USING ERRCODE = '22023'; END IF;
  IF jsonb_typeof(p_settings) <> 'object' OR (SELECT count(*) FROM jsonb_object_keys(p_settings) k WHERE k NOT IN ('sandbox', 'store_id', 'pickup_store_id')) > 0 THEN RAISE EXCEPTION 'bad settings' USING ERRCODE = '22023'; END IF;
  IF p_settings ? 'sandbox' AND jsonb_typeof(p_settings->'sandbox') <> 'boolean' THEN RAISE EXCEPTION 'bad settings' USING ERRCODE = '22023'; END IF;
  IF (p_settings ? 'store_id' AND p_settings->>'store_id' !~ '^[0-9]{1,12}$') OR (p_settings ? 'pickup_store_id' AND p_settings->>'pickup_store_id' !~ '^[0-9]{1,12}$') THEN RAISE EXCEPTION 'bad settings' USING ERRCODE = '22023'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  INSERT INTO courier_accounts (id, tenant_id, courier, credentials_ct, key_version, settings) VALUES (p_id, p_tenant, p_courier, p_ct, p_ver, p_settings)
    ON CONFLICT (tenant_id, courier) DO UPDATE SET credentials_ct = excluded.credentials_ct, key_version = excluded.key_version, settings = excluded.settings, status = 'active', updated_at = now()
    RETURNING id INTO a;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN a;
END $$;

CREATE FUNCTION courier_account_status(p_account char(26), p_status text) RETURNS boolean
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); t char(26);
BEGIN
  IF p_status NOT IN ('active', 'disabled') THEN RAISE EXCEPTION 'bad status' USING ERRCODE = '22023'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT tenant_id INTO t FROM courier_accounts WHERE id = p_account;
  PERFORM set_config('app.bypass_rls', prev, true);
  IF t IS NULL THEN RAISE EXCEPTION 'forbidden' USING ERRCODE = '42501'; END IF;
  PERFORM credit_authorize(t, false);
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE courier_accounts SET status = p_status, updated_at = now() WHERE id = p_account;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN true;
END $$;

-- The only way to read a courier's secrets (after the caller is proven to belong to that workspace).
CREATE FUNCTION courier_credentials(p_account char(26)) RETURNS TABLE (credentials_ct text, key_version smallint, courier text, settings jsonb, status text)
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); a courier_accounts%ROWTYPE;
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO a FROM courier_accounts WHERE id = p_account;
  PERFORM set_config('app.bypass_rls', prev, true);
  IF NOT FOUND THEN RAISE EXCEPTION 'forbidden' USING ERRCODE = '42501'; END IF;
  PERFORM credit_authorize(a.tenant_id, false);
  RETURN QUERY SELECT a.credentials_ct, a.key_version, a.courier, a.settings, a.status;
END $$;

-- Step 1 of booking. Only a confirmed / packed order, one live shipment per order, never during the emergency stop.
-- result: ok | not_found | bad_status | emergency | no_account | account_disabled | already_booked
CREATE FUNCTION shipment_reserve(p_id char(26), p_order char(26), p_account char(26), p_user char(26)) RETURNS TABLE (result text, shipment_id char(26), cod_minor bigint)
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); o store_orders%ROWTYPE; a courier_accounts%ROWTYPE;
BEGIN
  o := order_guard(p_order);                       -- foreign / unknown ids raise 'forbidden'
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO o FROM store_orders WHERE id = p_order FOR UPDATE;
  SELECT * INTO a FROM courier_accounts WHERE id = p_account AND tenant_id = o.tenant_id;
  IF NOT FOUND THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN QUERY SELECT 'no_account'::text, NULL::char(26), NULL::bigint; RETURN; END IF;
  IF a.status <> 'active' THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN QUERY SELECT 'account_disabled'::text, NULL::char(26), NULL::bigint; RETURN; END IF;
  IF o.status NOT IN ('confirmed', 'packed') THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN QUERY SELECT 'bad_status'::text, NULL::char(26), NULL::bigint; RETURN; END IF;
  IF emergency_active(o.tenant_id) THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN QUERY SELECT 'emergency'::text, NULL::char(26), NULL::bigint; RETURN; END IF;
  IF EXISTS (SELECT 1 FROM order_shipments s WHERE s.order_id = p_order AND s.status NOT IN ('failed', 'cancelled')) THEN
    PERFORM set_config('app.bypass_rls', prev, true); RETURN QUERY SELECT 'already_booked'::text, NULL::char(26), NULL::bigint; RETURN;
  END IF;
  INSERT INTO order_shipments (id, tenant_id, order_id, account_id, courier, cod_minor, booked_by) VALUES (p_id, o.tenant_id, p_order, p_account, a.courier, o.total_minor, p_user);
  INSERT INTO order_shipment_events (tenant_id, shipment_id, status, note) VALUES (o.tenant_id, p_id, 'booking', 'booking started');
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN QUERY SELECT 'ok'::text, p_id, o.total_minor;
END $$;

-- Step 3a: the courier accepted the parcel. Moves the order to shipped (via packed if needed). result: ok | not_found | not_booking
CREATE FUNCTION shipment_booked(p_shipment char(26), p_consignment text, p_tracking text, p_raw text) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); s order_shipments%ROWTYPE; o store_orders%ROWTYPE; r text;
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO s FROM order_shipments WHERE id = p_shipment FOR UPDATE;
  PERFORM set_config('app.bypass_rls', prev, true);
  IF NOT FOUND THEN RAISE EXCEPTION 'forbidden' USING ERRCODE = '42501'; END IF;
  PERFORM credit_authorize(s.tenant_id, false);
  IF s.status <> 'booking' THEN RETURN 'not_booking'; END IF;
  IF p_consignment IS NULL OR btrim(p_consignment) = '' THEN RAISE EXCEPTION 'consignment required' USING ERRCODE = '22023'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE order_shipments SET status = 'booked', consignment_id = left(p_consignment, 80), tracking_code = left(p_tracking, 80), raw_status = left(p_raw, 120), error = NULL, updated_at = now() WHERE id = p_shipment;
  INSERT INTO order_shipment_events (tenant_id, shipment_id, status, raw_status, note) VALUES (s.tenant_id, p_shipment, 'booked', left(p_raw, 120), 'courier accepted the parcel');
  SELECT * INTO o FROM store_orders WHERE id = s.order_id;
  PERFORM set_config('app.bypass_rls', prev, true);
  IF o.status = 'confirmed' THEN r := order_transition(o.id, s.booked_by, 'packed', 'courier booking'); END IF;
  r := order_transition(o.id, s.booked_by, 'shipped', 'courier: ' || s.courier || ' ' || left(p_consignment, 40));
  RETURN 'ok';
END $$;

-- Step 3b: the courier refused / the call failed. Frees the order for another try. result: ok | not_booking
CREATE FUNCTION shipment_fail(p_shipment char(26), p_error text) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); s order_shipments%ROWTYPE;
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO s FROM order_shipments WHERE id = p_shipment FOR UPDATE;
  PERFORM set_config('app.bypass_rls', prev, true);
  IF NOT FOUND THEN RAISE EXCEPTION 'forbidden' USING ERRCODE = '42501'; END IF;
  PERFORM credit_authorize(s.tenant_id, false);
  IF s.status <> 'booking' THEN RETURN 'not_booking'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE order_shipments SET status = 'failed', error = left(p_error, 200), updated_at = now() WHERE id = p_shipment;
  INSERT INTO order_shipment_events (tenant_id, shipment_id, status, note) VALUES (s.tenant_id, p_shipment, 'failed', left(p_error, 200));
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

-- The courier reported a status (already mapped by the app to: in_transit | attention | delivered | returned | cancelled).
-- delivered -> order delivered; returned / cancelled -> order returned (stock comes back once, inside order_transition).
-- result: changed | same | terminal | not_found-style raise | mismatch (the shipment moved but the order could not follow, e.g. a person already changed it)
CREATE FUNCTION shipment_apply(p_shipment char(26), p_status text, p_raw text) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); s order_shipments%ROWTYPE; r text := 'ok'; note text := NULL; o_to text;
BEGIN
  IF p_status NOT IN ('in_transit', 'attention', 'delivered', 'returned', 'cancelled') THEN RAISE EXCEPTION 'bad status' USING ERRCODE = '22023'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO s FROM order_shipments WHERE id = p_shipment FOR UPDATE;
  PERFORM set_config('app.bypass_rls', prev, true);
  IF NOT FOUND THEN RAISE EXCEPTION 'forbidden' USING ERRCODE = '42501'; END IF;
  PERFORM credit_authorize(s.tenant_id, false);
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE order_shipments SET last_checked_at = now(), check_fails = 0, raw_status = left(p_raw, 120) WHERE id = p_shipment;
  PERFORM set_config('app.bypass_rls', prev, true);
  IF s.status IN ('booking', 'failed', 'delivered', 'returned', 'cancelled') THEN RETURN 'terminal'; END IF;
  IF s.status = p_status THEN RETURN 'same'; END IF;
  o_to := CASE p_status WHEN 'delivered' THEN 'delivered' WHEN 'returned' THEN 'returned' WHEN 'cancelled' THEN 'returned' END;
  IF o_to IS NOT NULL THEN
    r := order_transition(s.order_id, s.booked_by, o_to, 'courier: ' || s.courier || ' ' || coalesce(left(p_raw, 60), p_status));
    IF r <> 'ok' THEN note := 'order did not follow: ' || r; END IF;
  END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE order_shipments SET status = p_status, updated_at = now() WHERE id = p_shipment;
  INSERT INTO order_shipment_events (tenant_id, shipment_id, status, raw_status, note) VALUES (s.tenant_id, p_shipment, p_status, left(p_raw, 120), note);
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN note IS NULL THEN 'changed' ELSE 'mismatch' END;
END $$;

-- A status check failed (courier down, bad credentials). After 20 in a row a person is asked to look.
CREATE FUNCTION shipment_check_failed(p_shipment char(26), p_error text) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); s order_shipments%ROWTYPE; n smallint;
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO s FROM order_shipments WHERE id = p_shipment FOR UPDATE;
  PERFORM set_config('app.bypass_rls', prev, true);
  IF NOT FOUND THEN RAISE EXCEPTION 'forbidden' USING ERRCODE = '42501'; END IF;
  PERFORM credit_authorize(s.tenant_id, false);
  IF s.status NOT IN ('booked', 'in_transit', 'attention') THEN RETURN 'terminal'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE order_shipments SET last_checked_at = now(), check_fails = least(check_fails + 1, 1000), error = left(p_error, 200),
         status = CASE WHEN check_fails + 1 >= 20 THEN 'attention' ELSE status END, updated_at = now() WHERE id = p_shipment RETURNING check_fails INTO n;
  IF n >= 20 AND s.status <> 'attention' THEN INSERT INTO order_shipment_events (tenant_id, shipment_id, status, note) VALUES (s.tenant_id, p_shipment, 'attention', 'status checks keep failing: ' || left(p_error, 120)); END IF;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN n >= 20 THEN 'attention' ELSE 'retry' END;
END $$;

-- ---------------------------------------------------------------------------------------------------------------------
-- The daily report numbers for one Asia/Dhaka day. Runs as the caller, so row-level security keeps it to one workspace.
CREATE FUNCTION order_report_calc(p_day date) RETURNS jsonb LANGUAGE sql STABLE AS $$
  WITH d AS (SELECT p_day AS day),
  created AS (SELECT count(*) AS n, coalesce(sum(total_minor), 0) AS value, count(*) FILTER (WHERE source = 'agent') AS by_agent, count(*) FILTER (WHERE needs_review) AS risky
              FROM store_orders, d WHERE (created_at AT TIME ZONE 'Asia/Dhaka')::date = d.day),
  moves AS (SELECT e.to_status, count(*) AS n, coalesce(sum(o.total_minor), 0) AS value
              FROM store_order_events e JOIN store_orders o ON o.id = e.order_id AND o.tenant_id = e.tenant_id, d
             WHERE (e.created_at AT TIME ZONE 'Asia/Dhaka')::date = d.day GROUP BY e.to_status),
  open AS (SELECT status, count(*) AS n FROM store_orders WHERE status IN ('pending', 'confirmed', 'packed', 'shipped') GROUP BY status),
  cod AS (SELECT coalesce(sum(cod_minor), 0) AS pending_minor, count(*) AS n FROM order_shipments WHERE status IN ('booked', 'in_transit', 'attention')),
  att AS (SELECT count(*) AS n FROM order_shipments WHERE status = 'attention'),
  perf AS (SELECT courier, count(*) FILTER (WHERE status = 'delivered') AS delivered, count(*) FILTER (WHERE status IN ('returned', 'cancelled')) AS bad
             FROM order_shipments WHERE created_at > now() - interval '30 days' GROUP BY courier)
  SELECT jsonb_build_object(
    'day', p_day,
    'created', (SELECT jsonb_build_object('count', n, 'value_minor', value, 'by_agent', by_agent, 'risky', risky) FROM created),
    'confirmed', coalesce((SELECT n FROM moves WHERE to_status = 'confirmed'), 0),
    'shipped', coalesce((SELECT n FROM moves WHERE to_status = 'shipped'), 0),
    'delivered', jsonb_build_object('count', coalesce((SELECT n FROM moves WHERE to_status = 'delivered'), 0), 'value_minor', coalesce((SELECT value FROM moves WHERE to_status = 'delivered'), 0)),
    'cancelled', coalesce((SELECT n FROM moves WHERE to_status = 'cancelled'), 0),
    'returned', coalesce((SELECT n FROM moves WHERE to_status = 'returned'), 0),
    'open', coalesce((SELECT jsonb_object_agg(status, n) FROM open), '{}'::jsonb),
    'cod_pending', (SELECT jsonb_build_object('count', n, 'value_minor', pending_minor) FROM cod),
    'attention', (SELECT n FROM att),
    'couriers', coalesce((SELECT jsonb_agg(jsonb_build_object('courier', courier, 'delivered', delivered, 'bad', bad) ORDER BY courier) FROM perf), '[]'::jsonb));
$$;

CREATE FUNCTION order_report_store(p_tenant char(26), p_day date, p_data jsonb) RETURNS boolean
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), '');
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF jsonb_typeof(p_data) <> 'object' THEN RAISE EXCEPTION 'bad data' USING ERRCODE = '22023'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  INSERT INTO order_reports (tenant_id, day, data) VALUES (p_tenant, p_day, p_data) ON CONFLICT (tenant_id, day) DO UPDATE SET data = excluded.data, created_at = now();
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN true;
END $$;

REVOKE ALL ON FUNCTION courier_account_save(char, char, text, text, smallint, jsonb), courier_account_status(char, text), courier_credentials(char), shipment_reserve(char, char, char, char),
  shipment_booked(char, text, text, text), shipment_fail(char, text), shipment_apply(char, text, text), shipment_check_failed(char, text), order_report_store(char, date, jsonb) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION courier_account_save(char, char, text, text, smallint, jsonb), courier_account_status(char, text), courier_credentials(char), shipment_reserve(char, char, char, char),
  shipment_booked(char, text, text, text), shipment_fail(char, text), shipment_apply(char, text, text), shipment_check_failed(char, text), order_report_store(char, date, jsonb) TO jarvis_app_rw, jarvis_admin;
GRANT EXECUTE ON FUNCTION order_report_calc(date) TO jarvis_app_rw, jarvis_admin;
