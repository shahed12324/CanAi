-- Phase 23.2d: YouTube channel upload.  A connected channel (Google OAuth, sealed REFRESH token) can be attached to a social post of platform 'youtube' whose media is a finished video.
-- Same safety rules as Facebook: the post is claimed exactly once, an upload whose outcome is unknown is failed (never auto-retried), nothing is uploaded without approval.
-- YouTube-specific: the video is private unless the author picks otherwise; uploads are capped per day (YouTube's API quota is shared by every workspace of this installation).

ALTER TABLE social_accounts DROP CONSTRAINT social_accounts_platform_check;
ALTER TABLE social_accounts DROP CONSTRAINT social_accounts_external_id_check;
ALTER TABLE social_accounts ADD CONSTRAINT social_accounts_platform_check CHECK (platform IN ('facebook', 'youtube'));
ALTER TABLE social_accounts ADD CONSTRAINT social_accounts_external_id_check CHECK ((platform = 'facebook' AND external_id ~ '^[0-9]{5,30}$') OR (platform = 'youtube' AND external_id ~ '^UC[0-9A-Za-z_-]{22}$'));

ALTER TABLE social_posts
  ADD COLUMN yt_tags varchar(500),
  ADD COLUMN yt_privacy text NOT NULL DEFAULT 'private' CHECK (yt_privacy IN ('private', 'unlisted', 'public')),
  ADD COLUMN thumb_design_id char(26),
  ADD COLUMN thumb_size text CHECK (thumb_size IS NULL OR thumb_size IN ('post', 'portrait', 'story', 'cover', 'ad')),
  ADD COLUMN publish_note varchar(300),
  ADD CONSTRAINT social_posts_thumb_pair CHECK ((thumb_design_id IS NULL) = (thumb_size IS NULL));
GRANT SELECT ON social_posts TO jarvis_app_rw, jarvis_admin;

-- Connect (or re-connect with a new refresh token) a YouTube channel; same limit of 5 accounts per workspace.  Returns the account id, or 'limit'.
CREATE FUNCTION social_channel_save(p_id char(26), p_tenant char(26), p_name text, p_external text, p_ct text, p_ver smallint) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); a char(26); n integer; nm text := btrim(coalesce(p_name, ''));
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF nm = '' OR char_length(nm) > 120 OR p_external IS NULL OR p_external !~ '^UC[0-9A-Za-z_-]{22}$' OR p_ct IS NULL OR btrim(p_ct) = '' OR p_ver IS NULL THEN RAISE EXCEPTION 'bad account' USING ERRCODE = '22023'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT id INTO a FROM social_accounts WHERE tenant_id = p_tenant AND platform = 'youtube' AND external_id = p_external;
  IF a IS NULL THEN
    SELECT count(*) INTO n FROM social_accounts WHERE tenant_id = p_tenant;
    IF n >= 5 THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'limit'; END IF;
    INSERT INTO social_accounts (id, tenant_id, platform, name, external_id, credentials_ct, key_version) VALUES (p_id, p_tenant, 'youtube', nm, p_external, p_ct, p_ver);
    a := p_id;
  ELSE
    UPDATE social_accounts SET name = nm, credentials_ct = p_ct, key_version = p_ver, status = 'active', updated_at = now() WHERE id = a;
  END IF;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN a;
END $$;

