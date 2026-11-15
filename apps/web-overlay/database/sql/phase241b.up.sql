-- Phase 24.1b: Meta ad DRAFTS.  A person (or the AI adviser, which only writes words) prepares a campaign draft: goal, audience, budget, ad text, link.  A second step approves it.
-- Nothing here talks to Meta and nothing can spend money; a later phase creates approved drafts on Meta as PAUSED campaigns.  Safety rules enforced here, in the database:
--   * a workspace must set a spending cap first — with no cap nothing can be submitted or approved;
--   * daily budget <= the daily cap AND daily budget x days <= the total cap, checked again at approval (a lowered cap stops older drafts);
--   * only an approver (permission checked by the app) approves; approving is blocked during an emergency stop;
--   * only a draft can be edited; reopening a pending / approved draft clears the approval.

CREATE TABLE ads_settings (
  tenant_id       char(26)      PRIMARY KEY REFERENCES tenants (id),
  max_daily       numeric(14,2) NOT NULL CHECK (max_daily > 0),
  max_total       numeric(14,2) NOT NULL CHECK (max_total >= max_daily),
  updated_by      char(26)      NOT NULL REFERENCES users (id),
  updated_at      timestamptz   NOT NULL DEFAULT now()
);
CREATE TABLE ads_drafts (
  id            char(26)      PRIMARY KEY,
  tenant_id     char(26)      NOT NULL REFERENCES tenants (id),
  user_id       char(26)      NOT NULL REFERENCES users (id),
  account_id    char(26)      NOT NULL,
  name          varchar(120)  NOT NULL CHECK (btrim(name) <> ''),
  objective     text          NOT NULL CHECK (objective IN ('sales', 'leads', 'messages', 'traffic', 'awareness')),
  country       char(2)       NOT NULL DEFAULT 'BD' CHECK (country ~ '^[A-Z]{2}$'),
  age_min       smallint      NOT NULL DEFAULT 18 CHECK (age_min BETWEEN 18 AND 65),
  age_max       smallint      NOT NULL DEFAULT 65 CHECK (age_max BETWEEN 18 AND 65),
  gender        text          NOT NULL DEFAULT 'all' CHECK (gender IN ('all', 'men', 'women')),
  interests     varchar(300),
  primary_text  varchar(1000) NOT NULL DEFAULT '',
  headline      varchar(80)   NOT NULL DEFAULT '',
  description   varchar(200)  NOT NULL DEFAULT '',
  cta           text          NOT NULL DEFAULT 'LEARN_MORE' CHECK (cta IN ('SHOP_NOW', 'LEARN_MORE', 'SIGN_UP', 'CONTACT_US', 'SEND_MESSAGE', 'ORDER_NOW', 'GET_OFFER')),
  link_url      varchar(500),
  daily_budget  numeric(14,2) NOT NULL CHECK (daily_budget > 0),
  days          smallint      NOT NULL DEFAULT 7 CHECK (days BETWEEN 1 AND 60),
  start_date    date,
  status        text          NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'pending', 'approved')),
  reject_note   varchar(300),
  approved_by   char(26)      REFERENCES users (id),
  approved_at   timestamptz,
  created_at    timestamptz   NOT NULL DEFAULT now(),
  updated_at    timestamptz   NOT NULL DEFAULT now(),
  CHECK (age_min <= age_max),
  CHECK ((status = 'approved') = (approved_by IS NOT NULL AND approved_at IS NOT NULL)),
  CHECK (link_url IS NULL OR link_url ~ '^https://[^[:space:]<>]+$'),
  FOREIGN KEY (account_id, tenant_id) REFERENCES ads_accounts (id, tenant_id) ON DELETE CASCADE
);
CREATE INDEX ads_drafts_tenant ON ads_drafts (tenant_id, updated_at DESC);

DO $$ DECLARE t text; BEGIN
  FOREACH t IN ARRAY ARRAY['ads_settings', 'ads_drafts'] LOOP
    EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', t);
    EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY', t);
    EXECUTE format('CREATE POLICY tenant_isolation ON %I USING (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin())) WITH CHECK (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()))', t);
    EXECUTE format('REVOKE ALL ON %I FROM jarvis_app_rw, jarvis_admin', t);
    EXECUTE format('GRANT SELECT ON %I TO jarvis_app_rw, jarvis_admin', t);
  END LOOP;
END $$;

-- Disconnecting an ad account is refused while a draft of it waits for / has approval.  result: ok | not_found | busy
CREATE OR REPLACE FUNCTION ads_account_delete(p_id char(26), p_tenant char(26)) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); rc integer;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  PERFORM set_config('app.bypass_rls', 'on', true);
  IF EXISTS (SELECT 1 FROM ads_drafts WHERE account_id = p_id AND tenant_id = p_tenant AND status IN ('pending', 'approved')) THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'busy'; END IF;
  DELETE FROM ads_accounts WHERE id = p_id AND tenant_id = p_tenant;
  GET DIAGNOSTICS rc = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN rc = 0 THEN 'not_found' ELSE 'ok' END;
