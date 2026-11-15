-- Phase 8a: lead research jobs + the lead/company/contact core. Tenant-isolated by RLS.
-- Duplicates are prevented by the database (unique indexes), not only by application code.

CREATE TABLE lead_research_jobs (
  id           char(26)     PRIMARY KEY,
  tenant_id    char(26)     NOT NULL REFERENCES tenants (id),
  user_id      char(26)     NOT NULL REFERENCES users (id),
  name         varchar(120) NOT NULL,
  input_type   text         NOT NULL CHECK (input_type IN ('url', 'csv')),
  status       text         NOT NULL DEFAULT 'queued' CHECK (status IN ('queued', 'running', 'done', 'cancelled')),
  total        integer      NOT NULL DEFAULT 0 CHECK (total >= 0),
  options      jsonb        NOT NULL DEFAULT '{}'::jsonb,
  created_at   timestamptz  NOT NULL DEFAULT now(),
  started_at   timestamptz,
  finished_at  timestamptz
);
CREATE INDEX lead_research_jobs_recent ON lead_research_jobs (tenant_id, created_at DESC);

CREATE TABLE companies (
  id           char(26)     PRIMARY KEY,
  tenant_id    char(26)     NOT NULL REFERENCES tenants (id),
  name         varchar(200) NOT NULL,
  domain       varchar(253) NOT NULL CHECK (domain = lower(domain) AND domain !~ '\s' AND domain !~ '^www\.'),
  website      varchar(2048),
  description  varchar(500),
  address      varchar(300),
  industry     varchar(100),
  size         varchar(40),
  analysis     jsonb        NOT NULL DEFAULT '{}'::jsonb,       -- gaps, tech, tracking, social: what the crawler saw
  score        smallint     CHECK (score BETWEEN 0 AND 100),
  analyzed_at  timestamptz,
  created_at   timestamptz  NOT NULL DEFAULT now(),
  updated_at   timestamptz  NOT NULL DEFAULT now(),
  deleted_at   timestamptz
);
CREATE UNIQUE INDEX companies_tenant_domain ON companies (tenant_id, domain) WHERE deleted_at IS NULL;

CREATE TABLE contacts (
  id            char(26)     PRIMARY KEY,
  tenant_id     char(26)     NOT NULL REFERENCES tenants (id),
  company_id    char(26)     REFERENCES companies (id),
  name          varchar(200),
  email         varchar(254) CHECK (email IS NULL OR email = lower(email)),
  email_status  text         NOT NULL DEFAULT 'unverified' CHECK (email_status IN ('unverified', 'syntax_ok', 'mx_ok', 'no_mx', 'bounced', 'unsubscribed')),
  phone_e164    varchar(20)  CHECK (phone_e164 IS NULL OR phone_e164 ~ '^\+[1-9][0-9]{6,14}$'),
  phone_type    varchar(10),
  role          varchar(100),
  source_url    varchar(2048),
  collected_at  timestamptz  NOT NULL DEFAULT now(),
  CHECK (email IS NOT NULL OR phone_e164 IS NOT NULL)
);
CREATE UNIQUE INDEX contacts_tenant_email ON contacts (tenant_id, email) WHERE email IS NOT NULL;
CREATE UNIQUE INDEX contacts_company_phone ON contacts (tenant_id, company_id, phone_e164) WHERE phone_e164 IS NOT NULL AND company_id IS NOT NULL;
CREATE INDEX contacts_company ON contacts (company_id);

CREATE TABLE leads (
  id                 char(26)     PRIMARY KEY,
  tenant_id          char(26)     NOT NULL REFERENCES tenants (id),
  company_id         char(26)     NOT NULL REFERENCES companies (id),
  stage              text         NOT NULL DEFAULT 'new' CHECK (stage IN ('new', 'contacted', 'qualified', 'proposal', 'won', 'lost')),
  score              smallint     NOT NULL DEFAULT 0 CHECK (score BETWEEN 0 AND 100),
  owner_id           char(26)     REFERENCES users (id),
  source             varchar(40)  NOT NULL DEFAULT 'research',
  job_id             char(26)     REFERENCES lead_research_jobs (id),
  next_follow_up_at  timestamptz,
  tags               text[]       NOT NULL DEFAULT '{}',
  custom_fields      jsonb        NOT NULL DEFAULT '{}'::jsonb,
  created_at         timestamptz  NOT NULL DEFAULT now(),
  updated_at         timestamptz  NOT NULL DEFAULT now(),
  deleted_at         timestamptz
);
-- one live lead per company per tenant
CREATE UNIQUE INDEX leads_tenant_company ON leads (tenant_id, company_id) WHERE deleted_at IS NULL;
CREATE INDEX leads_board ON leads (tenant_id, stage, score DESC) WHERE deleted_at IS NULL;

