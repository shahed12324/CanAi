-- Phase 24.2b: Google Ads Search-campaign DRAFTS.  A person (or the AI adviser, which only writes words) prepares a Search campaign draft: keywords, ad headlines and descriptions, link, budget.  A second step approves it.
-- Nothing here talks to Google and nothing can spend money; a later phase creates approved drafts on Google as PAUSED campaigns.  Safety rules enforced here, in the database (same as Meta, own caps because currencies differ):
--   * a workspace must set a spending cap first — with no cap nothing can be submitted or approved;
--   * daily budget <= the daily cap AND daily budget x days <= the total cap, checked again at approval;
--   * a Responsive Search Ad needs 3–15 headlines (<= 30 characters) and 2–4 descriptions (<= 90 characters); keywords are PHRASE or EXACT only (never broad), up to 30; negatives up to 30;
--   * only an approver approves; approving is blocked during an emergency stop; only a draft can be edited; reopening clears the approval.

CREATE TABLE gads_settings (
  tenant_id   char(26)      PRIMARY KEY REFERENCES tenants (id),
  max_daily   numeric(14,2) NOT NULL CHECK (max_daily > 0),
  max_total   numeric(14,2) NOT NULL CHECK (max_total >= max_daily),
  updated_by  char(26)      NOT NULL REFERENCES users (id),
  updated_at  timestamptz   NOT NULL DEFAULT now()
);
CREATE TABLE gads_drafts (
  id             char(26)      PRIMARY KEY,
  tenant_id      char(26)      NOT NULL REFERENCES tenants (id),
  user_id        char(26)      NOT NULL REFERENCES users (id),
  account_id     char(26)      NOT NULL,
  name           varchar(120)  NOT NULL CHECK (btrim(name) <> ''),
  country        char(2)       NOT NULL DEFAULT 'BD' CHECK (country ~ '^[A-Z]{2}$'),
  language       text          NOT NULL DEFAULT 'bn' CHECK (language IN ('bn', 'en', 'hi', 'ar', 'ur', 'ms', 'id')),
  final_url      varchar(500),
  headlines      jsonb         NOT NULL DEFAULT '[]' CHECK (jsonb_typeof(headlines) = 'array' AND jsonb_array_length(headlines) <= 15),
  descriptions   jsonb         NOT NULL DEFAULT '[]' CHECK (jsonb_typeof(descriptions) = 'array' AND jsonb_array_length(descriptions) <= 4),
  keywords       jsonb         NOT NULL DEFAULT '[]' CHECK (jsonb_typeof(keywords) = 'array' AND jsonb_array_length(keywords) <= 30),
  negatives      jsonb         NOT NULL DEFAULT '[]' CHECK (jsonb_typeof(negatives) = 'array' AND jsonb_array_length(negatives) <= 30),
  daily_budget   numeric(14,2) NOT NULL CHECK (daily_budget > 0),
  days           smallint      NOT NULL DEFAULT 7 CHECK (days BETWEEN 1 AND 60),
  start_date     date,
  status         text          NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'pending', 'approved')),
  reject_note    varchar(300),
  approved_by    char(26)      REFERENCES users (id),
  approved_at    timestamptz,
  created_at     timestamptz   NOT NULL DEFAULT now(),
  updated_at     timestamptz   NOT NULL DEFAULT now(),
  CHECK ((status = 'approved') = (approved_by IS NOT NULL AND approved_at IS NOT NULL)),
  CHECK (final_url IS NULL OR final_url ~ '^https://[^[:space:]<>]+$'),
  FOREIGN KEY (account_id, tenant_id) REFERENCES gads_accounts (id, tenant_id) ON DELETE CASCADE
);
CREATE INDEX gads_drafts_tenant ON gads_drafts (tenant_id, updated_at DESC);

DO $$ DECLARE t text; BEGIN
  FOREACH t IN ARRAY ARRAY['gads_settings', 'gads_drafts'] LOOP
    EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', t);
    EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY', t);
    EXECUTE format('CREATE POLICY tenant_isolation ON %I USING (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin())) WITH CHECK (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()))', t);
    EXECUTE format('REVOKE ALL ON %I FROM jarvis_app_rw, jarvis_admin', t);
    EXECUTE format('GRANT SELECT ON %I TO jarvis_app_rw, jarvis_admin', t);
  END LOOP;
END $$;

-- Disconnecting an account is refused while a draft of it waits for / has approval.  result: ok | not_found | busy
CREATE OR REPLACE FUNCTION gads_account_delete(p_id char(26), p_tenant char(26)) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); rc integer;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  PERFORM set_config('app.bypass_rls', 'on', true);
  IF EXISTS (SELECT 1 FROM gads_drafts WHERE account_id = p_id AND tenant_id = p_tenant AND status IN ('pending', 'approved')) THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'busy'; END IF;
  DELETE FROM gads_accounts WHERE id = p_id AND tenant_id = p_tenant;
  GET DIAGNOSTICS rc = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN rc = 0 THEN 'not_found' ELSE 'ok' END;
END $$;

