ALTER TABLE lead_activities DROP CONSTRAINT IF EXISTS lead_activities_kind_check;
DELETE FROM lead_activities WHERE kind = 'email';
ALTER TABLE lead_activities ADD CONSTRAINT lead_activities_kind_check CHECK (kind IN ('created', 'note', 'stage', 'task', 'follow_up', 'tag', 'import', 'proposal'));
DROP TABLE IF EXISTS outreach_messages, outreach_suppressions, outreach_campaigns, email_accounts CASCADE;
DROP FUNCTION IF EXISTS outreach_unsubscribe(text);
DROP FUNCTION IF EXISTS outreach_bounce_suppress();
DROP FUNCTION IF EXISTS outreach_guard();
