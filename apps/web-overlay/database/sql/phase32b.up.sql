-- Phase 32b (Agents & Workflows module, phase 1): AGENTS as first-class objects (prompt, model, tools, knowledge/memory switches, permissions), their full version history,
-- and a usage log (tests now; workflow/delegation runs later).  An agent belongs to the person who made it and only ever acts AS that person.
-- The plan limit comes from the existing entitlement system: feature 'agents.max' (plan_features / tenant overrides); when no plan lists it the default is 10.

INSERT INTO features (key, category, type, description) VALUES ('agents.max', 'agents', 'limit', 'Maximum number of AI agents per person')
ON CONFLICT (key) DO NOTHING;

CREATE TABLE wf_agents (
  id            char(26)      PRIMARY KEY,
  tenant_id     char(26)      NOT NULL REFERENCES tenants (id),
  user_id       char(26)      NOT NULL REFERENCES users (id),
  name          varchar(80)   NOT NULL CHECK (btrim(name) <> ''),
  description   varchar(500)  NOT NULL DEFAULT '',
  system_prompt text          NOT NULL CHECK (btrim(system_prompt) <> '' AND char_length(system_prompt) <= 8000),
  model_id      char(26),                                                    -- preferred model (NULL = the workspace's default pick); the app checks the plan allows it
  temperature   numeric(3,2)  NOT NULL DEFAULT 0.3 CHECK (temperature >= 0 AND temperature <= 2),
  max_tokens    integer       NOT NULL DEFAULT 800 CHECK (max_tokens BETWEEN 100 AND 4000),
  tools         text[]        NOT NULL DEFAULT '{}' CHECK (cardinality(tools) <= 20),
  use_knowledge boolean       NOT NULL DEFAULT false,
  use_memory    boolean       NOT NULL DEFAULT false,
  permissions   jsonb         NOT NULL DEFAULT '{}' CHECK (jsonb_typeof(permissions) = 'object' AND length(permissions::text) <= 2000),
  max_credits   numeric(12,4) NOT NULL DEFAULT 2 CHECK (max_credits >= 0 AND max_credits <= 1000),
  enabled       boolean       NOT NULL DEFAULT true,
  version       integer       NOT NULL DEFAULT 1 CHECK (version >= 1),
  created_at    timestamptz   NOT NULL DEFAULT now(),
  updated_at    timestamptz   NOT NULL DEFAULT now(),
  UNIQUE (id, tenant_id)
);
CREATE INDEX wf_agents_owner ON wf_agents (tenant_id, user_id);
CREATE UNIQUE INDEX wf_agents_name_owner ON wf_agents (tenant_id, user_id, lower(name));

CREATE TABLE wf_agent_versions (
  agent_id   char(26)    NOT NULL,
  tenant_id  char(26)    NOT NULL,
  version    integer     NOT NULL CHECK (version >= 1),
  snapshot   jsonb       NOT NULL CHECK (jsonb_typeof(snapshot) = 'object'),
  changed_by char(26)    NOT NULL,
  created_at timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY (agent_id, version),
  FOREIGN KEY (agent_id, tenant_id) REFERENCES wf_agents (id, tenant_id) ON DELETE CASCADE
);

CREATE TABLE wf_agent_runs (
  id            char(26)      PRIMARY KEY,
  tenant_id     char(26)      NOT NULL,
  agent_id      char(26)      NOT NULL,
  user_id       char(26)      NOT NULL,
  agent_version integer       NOT NULL,
  kind          text          NOT NULL CHECK (kind IN ('test', 'workflow', 'delegate')),
  status        text          NOT NULL CHECK (status IN ('ok', 'failed', 'blocked')),
  input         varchar(2000) NOT NULL DEFAULT '',
  output        varchar(4000) NOT NULL DEFAULT '',
  credits       numeric(14,4) NOT NULL DEFAULT 0 CHECK (credits >= 0),
  input_tokens  integer       NOT NULL DEFAULT 0 CHECK (input_tokens >= 0),
  output_tokens integer       NOT NULL DEFAULT 0 CHECK (output_tokens >= 0),
  model_key     varchar(100),
  created_at    timestamptz   NOT NULL DEFAULT now(),
  FOREIGN KEY (agent_id, tenant_id) REFERENCES wf_agents (id, tenant_id) ON DELETE CASCADE
);
CREATE INDEX wf_agent_runs_agent ON wf_agent_runs (tenant_id, agent_id, created_at DESC);

DO $$ DECLARE t text; BEGIN
  FOREACH t IN ARRAY ARRAY['wf_agents', 'wf_agent_versions', 'wf_agent_runs'] LOOP
    EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', t);
    EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY', t);
    EXECUTE format('CREATE POLICY tenant_isolation ON %I USING (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin())) WITH CHECK (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()))', t);
    EXECUTE format('REVOKE ALL ON %I FROM jarvis_app_rw, jarvis_admin', t);
    EXECUTE format('GRANT SELECT ON %I TO jarvis_app_rw, jarvis_admin', t);
  END LOOP;
END $$;

-- How many agents one person may have in this workspace: the entitlement 'agents.max' (NULL = unlimited), default 10 when no plan lists it.
CREATE FUNCTION wf_agent_limit(p_tenant char(26)) RETURNS numeric LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE e record;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  SELECT * INTO e FROM effective_feature(p_tenant, 'agents.max');
  IF e.source = 'none' THEN RETURN 10; END IF;
  IF NOT e.enabled THEN RETURN 0; END IF;
  RETURN e.limit_value;                                                       -- NULL = unlimited
END $$;

-- result: ok | bad_input | limit | duplicate | not_found.  p_new = true creates, false edits (only the owner).  Every change that alters the configuration makes a new version and keeps the old one.
CREATE FUNCTION wf_agent_save(p_id char(26), p_tenant char(26), p_user char(26), p_new boolean, p_name text, p_desc text, p_prompt text, p_model char(26), p_temp numeric,
                              p_max_tokens integer, p_tools text[], p_knowledge boolean, p_memory boolean, p_perm jsonb, p_max_credits numeric, p_enabled boolean) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); nm text := regexp_replace(btrim(coalesce(p_name, '')), '[[:space:]]+', ' ', 'g');
        n integer; lim numeric; cur wf_agents%ROWTYPE; t text; tl text[] := coalesce(p_tools, '{}'); changed boolean; v integer;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  IF p_id IS NULL OR nm = '' OR char_length(nm) > 80 OR char_length(coalesce(p_desc, '')) > 500 OR btrim(coalesce(p_prompt, '')) = '' OR char_length(p_prompt) > 8000
     OR coalesce(p_temp, 0.3) < 0 OR coalesce(p_temp, 0.3) > 2 OR coalesce(p_max_tokens, 800) NOT BETWEEN 100 AND 4000
     OR cardinality(tl) > 20 OR coalesce(p_max_credits, 2) < 0 OR coalesce(p_max_credits, 2) > 1000
     OR (p_perm IS NOT NULL AND (jsonb_typeof(p_perm) <> 'object' OR length(p_perm::text) > 2000)) THEN RETURN 'bad_input'; END IF;
  FOREACH t IN ARRAY tl LOOP
    IF t IS NULL OR t !~ '^[a-z][a-z0-9_.]{1,40}$' THEN RETURN 'bad_input'; END IF;
  END LOOP;
  PERFORM set_config('app.bypass_rls', 'on', true);
  IF coalesce(p_new, true) THEN
    lim := wf_agent_limit(p_tenant);
    SELECT count(*) INTO n FROM wf_agents WHERE tenant_id = p_tenant AND user_id = p_user;
    IF lim IS NOT NULL AND n >= lim THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'limit'; END IF;
    IF EXISTS (SELECT 1 FROM wf_agents WHERE tenant_id = p_tenant AND user_id = p_user AND lower(name) = lower(nm)) THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'duplicate'; END IF;
    INSERT INTO wf_agents (id, tenant_id, user_id, name, description, system_prompt, model_id, temperature, max_tokens, tools, use_knowledge, use_memory, permissions, max_credits, enabled)
    VALUES (p_id, p_tenant, p_user, nm, btrim(coalesce(p_desc, '')), btrim(p_prompt), p_model, coalesce(p_temp, 0.3), coalesce(p_max_tokens, 800), tl, coalesce(p_knowledge, false), coalesce(p_memory, false),
            coalesce(p_perm, '{}'::jsonb), coalesce(p_max_credits, 2), coalesce(p_enabled, true)) ON CONFLICT DO NOTHING;
    GET DIAGNOSTICS n = ROW_COUNT;
    IF n = 1 THEN
      INSERT INTO wf_agent_versions (agent_id, tenant_id, version, snapshot, changed_by)
      SELECT id, tenant_id, 1, to_jsonb(a) - 'tenant_id' - 'user_id' - 'created_at' - 'updated_at', p_user FROM wf_agents a WHERE id = p_id;
    END IF;
    PERFORM set_config('app.bypass_rls', prev, true);
    RETURN CASE WHEN n = 0 THEN 'duplicate' ELSE 'ok' END;
  END IF;
  SELECT * INTO cur FROM wf_agents WHERE id = p_id AND tenant_id = p_tenant AND user_id = p_user FOR UPDATE;
  IF cur.id IS NULL THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'not_found'; END IF;
  IF EXISTS (SELECT 1 FROM wf_agents WHERE tenant_id = p_tenant AND user_id = p_user AND lower(name) = lower(nm) AND id <> p_id) THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'duplicate'; END IF;
  changed := (cur.name, cur.description, cur.system_prompt, cur.model_id, cur.temperature, cur.max_tokens, cur.tools, cur.use_knowledge, cur.use_memory, cur.permissions, cur.max_credits)
             IS DISTINCT FROM (nm, btrim(coalesce(p_desc, '')), btrim(p_prompt), p_model, coalesce(p_temp, 0.3), coalesce(p_max_tokens, 800), tl, coalesce(p_knowledge, false), coalesce(p_memory, false), coalesce(p_perm, '{}'::jsonb), coalesce(p_max_credits, 2));
  v := cur.version + CASE WHEN changed THEN 1 ELSE 0 END;
  UPDATE wf_agents SET name = nm, description = btrim(coalesce(p_desc, '')), system_prompt = btrim(p_prompt), model_id = p_model, temperature = coalesce(p_temp, 0.3), max_tokens = coalesce(p_max_tokens, 800),
         tools = tl, use_knowledge = coalesce(p_knowledge, false), use_memory = coalesce(p_memory, false), permissions = coalesce(p_perm, '{}'::jsonb), max_credits = coalesce(p_max_credits, 2),
         enabled = coalesce(p_enabled, enabled), version = v, updated_at = now()
   WHERE id = p_id;
  IF changed THEN
    INSERT INTO wf_agent_versions (agent_id, tenant_id, version, snapshot, changed_by)
    SELECT id, tenant_id, v, to_jsonb(a) - 'tenant_id' - 'user_id' - 'created_at' - 'updated_at', p_user FROM wf_agents a WHERE id = p_id;
  END IF;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

