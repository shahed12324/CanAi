-- Phase 23b: Facebook Page auto-publishing.  A connected Page (id + sealed Page access token) can be attached to a social post; once the post is approved
-- and its time arrives, the worker publishes it.  Rules that protect against double-posting and secret leaks:
--   * the Page token is sealed by the app (platform vault); the app role cannot SELECT it — only the admin-only worker function returns it;
--   * a post is claimed exactly once (publish_state 'publishing'); a claim that never reports back is NOT retried automatically (the outcome is unknown) — it is marked failed
--     and the author must look at the Page and press retry;
--   * the author cannot reopen / cancel / mark-posted a post while it is being published.

CREATE TABLE social_accounts (
  id             char(26)     PRIMARY KEY,
  tenant_id      char(26)     NOT NULL REFERENCES tenants (id),
  platform       text         NOT NULL CHECK (platform IN ('facebook')),
  name           varchar(120) NOT NULL CHECK (btrim(name) <> ''),
  external_id    varchar(64)  NOT NULL CHECK (external_id ~ '^[0-9]{5,30}$'),
  credentials_ct text         NOT NULL,
  key_version    smallint     NOT NULL,
  status         text         NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'invalid', 'disabled')),
  created_at     timestamptz  NOT NULL DEFAULT now(),
  updated_at     timestamptz  NOT NULL DEFAULT now(),
  UNIQUE (id, tenant_id),
  UNIQUE (tenant_id, platform, external_id)
);
ALTER TABLE social_accounts ENABLE ROW LEVEL SECURITY;
ALTER TABLE social_accounts FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON social_accounts USING (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin())) WITH CHECK (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()));
REVOKE ALL ON social_accounts FROM jarvis_app_rw, jarvis_admin;
GRANT SELECT (id, tenant_id, platform, name, external_id, status, created_at, updated_at) ON social_accounts TO jarvis_app_rw, jarvis_admin;

ALTER TABLE social_posts
  ADD COLUMN account_id char(26),
  ADD COLUMN publish_state text CHECK (publish_state IS NULL OR publish_state IN ('publishing', 'failed')),
  ADD COLUMN publish_error varchar(300),
  ADD COLUMN publish_started_at timestamptz,
  ADD COLUMN remote_id varchar(100),
  ADD COLUMN attempts smallint NOT NULL DEFAULT 0,
  ADD CONSTRAINT social_posts_account_fk FOREIGN KEY (account_id, tenant_id) REFERENCES social_accounts (id, tenant_id),
  ADD CONSTRAINT social_posts_publish_only_approved CHECK (publish_state IS NULL OR status = 'approved'),
  ADD CONSTRAINT social_posts_publish_needs_account CHECK (publish_state IS NULL OR account_id IS NOT NULL);
CREATE INDEX social_posts_due ON social_posts (scheduled_at) WHERE status = 'approved' AND publish_state IS NULL AND account_id IS NOT NULL;
GRANT SELECT ON social_posts TO jarvis_app_rw, jarvis_admin;

-- Connect (or re-connect with a new token) a Page.  Returns the account id.  At most 5 Pages per workspace.  raises 22023 on bad input, 'limit' as P0001.
CREATE FUNCTION social_account_save(p_id char(26), p_tenant char(26), p_platform text, p_name text, p_external text, p_ct text, p_ver smallint) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); a char(26); n integer; nm text := btrim(coalesce(p_name, ''));
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_platform <> 'facebook' OR nm = '' OR char_length(nm) > 120 OR p_external IS NULL OR p_external !~ '^[0-9]{5,30}$' OR p_ct IS NULL OR btrim(p_ct) = '' OR p_ver IS NULL THEN RAISE EXCEPTION 'bad account' USING ERRCODE = '22023'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT id INTO a FROM social_accounts WHERE tenant_id = p_tenant AND platform = p_platform AND external_id = p_external;
  IF a IS NULL THEN
    SELECT count(*) INTO n FROM social_accounts WHERE tenant_id = p_tenant;
    IF n >= 5 THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'limit'; END IF;
    INSERT INTO social_accounts (id, tenant_id, platform, name, external_id, credentials_ct, key_version) VALUES (p_id, p_tenant, p_platform, nm, p_external, p_ct, p_ver);
    a := p_id;
  ELSE
    UPDATE social_accounts SET name = nm, credentials_ct = p_ct, key_version = p_ver, status = 'active', updated_at = now() WHERE id = a;
  END IF;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN a;
END $$;

-- Disconnect a Page.  Posts that pointed at it keep their text but lose the Page.  result: ok | not_found | busy (a post is being published right now)
CREATE FUNCTION social_account_delete(p_id char(26), p_tenant char(26)) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); rc integer;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  PERFORM set_config('app.bypass_rls', 'on', true);
  IF EXISTS (SELECT 1 FROM social_posts WHERE account_id = p_id AND tenant_id = p_tenant AND publish_state = 'publishing') THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'busy'; END IF;
  UPDATE social_posts SET account_id = NULL, publish_state = NULL, publish_error = NULL, updated_at = now() WHERE account_id = p_id AND tenant_id = p_tenant;
  DELETE FROM social_accounts WHERE id = p_id AND tenant_id = p_tenant;
  GET DIAGNOSTICS rc = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN rc = 0 THEN 'not_found' ELSE 'ok' END;