-- The author sets the YouTube details of a draft / pending post: tags, privacy and an optional thumbnail picture (one of the author's own designs).
-- result: ok | not_found | locked | bad_input | bad_media
CREATE FUNCTION social_post_set_youtube(p_id char(26), p_tenant char(26), p_user char(26), p_tags text, p_privacy text, p_thumb char(26), p_thumb_size text) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); p social_posts%ROWTYPE; t text := nullif(btrim(coalesce(p_tags, '')), '');
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  IF (t IS NOT NULL AND (char_length(t) > 500 OR t ~ '[<>]')) OR p_privacy IS NULL OR p_privacy NOT IN ('private', 'unlisted', 'public')
     OR (p_thumb IS NULL) <> (p_thumb_size IS NULL) OR (p_thumb_size IS NOT NULL AND p_thumb_size NOT IN ('post', 'portrait', 'story', 'cover', 'ad')) THEN RETURN 'bad_input'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO p FROM social_posts WHERE id = p_id AND tenant_id = p_tenant AND user_id = p_user FOR UPDATE;
  IF NOT FOUND THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'not_found'; END IF;
  IF p.status NOT IN ('draft', 'pending') OR p.platform <> 'youtube' THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'locked'; END IF;
  IF p_thumb IS NOT NULL AND NOT EXISTS (SELECT 1 FROM designs d WHERE d.id = p_thumb AND d.tenant_id = p_tenant AND d.user_id = p_user AND p_thumb_size = ANY (d.sizes)) THEN
    PERFORM set_config('app.bypass_rls', prev, true); RETURN 'bad_media';
  END IF;
  UPDATE social_posts SET yt_tags = t, yt_privacy = p_privacy, thumb_design_id = p_thumb, thumb_size = p_thumb_size, updated_at = now() WHERE id = p_id;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

-- 23b function redefined: the Facebook worker takes only posts of a Facebook account (YouTube posts have their own claim below).
CREATE OR REPLACE FUNCTION social_publish_claim(p_limit integer) RETURNS TABLE (post_id char(26), tenant_id char(26), user_id char(26), account_id char(26), caption text, media_kind text, media_id char(26), media_size text)
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), '');
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE social_posts SET publish_state = 'failed', publish_error = 'ফলাফল অজানা — প্ল্যাটফর্মে দেখে নিন; না গিয়ে থাকলে "আবার চেষ্টা" দিন (নইলে দুবার যেতে পারে)।'
    WHERE publish_state = 'publishing' AND publish_started_at < now() - interval '15 minutes';
  UPDATE social_posts p SET publish_state = 'failed', publish_error = 'অ্যাকাউন্টের সংযোগ চালু নেই — নতুন করে সংযোগ করুন, তারপর "আবার চেষ্টা" দিন।'
    WHERE p.status = 'approved' AND p.publish_state IS NULL AND p.account_id IS NOT NULL AND p.scheduled_at <= now()
      AND NOT EXISTS (SELECT 1 FROM social_accounts a WHERE a.id = p.account_id AND a.tenant_id = p.tenant_id AND a.status = 'active');
  UPDATE social_posts p SET publish_state = 'failed', publish_error = 'সময় ২৪ ঘণ্টার বেশি আগে পেরিয়ে গেছে — সময় বদলে আবার অনুমোদন নিন।'
    WHERE p.status = 'approved' AND p.publish_state IS NULL AND p.account_id IS NOT NULL AND p.scheduled_at < now() - interval '24 hours';
  RETURN QUERY
  WITH picked AS (
    SELECT p.id FROM social_posts p JOIN social_accounts a ON a.id = p.account_id AND a.tenant_id = p.tenant_id AND a.platform = 'facebook'
     WHERE p.status = 'approved' AND p.publish_state IS NULL AND p.scheduled_at <= now() AND NOT emergency_active(p.tenant_id)
     ORDER BY p.scheduled_at LIMIT greatest(1, least(coalesce(p_limit, 1), 10)) FOR UPDATE OF p SKIP LOCKED)
  UPDATE social_posts s SET publish_state = 'publishing', publish_started_at = now(), attempts = s.attempts + 1, publish_error = NULL
    FROM picked WHERE s.id = picked.id
    RETURNING s.id, s.tenant_id, s.user_id, s.account_id, s.caption, s.media_kind, s.media_id, s.media_size;
  PERFORM set_config('app.bypass_rls', prev, true);
END $$;

