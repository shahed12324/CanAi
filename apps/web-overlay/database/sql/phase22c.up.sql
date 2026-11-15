-- Phase 22.1c: editing a design with a version history.  Before every change the old words/theme/sizes are saved as a numbered version (the last 10 are kept);
-- "restore" puts an old version back (and itself saves the current one first, so a restore can be undone).  Pictures (logo / AI background) are never changed or copied.
-- Client-approval links keep the exact picture they froze, so editing a design never changes what a client already saw.

CREATE TABLE design_versions (
  design_id char(26)     NOT NULL,
  tenant_id char(26)     NOT NULL REFERENCES tenants (id),
  user_id   char(26)     NOT NULL REFERENCES users (id),
  n         integer      NOT NULL CHECK (n >= 1),
  title     varchar(120) NOT NULL,
  headline  varchar(200) NOT NULL,
  subline   varchar(400) NOT NULL,
  cta       varchar(100) NOT NULL,
  theme     text         NOT NULL CHECK (theme IN ('dark', 'light', 'brand')),
  sizes     text[]       NOT NULL CHECK (cardinality(sizes) BETWEEN 1 AND 5 AND sizes <@ ARRAY['post', 'portrait', 'story', 'cover', 'ad']),
  created_at timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY (design_id, n),
  FOREIGN KEY (design_id, tenant_id) REFERENCES designs (id, tenant_id) ON DELETE CASCADE
);
ALTER TABLE design_versions ENABLE ROW LEVEL SECURITY;
ALTER TABLE design_versions FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON design_versions USING (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin())) WITH CHECK (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()));
REVOKE ALL ON design_versions FROM jarvis_app_rw, jarvis_admin;
GRANT SELECT ON design_versions TO jarvis_app_rw, jarvis_admin;

-- Saves the current words as the next version and trims to the newest 10.  Internal helper (not callable by the app roles).
CREATE FUNCTION design_snapshot(p_id char(26), p_tenant char(26)) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); nx integer;
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT coalesce(max(n), 0) + 1 INTO nx FROM design_versions WHERE design_id = p_id AND tenant_id = p_tenant;
  INSERT INTO design_versions (design_id, tenant_id, user_id, n, title, headline, subline, cta, theme, sizes)
  SELECT id, tenant_id, user_id, nx, title, headline, subline, cta, theme, sizes FROM designs WHERE id = p_id AND tenant_id = p_tenant;
  DELETE FROM design_versions WHERE design_id = p_id AND tenant_id = p_tenant AND n <= nx - 10;
  PERFORM set_config('app.bypass_rls', prev, true);
END $$;

-- result: ok | bad_input | not_found | unchanged (nothing differs: no version is made).  Same limits as design_save.
CREATE FUNCTION design_update(p_id char(26), p_tenant char(26), p_user char(26), p_title text, p_headline text, p_subline text, p_cta text, p_theme text, p_sizes text[]) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); t text := regexp_replace(btrim(coalesce(p_title, '')), '[[:space:]]+', ' ', 'g'); d designs%ROWTYPE;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  IF t = '' OR char_length(t) > 120 OR char_length(coalesce(p_headline, '')) > 200 OR char_length(coalesce(p_subline, '')) > 400 OR char_length(coalesce(p_cta, '')) > 100
     OR p_theme IS NULL OR p_theme NOT IN ('dark', 'light', 'brand') OR p_sizes IS NULL OR cardinality(p_sizes) NOT BETWEEN 1 AND 5 OR NOT (p_sizes <@ ARRAY['post', 'portrait', 'story', 'cover', 'ad'])
     THEN RETURN 'bad_input'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO d FROM designs WHERE id = p_id AND tenant_id = p_tenant AND user_id = p_user FOR UPDATE;
  IF d.id IS NULL THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'not_found'; END IF;
  IF d.title = t AND d.headline = coalesce(p_headline, '') AND d.subline = coalesce(p_subline, '') AND d.cta = coalesce(p_cta, '') AND d.theme = p_theme
     AND (SELECT array_agg(x ORDER BY x) FROM unnest(d.sizes) x) IS NOT DISTINCT FROM (SELECT array_agg(x ORDER BY x) FROM (SELECT DISTINCT unnest(p_sizes) x) s)
  THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'unchanged'; END IF;
  PERFORM design_snapshot(p_id, p_tenant);
  UPDATE designs SET title = t, headline = coalesce(p_headline, ''), subline = coalesce(p_subline, ''), cta = coalesce(p_cta, ''), theme = p_theme, sizes = (SELECT array_agg(DISTINCT x) FROM unnest(p_sizes) x)
  WHERE id = p_id AND tenant_id = p_tenant;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

-- Puts version p_n back.  The current words are saved first as a new version.  result: ok | not_found.
CREATE FUNCTION design_restore(p_id char(26), p_tenant char(26), p_user char(26), p_n integer) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); v design_versions%ROWTYPE;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  PERFORM 1 FROM designs WHERE id = p_id AND tenant_id = p_tenant AND user_id = p_user FOR UPDATE;
  IF NOT FOUND THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'not_found'; END IF;
  SELECT * INTO v FROM design_versions WHERE design_id = p_id AND tenant_id = p_tenant AND n = p_n;
  IF v.design_id IS NULL THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'not_found'; END IF;
  PERFORM design_snapshot(p_id, p_tenant);
  UPDATE designs SET title = v.title, headline = v.headline, subline = v.subline, cta = v.cta, theme = v.theme, sizes = v.sizes WHERE id = p_id AND tenant_id = p_tenant;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

DO $$ DECLARE f text; BEGIN
  FOREACH f IN ARRAY ARRAY['design_update(char, char, char, text, text, text, text, text, text[])', 'design_restore(char, char, char, integer)'] LOOP
    EXECUTE format('REVOKE ALL ON FUNCTION %s FROM PUBLIC, jarvis_app_rw, jarvis_admin', f);
    EXECUTE format('GRANT EXECUTE ON FUNCTION %s TO jarvis_app_rw, jarvis_admin', f);
  END LOOP;
  REVOKE ALL ON FUNCTION design_snapshot(char, char) FROM PUBLIC, jarvis_app_rw, jarvis_admin;
END $$;
