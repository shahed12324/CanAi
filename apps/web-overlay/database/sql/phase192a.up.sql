-- Phase 19.2a: Productivity - tasks, reminders, notes. Everything here is PERSONAL: a person only ever sees and changes their own (the repo filters by user; the
-- write functions enforce it). Nothing here costs credits and nothing leaves the database. A reminder is delivered once, as a notification (in-app + phone push).

CREATE TABLE tasks (
  id          char(26)      PRIMARY KEY,
  tenant_id   char(26)      NOT NULL REFERENCES tenants (id),
  user_id     char(26)      NOT NULL REFERENCES users (id),
  title       varchar(200)  NOT NULL CHECK (btrim(title) <> ''),
  details     varchar(2000) NOT NULL DEFAULT '',
  priority    smallint      NOT NULL DEFAULT 2 CHECK (priority BETWEEN 1 AND 3),          -- 1 high, 2 normal, 3 low
  due_on      date,
  remind_at   timestamptz,
  reminded_at timestamptz,
  status      text          NOT NULL DEFAULT 'open' CHECK (status IN ('open', 'done')),
  done_at     timestamptz,
  created_at  timestamptz   NOT NULL DEFAULT now(),
  updated_at  timestamptz   NOT NULL DEFAULT now(),
  CHECK ((status = 'done') = (done_at IS NOT NULL)),
  UNIQUE (id, tenant_id)
);
CREATE INDEX tasks_person ON tasks (tenant_id, user_id, status, due_on);
CREATE INDEX tasks_reminders_due ON tasks (remind_at) WHERE status = 'open' AND reminded_at IS NULL AND remind_at IS NOT NULL;

CREATE TABLE notes (
  id         char(26)       PRIMARY KEY,
  tenant_id  char(26)       NOT NULL REFERENCES tenants (id),
  user_id    char(26)       NOT NULL REFERENCES users (id),
  title      varchar(200)   NOT NULL DEFAULT '',
  body       varchar(20000) NOT NULL DEFAULT '',
  pinned     boolean        NOT NULL DEFAULT false,
  created_at timestamptz    NOT NULL DEFAULT now(),
  updated_at timestamptz    NOT NULL DEFAULT now(),
  CHECK (btrim(title) <> '' OR btrim(body) <> ''),
  UNIQUE (id, tenant_id)
);
CREATE INDEX notes_person ON notes (tenant_id, user_id, pinned DESC, updated_at DESC);

DO $$ DECLARE t text; BEGIN
  FOREACH t IN ARRAY ARRAY['tasks', 'notes'] LOOP
    EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', t);
    EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY', t);
    EXECUTE format('CREATE POLICY tenant_isolation ON %I USING (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin())) WITH CHECK (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()))', t);
    EXECUTE format('REVOKE ALL ON %I FROM jarvis_app_rw, jarvis_admin', t);
    EXECUTE format('GRANT SELECT ON %I TO jarvis_app_rw, jarvis_admin', t);
  END LOOP;
END $$;

-- result: ok | bad_input | limit.  At most 500 open tasks per person.
CREATE FUNCTION task_add(p_id char(26), p_tenant char(26), p_user char(26), p_title text, p_details text, p_priority integer, p_due date, p_remind timestamptz) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); t text := regexp_replace(btrim(coalesce(p_title, '')), '[[:space:]]+', ' ', 'g'); n integer;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  IF p_id IS NULL OR t = '' OR char_length(t) > 200 OR char_length(coalesce(p_details, '')) > 2000 OR coalesce(p_priority, 2) NOT BETWEEN 1 AND 3 THEN RETURN 'bad_input'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT count(*) INTO n FROM tasks WHERE tenant_id = p_tenant AND user_id = p_user AND status = 'open';
  IF n >= 500 THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'limit'; END IF;
  INSERT INTO tasks (id, tenant_id, user_id, title, details, priority, due_on, remind_at) VALUES (p_id, p_tenant, p_user, t, btrim(coalesce(p_details, '')), coalesce(p_priority, 2), p_due, p_remind) ON CONFLICT DO NOTHING;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

-- Replaces all the editable fields (NULL due / remind = none).  Changing the reminder time arms it again.  result: ok | not_found | bad_input
CREATE FUNCTION task_edit(p_id char(26), p_tenant char(26), p_user char(26), p_title text, p_details text, p_priority integer, p_due date, p_remind timestamptz) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); t text := regexp_replace(btrim(coalesce(p_title, '')), '[[:space:]]+', ' ', 'g'); rc integer;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  IF t = '' OR char_length(t) > 200 OR char_length(coalesce(p_details, '')) > 2000 OR coalesce(p_priority, 2) NOT BETWEEN 1 AND 3 THEN RETURN 'bad_input'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE tasks SET title = t, details = btrim(coalesce(p_details, '')), priority = coalesce(p_priority, 2), due_on = p_due,
    reminded_at = CASE WHEN remind_at IS DISTINCT FROM p_remind THEN NULL ELSE reminded_at END, remind_at = p_remind, updated_at = now()
  WHERE id = p_id AND tenant_id = p_tenant AND user_id = p_user;
  GET DIAGNOSTICS rc = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN rc = 0 THEN 'not_found' ELSE 'ok' END;
END $$;