-- WORKER (admin only).  Takes due, approved YouTube posts and marks them 'publishing' (once per post until the author retries).
-- Daily caps over the last 24 hours (YouTube's quota is shared by the whole installation): p_global_cap uploads in total, p_tenant_cap per workspace.  Posts over a cap simply wait for the next minute.
-- A post must have a finished video as media (anything else is failed with a reason, nothing is uploaded).
CREATE FUNCTION social_youtube_claim(p_limit integer, p_global_cap integer, p_tenant_cap integer) RETURNS TABLE (post_id char(26), tenant_id char(26), user_id char(26), account_id char(26), title varchar, caption text, media_id char(26), yt_tags varchar, yt_privacy text, thumb_design_id char(26), thumb_size text)
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); gl integer := greatest(0, coalesce(p_global_cap, 0)); tl integer := greatest(0, coalesce(p_tenant_cap, 0)); used_g integer; r record; used_t integer; n integer := 0; lim integer := greatest(1, least(coalesce(p_limit, 1), 5));
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE social_posts p SET publish_state = 'failed', publish_error = 'YouTube পোস্টে একটি তৈরি-হওয়া ভিডিও লাগে — ভিডিও বেছে আবার অনুমোদন নিন।', updated_at = now()
    WHERE p.status = 'approved' AND p.publish_state IS NULL AND p.account_id IS NOT NULL AND p.scheduled_at <= now() AND p.platform = 'youtube' AND p.media_kind <> 'video';
  SELECT count(*) INTO used_g FROM social_posts p JOIN social_accounts a ON a.id = p.account_id AND a.tenant_id = p.tenant_id AND a.platform = 'youtube' WHERE p.publish_started_at > now() - interval '24 hours';
  FOR r IN
    SELECT p.id, p.tenant_id AS tid FROM social_posts p JOIN social_accounts a ON a.id = p.account_id AND a.tenant_id = p.tenant_id AND a.platform = 'youtube' AND a.status = 'active'
     WHERE p.status = 'approved' AND p.publish_state IS NULL AND p.platform = 'youtube' AND p.media_kind = 'video' AND p.scheduled_at <= now() AND p.scheduled_at >= now() - interval '24 hours' AND NOT emergency_active(p.tenant_id)
     ORDER BY p.scheduled_at FOR UPDATE OF p SKIP LOCKED
  LOOP
    EXIT WHEN n >= lim OR used_g >= gl;
    SELECT count(*) INTO used_t FROM social_posts q JOIN social_accounts b ON b.id = q.account_id AND b.tenant_id = q.tenant_id AND b.platform = 'youtube' WHERE q.tenant_id = r.tid AND q.publish_started_at > now() - interval '24 hours';
    CONTINUE WHEN used_t >= tl;
    UPDATE social_posts s SET publish_state = 'publishing', publish_started_at = now(), attempts = s.attempts + 1, publish_error = NULL, publish_note = NULL WHERE s.id = r.id;
    used_g := used_g + 1;
    n := n + 1;
    RETURN QUERY SELECT s.id, s.tenant_id, s.user_id, s.account_id, s.title, s.caption, s.media_id, s.yt_tags, s.yt_privacy, s.thumb_design_id, s.thumb_size FROM social_posts s WHERE s.id = r.id;
  END LOOP;
  PERFORM set_config('app.bypass_rls', prev, true);
END $$;

-- WORKER (admin only).  Finishes a YouTube upload (like social_publish_done) and keeps a short note, e.g. that the thumbnail could not be set.  result: ok | not_found
CREATE FUNCTION social_youtube_done(p_id char(26), p_tenant char(26), p_remote text, p_url text, p_note text) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); rc integer; u text := nullif(btrim(coalesce(p_url, '')), '');
BEGIN
  IF u IS NOT NULL AND (char_length(u) > 300 OR u !~ '^https://[^[:space:]]+$') THEN u := NULL; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE social_posts SET status = 'posted', posted_at = now(), post_url = u, remote_id = left(nullif(btrim(coalesce(p_remote, '')), ''), 100), publish_state = NULL, publish_error = NULL,
         publish_note = left(nullif(btrim(coalesce(p_note, '')), ''), 300), updated_at = now()
    WHERE id = p_id AND tenant_id = p_tenant AND status = 'approved' AND publish_state = 'publishing' AND platform = 'youtube';
  GET DIAGNOSTICS rc = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN rc = 0 THEN 'not_found' ELSE 'ok' END;
