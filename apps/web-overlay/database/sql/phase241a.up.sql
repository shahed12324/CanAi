-- Phase 24.1a: Meta Ads — read-only.  A workspace connects a Meta ad account (id + sealed access token); a worker copies campaigns and daily results (spend, impressions, clicks, results) into the
-- database so the workspace can see them.  Nothing here can create, change or spend anything on Meta: that comes later and only after approval.
--   * the token is sealed by the app; the app role cannot SELECT it (column grants), only the admin-only worker function returns it;
--   * all Meta numbers are stored as returned (account currency), never converted or guessed.

CREATE TABLE ads_accounts (
  id             char(26)     PRIMARY KEY,
  tenant_id      char(26)     NOT NULL REFERENCES tenants (id),
  name           varchar(120) NOT NULL CHECK (btrim(name) <> ''),
  external_id    varchar(30)  NOT NULL CHECK (external_id ~ '^[0-9]{5,30}$'),
  currency       char(3)      NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
  credentials_ct text         NOT NULL,
  key_version    smallint     NOT NULL,
  status         text         NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'invalid', 'disabled')),
  last_synced_at timestamptz,
  sync_error     varchar(300),
  created_at     timestamptz  NOT NULL DEFAULT now(),
  updated_at     timestamptz  NOT NULL DEFAULT now(),
  UNIQUE (id, tenant_id),
  UNIQUE (tenant_id, external_id)
);
CREATE TABLE ads_campaigns (
  id           char(26)     PRIMARY KEY,
  tenant_id    char(26)     NOT NULL,
  account_id   char(26)     NOT NULL,
  external_id  varchar(30)  NOT NULL CHECK (external_id ~ '^[0-9]{5,30}$'),
  name         varchar(200) NOT NULL,
  objective    varchar(60),
  status       varchar(30)  NOT NULL,
  daily_budget numeric(14,2) CHECK (daily_budget IS NULL OR daily_budget >= 0),
  updated_at   timestamptz  NOT NULL DEFAULT now(),
  FOREIGN KEY (account_id, tenant_id) REFERENCES ads_accounts (id, tenant_id) ON DELETE CASCADE,
  UNIQUE (account_id, external_id)
);
CREATE TABLE ads_insights_daily (
  tenant_id    char(26)     NOT NULL,
  account_id   char(26)     NOT NULL,
  campaign_ext varchar(30)  NOT NULL CHECK (campaign_ext ~ '^[0-9]{5,30}$'),
  day          date         NOT NULL,
  spend        numeric(14,2) NOT NULL CHECK (spend >= 0),
  impressions  bigint       NOT NULL CHECK (impressions >= 0),
  clicks       bigint       NOT NULL CHECK (clicks >= 0),
  results      bigint       NOT NULL DEFAULT 0 CHECK (results >= 0),
  PRIMARY KEY (account_id, campaign_ext, day),
  FOREIGN KEY (account_id, tenant_id) REFERENCES ads_accounts (id, tenant_id) ON DELETE CASCADE
);
CREATE INDEX ads_insights_day ON ads_insights_daily (tenant_id, day);

DO $$ DECLARE t text; BEGIN
  FOREACH t IN ARRAY ARRAY['ads_accounts', 'ads_campaigns', 'ads_insights_daily'] LOOP
    EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', t);
    EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY', t);
    EXECUTE format('CREATE POLICY tenant_isolation ON %I USING (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin())) WITH CHECK (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()))', t);
    EXECUTE format('REVOKE ALL ON %I FROM jarvis_app_rw, jarvis_admin', t);
  END LOOP;
END $$;
GRANT SELECT (id, tenant_id, name, external_id, currency, status, last_synced_at, sync_error, created_at, updated_at) ON ads_accounts TO jarvis_app_rw, jarvis_admin;
GRANT SELECT ON ads_campaigns, ads_insights_daily TO jarvis_app_rw, jarvis_admin;