CREATE TABLE lead_research_items (
  id           char(26)      PRIMARY KEY,
  tenant_id    char(26)      NOT NULL REFERENCES tenants (id),
  job_id       char(26)      NOT NULL REFERENCES lead_research_jobs (id),
  url          varchar(2048) NOT NULL,
  domain       varchar(253)  NOT NULL,
  status       text          NOT NULL DEFAULT 'queued' CHECK (status IN ('queued', 'running', 'done', 'failed', 'skipped')),
  result_code  varchar(40),                                    -- ok | blocked_by_robots | unreachable | unsafe_url | duplicate | ...
  company_id   char(26)      REFERENCES companies (id),
  attempts     smallint      NOT NULL DEFAULT 0,
  started_at   timestamptz,
  finished_at  timestamptz,
  UNIQUE (job_id, domain)
);
CREATE INDEX lead_research_items_job ON lead_research_items (job_id, status);

-- Rows must not point at another tenant's parents (RLS alone would let a buggy insert mix tenants).
CREATE FUNCTION lead_same_tenant() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE c char(26); j char(26); prev text := coalesce(current_setting('app.bypass_rls', true), '');
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  IF TG_TABLE_NAME IN ('contacts', 'leads', 'lead_research_items') THEN
    IF TG_TABLE_NAME = 'lead_research_items' THEN
      SELECT tenant_id INTO j FROM lead_research_jobs WHERE id = NEW.job_id;
      IF j IS DISTINCT FROM NEW.tenant_id THEN PERFORM set_config('app.bypass_rls', prev, true); RAISE EXCEPTION 'job belongs to another tenant'; END IF;
    END IF;
    IF NEW.company_id IS NOT NULL THEN
      SELECT tenant_id INTO c FROM companies WHERE id = NEW.company_id;
      IF c IS DISTINCT FROM NEW.tenant_id THEN PERFORM set_config('app.bypass_rls', prev, true); RAISE EXCEPTION 'company belongs to another tenant'; END IF;
    END IF;
  END IF;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN NEW;
END $$;
REVOKE ALL ON FUNCTION lead_same_tenant() FROM PUBLIC;
CREATE TRIGGER contacts_same_tenant BEFORE INSERT OR UPDATE OF company_id ON contacts FOR EACH ROW EXECUTE FUNCTION lead_same_tenant();
CREATE TRIGGER leads_same_tenant BEFORE INSERT OR UPDATE OF company_id ON leads FOR EACH ROW EXECUTE FUNCTION lead_same_tenant();
CREATE TRIGGER items_same_tenant BEFORE INSERT OR UPDATE OF company_id, job_id ON lead_research_items FOR EACH ROW EXECUTE FUNCTION lead_same_tenant();

DO $$
DECLARE t text;
BEGIN
  FOREACH t IN ARRAY ARRAY['lead_research_jobs', 'lead_research_items', 'companies', 'contacts', 'leads'] LOOP
    EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', t);
    EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY', t);
    EXECUTE format('CREATE POLICY tenant_isolation ON %I USING (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()))
                    WITH CHECK (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()))', t);
  END LOOP;
END $$;

-- The app may read/write all of it; nothing is hard-deleted (companies/leads use deleted_at), contacts and items are kept.
REVOKE ALL ON lead_research_jobs, lead_research_items, companies, contacts, leads FROM jarvis_app_rw, jarvis_admin;
GRANT SELECT, INSERT, UPDATE ON lead_research_jobs, lead_research_items, companies, contacts, leads TO jarvis_app_rw;
GRANT DELETE ON contacts TO jarvis_app_rw;                      -- a contact can be removed on request (privacy)
GRANT SELECT ON lead_research_jobs, lead_research_items, companies, contacts, leads TO jarvis_admin;

-- Items stuck in 'running' (worker died): put them back so the job can finish.
CREATE FUNCTION lead_items_requeue_stale(p_older_than_seconds integer DEFAULT 900) RETURNS integer
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE n integer; prev text := coalesce(current_setting('app.bypass_rls', true), '');
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE lead_research_items SET status = CASE WHEN attempts >= 3 THEN 'failed' ELSE 'queued' END,
         result_code = CASE WHEN attempts >= 3 THEN 'worker_lost' ELSE result_code END,
         finished_at = CASE WHEN attempts >= 3 THEN now() ELSE finished_at END
   WHERE status = 'running' AND started_at < now() - make_interval(secs => p_older_than_seconds);
  GET DIAGNOSTICS n = ROW_COUNT;
  -- a job whose last open item just failed would otherwise stay 'running' forever
  UPDATE lead_research_jobs j SET status = 'done', finished_at = now()
   WHERE j.status = 'running' AND NOT EXISTS (SELECT 1 FROM lead_research_items i WHERE i.job_id = j.id AND i.status IN ('queued', 'running'));
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN n;
END $$;
REVOKE ALL ON FUNCTION lead_items_requeue_stale(integer) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION lead_items_requeue_stale(integer) TO jarvis_app_rw;
