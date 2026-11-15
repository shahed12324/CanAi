-- Phase 24.2c: an APPROVED Google Ads draft is created on Google — always with the campaign PAUSED, so nothing serves and no money is spent until a person presses Enable in Google Ads.
-- Same safety rules as Meta (24.1c):
--   * a draft is claimed exactly once per attempt (launch_state 'launching'); every Google id is saved the moment Google returns it, so a retry RESUMES and never re-creates a finished step;
--   * an attempt whose outcome is unknown (timeout, claim that never reported back) is 'failed' with launch_unknown — never retried by itself; the person must check Google Ads and confirm;
--   * caps, completeness and the account state are re-checked at claim time; emergency stop pauses launching; at most p_hourly_cap attempts per workspace per hour.
-- Steps: budget → campaign → ad group → responsive search ad → keywords → targeting (location, language, negative keywords).

ALTER TABLE gads_drafts
  ADD COLUMN launch_state      text CHECK (launch_state IS NULL OR launch_state IN ('launching', 'created', 'failed')),
  ADD COLUMN launch_error      varchar(400),
  ADD COLUMN launch_unknown    boolean     NOT NULL DEFAULT false,
  ADD COLUMN launch_started_at timestamptz,
  ADD COLUMN launch_attempts   smallint    NOT NULL DEFAULT 0,
  ADD COLUMN launched_at       timestamptz,
  ADD COLUMN remote_budget     varchar(30) CHECK (remote_budget IS NULL OR remote_budget ~ '^[0-9]{5,30}$'),
  ADD COLUMN remote_campaign   varchar(30) CHECK (remote_campaign IS NULL OR remote_campaign ~ '^[0-9]{5,30}$'),
  ADD COLUMN remote_adgroup    varchar(30) CHECK (remote_adgroup IS NULL OR remote_adgroup ~ '^[0-9]{5,30}$'),
  ADD COLUMN remote_ad         varchar(30) CHECK (remote_ad IS NULL OR remote_ad ~ '^[0-9]{5,30}$'),
  ADD COLUMN keywords_done     boolean     NOT NULL DEFAULT false,
  ADD COLUMN targeting_done    boolean     NOT NULL DEFAULT false,
  ADD CONSTRAINT gads_drafts_launch_only_approved CHECK (launch_state IS NULL OR status = 'approved'),
  ADD CONSTRAINT gads_drafts_created_complete CHECK (launch_state IS DISTINCT FROM 'created' OR (remote_budget IS NOT NULL AND remote_campaign IS NOT NULL AND remote_adgroup IS NOT NULL AND remote_ad IS NOT NULL AND keywords_done AND targeting_done AND launched_at IS NOT NULL));
CREATE INDEX gads_drafts_launch ON gads_drafts (approved_at) WHERE status = 'approved' AND launch_state IS NULL;

-- The countries we can target correctly (Google geo-target ids live in the app).  Part of the completeness check.
CREATE FUNCTION gads_country_ok(p text) RETURNS boolean LANGUAGE sql IMMUTABLE AS $$ SELECT p IN ('BD', 'IN', 'PK', 'US', 'GB', 'AE', 'SA', 'MY', 'ID', 'SG', 'CA', 'AU') $$;

-- 24.2b check, now also: only a country we can target.  ok | incomplete | unsupported | no_cap | over_cap
CREATE OR REPLACE FUNCTION gads_draft_check(p_id char(26), p_tenant char(26)) RETURNS text
LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE d gads_drafts%ROWTYPE; s gads_settings%ROWTYPE;
BEGIN
  SELECT * INTO d FROM gads_drafts WHERE id = p_id AND tenant_id = p_tenant;
  IF NOT FOUND THEN RETURN 'incomplete'; END IF;
  IF d.final_url IS NULL OR d.start_date IS NULL OR jsonb_array_length(d.headlines) < 3 OR jsonb_array_length(d.descriptions) < 2 OR jsonb_array_length(d.keywords) < 1 THEN RETURN 'incomplete'; END IF;
  IF NOT gads_country_ok(d.country) THEN RETURN 'unsupported'; END IF;
  SELECT * INTO s FROM gads_settings WHERE tenant_id = p_tenant;
  IF NOT FOUND THEN RETURN 'no_cap'; END IF;
  IF d.daily_budget > s.max_daily OR d.daily_budget * d.days > s.max_total THEN RETURN 'over_cap'; END IF;
  RETURN 'ok';
