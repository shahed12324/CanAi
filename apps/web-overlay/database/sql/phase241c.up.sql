-- Phase 24.1c: an APPROVED draft is created on Meta — always as PAUSED objects (campaign, ad set, creative, ad).  Nothing is ever switched on by this system, so no money is spent
-- until a person presses "Active" in Meta Ads Manager.  Safety rules enforced here:
--   * a draft is claimed exactly once per attempt (launch_state 'launching'); every Meta id is saved the moment Meta returns it, so a retry RESUMES and never re-creates a finished step;
--   * an attempt whose outcome is unknown (timeout, claim that never reported back) is 'failed' with launch_unknown — it is never retried by itself; the person must check Ads Manager and confirm;
--   * the caps, completeness, page and pixel are re-checked at claim time; emergency stop pauses launching; at most p_hourly_cap attempts per workspace per hour;
--   * only objectives we can create correctly are accepted (traffic, awareness, sales with a pixel).  Leads and messages need forms / destinations that come later.

ALTER TABLE ads_accounts ADD COLUMN pixel_id varchar(30) CHECK (pixel_id IS NULL OR pixel_id ~ '^[0-9]{5,30}$');
GRANT SELECT (pixel_id) ON ads_accounts TO jarvis_app_rw, jarvis_admin;

ALTER TABLE ads_drafts
  ADD COLUMN page_account_id   char(26),
  ADD COLUMN launch_state      text CHECK (launch_state IS NULL OR launch_state IN ('launching', 'created', 'failed')),
  ADD COLUMN launch_error      varchar(400),
  ADD COLUMN launch_unknown    boolean     NOT NULL DEFAULT false,
  ADD COLUMN launch_started_at timestamptz,
  ADD COLUMN launch_attempts   smallint    NOT NULL DEFAULT 0,
  ADD COLUMN launched_at       timestamptz,
  ADD COLUMN remote_campaign   varchar(30) CHECK (remote_campaign IS NULL OR remote_campaign ~ '^[0-9]{5,30}$'),
  ADD COLUMN remote_adset      varchar(30) CHECK (remote_adset IS NULL OR remote_adset ~ '^[0-9]{5,30}$'),
  ADD COLUMN remote_creative   varchar(30) CHECK (remote_creative IS NULL OR remote_creative ~ '^[0-9]{5,30}$'),
  ADD COLUMN remote_ad         varchar(30) CHECK (remote_ad IS NULL OR remote_ad ~ '^[0-9]{5,30}$'),
  ADD CONSTRAINT ads_drafts_launch_only_approved CHECK (launch_state IS NULL OR status = 'approved'),
  ADD CONSTRAINT ads_drafts_created_complete CHECK (launch_state IS DISTINCT FROM 'created' OR (remote_campaign IS NOT NULL AND remote_adset IS NOT NULL AND remote_creative IS NOT NULL AND remote_ad IS NOT NULL AND launched_at IS NOT NULL));
CREATE INDEX ads_drafts_launch ON ads_drafts (approved_at) WHERE status = 'approved' AND launch_state IS NULL;

-- Sets (or clears, with an empty value) the Meta Pixel of an ad account — needed for sales campaigns.  result: ok | not_found | bad_input
CREATE FUNCTION ads_account_set_pixel(p_id char(26), p_tenant char(26), p_pixel text) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); rc integer; px text := nullif(btrim(coalesce(p_pixel, '')), '');
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF px IS NOT NULL AND px !~ '^[0-9]{5,30}$' THEN RETURN 'bad_input'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE ads_accounts SET pixel_id = px, updated_at = now() WHERE id = p_id AND tenant_id = p_tenant;
  GET DIAGNOSTICS rc = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN rc = 0 THEN 'not_found' ELSE 'ok' END;
END $$;

-- The author picks the connected Facebook Page the ad is published as (empty = none).  result: ok | not_found | locked | bad_page
CREATE FUNCTION ads_draft_set_page(p_id char(26), p_tenant char(26), p_user char(26), p_page char(26)) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); st text;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT status INTO st FROM ads_drafts WHERE id = p_id AND tenant_id = p_tenant AND user_id = p_user FOR UPDATE;
  IF st IS NULL THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'not_found'; END IF;
  IF st <> 'draft' THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'locked'; END IF;
  IF p_page IS NOT NULL AND NOT EXISTS (SELECT 1 FROM social_accounts WHERE id = p_page AND tenant_id = p_tenant AND platform = 'facebook') THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'bad_page'; END IF;
  UPDATE ads_drafts SET page_account_id = p_page, updated_at = now() WHERE id = p_id;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

