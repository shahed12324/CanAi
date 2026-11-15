DROP FUNCTION IF EXISTS social_youtube_done(char, char, text, text, text);
DROP FUNCTION IF EXISTS social_youtube_claim(integer, integer, integer);
DROP FUNCTION IF EXISTS social_post_set_youtube(char, char, char, text, text, char, text);
DROP FUNCTION IF EXISTS social_channel_save(char, char, text, text, text, smallint);
CREATE OR REPLACE FUNCTION social_publish_claim(p_limit integer) RETURNS TABLE (post_id char(26), tenant_id char(26), user_id char(26), account_id char(26), caption text, media_kind text, media_id char(26), media_size text)
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
CREATE OR REPLACE FUNCTION social_stats_due(p_limit integer) RETURNS TABLE (post_id char(26), tenant_id char(26), account_id char(26), remote_id varchar, media_kind text)
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), '');
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  RETURN QUERY
    SELECT p.id, p.tenant_id, p.account_id, p.remote_id, p.media_kind
      FROM social_posts p
      JOIN social_accounts a ON a.id = p.account_id AND a.tenant_id = p.tenant_id AND a.status = 'active'
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
      JOIN social_accounts a ON a.id = p.account_id AND a.tenant_id = p.tenant_id AND a.status = 'active'
     WHERE p.status = 'posted' AND p.remote_id IS NOT NULL AND p.posted_at < now() - interval '2 minutes' AND p.posted_at > now() - interval '30 days' AND NOT emergency_active(p.tenant_id)
       AND (p.comments_checked_at IS NULL OR p.comments_checked_at < now() - CASE WHEN p.posted_at > now() - interval '2 days' THEN interval '10 minutes' WHEN p.posted_at > now() - interval '14 days' THEN interval '2 hours' ELSE interval '24 hours' END)
     ORDER BY p.comments_checked_at NULLS FIRST, p.posted_at DESC
     LIMIT greatest(1, least(coalesce(p_limit, 1), 20)) FOR UPDATE OF p SKIP LOCKED)
  UPDATE social_posts s SET comments_checked_at = now() FROM picked WHERE s.id = picked.id
    RETURNING s.id, s.tenant_id, s.account_id, s.remote_id;
  PERFORM set_config('app.bypass_rls', prev, true);
END $$;
DELETE FROM social_posts WHERE account_id IN (SELECT id FROM social_accounts WHERE platform = 'youtube');
DELETE FROM social_accounts WHERE platform = 'youtube';
ALTER TABLE social_posts DROP CONSTRAINT IF EXISTS social_posts_thumb_pair;
ALTER TABLE social_posts DROP COLUMN IF EXISTS yt_tags, DROP COLUMN IF EXISTS yt_privacy, DROP COLUMN IF EXISTS thumb_design_id, DROP COLUMN IF EXISTS thumb_size, DROP COLUMN IF EXISTS publish_note;
ALTER TABLE social_accounts DROP CONSTRAINT social_accounts_external_id_check;
ALTER TABLE social_accounts DROP CONSTRAINT social_accounts_platform_check;
ALTER TABLE social_accounts ADD CONSTRAINT social_accounts_platform_check CHECK (platform IN ('facebook'));
ALTER TABLE social_accounts ADD CONSTRAINT social_accounts_external_id_check CHECK (external_id ~ '^[0-9]{5,30}$');