END $$;

-- Sets the workspace's spending caps (permission checked by the app).  result: ok | bad_input
CREATE FUNCTION ads_settings_save(p_tenant char(26), p_user char(26), p_daily numeric, p_total numeric) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), '');
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  IF p_daily IS NULL OR p_total IS NULL OR p_daily <= 0 OR p_total < p_daily OR p_total > 100000000 THEN RETURN 'bad_input'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  INSERT INTO ads_settings (tenant_id, max_daily, max_total, updated_by) VALUES (p_tenant, p_daily, p_total, p_user)
    ON CONFLICT (tenant_id) DO UPDATE SET max_daily = excluded.max_daily, max_total = excluded.max_total, updated_by = excluded.updated_by, updated_at = now();
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

-- Creates or edits a draft (author only, only while it is a draft).  At most 100 drafts per workspace.
-- result: ok | not_found | locked | bad_input | bad_account | limit
CREATE FUNCTION ads_draft_save(p_new boolean, p_id char(26), p_tenant char(26), p_user char(26), p_account char(26), p_name text, p_objective text, p_country text, p_age_min integer, p_age_max integer, p_gender text,
  p_interests text, p_primary text, p_headline text, p_description text, p_cta text, p_link text, p_daily numeric, p_days integer, p_start date) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); st text; n integer; nm text := btrim(coalesce(p_name, '')); ln text := nullif(btrim(coalesce(p_link, '')), ''); it text := nullif(btrim(coalesce(p_interests, '')), '');
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  IF nm = '' OR char_length(nm) > 120 OR p_objective IS NULL OR p_objective NOT IN ('sales', 'leads', 'messages', 'traffic', 'awareness') OR p_country IS NULL OR p_country !~ '^[A-Z]{2}$'
     OR p_age_min IS NULL OR p_age_max IS NULL OR p_age_min < 18 OR p_age_max > 65 OR p_age_min > p_age_max OR p_gender IS NULL OR p_gender NOT IN ('all', 'men', 'women')
     OR (it IS NOT NULL AND (char_length(it) > 300 OR it ~ '[<>]')) OR char_length(coalesce(p_primary, '')) > 1000 OR char_length(coalesce(p_headline, '')) > 80 OR char_length(coalesce(p_description, '')) > 200
     OR coalesce(p_primary, '') ~ '[<>]' OR coalesce(p_headline, '') ~ '[<>]' OR coalesce(p_description, '') ~ '[<>]'
     OR p_cta IS NULL OR p_cta NOT IN ('SHOP_NOW', 'LEARN_MORE', 'SIGN_UP', 'CONTACT_US', 'SEND_MESSAGE', 'ORDER_NOW', 'GET_OFFER')
     OR (ln IS NOT NULL AND (char_length(ln) > 500 OR ln !~ '^https://[^[:space:]<>]+$'))
     OR p_daily IS NULL OR p_daily <= 0 OR p_daily > 100000000 OR p_days IS NULL OR p_days < 1 OR p_days > 60 OR (p_start IS NOT NULL AND (p_start < current_date - 1 OR p_start > current_date + 90)) THEN RETURN 'bad_input'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  IF NOT EXISTS (SELECT 1 FROM ads_accounts WHERE id = p_account AND tenant_id = p_tenant) THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'bad_account'; END IF;
  IF p_new THEN
    SELECT count(*) INTO n FROM ads_drafts WHERE tenant_id = p_tenant;
    IF n >= 100 THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'limit'; END IF;
    INSERT INTO ads_drafts (id, tenant_id, user_id, account_id, name, objective, country, age_min, age_max, gender, interests, primary_text, headline, description, cta, link_url, daily_budget, days, start_date)
      VALUES (p_id, p_tenant, p_user, p_account, nm, p_objective, p_country, p_age_min, p_age_max, p_gender, it, coalesce(p_primary, ''), coalesce(p_headline, ''), coalesce(p_description, ''), p_cta, ln, p_daily, p_days, p_start);
  ELSE
    SELECT status INTO st FROM ads_drafts WHERE id = p_id AND tenant_id = p_tenant AND user_id = p_user FOR UPDATE;
    IF st IS NULL THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'not_found'; END IF;
    IF st <> 'draft' THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'locked'; END IF;
    UPDATE ads_drafts SET account_id = p_account, name = nm, objective = p_objective, country = p_country, age_min = p_age_min, age_max = p_age_max, gender = p_gender, interests = it, primary_text = coalesce(p_primary, ''),
      headline = coalesce(p_headline, ''), description = coalesce(p_description, ''), cta = p_cta, link_url = ln, daily_budget = p_daily, days = p_days, start_date = p_start, reject_note = NULL, updated_at = now() WHERE id = p_id;
  END IF;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

