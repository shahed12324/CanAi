-- Phase 23.2c: comment replies.  The system fetches the top-level comments on posts it published to a Facebook Page, an AI (or a person) DRAFTS a reply,
-- and a reply is sent ONLY after a person with approve rights approves that exact text.  Nothing is ever replied automatically.
--   new → drafted → approved → replying → replied | failed;  ignored (hidden from the to-do list).  A reply whose outcome is unknown is failed, never auto-retried.

ALTER TABLE social_posts ADD COLUMN comments_checked_at timestamptz;
GRANT SELECT ON social_posts TO jarvis_app_rw, jarvis_admin;

CREATE TABLE social_comments (
  id               char(26)     PRIMARY KEY,
  tenant_id        char(26)     NOT NULL REFERENCES tenants (id),
  post_id          char(26)     NOT NULL,
  account_id       char(26)     NOT NULL,
  remote_id        varchar(100) NOT NULL CHECK (remote_id ~ '^[0-9_]{3,100}$'),
  author_name      varchar(200) NOT NULL DEFAULT '',
  message          varchar(2000) NOT NULL CHECK (btrim(message) <> ''),
  commented_at     timestamptz  NOT NULL,
  status           text         NOT NULL DEFAULT 'new' CHECK (status IN ('new', 'drafted', 'approved', 'replying', 'replied', 'failed', 'ignored')),
  reply_text       varchar(1000),
  drafted_by       char(26),
  approved_by      char(26),
  approved_at      timestamptz,
  reply_started_at timestamptz,
  reply_remote_id  varchar(100),
  reply_error      varchar(300),
  replied_at       timestamptz,
  created_at       timestamptz  NOT NULL DEFAULT now(),
  updated_at       timestamptz  NOT NULL DEFAULT now(),
  UNIQUE (tenant_id, remote_id),
  UNIQUE (id, tenant_id),
  FOREIGN KEY (post_id, tenant_id) REFERENCES social_posts (id, tenant_id) ON DELETE CASCADE,
  CHECK (status NOT IN ('drafted', 'approved', 'replying', 'replied') OR (reply_text IS NOT NULL AND btrim(reply_text) <> '')),
  CHECK (status NOT IN ('approved', 'replying', 'replied') OR approved_at IS NOT NULL),
  CHECK ((status = 'replied') = (replied_at IS NOT NULL))
);
CREATE INDEX social_comments_inbox ON social_comments (tenant_id, status, commented_at DESC);
CREATE INDEX social_comments_post ON social_comments (post_id);
ALTER TABLE social_comments ENABLE ROW LEVEL SECURITY;
ALTER TABLE social_comments FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON social_comments USING (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin())) WITH CHECK (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()));
REVOKE ALL ON social_comments FROM jarvis_app_rw, jarvis_admin;
GRANT SELECT ON social_comments TO jarvis_app_rw, jarvis_admin;

-- A person writes / replaces the draft reply.  Only a new or already-drafted comment.  result: ok | not_found | locked | bad_input
CREATE FUNCTION social_comment_draft(p_id char(26), p_tenant char(26), p_user char(26), p_text text) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); t text := btrim(coalesce(p_text, '')); st text;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  IF t = '' OR char_length(t) > 1000 THEN RETURN 'bad_input'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT status INTO st FROM social_comments WHERE id = p_id AND tenant_id = p_tenant;
  IF st IS NULL THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'not_found'; END IF;
  IF st NOT IN ('new', 'drafted') THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'locked'; END IF;
  UPDATE social_comments SET status = 'drafted', reply_text = t, drafted_by = p_user, updated_at = now() WHERE id = p_id AND tenant_id = p_tenant;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

-- Approves the drafted text exactly as stored (the text is compared, so an edit between viewing and approving cannot slip through).  result: ok | not_found
CREATE FUNCTION social_comment_approve(p_id char(26), p_tenant char(26), p_user char(26), p_text text) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); rc integer;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE social_comments SET status = 'approved', approved_by = p_user, approved_at = now(), reply_error = NULL, updated_at = now()
    WHERE id = p_id AND tenant_id = p_tenant AND status = 'drafted' AND reply_text = btrim(coalesce(p_text, ''));
  GET DIAGNOSTICS rc = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN rc = 0 THEN 'not_found' ELSE 'ok' END;
END $$;

-- Takes an approved (not yet sent) reply back to a draft.  result: ok | not_found
CREATE FUNCTION social_comment_withdraw(p_id char(26), p_tenant char(26), p_user char(26)) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); rc integer;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE social_comments SET status = 'drafted', approved_by = NULL, approved_at = NULL, updated_at = now() WHERE id = p_id AND tenant_id = p_tenant AND status = 'approved';
  GET DIAGNOSTICS rc = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN rc = 0 THEN 'not_found' ELSE 'ok' END;