-- A jsonb array of strings, each at most p_len characters, free of <>, at most p_max items, helper (immutable, no data access).
CREATE FUNCTION gads_texts_ok(p jsonb, p_max integer, p_len integer) RETURNS boolean
LANGUAGE sql IMMUTABLE AS $$
  SELECT p IS NOT NULL AND jsonb_typeof(p) = 'array' AND jsonb_array_length(p) <= p_max
     AND NOT EXISTS (SELECT 1 FROM jsonb_array_elements(p) e WHERE jsonb_typeof(e) <> 'string' OR btrim(e #>> '{}') = '' OR char_length(e #>> '{}') > p_len OR (e #>> '{}') ~ '[<>]')
$$;

-- Keywords: [{"t": "text", "m": "phrase"|"exact"}], text 1–80 characters.
CREATE FUNCTION gads_keywords_ok(p jsonb, p_max integer) RETURNS boolean
LANGUAGE sql IMMUTABLE AS $$
  SELECT p IS NOT NULL AND jsonb_typeof(p) = 'array' AND jsonb_array_length(p) <= p_max
     AND NOT EXISTS (SELECT 1 FROM jsonb_array_elements(p) e WHERE jsonb_typeof(e) <> 'object' OR jsonb_typeof(e -> 't') IS DISTINCT FROM 'string' OR btrim(e ->> 't') = '' OR char_length(e ->> 't') > 80 OR (e ->> 't') ~ '[<>]'
        OR (e ->> 'm') IS NULL OR (e ->> 'm') NOT IN ('phrase', 'exact'))
$$;

-- Sets the workspace's Google spending caps (permission checked by the app).  result: ok | bad_input
CREATE FUNCTION gads_settings_save(p_tenant char(26), p_user char(26), p_daily numeric, p_total numeric) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), '');
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  IF p_daily IS NULL OR p_total IS NULL OR p_daily <= 0 OR p_total < p_daily OR p_total > 100000000 THEN RETURN 'bad_input'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  INSERT INTO gads_settings (tenant_id, max_daily, max_total, updated_by) VALUES (p_tenant, p_daily, p_total, p_user)
    ON CONFLICT (tenant_id) DO UPDATE SET max_daily = excluded.max_daily, max_total = excluded.max_total, updated_by = excluded.updated_by, updated_at = now();
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

-- Creates or edits a draft (author only, only while it is a draft).  At most 100 drafts per workspace.  result: ok | not_found | locked | bad_input | bad_account | limit
CREATE FUNCTION gads_draft_save(p_new boolean, p_id char(26), p_tenant char(26), p_user char(26), p_account char(26), p_name text, p_country text, p_language text, p_url text,
  p_headlines jsonb, p_descriptions jsonb, p_keywords jsonb, p_negatives jsonb, p_daily numeric, p_days integer, p_start date) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); st text; n integer; nm text := btrim(coalesce(p_name, '')); ln text := nullif(btrim(coalesce(p_url, '')), '');
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  IF nm = '' OR char_length(nm) > 120 OR p_country IS NULL OR p_country !~ '^[A-Z]{2}$' OR p_language IS NULL OR p_language NOT IN ('bn', 'en', 'hi', 'ar', 'ur', 'ms', 'id')
     OR (ln IS NOT NULL AND (char_length(ln) > 500 OR ln !~ '^https://[^[:space:]<>]+$'))
     OR NOT gads_texts_ok(p_headlines, 15, 30) OR NOT gads_texts_ok(p_descriptions, 4, 90) OR NOT gads_keywords_ok(p_keywords, 30) OR NOT gads_texts_ok(p_negatives, 30, 80)
     OR p_daily IS NULL OR p_daily <= 0 OR p_daily > 100000000 OR p_days IS NULL OR p_days < 1 OR p_days > 60 OR (p_start IS NOT NULL AND (p_start < current_date - 1 OR p_start > current_date + 90)) THEN RETURN 'bad_input'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  IF NOT EXISTS (SELECT 1 FROM gads_accounts WHERE id = p_account AND tenant_id = p_tenant) THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'bad_account'; END IF;
  IF p_new THEN
    SELECT count(*) INTO n FROM gads_drafts WHERE tenant_id = p_tenant;
    IF n >= 100 THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'limit'; END IF;
    INSERT INTO gads_drafts (id, tenant_id, user_id, account_id, name, country, language, final_url, headlines, descriptions, keywords, negatives, daily_budget, days, start_date)
      VALUES (p_id, p_tenant, p_user, p_account, nm, p_country, p_language, ln, p_headlines, p_descriptions, p_keywords, p_negatives, p_daily, p_days, p_start);
  ELSE
    SELECT status INTO st FROM gads_drafts WHERE id = p_id AND tenant_id = p_tenant AND user_id = p_user FOR UPDATE;
    IF st IS NULL THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'not_found'; END IF;
    IF st <> 'draft' THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'locked'; END IF;
    UPDATE gads_drafts SET account_id = p_account, name = nm, country = p_country, language = p_language, final_url = ln, headlines = p_headlines, descriptions = p_descriptions, keywords = p_keywords, negatives = p_negatives,
      daily_budget = p_daily, days = p_days, start_date = p_start, reject_note = NULL, updated_at = now() WHERE id = p_id;
  END IF;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