-- Shared check: is the draft complete and inside the caps?  Returns ok | incomplete | no_cap | over_cap.  Caller holds bypass.
CREATE FUNCTION ads_draft_check(p_id char(26), p_tenant char(26)) RETURNS text
LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE d ads_drafts%ROWTYPE; s ads_settings%ROWTYPE;
BEGIN
  SELECT * INTO d FROM ads_drafts WHERE id = p_id AND tenant_id = p_tenant;
  IF NOT FOUND THEN RETURN 'incomplete'; END IF;
  IF btrim(d.primary_text) = '' OR btrim(d.headline) = '' OR (d.objective IN ('sales', 'leads', 'traffic') AND d.link_url IS NULL) THEN RETURN 'incomplete'; END IF;
  SELECT * INTO s FROM ads_settings WHERE tenant_id = p_tenant;
  IF NOT FOUND THEN RETURN 'no_cap'; END IF;
  IF d.daily_budget > s.max_daily OR d.daily_budget * d.days > s.max_total THEN RETURN 'over_cap'; END IF;
  RETURN 'ok';
END $$;

-- Author sends a complete draft for approval.  result: ok | not_found | incomplete | no_cap | over_cap
CREATE FUNCTION ads_draft_submit(p_id char(26), p_tenant char(26), p_user char(26)) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); st text; c text;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT status INTO st FROM ads_drafts WHERE id = p_id AND tenant_id = p_tenant AND user_id = p_user FOR UPDATE;
  IF st IS DISTINCT FROM 'draft' THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'not_found'; END IF;
  c := ads_draft_check(p_id, p_tenant);
  IF c <> 'ok' THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN c; END IF;
  UPDATE ads_drafts SET status = 'pending', reject_note = NULL, updated_at = now() WHERE id = p_id;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

-- An approver approves or sends back a pending draft.  Approving re-checks completeness and the caps and is refused during an emergency stop.
-- result: ok | not_found | incomplete | no_cap | over_cap | emergency
CREATE FUNCTION ads_draft_decide(p_id char(26), p_tenant char(26), p_approver char(26), p_approve boolean, p_note text) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); st text; c text; note text := nullif(left(btrim(coalesce(p_note, '')), 300), '');
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_approver IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT status INTO st FROM ads_drafts WHERE id = p_id AND tenant_id = p_tenant FOR UPDATE;
  IF st IS DISTINCT FROM 'pending' THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'not_found'; END IF;
  IF p_approve THEN
    IF emergency_active(p_tenant) THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'emergency'; END IF;
    c := ads_draft_check(p_id, p_tenant);
    IF c <> 'ok' THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN c; END IF;
    UPDATE ads_drafts SET status = 'approved', approved_by = p_approver, approved_at = now(), reject_note = NULL, updated_at = now() WHERE id = p_id;
  ELSE
    UPDATE ads_drafts SET status = 'draft', reject_note = note, updated_at = now() WHERE id = p_id;
  END IF;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

-- Author takes a pending / approved draft back to a draft (clears the approval).  result: ok | not_found
CREATE FUNCTION ads_draft_reopen(p_id char(26), p_tenant char(26), p_user char(26)) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); rc integer;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE ads_drafts SET status = 'draft', approved_by = NULL, approved_at = NULL, updated_at = now() WHERE id = p_id AND tenant_id = p_tenant AND user_id = p_user AND status IN ('pending', 'approved');
  GET DIAGNOSTICS rc = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN rc = 0 THEN 'not_found' ELSE 'ok' END;
END $$;

-- Author deletes a draft that is still a draft.  result: ok | not_found
CREATE FUNCTION ads_draft_delete(p_id char(26), p_tenant char(26), p_user char(26)) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); rc integer;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  DELETE FROM ads_drafts WHERE id = p_id AND tenant_id = p_tenant AND user_id = p_user AND status = 'draft';
  GET DIAGNOSTICS rc = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN rc = 0 THEN 'not_found' ELSE 'ok' END;
END $$;

DO $$ DECLARE f text; BEGIN
  FOREACH f IN ARRAY ARRAY['ads_settings_save(char, char, numeric, numeric)', 'ads_draft_save(boolean, char, char, char, char, text, text, text, integer, integer, text, text, text, text, text, text, text, numeric, integer, date)',
    'ads_draft_submit(char, char, char)', 'ads_draft_decide(char, char, char, boolean, text)', 'ads_draft_reopen(char, char, char)', 'ads_draft_delete(char, char, char)'] LOOP
    EXECUTE format('REVOKE ALL ON FUNCTION %s FROM PUBLIC, jarvis_app_rw, jarvis_admin', f);
    EXECUTE format('GRANT EXECUTE ON FUNCTION %s TO jarvis_app_rw, jarvis_admin', f);
  END LOOP;
  REVOKE ALL ON FUNCTION ads_draft_check(char, char) FROM PUBLIC, jarvis_app_rw, jarvis_admin;
END $$;