-- Bring an old version back as a NEW version (history is never rewritten).  result: ok | not_found.
CREATE FUNCTION wf_agent_restore(p_id char(26), p_tenant char(26), p_user char(26), p_version integer) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); cur wf_agents%ROWTYPE; s jsonb; v integer;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO cur FROM wf_agents WHERE id = p_id AND tenant_id = p_tenant AND user_id = p_user FOR UPDATE;
  SELECT snapshot INTO s FROM wf_agent_versions WHERE agent_id = p_id AND tenant_id = p_tenant AND version = p_version;
  IF cur.id IS NULL OR s IS NULL THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'not_found'; END IF;
  IF EXISTS (SELECT 1 FROM wf_agents WHERE tenant_id = p_tenant AND user_id = p_user AND lower(name) = lower(s ->> 'name') AND id <> p_id) THEN
    PERFORM set_config('app.bypass_rls', prev, true); RETURN 'not_found';
  END IF;
  v := cur.version + 1;
  UPDATE wf_agents SET name = s ->> 'name', description = s ->> 'description', system_prompt = s ->> 'system_prompt', model_id = nullif(s ->> 'model_id', '')::char(26),
         temperature = (s ->> 'temperature')::numeric, max_tokens = (s ->> 'max_tokens')::integer,
         tools = coalesce(ARRAY(SELECT jsonb_array_elements_text(s -> 'tools')), '{}'), use_knowledge = (s ->> 'use_knowledge')::boolean, use_memory = (s ->> 'use_memory')::boolean,
         permissions = s -> 'permissions', max_credits = (s ->> 'max_credits')::numeric, version = v, updated_at = now()
   WHERE id = p_id;
  INSERT INTO wf_agent_versions (agent_id, tenant_id, version, snapshot, changed_by)
  SELECT id, tenant_id, v, to_jsonb(a) - 'tenant_id' - 'user_id' - 'created_at' - 'updated_at', p_user FROM wf_agents a WHERE id = p_id;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