END $$;

-- Removes a comment from the to-do list (nothing is sent).  Not while a reply is going out or after it went.  result: ok | not_found
CREATE FUNCTION social_comment_ignore(p_id char(26), p_tenant char(26), p_user char(26)) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); rc integer;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE social_comments SET status = 'ignored', approved_by = NULL, approved_at = NULL, updated_at = now() WHERE id = p_id AND tenant_id = p_tenant AND status IN ('new', 'drafted', 'approved', 'failed');
  GET DIAGNOSTICS rc = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN rc = 0 THEN 'not_found' ELSE 'ok' END;
END $$;

-- After checking the Page, a person re-queues a failed reply (it is approved again as it stands).  result: ok | not_found
CREATE FUNCTION social_comment_retry(p_id char(26), p_tenant char(26), p_user char(26)) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); rc integer;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE social_comments SET status = 'approved', approved_by = p_user, approved_at = now(), reply_error = NULL, updated_at = now() WHERE id = p_id AND tenant_id = p_tenant AND status = 'failed';
  GET DIAGNOSTICS rc = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN rc = 0 THEN 'not_found' ELSE 'ok' END;
END $$;

-- WORKER (admin only).  Posts the system published whose comments are due for a look: every 10 minutes for 2 days, every 2 hours until 14 days, daily until 30 days.
-- The look time is stamped here, so a failing look is not repeated at once.  Only posts whose Page is active, and not for a workspace under the emergency stop.
CREATE FUNCTION social_comments_due(p_limit integer) RETURNS TABLE (post_id char(26), tenant_id char(26), account_id char(26), remote_id varchar)
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), '');
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  RETURN QUERY
  WITH picked AS (
    SELECT p.id FROM social_posts p
      JOIN social_accounts a ON a.id = p.account_id AND a.tenant_id = p.tenant_id AND a.status = 'active'
     WHERE p.status = 'posted' AND p.remote_id IS NOT NULL AND p.posted_at < now() - interval '2 minutes' AND p.posted_at > now() - interval '30 days' AND NOT emergency_active(p.tenant_id)
       AND (p.comments_checked_at IS NULL OR p.comments_checked_at < now() - CASE WHEN p.posted_at > now() - interval '2 days' THEN interval '10 minutes' WHEN p.posted_at > now() - interval '14 days' THEN interval '2 hours' ELSE interval '24 hours' END)
     ORDER BY p.comments_checked_at NULLS FIRST, p.posted_at DESC
     LIMIT greatest(1, least(coalesce(p_limit, 1), 20)) FOR UPDATE OF p SKIP LOCKED)
  UPDATE social_posts s SET comments_checked_at = now() FROM picked WHERE s.id = picked.id
    RETURNING s.id, s.tenant_id, s.account_id, s.remote_id;
  PERFORM set_config('app.bypass_rls', prev, true);
END $$;

-- WORKER (admin only).  Stores one fetched comment (once per Facebook comment id).  At most 300 per post; text is trimmed.  result: new | exists | not_found | bad_input | full
CREATE FUNCTION social_comment_ingest(p_id char(26), p_tenant char(26), p_post char(26), p_remote text, p_author text, p_message text, p_at timestamptz) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); m text := btrim(coalesce(p_message, '')); acc char(26);
BEGIN
  IF m = '' OR p_remote IS NULL OR p_remote !~ '^[0-9_]{3,100}$' OR p_at IS NULL OR p_id IS NULL THEN RETURN 'bad_input'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT account_id INTO acc FROM social_posts WHERE id = p_post AND tenant_id = p_tenant AND status = 'posted';
  IF acc IS NULL THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'not_found'; END IF;
  IF EXISTS (SELECT 1 FROM social_comments WHERE tenant_id = p_tenant AND remote_id = p_remote) THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'exists'; END IF;
  IF (SELECT count(*) FROM social_comments WHERE post_id = p_post) >= 300 THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'full'; END IF;
  INSERT INTO social_comments (id, tenant_id, post_id, account_id, remote_id, author_name, message, commented_at)
    VALUES (p_id, p_tenant, p_post, acc, p_remote, left(btrim(coalesce(p_author, '')), 200), left(m, 2000), p_at);
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'new';
END $$;