END $$;

-- Taking a draft back to edit it is refused once Google objects exist (or may exist).  result: ok | not_found | launched
CREATE OR REPLACE FUNCTION gads_draft_reopen(p_id char(26), p_tenant char(26), p_user char(26)) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); d gads_drafts%ROWTYPE;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO d FROM gads_drafts WHERE id = p_id AND tenant_id = p_tenant AND user_id = p_user AND status IN ('pending', 'approved') FOR UPDATE;
  IF NOT FOUND THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'not_found'; END IF;
  IF d.launch_state IS NOT NULL AND NOT (d.launch_state = 'failed' AND d.remote_budget IS NULL AND d.remote_campaign IS NULL AND NOT d.launch_unknown) THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'launched'; END IF;
  UPDATE gads_drafts SET status = 'draft', approved_by = NULL, approved_at = NULL, launch_state = NULL, launch_error = NULL, launch_attempts = 0, updated_at = now() WHERE id = p_id;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

-- 24.2b function redefined: disconnecting is refused while a draft waits for approval or is approved and not yet created.
CREATE OR REPLACE FUNCTION gads_account_delete(p_id char(26), p_tenant char(26)) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); rc integer;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  PERFORM set_config('app.bypass_rls', 'on', true);
  IF EXISTS (SELECT 1 FROM gads_drafts WHERE account_id = p_id AND tenant_id = p_tenant AND (status = 'pending' OR (status = 'approved' AND launch_state IS DISTINCT FROM 'created'))) THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'busy'; END IF;
  DELETE FROM gads_accounts WHERE id = p_id AND tenant_id = p_tenant;
  GET DIAGNOSTICS rc = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN rc = 0 THEN 'not_found' ELSE 'ok' END;
END $$;