CREATE FUNCTION wf_agent_delete(p_id char(26), p_tenant char(26), p_user char(26)) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); rc integer;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  DELETE FROM wf_agents WHERE id = p_id AND tenant_id = p_tenant AND user_id = p_user;
  GET DIAGNOSTICS rc = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN rc = 0 THEN 'not_found' ELSE 'ok' END;
END $$;

-- One line of usage (a test run now; workflow / delegation runs later).  The agent must belong to the person.  result: ok | not_found | bad_input.
CREATE FUNCTION wf_agent_run_record(p_run char(26), p_tenant char(26), p_user char(26), p_agent char(26), p_kind text, p_status text, p_input text, p_output text,
                                    p_credits numeric, p_in integer, p_out integer, p_model text) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); a wf_agents%ROWTYPE;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  IF p_run IS NULL OR p_kind NOT IN ('test', 'workflow', 'delegate') OR p_status NOT IN ('ok', 'failed', 'blocked') OR coalesce(p_credits, 0) < 0 OR coalesce(p_in, 0) < 0 OR coalesce(p_out, 0) < 0 THEN RETURN 'bad_input'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO a FROM wf_agents WHERE id = p_agent AND tenant_id = p_tenant AND user_id = p_user;
  IF a.id IS NULL THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'not_found'; END IF;
  INSERT INTO wf_agent_runs (id, tenant_id, agent_id, user_id, agent_version, kind, status, input, output, credits, input_tokens, output_tokens, model_key)
  VALUES (p_run, p_tenant, a.id, p_user, a.version, p_kind, p_status, left(coalesce(p_input, ''), 2000), left(coalesce(p_output, ''), 4000), coalesce(p_credits, 0), coalesce(p_in, 0), coalesce(p_out, 0), left(p_model, 100))
  ON CONFLICT DO NOTHING;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

DO $$ DECLARE f text; BEGIN
  FOREACH f IN ARRAY ARRAY['wf_agent_limit(char)', 'wf_agent_save(char, char, char, boolean, text, text, text, char, numeric, integer, text[], boolean, boolean, jsonb, numeric, boolean)',
                           'wf_agent_restore(char, char, char, integer)', 'wf_agent_delete(char, char, char)',
                           'wf_agent_run_record(char, char, char, char, text, text, text, text, numeric, integer, integer, text)'] LOOP
    EXECUTE format('REVOKE ALL ON FUNCTION %s FROM PUBLIC, jarvis_app_rw, jarvis_admin', f);
    EXECUTE format('GRANT EXECUTE ON FUNCTION %s TO jarvis_app_rw, jarvis_admin', f);
  END LOOP;
END $$;