-- 24.1b check, now also: a start date (the end date = start + days keeps the approved total true) and only launchable drafts (objective, button, a connected Page, a Pixel for sales).  ok | incomplete | unsupported | no_page | no_pixel | no_cap | over_cap
CREATE OR REPLACE FUNCTION ads_draft_check(p_id char(26), p_tenant char(26)) RETURNS text
LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE d ads_drafts%ROWTYPE; s ads_settings%ROWTYPE; px text;
BEGIN
  SELECT * INTO d FROM ads_drafts WHERE id = p_id AND tenant_id = p_tenant;
  IF NOT FOUND THEN RETURN 'incomplete'; END IF;
  IF btrim(d.primary_text) = '' OR btrim(d.headline) = '' OR d.link_url IS NULL OR d.start_date IS NULL THEN RETURN 'incomplete'; END IF;
  IF d.objective NOT IN ('traffic', 'awareness', 'sales') OR d.cta = 'SEND_MESSAGE' THEN RETURN 'unsupported'; END IF;
  IF d.page_account_id IS NULL OR NOT EXISTS (SELECT 1 FROM social_accounts WHERE id = d.page_account_id AND tenant_id = p_tenant AND platform = 'facebook' AND status = 'active') THEN RETURN 'no_page'; END IF;
  IF d.objective = 'sales' THEN
    SELECT pixel_id INTO px FROM ads_accounts WHERE id = d.account_id AND tenant_id = p_tenant;
    IF px IS NULL THEN RETURN 'no_pixel'; END IF;
  END IF;
  SELECT * INTO s FROM ads_settings WHERE tenant_id = p_tenant;
  IF NOT FOUND THEN RETURN 'no_cap'; END IF;
  IF d.daily_budget > s.max_daily OR d.daily_budget * d.days > s.max_total THEN RETURN 'over_cap'; END IF;
  RETURN 'ok';
END $$;

-- Taking a draft back to edit it is refused once Meta objects exist (or may exist).  result: ok | not_found | launched
CREATE OR REPLACE FUNCTION ads_draft_reopen(p_id char(26), p_tenant char(26), p_user char(26)) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); d ads_drafts%ROWTYPE;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO d FROM ads_drafts WHERE id = p_id AND tenant_id = p_tenant AND user_id = p_user AND status IN ('pending', 'approved') FOR UPDATE;
  IF NOT FOUND THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'not_found'; END IF;
  IF d.launch_state IS NOT NULL AND NOT (d.launch_state = 'failed' AND d.remote_campaign IS NULL AND NOT d.launch_unknown) THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'launched'; END IF;
  UPDATE ads_drafts SET status = 'draft', approved_by = NULL, approved_at = NULL, launch_state = NULL, launch_error = NULL, launch_attempts = 0, updated_at = now() WHERE id = p_id;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

-- 24.1a function redefined: disconnecting is refused while a draft waits for approval or is approved and not yet created.
CREATE OR REPLACE FUNCTION ads_account_delete(p_id char(26), p_tenant char(26)) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); rc integer;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  PERFORM set_config('app.bypass_rls', 'on', true);
  IF EXISTS (SELECT 1 FROM ads_drafts WHERE account_id = p_id AND tenant_id = p_tenant AND (status = 'pending' OR (status = 'approved' AND launch_state IS DISTINCT FROM 'created'))) THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'busy'; END IF;
  DELETE FROM ads_accounts WHERE id = p_id AND tenant_id = p_tenant;
  GET DIAGNOSTICS rc = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN rc = 0 THEN 'not_found' ELSE 'ok' END;
END $$;

