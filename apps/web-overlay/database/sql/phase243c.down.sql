DROP FUNCTION IF EXISTS seo_audit_delete(char, char, char);
DROP FUNCTION IF EXISTS seo_audit_save(char, char, char, char, text, text, text, text, integer, integer, jsonb);
DROP FUNCTION IF EXISTS seo_audit_allowed(char);
DROP TABLE IF EXISTS seo_audits;