-- Tick / untick.  A finished task never reminds.  result: ok | not_found
CREATE FUNCTION task_set_done(p_id char(26), p_tenant char(26), p_user char(26), p_done boolean) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); rc integer;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE tasks SET status = CASE WHEN coalesce(p_done, false) THEN 'done' ELSE 'open' END, done_at = CASE WHEN coalesce(p_done, false) THEN now() ELSE NULL END, updated_at = now()
  WHERE id = p_id AND tenant_id = p_tenant AND user_id = p_user;
  GET DIAGNOSTICS rc = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN rc = 0 THEN 'not_found' ELSE 'ok' END;
END $$;

CREATE FUNCTION task_delete(p_id char(26), p_tenant char(26), p_user char(26)) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); rc integer;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  DELETE FROM tasks WHERE id = p_id AND tenant_id = p_tenant AND user_id = p_user;
  GET DIAGNOSTICS rc = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN rc = 0 THEN 'not_found' ELSE 'ok' END;
END $$;

-- Removes the person's finished tasks.  Returns how many.
CREATE FUNCTION task_clear_done(p_tenant char(26), p_user char(26)) RETURNS integer
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); n integer;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  DELETE FROM tasks WHERE tenant_id = p_tenant AND user_id = p_user AND status = 'done';
  GET DIAGNOSTICS n = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN n;
END $$;

-- result: ok | bad_input | limit.  At most 1000 notes per person.
CREATE FUNCTION note_save(p_id char(26), p_tenant char(26), p_user char(26), p_title text, p_body text, p_pinned boolean, p_new boolean) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); ti text := btrim(coalesce(p_title, '')); bo text := btrim(coalesce(p_body, '')); n integer; rc integer;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  IF p_id IS NULL OR (ti = '' AND bo = '') OR char_length(ti) > 200 OR char_length(bo) > 20000 THEN RETURN 'bad_input'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  IF coalesce(p_new, true) THEN
    SELECT count(*) INTO n FROM notes WHERE tenant_id = p_tenant AND user_id = p_user;
    IF n >= 1000 THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'limit'; END IF;
    INSERT INTO notes (id, tenant_id, user_id, title, body, pinned) VALUES (p_id, p_tenant, p_user, ti, bo, coalesce(p_pinned, false)) ON CONFLICT DO NOTHING;
    rc := 1;
  ELSE
    UPDATE notes SET title = ti, body = bo, pinned = coalesce(p_pinned, pinned), updated_at = now() WHERE id = p_id AND tenant_id = p_tenant AND user_id = p_user;
    GET DIAGNOSTICS rc = ROW_COUNT;
  END IF;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN rc = 0 THEN 'not_found' ELSE 'ok' END;
END $$;

CREATE FUNCTION note_delete(p_id char(26), p_tenant char(26), p_user char(26)) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); rc integer;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  DELETE FROM notes WHERE id = p_id AND tenant_id = p_tenant AND user_id = p_user;
  GET DIAGNOSTICS rc = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN rc = 0 THEN 'not_found' ELSE 'ok' END;
END $$;

-- Run every minute by the scheduler (admin only): every open task whose reminder time has come gets ONE notification, then is marked reminded - in one statement, so a
-- crash cannot remind twice or lose a reminder.  Reminders more than 2 days late are marked reminded without notifying (an old backlog must not flood a phone).  Returns how many notified.
CREATE FUNCTION task_reminders_dispatch(p_limit integer) RETURNS integer
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); n integer;
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  WITH due AS (
    SELECT id FROM tasks WHERE status = 'open' AND reminded_at IS NULL AND remind_at IS NOT NULL AND remind_at <= now()
    ORDER BY remind_at LIMIT greatest(1, least(coalesce(p_limit, 200), 1000)) FOR UPDATE SKIP LOCKED
  ), marked AS (
    UPDATE tasks t SET reminded_at = now() FROM due WHERE t.id = due.id RETURNING t.id, t.tenant_id, t.user_id, t.title, t.remind_at
  ), ins AS (
    INSERT INTO inbox_notifications (id, tenant_id, user_id, kind, title, body, ref_kind, ref_id)
    SELECT substr(md5(m.id || 'reminder' || m.remind_at::text) || md5(m.id), 1, 26), m.tenant_id, m.user_id, 'task_reminder', left('রিমাইন্ডার: ' || m.title, 160), 'আপনার টাস্কের সময় হয়েছে।', 'task', m.id
    FROM marked m WHERE m.remind_at > now() - interval '2 days'
    RETURNING 1
  ) SELECT count(*) INTO n FROM ins;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN n;
END $$;

DO $$ DECLARE f text; BEGIN
  FOREACH f IN ARRAY ARRAY['task_add(char, char, char, text, text, integer, date, timestamptz)', 'task_edit(char, char, char, text, text, integer, date, timestamptz)', 'task_set_done(char, char, char, boolean)',
    'task_delete(char, char, char)', 'task_clear_done(char, char)', 'note_save(char, char, char, text, text, boolean, boolean)', 'note_delete(char, char, char)'] LOOP
    EXECUTE format('REVOKE ALL ON FUNCTION %s FROM PUBLIC, jarvis_app_rw, jarvis_admin', f);
    EXECUTE format('GRANT EXECUTE ON FUNCTION %s TO jarvis_app_rw, jarvis_admin', f);
  END LOOP;
END $$;
REVOKE ALL ON FUNCTION task_reminders_dispatch(integer) FROM PUBLIC, jarvis_app_rw, jarvis_admin;
GRANT EXECUTE ON FUNCTION task_reminders_dispatch(integer) TO jarvis_admin;