-- Connect (or re-connect with a new token) an ad account.  Returns the account id, or 'limit' (5 per workspace).  22023 on bad input.
CREATE FUNCTION ads_account_save(p_id char(26), p_tenant char(26), p_name text, p_external text, p_currency text, p_ct text, p_ver smallint) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); a char(26); n integer; nm text := btrim(coalesce(p_name, ''));
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF nm = '' OR char_length(nm) > 120 OR p_external IS NULL OR p_external !~ '^[0-9]{5,30}$' OR p_currency IS NULL OR p_currency !~ '^[A-Z]{3}$' OR p_ct IS NULL OR btrim(p_ct) = '' OR p_ver IS NULL THEN
    RAISE EXCEPTION 'bad ad account' USING ERRCODE = '22023';
  END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT id INTO a FROM ads_accounts WHERE tenant_id = p_tenant AND external_id = p_external;
  IF a IS NULL THEN
    SELECT count(*) INTO n FROM ads_accounts WHERE tenant_id = p_tenant;
    IF n >= 5 THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'limit'; END IF;
    INSERT INTO ads_accounts (id, tenant_id, name, external_id, currency, credentials_ct, key_version) VALUES (p_id, p_tenant, nm, p_external, p_currency, p_ct, p_ver);
    a := p_id;
  ELSE
    UPDATE ads_accounts SET name = nm, currency = p_currency, credentials_ct = p_ct, key_version = p_ver, status = 'active', sync_error = NULL, updated_at = now() WHERE id = a;
  END IF;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN a;
END $$;

-- Disconnect: forgets the token, campaigns and numbers copied from Meta.  Nothing on Meta changes.  result: ok | not_found
CREATE FUNCTION ads_account_delete(p_id char(26), p_tenant char(26)) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); rc integer;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  PERFORM set_config('app.bypass_rls', 'on', true);
  DELETE FROM ads_accounts WHERE id = p_id AND tenant_id = p_tenant;
  GET DIAGNOSTICS rc = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN rc = 0 THEN 'not_found' ELSE 'ok' END;
END $$;

-- WORKER (admin only).  Active accounts not synced in the last p_minutes minutes, oldest first, with the sealed token.
CREATE FUNCTION ads_sync_due(p_limit integer, p_minutes integer) RETURNS TABLE (account_id char(26), tenant_id char(26), external_id varchar, credentials_ct text, key_version smallint)
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), '');
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  RETURN QUERY
  WITH picked AS (
    SELECT a.id FROM ads_accounts a
     WHERE a.status = 'active' AND NOT emergency_active(a.tenant_id) AND (a.last_synced_at IS NULL OR a.last_synced_at < now() - make_interval(mins => greatest(5, coalesce(p_minutes, 60))))
     ORDER BY a.last_synced_at NULLS FIRST LIMIT greatest(1, least(coalesce(p_limit, 1), 10)) FOR UPDATE OF a SKIP LOCKED)
  UPDATE ads_accounts s SET last_synced_at = now() FROM picked WHERE s.id = picked.id
    RETURNING s.id, s.tenant_id, s.external_id, s.credentials_ct, s.key_version;
  PERFORM set_config('app.bypass_rls', prev, true);
END $$;

