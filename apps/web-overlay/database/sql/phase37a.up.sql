-- Phase 37a (prompt v0.133.0, Phase 10): the desktop agent's local hash-chained audit log is copied to the server, so the two can be compared.
-- The server stores each device's chain append-only, RECOMPUTES every hash itself (so a row cannot be stored with a wrong hash), requires each row to link to the
-- previous one and the first new row to link to the stored head, and answers "where is your head" so the agent can tell whether its local file still matches.
-- It proves the local file was not rewritten AFTER upload; it cannot prove what an agent that was already compromised before upload chose to write.
CREATE TABLE pc_audit_chain (
  tenant_id   char(26)    NOT NULL REFERENCES tenants (id),
  device_id   char(26)    NOT NULL REFERENCES pc_devices (id),
  seq         integer     NOT NULL CHECK (seq >= 0),
  t           bigint      NOT NULL CHECK (t BETWEEN 0 AND 4102444800),
  task        text        NOT NULL CHECK (char_length(task) <= 64),
  step        text        NOT NULL CHECK (char_length(step) <= 64),
  event       text        NOT NULL CHECK (char_length(event) BETWEEN 1 AND 40),
  detail      text        NOT NULL CHECK (char_length(detail) <= 400),
  prev        char(64)    NOT NULL CHECK (prev ~ '^[0-9a-f]{64}$'),
  h           char(64)    NOT NULL CHECK (h ~ '^[0-9a-f]{64}$'),
  received_at timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY (device_id, seq)
);
CREATE INDEX pc_audit_chain_tenant ON pc_audit_chain (tenant_id, received_at DESC);
ALTER TABLE pc_audit_chain ENABLE ROW LEVEL SECURITY;
ALTER TABLE pc_audit_chain FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON pc_audit_chain USING (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()))
  WITH CHECK (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()));
REVOKE INSERT, UPDATE, DELETE, TRUNCATE ON pc_audit_chain FROM jarvis_app_rw;
CREATE TRIGGER pc_audit_chain_append_only BEFORE UPDATE OR DELETE ON pc_audit_chain FOR EACH ROW EXECUTE FUNCTION reject_mutation();
CREATE TRIGGER pc_audit_chain_no_truncate BEFORE TRUNCATE ON pc_audit_chain FOR EACH STATEMENT EXECUTE FUNCTION reject_mutation();

-- The exact text the agent hashes: json.dumps(row, sort_keys=True, ensure_ascii=False, separators=(",", ":")) over {t, task, step, event, detail, prev}.
CREATE FUNCTION pc_audit_canonical(p_t bigint, p_task text, p_step text, p_event text, p_detail text, p_prev text) RETURNS text
LANGUAGE sql IMMUTABLE AS $$
  SELECT '{"detail":' || to_json(p_detail)::text || ',"event":' || to_json(p_event)::text || ',"prev":' || to_json(p_prev)::text
      || ',"step":' || to_json(p_step)::text || ',"t":' || p_t::text || ',"task":' || to_json(p_task)::text || '}'
$$;

CREATE FUNCTION pc_audit_hash(p_t bigint, p_task text, p_step text, p_event text, p_detail text, p_prev text) RETURNS text
LANGUAGE sql IMMUTABLE AS $$
  SELECT encode(sha256(convert_to(pc_audit_canonical(p_t, p_task, p_step, p_event, p_detail, p_prev), 'UTF8')), 'hex')
$$;

-- Where this device's stored chain ends: {"seq": number of rows, "h": last hash or 64 zeros}.
CREATE FUNCTION pc_audit_head(p_tenant char(26), p_device char(26)) RETURNS jsonb
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); n int; last text;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  PERFORM set_config('app.bypass_rls', 'on', true);
  IF NOT EXISTS (SELECT 1 FROM pc_devices WHERE id = p_device AND tenant_id = p_tenant AND revoked_at IS NULL) THEN
    PERFORM set_config('app.bypass_rls', prev, true); RETURN jsonb_build_object('status', 'not_found');
  END IF;
  SELECT count(*), max(seq) INTO n, last FROM pc_audit_chain WHERE device_id = p_device;
  SELECT h INTO last FROM pc_audit_chain WHERE device_id = p_device ORDER BY seq DESC LIMIT 1;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN jsonb_build_object('status', 'ok', 'seq', n, 'h', coalesce(last, repeat('0', 64)));
END $$;