-- WORKER (admin only).  Takes approved, not-yet-launched drafts and marks them 'launching' (once per attempt).  At most p_hourly_cap attempts per workspace per hour.
-- A draft whose caps / completeness no longer check out, or whose Google account is not active, is failed with a reason instead (nothing is created).  A claim older than 15 minutes is failed as unknown.
CREATE FUNCTION gads_launch_claim(p_limit integer, p_hourly_cap integer) RETURNS TABLE (draft_id char(26), tenant_id char(26), account_id char(26), customer_id char(10), login_customer_id char(10), currency char(3), credentials_ct text, key_version smallint,
  name varchar, country char(2), language text, final_url varchar, headlines jsonb, descriptions jsonb, keywords jsonb, negatives jsonb, daily_budget numeric, days smallint, start_date date,
  remote_budget varchar, remote_campaign varchar, remote_adgroup varchar, remote_ad varchar, keywords_done boolean, targeting_done boolean)
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); r record; n integer := 0; lim integer := greatest(1, least(coalesce(p_limit, 1), 5)); cap integer := greatest(0, coalesce(p_hourly_cap, 0)); used integer; c text; acct_ok boolean;
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE gads_drafts SET launch_state = 'failed', launch_unknown = true, launch_error = 'ফলাফল অজানা — Google Ads-এ দেখুন কিছু তৈরি হয়েছে কিনা; তারপর "আবার চেষ্টা" দিন।', updated_at = now()
    WHERE launch_state = 'launching' AND launch_started_at < now() - interval '15 minutes';
  FOR r IN
    SELECT d.id, d.tenant_id AS tid FROM gads_drafts d
     WHERE d.status = 'approved' AND d.launch_state IS NULL AND NOT emergency_active(d.tenant_id)
     ORDER BY d.approved_at FOR UPDATE OF d SKIP LOCKED
  LOOP
    EXIT WHEN n >= lim;
    SELECT count(*) INTO used FROM gads_drafts q WHERE q.tenant_id = r.tid AND q.launch_started_at > now() - interval '1 hour';
    CONTINUE WHEN used >= cap;
    c := gads_draft_check(r.id, r.tid);
    SELECT (a.status = 'active') INTO acct_ok FROM gads_drafts d2 JOIN gads_accounts a ON a.id = d2.account_id AND a.tenant_id = d2.tenant_id WHERE d2.id = r.id;
    IF c <> 'ok' OR acct_ok IS NOT TRUE THEN
      UPDATE gads_drafts SET launch_state = 'failed', launch_unknown = false, launch_attempts = launch_attempts + 1, launch_started_at = now(),
        launch_error = CASE WHEN acct_ok IS NOT TRUE THEN 'Google Ads অ্যাকাউন্টের সংযোগ চালু নেই — আবার সংযোগ করুন। কিছুই তৈরি হয়নি।' ELSE 'অনুমোদনের পরে শর্ত বদলেছে (' || c || ') — খসড়ায় ফিরিয়ে ঠিক করুন। কিছুই তৈরি হয়নি।' END, updated_at = now() WHERE id = r.id;
      CONTINUE;
    END IF;
    UPDATE gads_drafts SET launch_state = 'launching', launch_started_at = now(), launch_attempts = launch_attempts + 1, launch_error = NULL, launch_unknown = false, updated_at = now() WHERE id = r.id;
    n := n + 1;
    RETURN QUERY
      SELECT d.id, d.tenant_id, d.account_id, a.customer_id, a.login_customer_id, a.currency, a.credentials_ct, a.key_version, d.name, d.country, d.language, d.final_url, d.headlines, d.descriptions, d.keywords, d.negatives, d.daily_budget, d.days, d.start_date,
             d.remote_budget, d.remote_campaign, d.remote_adgroup, d.remote_ad, d.keywords_done, d.targeting_done
        FROM gads_drafts d JOIN gads_accounts a ON a.id = d.account_id AND a.tenant_id = d.tenant_id WHERE d.id = r.id;
  END LOOP;
  PERFORM set_config('app.bypass_rls', prev, true);
END $$;

-- WORKER (admin only).  Saves one finished step the moment it is known.  p_field: budget | campaign | adgroup | ad (p_value = Google id) or keywords | targeting (p_value = 'done').  result: ok | not_found | bad_input
CREATE FUNCTION gads_launch_step(p_id char(26), p_tenant char(26), p_field text, p_value text) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); rc integer;
BEGIN
  IF p_field IS NULL OR p_value IS NULL OR NOT ((p_field IN ('budget', 'campaign', 'adgroup', 'ad') AND p_value ~ '^[0-9]{5,30}$') OR (p_field IN ('keywords', 'targeting') AND p_value = 'done')) THEN RETURN 'bad_input'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE gads_drafts SET remote_budget = CASE WHEN p_field = 'budget' THEN p_value ELSE remote_budget END, remote_campaign = CASE WHEN p_field = 'campaign' THEN p_value ELSE remote_campaign END,
         remote_adgroup = CASE WHEN p_field = 'adgroup' THEN p_value ELSE remote_adgroup END, remote_ad = CASE WHEN p_field = 'ad' THEN p_value ELSE remote_ad END,
         keywords_done = keywords_done OR p_field = 'keywords', targeting_done = targeting_done OR p_field = 'targeting', updated_at = now()
    WHERE id = p_id AND tenant_id = p_tenant AND launch_state = 'launching'
      AND ((p_field = 'budget' AND remote_budget IS NULL) OR (p_field = 'campaign' AND remote_campaign IS NULL) OR (p_field = 'adgroup' AND remote_adgroup IS NULL) OR (p_field = 'ad' AND remote_ad IS NULL)
           OR (p_field = 'keywords' AND NOT keywords_done) OR (p_field = 'targeting' AND NOT targeting_done));
  GET DIAGNOSTICS rc = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN rc = 0 THEN 'not_found' ELSE 'ok' END;