-- WORKER (admin only).  Stores what Meta returned.  p_campaigns: [{id,name,objective,status,daily_budget}], p_insights: [{campaign,day,spend,impressions,clicks,results}].
-- Rows with a malformed id/day/number are skipped (never guessed); at most 500 campaigns and 20000 daily rows per call.  result: ok | not_found | bad_input
CREATE FUNCTION ads_sync_store(p_account char(26), p_tenant char(26), p_campaigns jsonb, p_insights jsonb) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), '');
BEGIN
  IF p_campaigns IS NULL OR p_insights IS NULL OR jsonb_typeof(p_campaigns) <> 'array' OR jsonb_typeof(p_insights) <> 'array'
     OR jsonb_array_length(p_campaigns) > 500 OR jsonb_array_length(p_insights) > 20000 THEN RETURN 'bad_input'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  IF NOT EXISTS (SELECT 1 FROM ads_accounts WHERE id = p_account AND tenant_id = p_tenant) THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'not_found'; END IF;
  INSERT INTO ads_campaigns (id, tenant_id, account_id, external_id, name, objective, status, daily_budget)
    SELECT substr(md5(p_account || ':' || c.id) || md5(c.id || ':' || p_account), 1, 26), p_tenant, p_account, c.id, left(btrim(coalesce(c.name, '')), 200), left(c.objective, 60), left(coalesce(c.status, 'UNKNOWN'), 30),
           CASE WHEN c.daily_budget ~ '^[0-9]{1,12}(\.[0-9]{1,2})?$' THEN c.daily_budget::numeric ELSE NULL END
      FROM jsonb_to_recordset(p_campaigns) AS c(id text, name text, objective text, status text, daily_budget text)
     WHERE c.id ~ '^[0-9]{5,30}$'
    ON CONFLICT (account_id, external_id) DO UPDATE SET name = excluded.name, objective = excluded.objective, status = excluded.status, daily_budget = excluded.daily_budget, updated_at = now();
  INSERT INTO ads_insights_daily (tenant_id, account_id, campaign_ext, day, spend, impressions, clicks, results)
    SELECT p_tenant, p_account, i.campaign, i.day::date, i.spend::numeric, coalesce(nullif(i.impressions, ''), '0')::bigint, coalesce(nullif(i.clicks, ''), '0')::bigint, coalesce(nullif(i.results, ''), '0')::bigint
      FROM jsonb_to_recordset(p_insights) AS i(campaign text, day text, spend text, impressions text, clicks text, results text)
     WHERE i.campaign ~ '^[0-9]{5,30}$' AND i.day ~ '^[0-9]{4}-[0-9]{2}-[0-9]{2}$' AND i.spend ~ '^[0-9]{1,12}(\.[0-9]{1,6})?$'
       AND coalesce(nullif(i.impressions, ''), '0') ~ '^[0-9]{1,15}$' AND coalesce(nullif(i.clicks, ''), '0') ~ '^[0-9]{1,15}$' AND coalesce(nullif(i.results, ''), '0') ~ '^[0-9]{1,15}$'
    ON CONFLICT (account_id, campaign_ext, day) DO UPDATE SET spend = excluded.spend, impressions = excluded.impressions, clicks = excluded.clicks, results = excluded.results;
  UPDATE ads_accounts SET sync_error = NULL, updated_at = now() WHERE id = p_account;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

-- WORKER (admin only).  Records why a sync failed; p_invalid = true when Meta rejected the token (the account is then 'invalid' until re-connected).
CREATE FUNCTION ads_sync_fail(p_account char(26), p_tenant char(26), p_note text, p_invalid boolean) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); rc integer;
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE ads_accounts SET sync_error = left(btrim(coalesce(p_note, '')), 300), status = CASE WHEN p_invalid THEN 'invalid' ELSE status END, updated_at = now() WHERE id = p_account AND tenant_id = p_tenant;
  GET DIAGNOSTICS rc = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN rc = 0 THEN 'not_found' ELSE 'ok' END;
END $$;

DO $$ DECLARE f text; BEGIN
  FOREACH f IN ARRAY ARRAY['ads_account_save(char, char, text, text, text, text, smallint)', 'ads_account_delete(char, char)'] LOOP
    EXECUTE format('REVOKE ALL ON FUNCTION %s FROM PUBLIC, jarvis_app_rw, jarvis_admin', f);
    EXECUTE format('GRANT EXECUTE ON FUNCTION %s TO jarvis_app_rw, jarvis_admin', f);
  END LOOP;
  FOREACH f IN ARRAY ARRAY['ads_sync_due(integer, integer)', 'ads_sync_store(char, char, jsonb, jsonb)', 'ads_sync_fail(char, char, text, boolean)'] LOOP
    EXECUTE format('REVOKE ALL ON FUNCTION %s FROM PUBLIC, jarvis_app_rw, jarvis_admin', f);
    EXECUTE format('GRANT EXECUTE ON FUNCTION %s TO jarvis_admin', f);
  END LOOP;
END $$;
