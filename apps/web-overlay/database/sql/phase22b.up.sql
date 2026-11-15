-- Phase 22.2a: slide videos.  A job = scenes (words + optional photo) + an optional voice-over, drawn into an MP4 by the engine in a background queue.
-- Credits are RESERVED when the job is created and settled (done) or released (failed / cancelled) by the worker: exactly one of the two, ever.
-- The picture sources and the finished file live on the private disk (paths only here); this table never holds video bytes.

ALTER TABLE usage_records DROP CONSTRAINT usage_records_source_check;
ALTER TABLE usage_records ADD CONSTRAINT usage_records_source_check CHECK (source IN
  ('chat', 'agent', 'workflow', 'image', 'video', 'voice', 'email', 'storage', 'render', 'catalog', 'bill_scan', 'inbox', 'analyst', 'memory', 'vision', 'docgen', 'assist'));

CREATE TABLE video_jobs (
  id             char(26)      PRIMARY KEY,
  tenant_id      char(26)      NOT NULL REFERENCES tenants (id),
  user_id        char(26)      NOT NULL REFERENCES users (id),
  title          varchar(120)  NOT NULL CHECK (btrim(title) <> ''),
  size_key       text          NOT NULL CHECK (size_key IN ('post', 'portrait', 'story', 'ad')),
  scenes         jsonb         NOT NULL CHECK (jsonb_typeof(scenes) = 'array' AND jsonb_array_length(scenes) BETWEEN 1 AND 10),
  narration      varchar(2000),
  voice          varchar(20),
  brand          jsonb         NOT NULL CHECK (jsonb_typeof(brand) = 'object'),
  logo_b64       text          CHECK (logo_b64 IS NULL OR length(logo_b64) <= 900000),
  audio_path     varchar(300),
  reservation_id char(26),
  amount         numeric(20,4) NOT NULL CHECK (amount > 0),
  charged        numeric(20,4),
  seconds        numeric(5,1)  NOT NULL CHECK (seconds BETWEEN 2 AND 60),
  status         text          NOT NULL DEFAULT 'queued' CHECK (status IN ('queued', 'rendering', 'done', 'failed', 'cancelled')),
  file_path      varchar(300),
  bytes          bigint        CHECK (bytes IS NULL OR bytes > 0),
  error          varchar(200),
  created_at     timestamptz   NOT NULL DEFAULT now(),
  started_at     timestamptz,
  finished_at    timestamptz,
  UNIQUE (id, tenant_id),
  CHECK ((status = 'done') = (file_path IS NOT NULL))
);
CREATE INDEX video_jobs_person ON video_jobs (tenant_id, user_id, created_at DESC);
CREATE INDEX video_jobs_queue ON video_jobs (created_at) WHERE status IN ('queued', 'rendering');

ALTER TABLE video_jobs ENABLE ROW LEVEL SECURITY;
ALTER TABLE video_jobs FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON video_jobs USING (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin())) WITH CHECK (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()));
REVOKE ALL ON video_jobs FROM jarvis_app_rw, jarvis_admin;
GRANT SELECT ON video_jobs TO jarvis_app_rw, jarvis_admin;

-- result: ok | bad_input | limit.  At most 3 jobs waiting or rendering per person and 30 jobs kept per person.
CREATE FUNCTION video_job_create(p_id char(26), p_tenant char(26), p_user char(26), p_title text, p_size text, p_scenes jsonb, p_narration text, p_voice text, p_brand jsonb,
                                 p_logo text, p_audio_path text, p_reservation char(26), p_amount numeric, p_seconds numeric) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); t text := regexp_replace(btrim(coalesce(p_title, '')), '[[:space:]]+', ' ', 'g'); n integer; a integer;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  IF p_id IS NULL OR t = '' OR char_length(t) > 120 OR p_size IS NULL OR p_size NOT IN ('post', 'portrait', 'story', 'ad')
     OR p_scenes IS NULL OR jsonb_typeof(p_scenes) IS DISTINCT FROM 'array' OR jsonb_array_length(p_scenes) NOT BETWEEN 1 AND 10
     OR p_brand IS NULL OR jsonb_typeof(p_brand) IS DISTINCT FROM 'object' OR char_length(coalesce(p_narration, '')) > 2000 OR char_length(coalesce(p_voice, '')) > 20
     OR char_length(coalesce(p_logo, '')) > 900000 OR char_length(coalesce(p_audio_path, '')) > 300 OR p_amount IS NULL OR p_amount <= 0
     OR p_seconds IS NULL OR p_seconds < 2 OR p_seconds > 60 THEN RETURN 'bad_input'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT count(*), count(*) FILTER (WHERE status IN ('queued', 'rendering')) INTO n, a FROM video_jobs WHERE tenant_id = p_tenant AND user_id = p_user;
  IF n >= 30 OR a >= 3 THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'limit'; END IF;
  INSERT INTO video_jobs (id, tenant_id, user_id, title, size_key, scenes, narration, voice, brand, logo_b64, audio_path, reservation_id, amount, seconds)
  VALUES (p_id, p_tenant, p_user, t, p_size, p_scenes, nullif(p_narration, ''), nullif(p_voice, ''), p_brand, nullif(p_logo, ''), nullif(p_audio_path, ''), p_reservation, p_amount, p_seconds)
  ON CONFLICT DO NOTHING;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

