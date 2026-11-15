ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE ALL ON TABLES FROM jarvis_app_rw;
ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE ALL ON SEQUENCES FROM jarvis_app_rw;
DROP FUNCTION IF EXISTS invitation_accept(text, char);
DROP FUNCTION IF EXISTS invitation_preview(text);
DROP TABLE IF EXISTS audit_logs, invitations, tenant_settings, tenant_users, tenants,
  login_logs, sessions, password_reset_tokens, users CASCADE;
DROP FUNCTION IF EXISTS users_guard_privileged();
DROP FUNCTION IF EXISTS reject_mutation();
DROP FUNCTION IF EXISTS app_is_admin();
DROP FUNCTION IF EXISTS app_tenant_id();
