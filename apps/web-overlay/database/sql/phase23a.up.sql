-- Phase 23a: social content planner.  A post is a caption + (optionally) a picture from the design studio or a finished video, a platform and a time.
-- Flow: draft -> pending (sent for approval) -> approved (scheduled) -> posted (the person marks it posted, with the link) ; or cancelled.  Nothing is published by the system in this phase.
-- The media itself stays in the design / video studio; here only its id is kept (a deleted design or video leaves the post, shown as "media removed").

CREATE TABLE social_posts (
  id           char(26)      PRIMARY KEY,
  tenant_id    char(26)      NOT NULL REFERENCES tenants (id),
  user_id      char(26)      NOT NULL REFERENCES users (id),
  title        varchar(120)  NOT NULL CHECK (btrim(title) <> ''),
  platform     text          NOT NULL CHECK (platform IN ('facebook', 'instagram', 'youtube', 'tiktok', 'linkedin', 'other')),
  caption      text          NOT NULL CHECK (btrim(caption) <> '' AND char_length(caption) <= 3000),
  media_kind   text          NOT NULL DEFAULT 'none' CHECK (media_kind IN ('none', 'design', 'video')),
  media_id     char(26),
  media_size   text          CHECK (media_size IS NULL OR media_size IN ('post', 'portrait', 'story', 'cover', 'ad')),
  scheduled_at timestamptz,
  status       text          NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'pending', 'approved', 'posted', 'cancelled')),
  reject_note  varchar(300),
  approved_by  char(26)      REFERENCES users (id),
  approved_at  timestamptz,
  posted_at    timestamptz,
  post_url     varchar(300),
  created_at   timestamptz   NOT NULL DEFAULT now(),
  updated_at   timestamptz   NOT NULL DEFAULT now(),
  UNIQUE (id, tenant_id),
  CHECK ((media_kind = 'none') = (media_id IS NULL)),
  CHECK (media_kind <> 'design' OR media_size IS NOT NULL),
  CHECK (media_kind = 'design' OR media_size IS NULL),
  CHECK (status NOT IN ('approved', 'posted') OR scheduled_at IS NOT NULL),
  CHECK ((status = 'posted') = (posted_at IS NOT NULL))
);
CREATE INDEX social_posts_calendar ON social_posts (tenant_id, scheduled_at) WHERE status IN ('pending', 'approved');
CREATE INDEX social_posts_person ON social_posts (tenant_id, user_id, updated_at DESC);

ALTER TABLE social_posts ENABLE ROW LEVEL SECURITY;
ALTER TABLE social_posts FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON social_posts USING (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin())) WITH CHECK (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()));
REVOKE ALL ON social_posts FROM jarvis_app_rw, jarvis_admin;
GRANT SELECT ON social_posts TO jarvis_app_rw, jarvis_admin;

-- Creates (p_new = true) or edits a draft.  Only the author can edit, and only while it is a draft.
-- result: ok | bad_input | not_found | locked (not a draft any more) | bad_media (the design / video is not the author's) | limit (500 kept per workspace)
CREATE FUNCTION social_post_save(p_new boolean, p_id char(26), p_tenant char(26), p_user char(26), p_title text, p_platform text, p_caption text,
                                 p_media_kind text, p_media_id char(26), p_media_size text, p_scheduled timestamptz) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); t text := regexp_replace(btrim(coalesce(p_title, '')), '[[:space:]]+', ' ', 'g'); c text := btrim(coalesce(p_caption, '')); st text; n integer; ok boolean;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  IF p_id IS NULL OR t = '' OR char_length(t) > 120 OR c = '' OR char_length(c) > 3000 OR p_platform IS NULL OR p_platform NOT IN ('facebook', 'instagram', 'youtube', 'tiktok', 'linkedin', 'other')
     OR p_media_kind IS NULL OR p_media_kind NOT IN ('none', 'design', 'video') OR (p_media_kind = 'none') <> (p_media_id IS NULL)
     OR (p_media_kind = 'design' AND (p_media_size IS NULL OR p_media_size NOT IN ('post', 'portrait', 'story', 'cover', 'ad')))
     OR (p_media_kind <> 'design' AND p_media_size IS NOT NULL) THEN RETURN 'bad_input'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  IF p_media_kind = 'design' THEN
    SELECT EXISTS (SELECT 1 FROM designs d WHERE d.id = p_media_id AND d.tenant_id = p_tenant AND d.user_id = p_user AND p_media_size = ANY (d.sizes)) INTO ok;
  ELSIF p_media_kind = 'video' THEN
    SELECT EXISTS (SELECT 1 FROM video_jobs v WHERE v.id = p_media_id AND v.tenant_id = p_tenant AND v.user_id = p_user AND v.status = 'done') INTO ok;
  ELSE ok := true; END IF;
  IF NOT ok THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'bad_media'; END IF;
  IF p_new THEN
    SELECT count(*) INTO n FROM social_posts WHERE tenant_id = p_tenant;
    IF n >= 500 THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'limit'; END IF;
    INSERT INTO social_posts (id, tenant_id, user_id, title, platform, caption, media_kind, media_id, media_size, scheduled_at)
    VALUES (p_id, p_tenant, p_user, t, p_platform, c, p_media_kind, p_media_id, p_media_size, p_scheduled) ON CONFLICT DO NOTHING;
  ELSE
    SELECT status INTO st FROM social_posts WHERE id = p_id AND tenant_id = p_tenant AND user_id = p_user;
    IF st IS NULL THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'not_found'; END IF;
    IF st <> 'draft' THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'locked'; END IF;
    UPDATE social_posts SET title = t, platform = p_platform, caption = c, media_kind = p_media_kind, media_id = p_media_id, media_size = p_media_size, scheduled_at = p_scheduled, reject_note = NULL, updated_at = now()
     WHERE id = p_id AND tenant_id = p_tenant;
  END IF;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