END $$;

-- WORKER (admin only).  Everything exists on Google (campaign still PAUSED).  result: ok | not_found
CREATE FUNCTION gads_launch_done(p_id char(26), p_tenant char(26)) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); rc integer;
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE gads_drafts SET launch_state = 'created', launched_at = now(), launch_error = NULL, launch_unknown = false, updated_at = now()
    WHERE id = p_id AND tenant_id = p_tenant AND launch_state = 'launching' AND remote_budget IS NOT NULL AND remote_campaign IS NOT NULL AND remote_adgroup IS NOT NULL AND remote_ad IS NOT NULL AND keywords_done AND targeting_done;
  GET DIAGNOSTICS rc = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN rc = 0 THEN 'not_found' ELSE 'ok' END;
END $$;

-- WORKER (admin only).  The attempt stopped.  p_unknown = true when we cannot tell whether the last Google call went through.  result: ok | not_found
CREATE FUNCTION gads_launch_fail(p_id char(26), p_tenant char(26), p_error text, p_unknown boolean) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); rc integer;
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE gads_drafts SET launch_state = 'failed', launch_error = left(btrim(coalesce(p_error, 'অজানা ত্রুটি')), 400), launch_unknown = coalesce(p_unknown, false), updated_at = now()
    WHERE id = p_id AND tenant_id = p_tenant AND launch_state = 'launching';
  GET DIAGNOSTICS rc = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN rc = 0 THEN 'not_found' ELSE 'ok' END;
END $$;

-- A person asks for another attempt after a failure.  Finished Google steps are kept and skipped.  If the last attempt's outcome was unknown the person must confirm they checked Google Ads (p_confirm).
-- result: ok | not_found | confirm
CREATE FUNCTION gads_launch_retry(p_id char(26), p_tenant char(26), p_user char(26), p_confirm boolean) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); d gads_drafts%ROWTYPE;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO d FROM gads_drafts WHERE id = p_id AND tenant_id = p_tenant AND status = 'approved' AND launch_state = 'failed' FOR UPDATE;
  IF NOT FOUND THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'not_found'; END IF;
  IF d.launch_unknown AND NOT coalesce(p_confirm, false) THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'confirm'; END IF;
  UPDATE gads_drafts SET launch_state = NULL, launch_error = NULL, launch_unknown = false, updated_at = now() WHERE id = p_id;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

DO $$ DECLARE f text; BEGIN
  EXECUTE 'REVOKE ALL ON FUNCTION gads_launch_retry(char, char, char, boolean) FROM PUBLIC, jarvis_app_rw, jarvis_admin';
  EXECUTE 'GRANT EXECUTE ON FUNCTION gads_launch_retry(char, char, char, boolean) TO jarvis_app_rw, jarvis_admin';
  FOREACH f IN ARRAY ARRAY['gads_launch_claim(integer, integer)', 'gads_launch_step(char, char, text, text)', 'gads_launch_done(char, char)', 'gads_launch_fail(char, char, text, boolean)'] LOOP
    EXECUTE format('REVOKE ALL ON FUNCTION %s FROM PUBLIC, jarvis_app_rw, jarvis_admin', f);
    EXECUTE format('GRANT EXECUTE ON FUNCTION %s TO jarvis_admin', f);
  END LOOP;
  REVOKE ALL ON FUNCTION gads_draft_check(char, char) FROM PUBLIC, jarvis_app_rw, jarvis_admin;
END $$;