END $$;

-- 23c / 23.2c workers redefined: numbers and comments are Facebook-only (a YouTube video id is not a Facebook post id).
CREATE OR REPLACE FUNCTION social_stats_due(p_limit integer) RETURNS TABLE (post_id char(26), tenant_id char(26), account_id char(26), remote_id varchar, media_kind text)
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), '');
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  RETURN QUERY
    SELECT p.id, p.tenant_id, p.account_id, p.remote_id, p.media_kind
      FROM social_posts p
      JOIN social_accounts a ON a.id = p.account_id AND a.tenant_id = p.tenant_id AND a.status = 'active' AND a.platform = 'facebook'
      LEFT JOIN LATERAL (SELECT max(s.taken_at) AS last FROM social_post_stats s WHERE s.post_id = p.id) l ON true
     WHERE p.status = 'posted' AND p.remote_id IS NOT NULL AND p.posted_at < now() - interval '10 minutes' AND p.posted_at > now() - interval '60 days'
       AND (l.last IS NULL OR l.last < now() - CASE WHEN p.posted_at > now() - interval '14 days' THEN interval '6 hours' ELSE interval '24 hours' END)
     ORDER BY l.last NULLS FIRST, p.posted_at
     LIMIT greatest(1, least(coalesce(p_limit, 1), 20));
  PERFORM set_config('app.bypass_rls', prev, true);
END $$;

CREATE OR REPLACE FUNCTION social_comments_due(p_limit integer) RETURNS TABLE (post_id char(26), tenant_id char(26), account_id char(26), remote_id varchar)
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), '');
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  RETURN QUERY
  WITH picked AS (
    SELECT p.id FROM social_posts p
      JOIN social_accounts a ON a.id = p.account_id AND a.tenant_id = p.tenant_id AND a.status = 'active' AND a.platform = 'facebook'
     WHERE p.status = 'posted' AND p.remote_id IS NOT NULL AND p.posted_at < now() - interval '2 minutes' AND p.posted_at > now() - interval '30 days' AND NOT emergency_active(p.tenant_id)
       AND (p.comments_checked_at IS NULL OR p.comments_checked_at < now() - CASE WHEN p.posted_at > now() - interval '2 days' THEN interval '10 minutes' WHEN p.posted_at > now() - interval '14 days' THEN interval '2 hours' ELSE interval '24 hours' END)
     ORDER BY p.comments_checked_at NULLS FIRST, p.posted_at DESC
     LIMIT greatest(1, least(coalesce(p_limit, 1), 20)) FOR UPDATE OF p SKIP LOCKED)
  UPDATE social_posts s SET comments_checked_at = now() FROM picked WHERE s.id = picked.id
    RETURNING s.id, s.tenant_id, s.account_id, s.remote_id;
  PERFORM set_config('app.bypass_rls', prev, true);
END $$;

DO $$ DECLARE f text; BEGIN
  FOREACH f IN ARRAY ARRAY['social_channel_save(char, char, text, text, text, smallint)', 'social_post_set_youtube(char, char, char, text, text, char, text)'] LOOP
    EXECUTE format('REVOKE ALL ON FUNCTION %s FROM PUBLIC, jarvis_app_rw, jarvis_admin', f);
    EXECUTE format('GRANT EXECUTE ON FUNCTION %s TO jarvis_app_rw, jarvis_admin', f);
  END LOOP;
  FOREACH f IN ARRAY ARRAY['social_youtube_claim(integer, integer, integer)', 'social_youtube_done(char, char, text, text, text)'] LOOP
    EXECUTE format('REVOKE ALL ON FUNCTION %s FROM PUBLIC, jarvis_app_rw, jarvis_admin', f);
    EXECUTE format('GRANT EXECUTE ON FUNCTION %s TO jarvis_admin', f);
  END LOOP;
END $$;
