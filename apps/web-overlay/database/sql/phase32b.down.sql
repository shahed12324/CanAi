-- Refuses if agents exist (they are the customer's data).
DO $$ BEGIN
  IF EXISTS (SELECT 1 FROM wf_agents) THEN RAISE EXCEPTION 'wf_agents holds rows; cannot roll back' USING ERRCODE = '22023'; END IF;
END $$;
DROP FUNCTION IF EXISTS wf_agent_run_record(char, char, char, char, text, text, text, text, numeric, integer, integer, text);
DROP FUNCTION IF EXISTS wf_agent_delete(char, char, char);
DROP FUNCTION IF EXISTS wf_agent_restore(char, char, char, integer);
DROP FUNCTION IF EXISTS wf_agent_save(char, char, char, boolean, text, text, text, char, numeric, integer, text[], boolean, boolean, jsonb, numeric, boolean);
DROP FUNCTION IF EXISTS wf_agent_limit(char);
DROP TABLE IF EXISTS wf_agent_runs;
DROP TABLE IF EXISTS wf_agent_versions;
DROP TABLE IF EXISTS wf_agents;
DELETE FROM features WHERE key = 'agents.max' AND NOT EXISTS (SELECT 1 FROM plan_features WHERE feature_key = 'agents.max') AND NOT EXISTS (SELECT 1 FROM tenant_feature_overrides WHERE feature_key = 'agents.max');
