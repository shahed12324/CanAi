ALTER TABLE lead_activities DROP CONSTRAINT IF EXISTS lead_activities_kind_check;
DELETE FROM lead_activities WHERE kind = 'proposal';
ALTER TABLE lead_activities ADD CONSTRAINT lead_activities_kind_check CHECK (kind IN ('created', 'note', 'stage', 'task', 'follow_up', 'tag', 'import'));
DROP TABLE IF EXISTS proposals, proposal_templates, portfolio_items, brand_kits CASCADE;
DROP FUNCTION IF EXISTS proposal_same_tenant();
