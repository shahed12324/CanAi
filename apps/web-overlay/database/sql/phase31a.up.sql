-- phase31a: scheduled AI jobs (natural-language schedule + a prompt the AI runs for the owner on time).

CREATE TABLE scheduled_jobs (
  id            char(26)     PRIMARY KEY,
  tenant_id     char(26)     NOT NULL REFERENCES tenants (id),
  user_id       char(26)     NOT NULL REFERENCES users (id),
  title         varchar(120) NOT NULL CHECK (length(btrim(title)) > 0),
  prompt        varchar(1500) NOT NULL CHECK (length(btrim(prompt)) > 0),
  schedule      jsonb        NOT NULL,                      -- the parsed spec (kind, time, weekday, dom, interval, date)
  schedule_text varchar(200) NOT NULL,                      -- what the person typed
  tz            varchar(40)  NOT NULL DEFAULT 'Asia/Dhaka',
  deliver       text         NOT NULL DEFAULT 'both' CHECK (deliver IN ('inbox', 'channel', 'both')),
  continuity    boolean      NOT NULL DEFAULT false,        -- true: every run continues the same chat
  conversation_id char(26)   REFERENCES conversations (id),
  status        text         NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'paused', 'done')),
  next_run_at   timestamptz,
  last_run_at   timestamptz,
  last_status   text,
  run_count     integer      NOT NULL DEFAULT 0,
  fail_streak   integer      NOT NULL DEFAULT 0,
  created_at    timestamptz  NOT NULL DEFAULT now(),
  updated_at    timestamptz  NOT NULL DEFAULT now()
);
CREATE INDEX scheduled_jobs_due ON scheduled_jobs (next_run_at) WHERE status = 'active';
CREATE INDEX scheduled_jobs_owner ON scheduled_jobs (tenant_id, user_id, created_at DESC);

CREATE TABLE scheduled_job_runs (
  id          char(26)    PRIMARY KEY,
  job_id      char(26)    NOT NULL REFERENCES scheduled_jobs (id) ON DELETE CASCADE,
  tenant_id   char(26)    NOT NULL REFERENCES tenants (id),
  started_at  timestamptz NOT NULL DEFAULT now(),
  status      text        NOT NULL CHECK (status IN ('ok', 'error')),
  error       varchar(300),
  output      varchar(8000),
  credits     varchar(20),
  delivered   text        NOT NULL DEFAULT ''
);
CREATE INDEX scheduled_job_runs_job ON scheduled_job_runs (job_id, started_at DESC);

DO $$
DECLARE t text;
BEGIN
  FOREACH t IN ARRAY ARRAY['scheduled_jobs', 'scheduled_job_runs'] LOOP
    EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', t);
    EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY', t);
    EXECUTE format('CREATE POLICY tenant_isolation ON %I USING (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()))
                    WITH CHECK (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()))', t);
    EXECUTE format('REVOKE ALL ON %I FROM jarvis_app_rw, jarvis_admin', t);
  END LOOP;
END $$;

GRANT SELECT, INSERT, DELETE ON scheduled_jobs TO jarvis_app_rw;
GRANT UPDATE (title, prompt, schedule, schedule_text, tz, deliver, continuity, conversation_id, status, next_run_at, last_run_at, last_status, run_count, fail_streak, updated_at) ON scheduled_jobs TO jarvis_app_rw;
GRANT SELECT, UPDATE ON scheduled_jobs TO jarvis_admin;
GRANT SELECT, INSERT ON scheduled_job_runs TO jarvis_app_rw;
GRANT SELECT, INSERT, DELETE ON scheduled_job_runs TO jarvis_admin;