-- The owner withdraws a job that has not started.  result: ok | not_found (also when it is already rendering or finished)
CREATE FUNCTION video_job_cancel(p_id char(26), p_tenant char(26), p_user char(26)) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); rc integer;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE video_jobs SET status = 'cancelled', finished_at = now() WHERE id = p_id AND tenant_id = p_tenant AND user_id = p_user AND status = 'queued';
  GET DIAGNOSTICS rc = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN rc = 0 THEN 'not_found' ELSE 'ok' END;
END $$;

-- Removes a finished job (done / failed / cancelled).  result: ok | not_found (also while it is still waiting or rendering)
CREATE FUNCTION video_job_delete(p_id char(26), p_tenant char(26), p_user char(26)) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); rc integer;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  DELETE FROM video_jobs WHERE id = p_id AND tenant_id = p_tenant AND user_id = p_user AND status IN ('done', 'failed', 'cancelled');
  GET DIAGNOSTICS rc = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN rc = 0 THEN 'not_found' ELSE 'ok' END;
END $$;

-- WORKER (admin only).  Takes the oldest queued jobs (one per call is typical) and marks them rendering.  A job stuck rendering for 20 minutes is failed.
CREATE FUNCTION video_job_claim(p_limit integer) RETURNS TABLE (job_id char(26), tenant_id char(26), user_id char(26))
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), '');
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE video_jobs SET status = 'failed', error = 'আটকে ছিল (সময় পেরিয়ে গেছে)', finished_at = now() WHERE status = 'rendering' AND started_at < now() - interval '20 minutes';
  RETURN QUERY
  WITH picked AS (SELECT j.id FROM video_jobs j WHERE j.status = 'queued' ORDER BY j.created_at LIMIT greatest(1, least(coalesce(p_limit, 1), 10)) FOR UPDATE SKIP LOCKED)
  UPDATE video_jobs j SET status = 'rendering', started_at = now() FROM picked WHERE j.id = picked.id RETURNING j.id, j.tenant_id, j.user_id;
  PERFORM set_config('app.bypass_rls', prev, true);
END $$;

-- WORKER (admin only).  Only a rendering job can finish.  result: ok | not_found
CREATE FUNCTION video_job_done(p_id char(26), p_tenant char(26), p_file text, p_bytes bigint, p_charged numeric) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); rc integer;
BEGIN
  IF p_file IS NULL OR btrim(p_file) = '' OR char_length(p_file) > 300 OR p_bytes IS NULL OR p_bytes <= 0 THEN RETURN 'not_found'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE video_jobs SET status = 'done', file_path = p_file, bytes = p_bytes, charged = p_charged, error = NULL, finished_at = now() WHERE id = p_id AND tenant_id = p_tenant AND status = 'rendering';
  GET DIAGNOSTICS rc = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN rc = 0 THEN 'not_found' ELSE 'ok' END;
END $$;

-- WORKER (admin only).  Only a rendering job can fail.  result: ok | not_found
CREATE FUNCTION video_job_fail(p_id char(26), p_tenant char(26), p_error text) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); rc integer;
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE video_jobs SET status = 'failed', error = left(coalesce(nullif(btrim(p_error), ''), 'ব্যর্থ'), 200), finished_at = now() WHERE id = p_id AND tenant_id = p_tenant AND status = 'rendering';
  GET DIAGNOSTICS rc = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN rc = 0 THEN 'not_found' ELSE 'ok' END;
END $$;

DO $$ DECLARE f text; BEGIN
  FOREACH f IN ARRAY ARRAY['video_job_create(char, char, char, text, text, jsonb, text, text, jsonb, text, text, char, numeric, numeric)', 'video_job_cancel(char, char, char)', 'video_job_delete(char, char, char)'] LOOP
    EXECUTE format('REVOKE ALL ON FUNCTION %s FROM PUBLIC, jarvis_app_rw, jarvis_admin', f);
    EXECUTE format('GRANT EXECUTE ON FUNCTION %s TO jarvis_app_rw, jarvis_admin', f);
  END LOOP;
  FOREACH f IN ARRAY ARRAY['video_job_claim(integer)', 'video_job_done(char, char, text, bigint, numeric)', 'video_job_fail(char, char, text)'] LOOP
    EXECUTE format('REVOKE ALL ON FUNCTION %s FROM PUBLIC, jarvis_app_rw, jarvis_admin', f);
    EXECUTE format('GRANT EXECUTE ON FUNCTION %s TO jarvis_admin', f);
  END LOOP;
END $$;
