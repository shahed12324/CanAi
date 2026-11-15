DROP FUNCTION IF EXISTS lead_move_stage(char, text, char);
DROP TABLE IF EXISTS crm_tasks, lead_activities CASCADE;
DROP FUNCTION IF EXISTS lead_child_same_tenant();
DROP INDEX IF EXISTS leads_tags;
DROP INDEX IF EXISTS leads_follow_up;
ALTER TABLE leads DROP CONSTRAINT IF EXISTS leads_tags_limit;
