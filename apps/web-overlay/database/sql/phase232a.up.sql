-- Phase 23.2a: content-plan agent.  The agent only DRAFTS: it proposes posts, the person picks which to add, and each becomes an ordinary draft in the planner.
--   * social_plans is the log of every plan request: it is the audit trail and the daily cap (10 per person, 30 per workspace, per day);
--   * social_posts.agent_note keeps the agent's media idea ("a picture of ...") next to the draft.

CREATE TABLE social_plans (
  id          char(26)     PRIMARY KEY,
  tenant_id   char(26)     NOT NULL REFERENCES tenants (id),
  user_id     char(26)     NOT NULL REFERENCES users (id),
  brief       varchar(1500) NOT NULL CHECK (btrim(brief) <> ''),
  days        smallint     NOT NULL CHECK (days BETWEEN 1 AND 14),
  platforms   text[]       NOT NULL CHECK (cardinality(platforms) BETWEEN 1 AND 6),
  items       smallint     CHECK (items IS NULL OR items BETWEEN 0 AND 14),
  outcome     text         NOT NULL DEFAULT 'started' CHECK (outcome IN ('started', 'done', 'failed')),
  created_at  timestamptz  NOT NULL DEFAULT now(),
  UNIQUE (id, tenant_id)
);
CREATE INDEX social_plans_day ON social_plans (tenant_id, created_at DESC);
ALTER TABLE social_plans ENABLE ROW LEVEL SECURITY;
ALTER TABLE social_plans FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON social_plans USING (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin())) WITH CHECK (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()));
REVOKE ALL ON social_plans FROM jarvis_app_rw, jarvis_admin;
GRANT SELECT ON social_plans TO jarvis_app_rw, jarvis_admin;

ALTER TABLE social_posts ADD COLUMN agent_note varchar(500);
GRANT SELECT ON social_posts TO jarvis_app_rw, jarvis_admin;

-- Records a plan request and enforces the daily caps.  result: ok | bad_input | person_cap | workspace_cap
CREATE FUNCTION social_plan_start(p_id char(26), p_tenant char(26), p_user char(26), p_brief text, p_days integer, p_platforms text[]) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); b text := btrim(coalesce(p_brief, '')); since timestamptz := now() - interval '24 hours';
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  IF b = '' OR char_length(b) > 1500 OR p_days IS NULL OR p_days NOT BETWEEN 1 AND 14 OR p_platforms IS NULL OR cardinality(p_platforms) NOT BETWEEN 1 AND 6
     OR EXISTS (SELECT 1 FROM unnest(p_platforms) x WHERE x IS NULL OR x NOT IN ('facebook', 'instagram', 'youtube', 'tiktok', 'linkedin', 'other')) THEN RETURN 'bad_input'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  IF (SELECT count(*) FROM social_plans WHERE tenant_id = p_tenant AND user_id = p_user AND created_at > since) >= 10 THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'person_cap'; END IF;
  IF (SELECT count(*) FROM social_plans WHERE tenant_id = p_tenant AND created_at > since) >= 30 THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'workspace_cap'; END IF;
  INSERT INTO social_plans (id, tenant_id, user_id, brief, days, platforms) VALUES (p_id, p_tenant, p_user, b, p_days, p_platforms);
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

-- Closes the log entry.  Only the requester's own started plan.  result: ok | not_found
CREATE FUNCTION social_plan_finish(p_id char(26), p_tenant char(26), p_user char(26), p_ok boolean, p_items integer) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); rc integer;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE social_plans SET outcome = CASE WHEN p_ok THEN 'done' ELSE 'failed' END, items = CASE WHEN p_ok THEN least(greatest(coalesce(p_items, 0), 0), 14) ELSE NULL END
    WHERE id = p_id AND tenant_id = p_tenant AND user_id = p_user AND outcome = 'started';
  GET DIAGNOSTICS rc = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN rc = 0 THEN 'not_found' ELSE 'ok' END;
END $$;

-- The author keeps a short note on a draft / pending post (the agent's media idea).  result: ok | not_found | locked | bad_input
CREATE FUNCTION social_post_set_note(p_id char(26), p_tenant char(26), p_user char(26), p_note text) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); n text := nullif(btrim(coalesce(p_note, '')), ''); st text;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  IF n IS NOT NULL AND char_length(n) > 500 THEN RETURN 'bad_input'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT status INTO st FROM social_posts WHERE id = p_id AND tenant_id = p_tenant AND user_id = p_user;
  IF st IS NULL THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'not_found'; END IF;
  IF st NOT IN ('draft', 'pending') THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'locked'; END IF;
  UPDATE social_posts SET agent_note = n, updated_at = now() WHERE id = p_id;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

DO $$ DECLARE f text; BEGIN
  FOREACH f IN ARRAY ARRAY['social_plan_start(char, char, char, text, integer, text[])', 'social_plan_finish(char, char, char, boolean, integer)', 'social_post_set_note(char, char, char, text)'] LOOP
    EXECUTE format('REVOKE ALL ON FUNCTION %s FROM PUBLIC, jarvis_app_rw, jarvis_admin', f);
    EXECUTE format('GRANT EXECUTE ON FUNCTION %s TO jarvis_app_rw, jarvis_admin', f);
  END LOOP;
END $$;
