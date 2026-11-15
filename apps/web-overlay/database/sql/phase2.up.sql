-- Phase 2: identity + tenancy core, with Row-Level Security.
-- Executed by the migration role (table owner). See DATABASE_SCHEMA.md §1, §3.1, §3.2.

DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'jarvis_app_rw')
     OR NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'jarvis_admin') THEN
    RAISE EXCEPTION 'roles missing: run database/sql/roles.sql first';
  END IF;
  IF NOT pg_has_role(current_user, 'jarvis_admin', 'member') THEN
    RAISE EXCEPTION 'migration role % must be a member of jarvis_admin', current_user;
  END IF;
END
$$;

CREATE EXTENSION IF NOT EXISTS citext;

-- ---------------------------------------------------------------- RLS helpers
-- Current tenant from the transaction/session setting. Returns NULL (=> no rows)
-- unless the value is exactly a 26-char alphanumeric id. The format check also
-- stops char(26) casts from silently truncating longer values.
CREATE FUNCTION app_tenant_id() RETURNS char(26)
LANGUAGE sql STABLE AS $$
  SELECT CASE WHEN v ~ '^[0-9A-Za-z]{26}$' THEN v::char(26) END
  FROM (SELECT current_setting('app.tenant_id', true) AS v) s
$$;

-- Cross-tenant access: needs BOTH the setting and DB-level membership in jarvis_admin.
-- A compromised or buggy app connection cannot grant itself this by setting a GUC.
CREATE FUNCTION app_is_admin() RETURNS boolean
LANGUAGE sql STABLE AS $$
  SELECT coalesce(current_setting('app.bypass_rls', true), '') = 'on'
     AND pg_has_role(current_user, 'jarvis_admin', 'member')
$$;

CREATE FUNCTION reject_mutation() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  RAISE EXCEPTION '% is append-only', TG_TABLE_NAME USING ERRCODE = '42501';
END
$$;

-- ---------------------------------------------------------------------- users
CREATE TABLE users (
  id                       char(26)     PRIMARY KEY,
  name                     varchar(255) NOT NULL,
  email                    citext       NOT NULL,
  email_verified_at        timestamptz,
  password                 varchar(255) NOT NULL,
  remember_token           varchar(100),
  -- Fortify's TwoFactorAuthenticatable trait reads these from the user row.
  two_factor_secret        text,
  two_factor_recovery_codes text,
  two_factor_confirmed_at  timestamptz,
  locale                   varchar(10)  NOT NULL DEFAULT 'bn',
  timezone                 varchar(64)  NOT NULL DEFAULT 'Asia/Dhaka',
  is_platform_admin        boolean      NOT NULL DEFAULT false,
  status                   text         NOT NULL DEFAULT 'active'
                           CHECK (status IN ('active', 'suspended', 'pending')),
  last_login_at            timestamptz,
  created_at               timestamptz  NOT NULL DEFAULT now(),
  updated_at               timestamptz  NOT NULL DEFAULT now(),
  deleted_at               timestamptz
);
CREATE UNIQUE INDEX users_email_active_unique ON users (email) WHERE deleted_at IS NULL;

-- Privilege-escalation guard: only an admin context may create/flip a platform admin.
CREATE FUNCTION users_guard_privileged() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF TG_OP = 'INSERT' THEN
    IF NEW.is_platform_admin AND NOT app_is_admin() THEN
      RAISE EXCEPTION 'platform admin flag requires admin context' USING ERRCODE = '42501';
    END IF;
  ELSIF NEW.is_platform_admin IS DISTINCT FROM OLD.is_platform_admin AND NOT app_is_admin() THEN
    RAISE EXCEPTION 'platform admin flag requires admin context' USING ERRCODE = '42501';
  END IF;
  RETURN NEW;
END
$$;
CREATE TRIGGER users_guard_privileged BEFORE INSERT OR UPDATE ON users
  FOR EACH ROW EXECUTE FUNCTION users_guard_privileged();

CREATE TABLE password_reset_tokens (
  email      varchar(255) PRIMARY KEY,
  token      varchar(255) NOT NULL,
  created_at timestamptz
);

CREATE TABLE sessions (
  id            varchar(255) PRIMARY KEY,
  user_id       char(26),
  ip_address    varchar(45),
  user_agent    text,
  payload       text    NOT NULL,
  last_activity integer NOT NULL
);
CREATE INDEX sessions_user_id_idx ON sessions (user_id);
CREATE INDEX sessions_last_activity_idx ON sessions (last_activity);