END $$;

-- ADMIN ONLY.  The only way to read a Page's sealed token.
CREATE FUNCTION social_account_credentials(p_id char(26)) RETURNS TABLE (tenant_id char(26), external_id varchar, credentials_ct text, key_version smallint, status text)
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), '');
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  RETURN QUERY SELECT a.tenant_id, a.external_id, a.credentials_ct, a.key_version, a.status FROM social_accounts a WHERE a.id = p_id;
  PERFORM set_config('app.bypass_rls', prev, true);
END $$;

-- ADMIN ONLY.  The worker marks a Page whose token Facebook rejected.
CREATE FUNCTION social_account_mark(p_id char(26), p_status text) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); rc integer;
BEGIN
  IF p_status NOT IN ('active', 'invalid', 'disabled') THEN RAISE EXCEPTION 'bad status' USING ERRCODE = '22023'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE social_accounts SET status = p_status, updated_at = now() WHERE id = p_id;
  GET DIAGNOSTICS rc = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN rc = 0 THEN 'not_found' ELSE 'ok' END;
END $$;

-- Author attaches (or removes, p_account NULL) the Page for auto-publishing.  Only drafts / pending posts; the Page must be the same platform and active.
-- result: ok | not_found | locked | bad_account
CREATE FUNCTION social_post_set_account(p_id char(26), p_tenant char(26), p_user char(26), p_account char(26)) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); p social_posts%ROWTYPE; acc social_accounts%ROWTYPE;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO p FROM social_posts WHERE id = p_id AND tenant_id = p_tenant AND user_id = p_user FOR UPDATE;
  IF NOT FOUND THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'not_found'; END IF;
  IF p.status NOT IN ('draft', 'pending') THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'locked'; END IF;
  IF p_account IS NOT NULL THEN
    SELECT * INTO acc FROM social_accounts WHERE id = p_account AND tenant_id = p_tenant AND platform = p.platform AND status = 'active';
    IF NOT FOUND THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'bad_account'; END IF;
  END IF;
  UPDATE social_posts SET account_id = p_account, updated_at = now() WHERE id = p_id;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

-- Author retries a failed publish (after checking the Page).  result: ok | not_found
CREATE FUNCTION social_post_retry(p_id char(26), p_tenant char(26), p_user char(26)) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); rc integer;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE social_posts SET publish_state = NULL, publish_error = NULL, updated_at = now() WHERE id = p_id AND tenant_id = p_tenant AND user_id = p_user AND status = 'approved' AND publish_state = 'failed';
  GET DIAGNOSTICS rc = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN rc = 0 THEN 'not_found' ELSE 'ok' END;
END $$;

-- 23a functions, redefined: nothing may change while a post is being published; leaving 'approved' clears the publish fields.
CREATE OR REPLACE FUNCTION social_post_reopen(p_id char(26), p_tenant char(26), p_user char(26)) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); rc integer;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE social_posts SET status = 'draft', approved_by = NULL, approved_at = NULL, publish_state = NULL, publish_error = NULL, updated_at = now()
    WHERE id = p_id AND tenant_id = p_tenant AND user_id = p_user AND status IN ('pending', 'approved') AND publish_state IS DISTINCT FROM 'publishing';
  GET DIAGNOSTICS rc = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN rc = 0 THEN 'not_found' ELSE 'ok' END;
END $$;

CREATE OR REPLACE FUNCTION social_post_mark_posted(p_id char(26), p_tenant char(26), p_user char(26), p_url text) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); rc integer; u text := nullif(btrim(coalesce(p_url, '')), '');
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  IF u IS NOT NULL AND (char_length(u) > 300 OR u !~ '^https://[^[:space:]]+$') THEN RETURN 'bad_url'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE social_posts SET status = 'posted', posted_at = now(), post_url = u, publish_state = NULL, publish_error = NULL, updated_at = now()
    WHERE id = p_id AND tenant_id = p_tenant AND user_id = p_user AND status = 'approved' AND publish_state IS DISTINCT FROM 'publishing';
  GET DIAGNOSTICS rc = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN rc = 0 THEN 'not_found' ELSE 'ok' END;
END $$;

CREATE OR REPLACE FUNCTION social_post_cancel(p_id char(26), p_tenant char(26), p_user char(26)) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); rc integer;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE social_posts SET status = 'cancelled', publish_state = NULL, publish_error = NULL, updated_at = now()
    WHERE id = p_id AND tenant_id = p_tenant AND user_id = p_user AND status IN ('draft', 'pending', 'approved') AND publish_state IS DISTINCT FROM 'publishing';
  GET DIAGNOSTICS rc = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN rc = 0 THEN 'not_found' ELSE 'ok' END;
END $$;

