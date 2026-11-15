-- phase32a: skills — reusable procedures the AI follows ("/slug input"). Personal to the owner; every change is a new version.

CREATE TABLE skills (
  id            char(26)      PRIMARY KEY,
  tenant_id     char(26)      NOT NULL REFERENCES tenants (id),
  user_id       char(26)      NOT NULL REFERENCES users (id),
  slug          varchar(40)   NOT NULL CHECK (slug ~ '^[^[:space:]/]{1,40}$'),
  name          varchar(80)   NOT NULL CHECK (length(btrim(name)) > 0),
  description   varchar(300)  NOT NULL DEFAULT '',
  instructions  varchar(4000) NOT NULL CHECK (length(btrim(instructions)) > 0),
  version       integer       NOT NULL DEFAULT 1,
  source        text          NOT NULL DEFAULT 'manual' CHECK (source IN ('manual', 'learned')),
  status        text          NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'archived')),
  use_count     integer       NOT NULL DEFAULT 0,
  last_used_at  timestamptz,
  created_at    timestamptz   NOT NULL DEFAULT now(),
  updated_at    timestamptz   NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX skills_slug ON skills (tenant_id, user_id, slug) WHERE status = 'active';
CREATE INDEX skills_owner ON skills (tenant_id, user_id, updated_at DESC);

-- Every saved or proposed text of a skill. A proposal is never applied until the owner approves it.
CREATE TABLE skill_versions (
  id            char(26)      PRIMARY KEY,
  skill_id      char(26)      NOT NULL REFERENCES skills (id) ON DELETE CASCADE,
  tenant_id     char(26)      NOT NULL REFERENCES tenants (id),
  version       integer       NOT NULL,
  name          varchar(80)   NOT NULL,
  description   varchar(300)  NOT NULL DEFAULT '',
  instructions  varchar(4000) NOT NULL,
  state         text          NOT NULL CHECK (state IN ('current', 'old', 'proposed', 'rejected')),
  note          varchar(300),
  created_at    timestamptz   NOT NULL DEFAULT now()
);
CREATE INDEX skill_versions_skill ON skill_versions (skill_id, version DESC);

CREATE TABLE skill_feedback (
  id          char(26)     PRIMARY KEY,
  skill_id    char(26)     NOT NULL REFERENCES skills (id) ON DELETE CASCADE,
  tenant_id   char(26)     NOT NULL REFERENCES tenants (id),
  user_id     char(26)     NOT NULL REFERENCES users (id),
  rating      smallint     NOT NULL CHECK (rating IN (-1, 1)),
  note        varchar(500),
  context     varchar(600),                                -- the start of the answer being rated
  created_at  timestamptz  NOT NULL DEFAULT now()
);
CREATE INDEX skill_feedback_skill ON skill_feedback (skill_id, created_at DESC);

DO $$
DECLARE t text;
BEGIN
  FOREACH t IN ARRAY ARRAY['skills', 'skill_versions', 'skill_feedback'] LOOP
    EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', t);
    EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY', t);
    EXECUTE format('CREATE POLICY tenant_isolation ON %I USING (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()))
                    WITH CHECK (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()))', t);
    EXECUTE format('REVOKE ALL ON %I FROM jarvis_app_rw, jarvis_admin', t);
  END LOOP;
END $$;

GRANT SELECT, INSERT, DELETE ON skills TO jarvis_app_rw;
GRANT UPDATE (slug, name, description, instructions, version, source, status, use_count, last_used_at, updated_at) ON skills TO jarvis_app_rw;
GRANT SELECT ON skills TO jarvis_admin;
GRANT SELECT, INSERT ON skill_versions TO jarvis_app_rw;
GRANT UPDATE (state) ON skill_versions TO jarvis_app_rw;
GRANT SELECT, INSERT ON skill_feedback TO jarvis_app_rw;
GRANT SELECT ON skill_versions, skill_feedback TO jarvis_admin;