CREATE TABLE login_logs (
  id         bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  user_id    char(26) REFERENCES users (id),
  email      citext,
  ip         inet,
  user_agent text,
  result     text NOT NULL CHECK (result IN ('success', 'failed', '2fa_failed')),
  created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX login_logs_user_idx ON login_logs (user_id, created_at);
CREATE TRIGGER login_logs_append_only BEFORE UPDATE OR DELETE ON login_logs
  FOR EACH ROW EXECUTE FUNCTION reject_mutation();
CREATE TRIGGER login_logs_no_truncate BEFORE TRUNCATE ON login_logs
  FOR EACH STATEMENT EXECUTE FUNCTION reject_mutation();

-- -------------------------------------------------------------------- tenants
-- Global tables (no RLS): resolved BEFORE a tenant context exists. Application code
-- must always scope them through the authenticated user's memberships.
CREATE TABLE tenants (
  id          char(26)     PRIMARY KEY,
  name        varchar(255) NOT NULL,
  slug        citext       NOT NULL,
  type        text         NOT NULL CHECK (type IN ('personal', 'organization')),
  owner_id    char(26)     NOT NULL REFERENCES users (id),
  status      text         NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'suspended', 'closed')),
  locale      varchar(10)  NOT NULL DEFAULT 'bn',
  timezone    varchar(64)  NOT NULL DEFAULT 'Asia/Dhaka',
  currency    char(3)      NOT NULL DEFAULT 'BDT',
  reseller_id char(26),
  created_at  timestamptz  NOT NULL DEFAULT now(),
  updated_at  timestamptz  NOT NULL DEFAULT now(),
  deleted_at  timestamptz
);
-- Slug is unique even for soft-deleted tenants so a slug can never be re-claimed.
CREATE UNIQUE INDEX tenants_slug_unique ON tenants (slug);
CREATE INDEX tenants_owner_idx ON tenants (owner_id);
CREATE UNIQUE INDEX tenants_one_personal_per_owner
  ON tenants (owner_id) WHERE type = 'personal' AND deleted_at IS NULL;

CREATE TABLE tenant_users (
  id         char(26)    PRIMARY KEY,
  tenant_id  char(26)    NOT NULL REFERENCES tenants (id),
  user_id    char(26)    NOT NULL REFERENCES users (id),
  status     text        NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'invited', 'removed')),
  joined_at  timestamptz,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (tenant_id, user_id)
);
CREATE INDEX tenant_users_user_idx ON tenant_users (user_id);

-- ------------------------------------------------- tenant-owned tables (RLS)
CREATE TABLE tenant_settings (
  tenant_id  char(26)    NOT NULL REFERENCES tenants (id),
  key        varchar(128) NOT NULL,
  value      jsonb       NOT NULL,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY (tenant_id, key)
);

CREATE TABLE invitations (
  id         char(26)     PRIMARY KEY,
  tenant_id  char(26)     NOT NULL REFERENCES tenants (id),
  email      citext       NOT NULL,
  role       varchar(64)  NOT NULL,
  token_hash char(64)     NOT NULL UNIQUE CHECK (token_hash ~ '^[0-9a-f]{64}$'),
  invited_by char(26)     NOT NULL REFERENCES users (id),
  expires_at timestamptz  NOT NULL,
  accepted_at timestamptz,
  created_at timestamptz  NOT NULL DEFAULT now(),
  updated_at timestamptz  NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX invitations_one_pending ON invitations (tenant_id, email) WHERE accepted_at IS NULL;

CREATE TABLE audit_logs (
  id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  tenant_id    char(26) REFERENCES tenants (id),   -- NULL = platform-level event
  actor_type   varchar(64),
  actor_id     varchar(64),
  action       varchar(128) NOT NULL,
  subject_type varchar(64),
  subject_id   varchar(64),
  before       jsonb,
  after        jsonb,
  ip           inet,
  channel      varchar(32),
  created_at   timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX audit_logs_tenant_idx ON audit_logs (tenant_id, created_at);
CREATE TRIGGER audit_logs_append_only BEFORE UPDATE OR DELETE ON audit_logs
  FOR EACH ROW EXECUTE FUNCTION reject_mutation();
CREATE TRIGGER audit_logs_no_truncate BEFORE TRUNCATE ON audit_logs
  FOR EACH STATEMENT EXECUTE FUNCTION reject_mutation();

-- RLS: FORCE applies policies to the table owner too (superusers still bypass,
-- so the app must never connect as one; see `php artisan tenancy:check`).
ALTER TABLE tenant_settings ENABLE ROW LEVEL SECURITY;
ALTER TABLE tenant_settings FORCE  ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON tenant_settings
  USING      (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()))
  WITH CHECK (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()));

ALTER TABLE invitations ENABLE ROW LEVEL SECURITY;
ALTER TABLE invitations FORCE  ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON invitations
  USING      (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()))
  WITH CHECK (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()));

