DROP FUNCTION IF EXISTS social_post_set_note(char, char, char, text);
DROP FUNCTION IF EXISTS social_plan_finish(char, char, char, boolean, integer);
DROP FUNCTION IF EXISTS social_plan_start(char, char, char, text, integer, text[]);
ALTER TABLE social_posts DROP COLUMN IF EXISTS agent_note;
DROP TABLE IF EXISTS social_plans;