-- Append rows [p_start, p_start + n).  All or nothing.  Answers {"status": ok|bad_input|seq_mismatch|chain_broken|not_found, "seq", "h", ["at"]}.
CREATE FUNCTION pc_audit_ingest(p_tenant char(26), p_device char(26), p_start integer, p_rows jsonb) RETURNS jsonb
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE
  prev_bypass text := coalesce(current_setting('app.bypass_rls', true), '');
  n int; head_seq int; head_h text; r jsonb; i int; want_prev text; hh text;
  t bigint; tk text; st text; ev text; dt text; pv text; hx text;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_start IS NULL OR p_start < 0 OR p_rows IS NULL OR jsonb_typeof(p_rows) <> 'array' OR jsonb_array_length(p_rows) NOT BETWEEN 1 AND 200 THEN
    RETURN jsonb_build_object('status', 'bad_input');
  END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  IF NOT EXISTS (SELECT 1 FROM pc_devices WHERE id = p_device AND tenant_id = p_tenant AND revoked_at IS NULL) THEN
    PERFORM set_config('app.bypass_rls', prev_bypass, true); RETURN jsonb_build_object('status', 'not_found');
  END IF;
  PERFORM pg_advisory_xact_lock(hashtext('pc_audit:' || p_device));              -- two uploads from one device cannot interleave
  SELECT count(*) INTO head_seq FROM pc_audit_chain WHERE device_id = p_device;
  SELECT h INTO head_h FROM pc_audit_chain WHERE device_id = p_device ORDER BY seq DESC LIMIT 1;
  head_h := coalesce(head_h, repeat('0', 64));
  IF p_start <> head_seq THEN
    PERFORM set_config('app.bypass_rls', prev_bypass, true);
    RETURN jsonb_build_object('status', 'seq_mismatch', 'seq', head_seq, 'h', head_h);
  END IF;
  n := jsonb_array_length(p_rows);
  want_prev := head_h;
  FOR i IN 0 .. n - 1 LOOP
    r := p_rows -> i;
    IF jsonb_typeof(r) <> 'object' OR jsonb_typeof(r -> 't') IS DISTINCT FROM 'number' OR jsonb_typeof(r -> 'task') IS DISTINCT FROM 'string' OR jsonb_typeof(r -> 'step') IS DISTINCT FROM 'string'
       OR jsonb_typeof(r -> 'event') IS DISTINCT FROM 'string' OR jsonb_typeof(r -> 'detail') IS DISTINCT FROM 'string' OR jsonb_typeof(r -> 'prev') IS DISTINCT FROM 'string' OR jsonb_typeof(r -> 'h') IS DISTINCT FROM 'string'
       OR (SELECT count(*) FROM jsonb_object_keys(r)) <> 7 THEN
      PERFORM set_config('app.bypass_rls', prev_bypass, true); RETURN jsonb_build_object('status', 'bad_input', 'at', p_start + i);
    END IF;
    IF (r ->> 't') !~ '^[0-9]{1,11}$' THEN PERFORM set_config('app.bypass_rls', prev_bypass, true); RETURN jsonb_build_object('status', 'bad_input', 'at', p_start + i); END IF;
    t := (r ->> 't')::bigint; tk := r ->> 'task'; st := r ->> 'step'; ev := r ->> 'event'; dt := r ->> 'detail'; pv := r ->> 'prev'; hx := r ->> 'h';
    IF t > 4102444800 OR char_length(tk) > 64 OR char_length(st) > 64 OR char_length(ev) NOT BETWEEN 1 AND 40 OR char_length(dt) > 400 OR pv !~ '^[0-9a-f]{64}$' OR hx !~ '^[0-9a-f]{64}$' THEN
      PERFORM set_config('app.bypass_rls', prev_bypass, true); RETURN jsonb_build_object('status', 'bad_input', 'at', p_start + i);
    END IF;
    hh := pc_audit_hash(t, tk, st, ev, dt, pv);
    IF pv <> want_prev OR hh <> hx THEN
      PERFORM set_config('app.bypass_rls', prev_bypass, true);
      RETURN jsonb_build_object('status', 'chain_broken', 'at', p_start + i, 'seq', head_seq, 'h', head_h);
    END IF;
    want_prev := hx;
  END LOOP;
  INSERT INTO pc_audit_chain (tenant_id, device_id, seq, t, task, step, event, detail, prev, h)
    SELECT p_tenant, p_device, p_start + (o - 1)::int, (e ->> 't')::bigint, e ->> 'task', e ->> 'step', e ->> 'event', e ->> 'detail', e ->> 'prev', e ->> 'h'
    FROM jsonb_array_elements(p_rows) WITH ORDINALITY AS x(e, o);
  PERFORM set_config('app.bypass_rls', prev_bypass, true);
  RETURN jsonb_build_object('status', 'ok', 'seq', head_seq + n, 'h', want_prev);
END $$;

DO $$ DECLARE f text; BEGIN
  FOREACH f IN ARRAY ARRAY['pc_audit_canonical(bigint, text, text, text, text, text)', 'pc_audit_hash(bigint, text, text, text, text, text)', 'pc_audit_head(char, char)', 'pc_audit_ingest(char, char, integer, jsonb)'] LOOP
    EXECUTE format('REVOKE ALL ON FUNCTION %s FROM PUBLIC, jarvis_app_rw, jarvis_admin', f);
    EXECUTE format('GRANT EXECUTE ON FUNCTION %s TO jarvis_app_rw, jarvis_admin', f);
  END LOOP;
END $$;
GRANT SELECT ON pc_audit_chain TO jarvis_app_rw;