-- WORKER (admin only).  Takes approved, not-yet-launched drafts and marks them 'launching' (once per attempt).  At most p_hourly_cap attempts per workspace per hour.
-- A draft whose caps / page / pixel no longer check out, or whose ad account is not active, is failed with a reason instead (nothing is created).  A claim older than 15 minutes is failed as unknown.
CREATE FUNCTION ads_launch_claim(p_limit integer, p_hourly_cap integer) RETURNS TABLE (draft_id char(26), tenant_id char(26), user_id char(26), account_id char(26), account_ext varchar, currency char(3), pixel_id varchar, credentials_ct text, key_version smallint,
  page_ext varchar, name varchar, objective text, country char(2), age_min smallint, age_max smallint, gender text, primary_text varchar, headline varchar, description varchar, cta text, link_url varchar, daily_budget numeric, days smallint, start_date date,
  remote_campaign varchar, remote_adset varchar, remote_creative varchar, remote_ad varchar)
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); r record; n integer := 0; lim integer := greatest(1, least(coalesce(p_limit, 1), 5)); cap integer := greatest(0, coalesce(p_hourly_cap, 0)); used integer; c text; acct_ok boolean;
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE ads_drafts SET launch_state = 'failed', launch_unknown = true, launch_error = 'ফলাফল অজানা — Meta Ads Manager-এ দেখুন কিছু তৈরি হয়েছে কিনা; তারপর "আবার চেষ্টা" দিন।', updated_at = now()
    WHERE launch_state = 'launching' AND launch_started_at < now() - interval '15 minutes';
  FOR r IN
    SELECT d.id, d.tenant_id AS tid FROM ads_drafts d
     WHERE d.status = 'approved' AND d.launch_state IS NULL AND NOT emergency_active(d.tenant_id)
     ORDER BY d.approved_at FOR UPDATE OF d SKIP LOCKED
  LOOP
    EXIT WHEN n >= lim;
    SELECT count(*) INTO used FROM ads_drafts q WHERE q.tenant_id = r.tid AND q.launch_started_at > now() - interval '1 hour';
    CONTINUE WHEN used >= cap;
    c := ads_draft_check(r.id, r.tid);
    SELECT (a.status = 'active') INTO acct_ok FROM ads_drafts d2 JOIN ads_accounts a ON a.id = d2.account_id AND a.tenant_id = d2.tenant_id WHERE d2.id = r.id;
    IF c <> 'ok' OR acct_ok IS NOT TRUE THEN
      UPDATE ads_drafts SET launch_state = 'failed', launch_unknown = false, launch_attempts = launch_attempts + 1, launch_started_at = now(),
        launch_error = CASE WHEN acct_ok IS NOT TRUE THEN 'বিজ্ঞাপন-অ্যাকাউন্টের সংযোগ চালু নেই — আবার সংযোগ করুন। কিছুই তৈরি হয়নি।' ELSE 'অনুমোদনের পরে শর্ত বদলেছে (' || c || ') — খসড়ায় ফিরিয়ে ঠিক করুন। কিছুই তৈরি হয়নি।' END, updated_at = now() WHERE id = r.id;
      CONTINUE;
    END IF;
    UPDATE ads_drafts SET launch_state = 'launching', launch_started_at = now(), launch_attempts = launch_attempts + 1, launch_error = NULL, launch_unknown = false, updated_at = now() WHERE id = r.id;
    n := n + 1;
    RETURN QUERY
      SELECT d.id, d.tenant_id, d.user_id, d.account_id, a.external_id, a.currency, a.pixel_id, a.credentials_ct, a.key_version, p.external_id, d.name, d.objective, d.country, d.age_min, d.age_max, d.gender, d.primary_text, d.headline, d.description, d.cta, d.link_url,
             d.daily_budget, d.days, d.start_date, d.remote_campaign, d.remote_adset, d.remote_creative, d.remote_ad
        FROM ads_drafts d JOIN ads_accounts a ON a.id = d.account_id AND a.tenant_id = d.tenant_id JOIN social_accounts p ON p.id = d.page_account_id AND p.tenant_id = d.tenant_id WHERE d.id = r.id;
  END LOOP;
  PERFORM set_config('app.bypass_rls', prev, true);
END $$;