-- Shared check: complete and inside the caps?  ok | incomplete | no_cap | over_cap.  Caller holds bypass.
CREATE FUNCTION gads_draft_check(p_id char(26), p_tenant char(26)) RETURNS text
LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE d gads_drafts%ROWTYPE; s gads_settings%ROWTYPE;
BEGIN
  SELECT * INTO d FROM gads_drafts WHERE id = p_id AND tenant_id = p_tenant;
  IF NOT FOUND THEN RETURN 'incomplete'; END IF;
  IF d.final_url IS NULL OR d.start_date IS NULL OR jsonb_array_length(d.headlines) < 3 OR jsonb_array_length(d.descriptions) < 2 OR jsonb_array_length(d.keywords) < 1 THEN RETURN 'incomplete'; END IF;
  SELECT * INTO s FROM gads_settings WHERE tenant_id = p_tenant;
  IF NOT FOUND THEN RETURN 'no_cap'; END IF;
  IF d.daily_budget > s.max_daily OR d.daily_budget * d.days > s.max_total THEN RETURN 'over_cap'; END IF;
  RETURN 'ok';
END $$;

-- Author sends a complete draft for approval.  result: ok | not_found | incomplete | no_cap | over_cap
CREATE FUNCTION gads_draft_submit(p_id char(26), p_tenant char(26), p_user char(26)) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); st text; c text;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT status INTO st FROM gads_drafts WHERE id = p_id AND tenant_id = p_tenant AND user_id = p_user FOR UPDATE;
  IF st IS DISTINCT FROM 'draft' THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'not_found'; END IF;
  c := gads_draft_check(p_id, p_tenant);
  IF c <> 'ok' THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN c; END IF;
  UPDATE gads_drafts SET status = 'pending', reject_note = NULL, updated_at = now() WHERE id = p_id;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

-- An approver approves or sends back a pending draft.  Approving re-checks completeness and the caps and is refused during an emergency stop.  result: ok | not_found | incomplete | no_cap | over_cap | emergency
CREATE FUNCTION gads_draft_decide(p_id char(26), p_tenant char(26), p_approver char(26), p_approve boolean, p_note text) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); st text; c text; note text := nullif(left(btrim(coalesce(p_note, '')), 300), '');
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_approver IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT status INTO st FROM gads_drafts WHERE id = p_id AND tenant_id = p_tenant FOR UPDATE;
  IF st IS DISTINCT FROM 'pending' THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'not_found'; END IF;
  IF p_approve THEN
    IF emergency_active(p_tenant) THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'emergency'; END IF;
    c := gads_draft_check(p_id, p_tenant);
    IF c <> 'ok' THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN c; END IF;
    UPDATE gads_drafts SET status = 'approved', approved_by = p_approver, approved_at = now(), reject_note = NULL, updated_at = now() WHERE id = p_id;
  ELSE
    UPDATE gads_drafts SET status = 'draft', reject_note = note, updated_at = now() WHERE id = p_id;
  END IF;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

-- Author takes a pending / approved draft back to a draft (clears the approval).  result: ok | not_found
CREATE FUNCTION gads_draft_reopen(p_id char(26), p_tenant char(26), p_user char(26)) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); rc integer;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE gads_drafts SET status = 'draft', approved_by = NULL, approved_at = NULL, updated_at = now() WHERE id = p_id AND tenant_id = p_tenant AND user_id = p_user AND status IN ('pending', 'approved');
  GET DIAGNOSTICS rc = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN rc = 0 THEN 'not_found' ELSE 'ok' END;
END $$;

-- Author deletes a draft that is still a draft.  result: ok | not_found
CREATE FUNCTION gads_draft_delete(p_id char(26), p_tenant char(26), p_user char(26)) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); rc integer;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  DELETE FROM gads_drafts WHERE id = p_id AND tenant_id = p_tenant AND user_id = p_user AND status = 'draft';
  GET DIAGNOSTICS rc = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN rc = 0 THEN 'not_found' ELSE 'ok' END;
END $$;

DO $$ DECLARE f text; BEGIN
  FOREACH f IN ARRAY ARRAY['gads_settings_save(char, char, numeric, numeric)', 'gads_draft_save(boolean, char, char, char, char, text, text, text, text, jsonb, jsonb, jsonb, jsonb, numeric, integer, date)',
    'gads_draft_submit(char, char, char)', 'gads_draft_decide(char, char, char, boolean, text)', 'gads_draft_reopen(char, char, char)', 'gads_draft_delete(char, char, char)'] LOOP
    EXECUTE format('REVOKE ALL ON FUNCTION %s FROM PUBLIC, jarvis_app_rw, jarvis_admin', f);
    EXECUTE format('GRANT EXECUTE ON FUNCTION %s TO jarvis_app_rw, jarvis_admin', f);
  END LOOP;
  REVOKE ALL ON FUNCTION gads_draft_check(char, char) FROM PUBLIC, jarvis_app_rw, jarvis_admin;
END $$;