-- Author sends a draft for approval (needs a time).  result: ok | not_found | no_schedule
CREATE FUNCTION social_post_submit(p_id char(26), p_tenant char(26), p_user char(26)) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); sched timestamptz; st text;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT status, scheduled_at INTO st, sched FROM social_posts WHERE id = p_id AND tenant_id = p_tenant AND user_id = p_user;
  IF st IS DISTINCT FROM 'draft' THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'not_found'; END IF;
  IF sched IS NULL THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'no_schedule'; END IF;
  UPDATE social_posts SET status = 'pending', reject_note = NULL, updated_at = now() WHERE id = p_id AND tenant_id = p_tenant;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

-- An approver (permission checked by the app) approves or sends back a pending post.  result: ok | not_found
CREATE FUNCTION social_post_decide(p_id char(26), p_tenant char(26), p_approver char(26), p_approve boolean, p_note text) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); rc integer; note text := nullif(left(btrim(coalesce(p_note, '')), 300), '');
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_approver IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  IF p_approve THEN
    UPDATE social_posts SET status = 'approved', approved_by = p_approver, approved_at = now(), reject_note = NULL, updated_at = now() WHERE id = p_id AND tenant_id = p_tenant AND status = 'pending';
  ELSE
    UPDATE social_posts SET status = 'draft', reject_note = note, approved_by = NULL, approved_at = NULL, updated_at = now() WHERE id = p_id AND tenant_id = p_tenant AND status = 'pending';
  END IF;
  GET DIAGNOSTICS rc = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN rc = 0 THEN 'not_found' ELSE 'ok' END;
END $$;

-- Author takes a pending / approved post back to a draft to change it.  result: ok | not_found
CREATE FUNCTION social_post_reopen(p_id char(26), p_tenant char(26), p_user char(26)) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); rc integer;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE social_posts SET status = 'draft', approved_by = NULL, approved_at = NULL, updated_at = now() WHERE id = p_id AND tenant_id = p_tenant AND user_id = p_user AND status IN ('pending', 'approved');
  GET DIAGNOSTICS rc = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN rc = 0 THEN 'not_found' ELSE 'ok' END;
END $$;

-- The author records that an approved post was published (with the link, optional).  result: ok | not_found | bad_url
CREATE FUNCTION social_post_mark_posted(p_id char(26), p_tenant char(26), p_user char(26), p_url text) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); rc integer; u text := nullif(btrim(coalesce(p_url, '')), '');
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  IF u IS NOT NULL AND (char_length(u) > 300 OR u !~ '^https://[^[:space:]]+$') THEN RETURN 'bad_url'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE social_posts SET status = 'posted', posted_at = now(), post_url = u, updated_at = now() WHERE id = p_id AND tenant_id = p_tenant AND user_id = p_user AND status = 'approved';
  GET DIAGNOSTICS rc = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN rc = 0 THEN 'not_found' ELSE 'ok' END;
END $$;

-- Author cancels a draft / pending / approved post.  result: ok | not_found
CREATE FUNCTION social_post_cancel(p_id char(26), p_tenant char(26), p_user char(26)) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); rc integer;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE social_posts SET status = 'cancelled', updated_at = now() WHERE id = p_id AND tenant_id = p_tenant AND user_id = p_user AND status IN ('draft', 'pending', 'approved');
  GET DIAGNOSTICS rc = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN rc = 0 THEN 'not_found' ELSE 'ok' END;
END $$;

-- Author removes a draft / cancelled / posted post.  result: ok | not_found
CREATE FUNCTION social_post_delete(p_id char(26), p_tenant char(26), p_user char(26)) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); rc integer;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  DELETE FROM social_posts WHERE id = p_id AND tenant_id = p_tenant AND user_id = p_user AND status IN ('draft', 'cancelled', 'posted');
  GET DIAGNOSTICS rc = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN rc = 0 THEN 'not_found' ELSE 'ok' END;
END $$;

DO $$ DECLARE f text; BEGIN
  FOREACH f IN ARRAY ARRAY[
    'social_post_save(boolean, char, char, char, text, text, text, text, char, text, timestamptz)', 'social_post_submit(char, char, char)', 'social_post_decide(char, char, char, boolean, text)',
    'social_post_reopen(char, char, char)', 'social_post_mark_posted(char, char, char, text)', 'social_post_cancel(char, char, char)', 'social_post_delete(char, char, char)'] LOOP
    EXECUTE format('REVOKE ALL ON FUNCTION %s FROM PUBLIC, jarvis_app_rw, jarvis_admin', f);
    EXECUTE format('GRANT EXECUTE ON FUNCTION %s TO jarvis_app_rw, jarvis_admin', f);
  END LOOP;
END $$;
