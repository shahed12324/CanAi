-- Run ONCE per cluster by a role with CREATEROLE (DBA / docker init). Idempotent.
-- Group roles only. Login roles are created by the deployer and added to these groups:
--   migration/owner login  -> owns the tables, MUST be a member of jarvis_admin
--   web/queue app login    -> member of jarvis_app_rw ONLY (never superuser, never BYPASSRLS)
--   admin-panel login      -> member of jarvis_app_rw AND jarvis_admin
DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'jarvis_app_rw') THEN
    CREATE ROLE jarvis_app_rw NOLOGIN NOSUPERUSER NOBYPASSRLS;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'jarvis_admin') THEN
    CREATE ROLE jarvis_admin NOLOGIN NOSUPERUSER NOBYPASSRLS;
  END IF;
END
$$;
