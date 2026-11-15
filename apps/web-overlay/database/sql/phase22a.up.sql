-- Phase 22.1: Image Studio.  A design = the words + brand snapshot + (optionally) an AI background picture.  The finished sizes are NOT stored: they are
-- drawn again from this record on demand (the drawing is free and deterministic).  Pictures are kept as base64 text, like the brand-kit logo.
-- Designs are personal to the person who made them.  A "review" is a client-approval link: the exact picture the client was shown is frozen inside it, the token is
-- random (256 bit) and only its SHA-256 is stored, so a database leak never yields a working link.

CREATE TABLE designs (
  id         char(26)      PRIMARY KEY,
  tenant_id  char(26)      NOT NULL REFERENCES tenants (id),
  user_id    char(26)      NOT NULL REFERENCES users (id),
  title      varchar(120)  NOT NULL CHECK (btrim(title) <> ''),
  brief      varchar(1500) NOT NULL DEFAULT '',
  headline   varchar(200)  NOT NULL DEFAULT '',
  subline    varchar(400)  NOT NULL DEFAULT '',
  cta        varchar(100)  NOT NULL DEFAULT '',
  theme      text          NOT NULL DEFAULT 'dark' CHECK (theme IN ('dark', 'light', 'brand')),
  sizes      text[]        NOT NULL CHECK (cardinality(sizes) BETWEEN 1 AND 5 AND sizes <@ ARRAY['post', 'portrait', 'story', 'cover', 'ad']),
  brand      jsonb         NOT NULL CHECK (jsonb_typeof(brand) = 'object'),
  logo_b64   text          CHECK (logo_b64 IS NULL OR length(logo_b64) <= 900000),
  base_b64   text          CHECK (base_b64 IS NULL OR length(base_b64) <= 5600000),
  created_at timestamptz   NOT NULL DEFAULT now(),
  UNIQUE (id, tenant_id)
);
CREATE INDEX designs_person ON designs (tenant_id, user_id, created_at DESC);

CREATE TABLE design_reviews (
  id          char(26)     PRIMARY KEY,
  tenant_id   char(26)     NOT NULL REFERENCES tenants (id),
  design_id   char(26)     NOT NULL,
  user_id     char(26)     NOT NULL REFERENCES users (id),
  token_hash  char(64)     NOT NULL UNIQUE CHECK (token_hash ~ '^[0-9a-f]{64}$'),
  client_name varchar(80)  NOT NULL DEFAULT '',
  size_key    text         NOT NULL CHECK (size_key IN ('post', 'portrait', 'story', 'cover', 'ad')),
  preview_b64 text         NOT NULL CHECK (preview_b64 <> '' AND length(preview_b64) <= 5600000),
  status      text         NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'approved', 'changes', 'revoked')),
  comment     varchar(1000),
  expires_at  timestamptz  NOT NULL,
  decided_at  timestamptz,
  created_at  timestamptz  NOT NULL DEFAULT now(),
  FOREIGN KEY (design_id, tenant_id) REFERENCES designs (id, tenant_id) ON DELETE CASCADE
);
CREATE INDEX design_reviews_design ON design_reviews (tenant_id, design_id, created_at DESC);

DO $$ DECLARE t text; BEGIN
  FOREACH t IN ARRAY ARRAY['designs', 'design_reviews'] LOOP
    EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', t);
    EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY', t);
    EXECUTE format('CREATE POLICY tenant_isolation ON %I USING (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin())) WITH CHECK (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()))', t);
    EXECUTE format('REVOKE ALL ON %I FROM jarvis_app_rw, jarvis_admin', t);
    EXECUTE format('GRANT SELECT ON %I TO jarvis_app_rw, jarvis_admin', t);
  END LOOP;
END $$;

-- result: ok | bad_input | limit.  At most 40 designs per person.
CREATE FUNCTION design_save(p_id char(26), p_tenant char(26), p_user char(26), p_title text, p_brief text, p_headline text, p_subline text, p_cta text, p_theme text,
                            p_sizes text[], p_brand jsonb, p_logo text, p_base text) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); t text := regexp_replace(btrim(coalesce(p_title, '')), '[[:space:]]+', ' ', 'g'); n integer;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  IF p_id IS NULL OR t = '' OR char_length(t) > 120 OR char_length(coalesce(p_brief, '')) > 1500 OR char_length(coalesce(p_headline, '')) > 200
     OR char_length(coalesce(p_subline, '')) > 400 OR char_length(coalesce(p_cta, '')) > 100 OR p_theme IS NULL OR p_theme NOT IN ('dark', 'light', 'brand')
     OR p_sizes IS NULL OR cardinality(p_sizes) NOT BETWEEN 1 AND 5 OR NOT (p_sizes <@ ARRAY['post', 'portrait', 'story', 'cover', 'ad'])
     OR p_brand IS NULL OR jsonb_typeof(p_brand) IS DISTINCT FROM 'object' OR char_length(coalesce(p_logo, '')) > 900000 OR char_length(coalesce(p_base, '')) > 5600000
     THEN RETURN 'bad_input'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT count(*) INTO n FROM designs WHERE tenant_id = p_tenant AND user_id = p_user;
  IF n >= 40 THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'limit'; END IF;
  INSERT INTO designs (id, tenant_id, user_id, title, brief, headline, subline, cta, theme, sizes, brand, logo_b64, base_b64)
  VALUES (p_id, p_tenant, p_user, t, coalesce(p_brief, ''), coalesce(p_headline, ''), coalesce(p_subline, ''), coalesce(p_cta, ''), p_theme, p_sizes, p_brand,
          nullif(p_logo, ''), nullif(p_base, '')) ON CONFLICT DO NOTHING;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