-- WORKER (admin only).  Takes approved replies and marks them 'replying' (one claim per reply until a person retries).
-- A claim older than 15 minutes becomes 'failed' (outcome unknown — never auto-retried); a reply whose Page is not active, or approved more than 3 days ago, is failed with a reason.  Skips workspaces under the emergency stop.
CREATE FUNCTION social_comment_reply_claim(p_limit integer) RETURNS TABLE (comment_id char(26), tenant_id char(26), account_id char(26), remote_id varchar, reply_text varchar)
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), '');
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE social_comments SET status = 'failed', reply_error = 'ফলাফল অজানা — Facebook পেজে কমেন্টটি দেখে নিন; উত্তর না গিয়ে থাকলে "আবার চেষ্টা" দিন (নইলে দুবার যেতে পারে)।', updated_at = now()
    WHERE status = 'replying' AND reply_started_at < now() - interval '15 minutes';
  UPDATE social_comments c SET status = 'failed', reply_error = 'পেজের সংযোগ চালু নেই — নতুন টোকেন দিয়ে আবার সংযোগ করুন, তারপর "আবার চেষ্টা" দিন।', updated_at = now()
    WHERE c.status = 'approved' AND NOT EXISTS (SELECT 1 FROM social_accounts a WHERE a.id = c.account_id AND a.tenant_id = c.tenant_id AND a.status = 'active');
  UPDATE social_comments c SET status = 'failed', reply_error = 'অনুমোদনের পর ৩ দিন পেরিয়ে গেছে — দেখে আবার অনুমোদন দিন।', updated_at = now()
    WHERE c.status = 'approved' AND c.approved_at < now() - interval '3 days';
  RETURN QUERY
  WITH picked AS (
    SELECT c.id FROM social_comments c WHERE c.status = 'approved' AND NOT emergency_active(c.tenant_id)
     ORDER BY c.approved_at LIMIT greatest(1, least(coalesce(p_limit, 1), 10)) FOR UPDATE SKIP LOCKED)
  UPDATE social_comments s SET status = 'replying', reply_started_at = now(), reply_error = NULL, updated_at = now()
    FROM picked WHERE s.id = picked.id
    RETURNING s.id, s.tenant_id, s.account_id, s.remote_id, s.reply_text;
  PERFORM set_config('app.bypass_rls', prev, true);
END $$;

-- WORKER (admin only).  Only a reply being sent can be finished.  result: ok | not_found
CREATE FUNCTION social_comment_reply_done(p_id char(26), p_tenant char(26), p_remote text) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); rc integer;
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE social_comments SET status = 'replied', replied_at = now(), reply_remote_id = left(nullif(btrim(coalesce(p_remote, '')), ''), 100), reply_error = NULL, updated_at = now()
    WHERE id = p_id AND tenant_id = p_tenant AND status = 'replying';
  GET DIAGNOSTICS rc = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN rc = 0 THEN 'not_found' ELSE 'ok' END;
END $$;

-- WORKER (admin only).  Only a reply being sent can fail.  result: ok | not_found
CREATE FUNCTION social_comment_reply_fail(p_id char(26), p_tenant char(26), p_error text) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); rc integer;
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE social_comments SET status = 'failed', reply_error = left(coalesce(nullif(btrim(p_error), ''), 'ব্যর্থ'), 300), updated_at = now()
    WHERE id = p_id AND tenant_id = p_tenant AND status = 'replying';
  GET DIAGNOSTICS rc = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN rc = 0 THEN 'not_found' ELSE 'ok' END;
END $$;

DO $$ DECLARE f text; BEGIN
  FOREACH f IN ARRAY ARRAY['social_comment_draft(char, char, char, text)', 'social_comment_approve(char, char, char, text)', 'social_comment_withdraw(char, char, char)', 'social_comment_ignore(char, char, char)', 'social_comment_retry(char, char, char)'] LOOP
    EXECUTE format('REVOKE ALL ON FUNCTION %s FROM PUBLIC, jarvis_app_rw, jarvis_admin', f);
    EXECUTE format('GRANT EXECUTE ON FUNCTION %s TO jarvis_app_rw, jarvis_admin', f);
  END LOOP;
  FOREACH f IN ARRAY ARRAY['social_comments_due(integer)', 'social_comment_ingest(char, char, char, text, text, text, timestamptz)', 'social_comment_reply_claim(integer)', 'social_comment_reply_done(char, char, text)', 'social_comment_reply_fail(char, char, text)'] LOOP
    EXECUTE format('REVOKE ALL ON FUNCTION %s FROM PUBLIC, jarvis_app_rw, jarvis_admin', f);
    EXECUTE format('GRANT EXECUTE ON FUNCTION %s TO jarvis_admin', f);
  END LOOP;
END $$;