-- WORKER (admin only).  Saves the id Meta returned for one step the moment it is known.  p_field: campaign | adset | creative | ad.  result: ok | not_found | bad_input
CREATE FUNCTION ads_launch_step(p_id char(26), p_tenant char(26), p_field text, p_value text) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); rc integer;
BEGIN
  IF p_field IS NULL OR p_field NOT IN ('campaign', 'adset', 'creative', 'ad') OR p_value IS NULL OR p_value !~ '^[0-9]{5,30}$' THEN RETURN 'bad_input'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE ads_drafts SET remote_campaign = CASE WHEN p_field = 'campaign' THEN p_value ELSE remote_campaign END, remote_adset = CASE WHEN p_field = 'adset' THEN p_value ELSE remote_adset END,
         remote_creative = CASE WHEN p_field = 'creative' THEN p_value ELSE remote_creative END, remote_ad = CASE WHEN p_field = 'ad' THEN p_value ELSE remote_ad END, updated_at = now()
    WHERE id = p_id AND tenant_id = p_tenant AND launch_state = 'launching'
      AND ((p_field = 'campaign' AND remote_campaign IS NULL) OR (p_field = 'adset' AND remote_adset IS NULL) OR (p_field = 'creative' AND remote_creative IS NULL) OR (p_field = 'ad' AND remote_ad IS NULL));
  GET DIAGNOSTICS rc = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN rc = 0 THEN 'not_found' ELSE 'ok' END;
END $$;

-- WORKER (admin only).  All four objects exist (still PAUSED on Meta).  result: ok | not_found
CREATE FUNCTION ads_launch_done(p_id char(26), p_tenant char(26)) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); rc integer;
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE ads_drafts SET launch_state = 'created', launched_at = now(), launch_error = NULL, launch_unknown = false, updated_at = now()
    WHERE id = p_id AND tenant_id = p_tenant AND launch_state = 'launching' AND remote_campaign IS NOT NULL AND remote_adset IS NOT NULL AND remote_creative IS NOT NULL AND remote_ad IS NOT NULL;
  GET DIAGNOSTICS rc = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN rc = 0 THEN 'not_found' ELSE 'ok' END;
END $$;

-- WORKER (admin only).  The attempt stopped.  p_unknown = true when we cannot tell whether the last Meta call went through.  result: ok | not_found
CREATE FUNCTION ads_launch_fail(p_id char(26), p_tenant char(26), p_error text, p_unknown boolean) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); rc integer;
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE ads_drafts SET launch_state = 'failed', launch_error = left(btrim(coalesce(p_error, 'অজানা ত্রুটি')), 400), launch_unknown = coalesce(p_unknown, false), updated_at = now()
    WHERE id = p_id AND tenant_id = p_tenant AND launch_state = 'launching';
  GET DIAGNOSTICS rc = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN rc = 0 THEN 'not_found' ELSE 'ok' END;
END $$;

-- A person asks for another attempt after a failure.  Already-created Meta steps are kept and skipped.  If the last attempt's outcome was unknown the person must confirm they checked Ads Manager (p_confirm).
-- result: ok | not_found | confirm
CREATE FUNCTION ads_launch_retry(p_id char(26), p_tenant char(26), p_user char(26), p_confirm boolean) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); d ads_drafts%ROWTYPE;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO d FROM ads_drafts WHERE id = p_id AND tenant_id = p_tenant AND status = 'approved' AND launch_state = 'failed' FOR UPDATE;
  IF NOT FOUND THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'not_found'; END IF;
  IF d.launch_unknown AND NOT coalesce(p_confirm, false) THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'confirm'; END IF;
  UPDATE ads_drafts SET launch_state = NULL, launch_error = NULL, launch_unknown = false, updated_at = now() WHERE id = p_id;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

DO $$ DECLARE f text; BEGIN
  FOREACH f IN ARRAY ARRAY['ads_account_set_pixel(char, char, text)', 'ads_draft_set_page(char, char, char, char)', 'ads_launch_retry(char, char, char, boolean)'] LOOP
    EXECUTE format('REVOKE ALL ON FUNCTION %s FROM PUBLIC, jarvis_app_rw, jarvis_admin', f);
    EXECUTE format('GRANT EXECUTE ON FUNCTION %s TO jarvis_app_rw, jarvis_admin', f);
  END LOOP;
  FOREACH f IN ARRAY ARRAY['ads_launch_claim(integer, integer)', 'ads_launch_step(char, char, text, text)', 'ads_launch_done(char, char)', 'ads_launch_fail(char, char, text, boolean)'] LOOP
    EXECUTE format('REVOKE ALL ON FUNCTION %s FROM PUBLIC, jarvis_app_rw, jarvis_admin', f);
    EXECUTE format('GRANT EXECUTE ON FUNCTION %s TO jarvis_admin', f);
  END LOOP;
  REVOKE ALL ON FUNCTION ads_draft_check(char, char) FROM PUBLIC, jarvis_app_rw, jarvis_admin;
END $$;