ALTER TABLE audit_logs ENABLE ROW LEVEL SECURITY;
ALTER TABLE audit_logs FORCE  ROW LEVEL SECURITY;
CREATE POLICY audit_read ON audit_logs FOR SELECT
  USING (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()));
-- Anyone may append a platform-level (NULL tenant) event; tenant rows need that tenant's context.
CREATE POLICY audit_insert ON audit_logs FOR INSERT
  WITH CHECK (tenant_id IS NULL OR tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()));

-- ---------------------------------------------------------- invitation flow
-- Accepting happens before the user has any tenant context, so these two
-- SECURITY DEFINER functions are the ONLY cross-tenant path for invitations.
-- Each restores app.bypass_rls to its previous value before returning.
CREATE FUNCTION invitation_preview(p_token_hash text)
RETURNS TABLE (tenant_id char(26), tenant_name varchar(255), email citext, role varchar(64),
               expires_at timestamptz, accepted_at timestamptz)
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), '');
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  RETURN QUERY
    SELECT i.tenant_id, t.name, i.email, i.role, i.expires_at, i.accepted_at
    FROM invitations i JOIN tenants t ON t.id = i.tenant_id
    WHERE i.token_hash = p_token_hash AND t.deleted_at IS NULL;
  PERFORM set_config('app.bypass_rls', prev, true);
END
$$;

CREATE FUNCTION invitation_accept(p_token_hash text, p_user_id char(26))
RETURNS TABLE (out_tenant_id char(26), out_role varchar(64))
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE
  prev  text := coalesce(current_setting('app.bypass_rls', true), '');
  inv   invitations%ROWTYPE;
  u     users%ROWTYPE;
  t     tenants%ROWTYPE;
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);

  SELECT * INTO inv FROM invitations WHERE token_hash = p_token_hash FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'invitation_invalid' USING ERRCODE = 'P0001'; END IF;
  IF inv.accepted_at IS NOT NULL THEN RAISE EXCEPTION 'invitation_used' USING ERRCODE = 'P0001'; END IF;
  IF inv.expires_at <= now() THEN RAISE EXCEPTION 'invitation_expired' USING ERRCODE = 'P0001'; END IF;

  SELECT * INTO t FROM tenants WHERE id = inv.tenant_id;
  IF NOT FOUND OR t.deleted_at IS NOT NULL OR t.status <> 'active' THEN
    RAISE EXCEPTION 'tenant_unavailable' USING ERRCODE = 'P0001';
  END IF;

  SELECT * INTO u FROM users WHERE id = p_user_id AND deleted_at IS NULL;
  IF NOT FOUND OR u.status <> 'active' THEN RAISE EXCEPTION 'user_unavailable' USING ERRCODE = 'P0001'; END IF;
  -- The invite is bound to an email address, so that address must be proven.
  IF u.email_verified_at IS NULL THEN RAISE EXCEPTION 'user_email_unverified' USING ERRCODE = 'P0001'; END IF;
  IF u.email <> inv.email THEN RAISE EXCEPTION 'invitation_email_mismatch' USING ERRCODE = 'P0001'; END IF;

  INSERT INTO tenant_users (id, tenant_id, user_id, status, joined_at)
  VALUES (substr(md5(random()::text || clock_timestamp()::text || inv.id), 1, 26),
          inv.tenant_id, u.id, 'active', now())
  ON CONFLICT (tenant_id, user_id) DO UPDATE
    SET status = 'active', joined_at = coalesce(tenant_users.joined_at, now()), updated_at = now();

  UPDATE invitations SET accepted_at = now(), updated_at = now() WHERE id = inv.id;

  PERFORM set_config('app.bypass_rls', prev, true);
  out_tenant_id := inv.tenant_id;
  out_role := inv.role;
  RETURN NEXT;
END
$$;

REVOKE ALL ON FUNCTION invitation_preview(text), invitation_accept(text, char) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION invitation_preview(text), invitation_accept(text, char) TO jarvis_app_rw;

-- ------------------------------------------------------------------- grants
GRANT SELECT, INSERT, UPDATE, DELETE ON
  users, password_reset_tokens, sessions, tenants, tenant_users, tenant_settings, invitations
  TO jarvis_app_rw;
GRANT SELECT, INSERT ON audit_logs, login_logs TO jarvis_app_rw;

-- Tables created LATER by the migration role (spatie, sanctum, jobs, ...) must be usable by the
-- app role without hand-written grants. This comes last on purpose: the append-only and
-- read-only tables above were granted explicitly and must not be widened.
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO jarvis_app_rw;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO jarvis_app_rw;