-- WORKER (admin only).  Takes due, approved posts that have an active Page and marks them 'publishing' (one claim per post, ever, until the author retries).
-- Also: a claim older than 15 minutes becomes 'failed' (outcome unknown — never auto-retried); a post whose Page is not active, or whose time is more than 24 hours past, is failed with a reason.
-- Skips workspaces under the emergency stop.
CREATE FUNCTION social_publish_claim(p_limit integer) RETURNS TABLE (post_id char(26), tenant_id char(26), user_id char(26), account_id char(26), caption text, media_kind text, media_id char(26), media_size text)
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), '');
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE social_posts SET publish_state = 'failed', publish_error = 'ফলাফল অজানা — Facebook পেজে দেখে নিন; না গিয়ে থাকলে "আবার চেষ্টা" দিন (নইলে দুবার যেতে পারে)।'
    WHERE publish_state = 'publishing' AND publish_started_at < now() - interval '15 minutes';
  UPDATE social_posts p SET publish_state = 'failed', publish_error = 'পেজের সংযোগ চালু নেই — নতুন টোকেন দিয়ে আবার সংযোগ করুন, তারপর "আবার চেষ্টা" দিন।'
    WHERE p.status = 'approved' AND p.publish_state IS NULL AND p.account_id IS NOT NULL AND p.scheduled_at <= now()
      AND NOT EXISTS (SELECT 1 FROM social_accounts a WHERE a.id = p.account_id AND a.tenant_id = p.tenant_id AND a.status = 'active');
  UPDATE social_posts p SET publish_state = 'failed', publish_error = 'সময় ২৪ ঘণ্টার বেশি আগে পেরিয়ে গেছে — সময় বদলে আবার অনুমোদন নিন।'
    WHERE p.status = 'approved' AND p.publish_state IS NULL AND p.account_id IS NOT NULL AND p.scheduled_at < now() - interval '24 hours';
  RETURN QUERY
  WITH picked AS (
    SELECT p.id FROM social_posts p
     WHERE p.status = 'approved' AND p.publish_state IS NULL AND p.account_id IS NOT NULL AND p.scheduled_at <= now() AND NOT emergency_active(p.tenant_id)
     ORDER BY p.scheduled_at LIMIT greatest(1, least(coalesce(p_limit, 1), 10)) FOR UPDATE SKIP LOCKED)
  UPDATE social_posts s SET publish_state = 'publishing', publish_started_at = now(), attempts = s.attempts + 1, publish_error = NULL
    FROM picked WHERE s.id = picked.id
    RETURNING s.id, s.tenant_id, s.user_id, s.account_id, s.caption, s.media_kind, s.media_id, s.media_size;
  PERFORM set_config('app.bypass_rls', prev, true);
END $$;

-- WORKER (admin only).  Only a post being published can be finished.  A non-https link is dropped, not trusted.  result: ok | not_found
CREATE FUNCTION social_publish_done(p_id char(26), p_tenant char(26), p_remote text, p_url text) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); rc integer; u text := nullif(btrim(coalesce(p_url, '')), '');
BEGIN
  IF u IS NOT NULL AND (char_length(u) > 300 OR u !~ '^https://[^[:space:]]+$') THEN u := NULL; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE social_posts SET status = 'posted', posted_at = now(), post_url = u, remote_id = left(nullif(btrim(coalesce(p_remote, '')), ''), 100), publish_state = NULL, publish_error = NULL, updated_at = now()
    WHERE id = p_id AND tenant_id = p_tenant AND status = 'approved' AND publish_state = 'publishing';
  GET DIAGNOSTICS rc = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN rc = 0 THEN 'not_found' ELSE 'ok' END;
END $$;

-- WORKER (admin only).  Only a post being published can fail.  result: ok | not_found
CREATE FUNCTION social_publish_fail(p_id char(26), p_tenant char(26), p_error text) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); rc integer;
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE social_posts SET publish_state = 'failed', publish_error = left(coalesce(nullif(btrim(p_error), ''), 'ব্যর্থ'), 300), updated_at = now()
    WHERE id = p_id AND tenant_id = p_tenant AND status = 'approved' AND publish_state = 'publishing';
  GET DIAGNOSTICS rc = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN rc = 0 THEN 'not_found' ELSE 'ok' END;
END $$;

DO $$ DECLARE f text; BEGIN
  FOREACH f IN ARRAY ARRAY['social_account_save(char, char, text, text, text, text, smallint)', 'social_account_delete(char, char)', 'social_post_set_account(char, char, char, char)', 'social_post_retry(char, char, char)'] LOOP
    EXECUTE format('REVOKE ALL ON FUNCTION %s FROM PUBLIC, jarvis_app_rw, jarvis_admin', f);
    EXECUTE format('GRANT EXECUTE ON FUNCTION %s TO jarvis_app_rw, jarvis_admin', f);
  END LOOP;
  FOREACH f IN ARRAY ARRAY['social_account_credentials(char)', 'social_account_mark(char, text)', 'social_publish_claim(integer)', 'social_publish_done(char, char, text, text)', 'social_publish_fail(char, char, text)'] LOOP
    EXECUTE format('REVOKE ALL ON FUNCTION %s FROM PUBLIC, jarvis_app_rw, jarvis_admin', f);
    EXECUTE format('GRANT EXECUTE ON FUNCTION %s TO jarvis_admin', f);
  END LOOP;
END $$;