CREATE FUNCTION design_delete(p_id char(26), p_tenant char(26), p_user char(26)) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); rc integer;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  DELETE FROM designs WHERE id = p_id AND tenant_id = p_tenant AND user_id = p_user;
  GET DIAGNOSTICS rc = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN rc = 0 THEN 'not_found' ELSE 'ok' END;
END $$;

-- A client-approval link for one design.  result: ok | bad_input | not_found | limit | duplicate.  At most 10 live (pending) links per design; valid 1..30 days.
CREATE FUNCTION review_create(p_id char(26), p_tenant char(26), p_user char(26), p_design char(26), p_hash text, p_client text, p_size text, p_preview text, p_days integer) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); n integer; rc integer; sz text[];
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  IF p_id IS NULL OR p_design IS NULL OR p_hash IS NULL OR p_hash !~ '^[0-9a-f]{64}$' OR char_length(coalesce(p_client, '')) > 80
     OR p_size IS NULL OR p_size NOT IN ('post', 'portrait', 'story', 'cover', 'ad') OR coalesce(p_preview, '') = '' OR char_length(p_preview) > 5600000
     OR p_days IS NULL OR p_days NOT BETWEEN 1 AND 30 THEN RETURN 'bad_input'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT sizes INTO sz FROM designs WHERE id = p_design AND tenant_id = p_tenant AND user_id = p_user;
  IF NOT FOUND OR NOT (p_size = ANY (sz)) THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'not_found'; END IF;
  SELECT count(*) INTO n FROM design_reviews WHERE tenant_id = p_tenant AND design_id = p_design AND status = 'pending' AND expires_at > now();
  IF n >= 10 THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'limit'; END IF;
  INSERT INTO design_reviews (id, tenant_id, design_id, user_id, token_hash, client_name, size_key, preview_b64, expires_at)
  VALUES (p_id, p_tenant, p_design, p_user, p_hash, btrim(coalesce(p_client, '')), p_size, p_preview, now() + make_interval(days => p_days)) ON CONFLICT DO NOTHING;
  GET DIAGNOSTICS rc = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN rc = 0 THEN 'duplicate' ELSE 'ok' END;
END $$;

-- The owner withdraws a link (a decided link stays as a record; only a pending one is withdrawn).  result: ok | not_found
CREATE FUNCTION review_revoke(p_id char(26), p_tenant char(26), p_user char(26)) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); rc integer;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE design_reviews SET status = 'revoked' WHERE id = p_id AND tenant_id = p_tenant AND user_id = p_user AND status = 'pending';
  GET DIAGNOSTICS rc = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN rc = 0 THEN 'not_found' ELSE 'ok' END;
END $$;

-- PUBLIC (no login, no tenant): what the client may see.  Knows only the token hash; returns NULL for anything else.  Never returns tenant or user ids.
CREATE FUNCTION review_public_view(p_hash text) RETURNS jsonb
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); r design_reviews%ROWTYPE; d designs%ROWTYPE; res jsonb;
BEGIN
  IF p_hash IS NULL OR p_hash !~ '^[0-9a-f]{64}$' THEN RETURN NULL; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO r FROM design_reviews WHERE token_hash = p_hash;
  IF NOT FOUND THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN NULL; END IF;
  SELECT * INTO d FROM designs WHERE id = r.design_id AND tenant_id = r.tenant_id;
  res := jsonb_build_object('title', d.title, 'brand_name', coalesce(d.brand ->> 'name', ''), 'client_name', r.client_name, 'size', r.size_key, 'preview_b64', r.preview_b64,
    'status', CASE WHEN r.status = 'pending' AND r.expires_at <= now() THEN 'expired' ELSE r.status END, 'comment', r.comment, 'decided_at', r.decided_at, 'expires_at', r.expires_at);
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN res;
END $$;

-- PUBLIC: the client's answer.  result: ok | not_found | expired | decided | revoked | bad_input.  Only a live pending link can be answered, and only once.
CREATE FUNCTION review_public_decide(p_hash text, p_decision text, p_comment text) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); r design_reviews%ROWTYPE; c text := btrim(coalesce(p_comment, ''));
BEGIN
  IF p_hash IS NULL OR p_hash !~ '^[0-9a-f]{64}$' THEN RETURN 'not_found'; END IF;
  IF p_decision IS NULL OR p_decision NOT IN ('approved', 'changes') OR char_length(c) > 1000 OR (p_decision = 'changes' AND c = '') THEN RETURN 'bad_input'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO r FROM design_reviews WHERE token_hash = p_hash FOR UPDATE;
  IF NOT FOUND THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'not_found'; END IF;
  IF r.status = 'revoked' THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'revoked'; END IF;
  IF r.status <> 'pending' THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'decided'; END IF;
  IF r.expires_at <= now() THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'expired'; END IF;
  UPDATE design_reviews SET status = p_decision, comment = nullif(c, ''), decided_at = now() WHERE id = r.id;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

DO $$ DECLARE f text; BEGIN
  FOREACH f IN ARRAY ARRAY['design_save(char, char, char, text, text, text, text, text, text, text[], jsonb, text, text)', 'design_delete(char, char, char)',
    'review_create(char, char, char, char, text, text, text, text, integer)', 'review_revoke(char, char, char)', 'review_public_view(text)', 'review_public_decide(text, text, text)'] LOOP
    EXECUTE format('REVOKE ALL ON FUNCTION %s FROM PUBLIC, jarvis_app_rw, jarvis_admin', f);
    EXECUTE format('GRANT EXECUTE ON FUNCTION %s TO jarvis_app_rw, jarvis_admin', f);
  END LOOP;
END $$;
