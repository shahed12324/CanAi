-- Phase 8b: CRM on top of leads — activity log (append-only), tasks, tag limit, stage move function.

ALTER TABLE leads ADD CONSTRAINT leads_tags_limit CHECK (cardinality(tags) <= 10);
CREATE INDEX leads_follow_up ON leads (tenant_id, next_follow_up_at) WHERE deleted_at IS NULL AND next_follow_up_at IS NOT NULL;
CREATE INDEX leads_tags ON leads USING gin (tags);

CREATE TABLE lead_activities (
  id          char(26)     PRIMARY KEY,
  tenant_id   char(26)     NOT NULL REFERENCES tenants (id),
  lead_id     char(26)     NOT NULL REFERENCES leads (id),
  user_id     char(26)     REFERENCES users (id),
  kind        text         NOT NULL CHECK (kind IN ('created', 'note', 'stage', 'task', 'follow_up', 'tag', 'import')),
  body        varchar(2000) NOT NULL DEFAULT '',
  meta        jsonb        NOT NULL DEFAULT '{}'::jsonb,
  created_at  timestamptz  NOT NULL DEFAULT now()
);
CREATE INDEX lead_activities_lead ON lead_activities (lead_id, created_at DESC);

CREATE TABLE crm_tasks (
  id           char(26)     PRIMARY KEY,
  tenant_id    char(26)     NOT NULL REFERENCES tenants (id),
  lead_id      char(26)     NOT NULL REFERENCES leads (id),
  title        varchar(200) NOT NULL CHECK (length(btrim(title)) > 0),
  due_at       timestamptz,
  assignee_id  char(26)     REFERENCES users (id),
  created_by   char(26)     REFERENCES users (id),
  done_at      timestamptz,
  created_at   timestamptz  NOT NULL DEFAULT now()
);
CREATE INDEX crm_tasks_open ON crm_tasks (tenant_id, due_at) WHERE done_at IS NULL;
CREATE INDEX crm_tasks_lead ON crm_tasks (lead_id);

-- Children must belong to the same tenant as their lead (RLS alone would let a buggy insert mix tenants).
CREATE FUNCTION lead_child_same_tenant() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE t char(26); prev text := coalesce(current_setting('app.bypass_rls', true), '');
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT tenant_id INTO t FROM leads WHERE id = NEW.lead_id;
  PERFORM set_config('app.bypass_rls', prev, true);
  IF t IS DISTINCT FROM NEW.tenant_id THEN RAISE EXCEPTION 'lead belongs to another tenant'; END IF;
  RETURN NEW;
END $$;
REVOKE ALL ON FUNCTION lead_child_same_tenant() FROM PUBLIC;
CREATE TRIGGER lead_activities_same_tenant BEFORE INSERT ON lead_activities FOR EACH ROW EXECUTE FUNCTION lead_child_same_tenant();
CREATE TRIGGER crm_tasks_same_tenant BEFORE INSERT OR UPDATE OF lead_id ON crm_tasks FOR EACH ROW EXECUTE FUNCTION lead_child_same_tenant();

DO $$
DECLARE t text;
BEGIN
  FOREACH t IN ARRAY ARRAY['lead_activities', 'crm_tasks'] LOOP
    EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', t);
    EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY', t);
    EXECUTE format('CREATE POLICY tenant_isolation ON %I USING (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()))
                    WITH CHECK (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()))', t);
  END LOOP;
END $$;

REVOKE ALL ON lead_activities, crm_tasks FROM jarvis_app_rw, jarvis_admin;
GRANT SELECT, INSERT ON lead_activities TO jarvis_app_rw;           -- append-only history
GRANT SELECT, INSERT, UPDATE ON crm_tasks TO jarvis_app_rw;
GRANT SELECT ON lead_activities, crm_tasks TO jarvis_admin;

-- Move a lead to another stage and record it, atomically. Runs as the caller, so RLS applies.
-- Returns the previous stage, or NULL when the lead does not exist (or is not visible).
-- Same stage = no change, no history row. Won/lost closes the follow-up date.
CREATE FUNCTION lead_move_stage(p_lead char(26), p_stage text, p_user char(26)) RETURNS text
LANGUAGE plpgsql AS $$
DECLARE old_stage text; t char(26);
BEGIN
  SELECT stage, tenant_id INTO old_stage, t FROM leads WHERE id = p_lead AND deleted_at IS NULL FOR UPDATE;
  IF NOT FOUND THEN RETURN NULL; END IF;
  IF old_stage = p_stage THEN RETURN old_stage; END IF;
  UPDATE leads SET stage = p_stage, updated_at = now(),
         next_follow_up_at = CASE WHEN p_stage IN ('won', 'lost') THEN NULL ELSE next_follow_up_at END
   WHERE id = p_lead;
  INSERT INTO lead_activities (id, tenant_id, lead_id, user_id, kind, body, meta)
  VALUES (substr(replace(gen_random_uuid()::text, '-', ''), 1, 26), t, p_lead, p_user, 'stage', '', jsonb_build_object('from', old_stage, 'to', p_stage));
  RETURN old_stage;
END $$;
GRANT EXECUTE ON FUNCTION lead_move_stage(char, text, char) TO jarvis_app_rw;
