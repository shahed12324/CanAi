-- Phase 27.1c: Enterprise licences.  A platform admin gives a workspace a licence: a number of seats and an expiry.  While a licence exists the workspace can
-- have at most that many ACTIVE members; once it has expired nobody new can be added until it is renewed (existing members are never removed).
-- Enforced by a trigger on tenant_users, so it holds for invitations, direct inserts and re-activations alike.  A workspace with no licence is not limited here.
CREATE TABLE licenses (
  tenant_id  char(26)     PRIMARY KEY REFERENCES tenants (id),
  seats      integer      NOT NULL CHECK (seats BETWEEN 1 AND 100000),
  expires_at timestamptz  NOT NULL,
  note       varchar(300) NOT NULL DEFAULT '',
  created_at timestamptz  NOT NULL DEFAULT now(),
  updated_at timestamptz  NOT NULL DEFAULT now()
);
ALTER TABLE licenses ENABLE ROW LEVEL SECURITY;
ALTER TABLE licenses FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON licenses USING (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin())) WITH CHECK (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()));
REVOKE ALL ON licenses FROM jarvis_app_rw, jarvis_admin;
GRANT SELECT ON licenses TO jarvis_app_rw, jarvis_admin;

CREATE FUNCTION license_seat_guard() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); l licenses%ROWTYPE; used integer;
BEGIN
  IF NEW.status <> 'active' THEN RETURN NEW; END IF;
  IF TG_OP = 'UPDATE' AND OLD.status = 'active' THEN RETURN NEW; END IF;        -- already holds a seat
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO l FROM licenses WHERE tenant_id = NEW.tenant_id FOR SHARE;
  IF FOUND THEN
    IF l.expires_at <= now() THEN PERFORM set_config('app.bypass_rls', prev, true); RAISE EXCEPTION 'license_expired' USING ERRCODE = 'P0001'; END IF;
    SELECT count(*) INTO used FROM tenant_users WHERE tenant_id = NEW.tenant_id AND status = 'active' AND id <> NEW.id;
    IF used >= l.seats THEN PERFORM set_config('app.bypass_rls', prev, true); RAISE EXCEPTION 'seat_limit' USING ERRCODE = 'P0001'; END IF;
  END IF;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN NEW;
END $$;
CREATE TRIGGER tenant_users_seat_guard BEFORE INSERT OR UPDATE OF status ON tenant_users FOR EACH ROW EXECUTE FUNCTION license_seat_guard();

-- ADMIN: set or replace a workspace's licence.  Cannot set fewer seats than there are active members now.
CREATE FUNCTION license_set(p_tenant char(26), p_seats integer, p_expires timestamptz, p_note text) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); used integer; nt text := btrim(coalesce(p_note, ''));
BEGIN
  IF p_tenant IS NULL OR p_seats IS NULL OR p_seats NOT BETWEEN 1 AND 100000 OR p_expires IS NULL OR char_length(nt) > 300 THEN RETURN 'bad_input'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  IF NOT EXISTS (SELECT 1 FROM tenants WHERE id = p_tenant AND deleted_at IS NULL) THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'not_found'; END IF;
  SELECT count(*) INTO used FROM tenant_users WHERE tenant_id = p_tenant AND status = 'active';
  IF p_seats < used THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'below_used'; END IF;
  INSERT INTO licenses (tenant_id, seats, expires_at, note) VALUES (p_tenant, p_seats, p_expires, nt)
  ON CONFLICT (tenant_id) DO UPDATE SET seats = p_seats, expires_at = p_expires, note = nt, updated_at = now();
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

-- ADMIN: remove the licence (the workspace is no longer limited by seats).
CREATE FUNCTION license_remove(p_tenant char(26)) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); rc integer;
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  DELETE FROM licenses WHERE tenant_id = p_tenant;
  GET DIAGNOSTICS rc = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN rc = 0 THEN 'not_found' ELSE 'ok' END;
END $$;

-- ANY MEMBER: seats left for new people, or NULL when there is no limit.  0 when expired (nobody new may join).
CREATE FUNCTION license_seats_left(p_tenant char(26)) RETURNS integer
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); l licenses%ROWTYPE; used integer;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO l FROM licenses WHERE tenant_id = p_tenant;
  IF NOT FOUND THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN NULL; END IF;
  SELECT count(*) INTO used FROM tenant_users WHERE tenant_id = p_tenant AND status = 'active';
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN l.expires_at <= now() THEN 0 ELSE greatest(0, l.seats - used) END;
END $$;

DO $$ DECLARE f text; BEGIN
  FOREACH f IN ARRAY ARRAY['license_set(char, integer, timestamptz, text)', 'license_remove(char)', 'license_seats_left(char)'] LOOP
    EXECUTE format('REVOKE ALL ON FUNCTION %s FROM PUBLIC, jarvis_app_rw, jarvis_admin', f);
    IF f LIKE 'license_seats_left%' THEN EXECUTE format('GRANT EXECUTE ON FUNCTION %s TO jarvis_app_rw, jarvis_admin', f);
    ELSE EXECUTE format('GRANT EXECUTE ON FUNCTION %s TO jarvis_admin', f); END IF;
  END LOOP;
END $$;
