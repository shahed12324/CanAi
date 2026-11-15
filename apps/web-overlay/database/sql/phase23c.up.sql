-- Phase 23c: post insights.  The worker reads reactions / comments / shares (and reach when Facebook gives it) of posts it published and keeps snapshots.
-- A snapshot with reactions IS NULL means "tried, got nothing" — it spaces out retries and is never shown.

CREATE TABLE social_post_stats (
  post_id    char(26)    NOT NULL,
  tenant_id  char(26)    NOT NULL REFERENCES tenants (id),
  taken_at   timestamptz NOT NULL DEFAULT now(),
  reactions  integer     CHECK (reactions IS NULL OR reactions >= 0),
  comments   integer     CHECK (comments IS NULL OR comments >= 0),
  shares     integer     CHECK (shares IS NULL OR shares >= 0),
  reach      integer     CHECK (reach IS NULL OR reach >= 0),
  PRIMARY KEY (post_id, taken_at),
  FOREIGN KEY (post_id, tenant_id) REFERENCES social_posts (id, tenant_id) ON DELETE CASCADE,
  CHECK (reactions IS NOT NULL OR (comments IS NULL AND shares IS NULL AND reach IS NULL))
);
CREATE INDEX social_post_stats_tenant ON social_post_stats (tenant_id, taken_at DESC);
ALTER TABLE social_post_stats ENABLE ROW LEVEL SECURITY;
ALTER TABLE social_post_stats FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON social_post_stats USING (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin())) WITH CHECK (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()));
REVOKE ALL ON social_post_stats FROM jarvis_app_rw, jarvis_admin;
GRANT SELECT ON social_post_stats TO jarvis_app_rw, jarvis_admin;
-- social_posts.id needs to be referenceable together with tenant_id: UNIQUE (id, tenant_id) already exists from 23a.

-- The newest real snapshot per post, for the screens.
CREATE VIEW social_post_latest_stats WITH (security_invoker = true) AS
  SELECT DISTINCT ON (s.post_id) s.post_id, s.tenant_id, s.taken_at, s.reactions, s.comments, s.shares, s.reach
    FROM social_post_stats s WHERE s.reactions IS NOT NULL ORDER BY s.post_id, s.taken_at DESC;
GRANT SELECT ON social_post_latest_stats TO jarvis_app_rw, jarvis_admin;

-- ADMIN ONLY.  Posts published by the system that are due for a look: never looked at (10 minutes after posting), every 6 hours for 14 days, then daily until 60 days.
-- Looks (successful or not) are spaced by the newest snapshot of any kind.  Only posts whose Page is still active.
CREATE FUNCTION social_stats_due(p_limit integer) RETURNS TABLE (post_id char(26), tenant_id char(26), account_id char(26), remote_id varchar, media_kind text)
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

-- ADMIN ONLY.  Stores a snapshot (p_reactions NULL = a failed look).  Keeps at most 60 snapshots per post.  result: ok | not_found | bad_input
CREATE FUNCTION social_stats_save(p_post char(26), p_tenant char(26), p_reactions integer, p_comments integer, p_shares integer, p_reach integer) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), '');
BEGIN
  IF (p_reactions IS NULL AND (p_comments IS NOT NULL OR p_shares IS NOT NULL OR p_reach IS NOT NULL))
     OR least(coalesce(p_reactions, 0), coalesce(p_comments, 0), coalesce(p_shares, 0), coalesce(p_reach, 0)) < 0 THEN RETURN 'bad_input'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  IF NOT EXISTS (SELECT 1 FROM social_posts WHERE id = p_post AND tenant_id = p_tenant AND status = 'posted') THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'not_found'; END IF;
  INSERT INTO social_post_stats (post_id, tenant_id, reactions, comments, shares, reach) VALUES (p_post, p_tenant, p_reactions, p_comments, p_shares, p_reach);
  DELETE FROM social_post_stats WHERE post_id = p_post AND taken_at < (SELECT taken_at FROM social_post_stats WHERE post_id = p_post ORDER BY taken_at DESC OFFSET 59 LIMIT 1);
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

REVOKE ALL ON FUNCTION social_stats_due(integer) FROM PUBLIC, jarvis_app_rw, jarvis_admin;
REVOKE ALL ON FUNCTION social_stats_save(char, char, integer, integer, integer, integer) FROM PUBLIC, jarvis_app_rw, jarvis_admin;
GRANT EXECUTE ON FUNCTION social_stats_due(integer) TO jarvis_admin;
GRANT EXECUTE ON FUNCTION social_stats_save(char, char, integer, integer, integer, integer) TO jarvis_admin;
