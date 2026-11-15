-- Phase 9a: brand kit, portfolio, proposal templates (global + own) and proposals.

CREATE TABLE brand_kits (
  id            char(26)     PRIMARY KEY,
  tenant_id     char(26)     NOT NULL REFERENCES tenants (id),
  name          varchar(80)  NOT NULL CHECK (length(btrim(name)) > 0),
  primary_color char(7)      NOT NULL DEFAULT '#1f3a5f' CHECK (primary_color ~ '^#[0-9a-f]{6}$'),
  accent_color  char(7)      NOT NULL DEFAULT '#e07a1f' CHECK (accent_color ~ '^#[0-9a-f]{6}$'),
  logo_b64      text         CHECK (logo_b64 IS NULL OR length(logo_b64) <= 820000),   -- PNG/JPEG <= 600 KB, validated in the app and again by the engine
  tone          varchar(300),
  email         varchar(120),
  phone         varchar(40),
  website       varchar(200),
  address       varchar(240),
  is_default    boolean      NOT NULL DEFAULT false,
  created_at    timestamptz  NOT NULL DEFAULT now(),
  updated_at    timestamptz  NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX brand_kits_one_default ON brand_kits (tenant_id) WHERE is_default;

CREATE TABLE portfolio_items (
  id           char(26)      PRIMARY KEY,
  tenant_id    char(26)      NOT NULL REFERENCES tenants (id),
  title        varchar(160)  NOT NULL CHECK (length(btrim(title)) > 0),
  url          varchar(300)  CHECK (url IS NULL OR url ~* '^https?://'),
  description  varchar(600),
  tags         text[]        NOT NULL DEFAULT '{}' CHECK (cardinality(tags) <= 10),
  created_at   timestamptz   NOT NULL DEFAULT now(),
  updated_at   timestamptz   NOT NULL DEFAULT now(),
  deleted_at   timestamptz
);
CREATE INDEX portfolio_items_tenant ON portfolio_items (tenant_id, created_at DESC) WHERE deleted_at IS NULL;

-- tenant_id NULL = shipped with the platform (every workspace can read, none can change).
CREATE TABLE proposal_templates (
  id          char(26)     PRIMARY KEY,
  tenant_id   char(26)     REFERENCES tenants (id),
  kind        text         NOT NULL CHECK (kind IN ('web_design', 'marketing', 'general')),
  language    text         NOT NULL CHECK (language IN ('bn', 'en')),
  name        varchar(120) NOT NULL,
  sections    jsonb        NOT NULL CHECK (jsonb_typeof(sections) = 'array'),
  created_at  timestamptz  NOT NULL DEFAULT now(),
  updated_at  timestamptz  NOT NULL DEFAULT now(),
  deleted_at  timestamptz
);
CREATE INDEX proposal_templates_tenant ON proposal_templates (tenant_id, kind, language);

CREATE TABLE proposals (
  id           char(26)     PRIMARY KEY,
  tenant_id    char(26)     NOT NULL REFERENCES tenants (id),
  lead_id      char(26)     REFERENCES leads (id),
  template_id  char(26)     REFERENCES proposal_templates (id),
  brand_kit_id char(26)     REFERENCES brand_kits (id),
  title        varchar(200) NOT NULL CHECK (length(btrim(title)) > 0),
  language     text         NOT NULL DEFAULT 'bn' CHECK (language IN ('bn', 'en')),
  status       text         NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'sent', 'accepted', 'declined')),
  data         jsonb        NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(data) = 'object' AND pg_column_size(data) < 200000),
  created_by   char(26)     REFERENCES users (id),
  created_at   timestamptz  NOT NULL DEFAULT now(),
  updated_at   timestamptz  NOT NULL DEFAULT now(),
  deleted_at   timestamptz
);
CREATE INDEX proposals_tenant ON proposals (tenant_id, updated_at DESC) WHERE deleted_at IS NULL;
CREATE INDEX proposals_lead ON proposals (lead_id) WHERE deleted_at IS NULL;

-- A proposal may only point at its own workspace's lead / brand kit, and at a global or own template.
CREATE FUNCTION proposal_same_tenant() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE t char(26); prev text := coalesce(current_setting('app.bypass_rls', true), ''); bad text := NULL;
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  IF NEW.lead_id IS NOT NULL THEN
    SELECT tenant_id INTO t FROM leads WHERE id = NEW.lead_id;
    IF t IS DISTINCT FROM NEW.tenant_id THEN bad := 'lead'; END IF;
  END IF;
  IF bad IS NULL AND NEW.brand_kit_id IS NOT NULL THEN
    SELECT tenant_id INTO t FROM brand_kits WHERE id = NEW.brand_kit_id;
    IF t IS DISTINCT FROM NEW.tenant_id THEN bad := 'brand kit'; END IF;
  END IF;
  IF bad IS NULL AND NEW.template_id IS NOT NULL THEN
    SELECT tenant_id INTO t FROM proposal_templates WHERE id = NEW.template_id;
    IF NOT FOUND OR (t IS NOT NULL AND t IS DISTINCT FROM NEW.tenant_id) THEN bad := 'template'; END IF;
  END IF;
  PERFORM set_config('app.bypass_rls', prev, true);
  IF bad IS NOT NULL THEN RAISE EXCEPTION '% belongs to another tenant', bad; END IF;
  RETURN NEW;
END $$;
REVOKE ALL ON FUNCTION proposal_same_tenant() FROM PUBLIC;
CREATE TRIGGER proposals_same_tenant BEFORE INSERT OR UPDATE OF lead_id, brand_kit_id, template_id ON proposals FOR EACH ROW EXECUTE FUNCTION proposal_same_tenant();

-- Proposal events show up in the lead's history.
ALTER TABLE lead_activities DROP CONSTRAINT lead_activities_kind_check;
ALTER TABLE lead_activities ADD CONSTRAINT lead_activities_kind_check CHECK (kind IN ('created', 'note', 'stage', 'task', 'follow_up', 'tag', 'import', 'proposal'));

-- ------------------------------------------------------------------ shipped templates (seeded before RLS is switched on)
INSERT INTO proposal_templates (id, tenant_id, kind, language, name, sections) VALUES
 (substr(md5('seed:web_design:bn'), 1, 26), NULL, 'web_design', 'bn', 'ওয়েবসাইট উন্নয়ন (বাংলা)', $j$[
  {"heading":"ভূমিকা","body":"প্রিয় {{client_name}},\n\nআপনাদের ওয়েবসাইট {{domain}} দেখার সুযোগ হয়েছে। {{brand_name}}-এর পক্ষ থেকে আমরা আপনাদের অনলাইন উপস্থিতি আরও শক্তিশালী করতে একটি প্রস্তাব দিচ্ছি।"},
  {"heading":"আপনার সাইটে চিহ্নিত সুযোগ","body":"{{gaps_list}}"},
  {"heading":"কাজের পরিধি","body":"- আধুনিক, মোবাইল-ফ্রেন্ডলি ডিজাইন\n- দ্রুত লোডিং ও মৌলিক SEO সেটআপ\n- যোগাযোগ ফর্ম, WhatsApp ও সোশ্যাল লিংক\n- Google Analytics ও Facebook Pixel সংযোগ\n- হস্তান্তরের পর ৩০ দিন বিনামূল্যে সহায়তা"},
  {"heading":"সময়সীমা","body":"কাজ শুরুর পর সাধারণত ২–৪ সপ্তাহের মধ্যে প্রথম সংস্করণ হস্তান্তর করা হবে।"},
  {"heading":"শর্তাবলি","body":"- কাজ শুরুর আগে ৫০% অগ্রিম, হস্তান্তরের সময় বাকি ৫০%\n- প্রস্তাবের মেয়াদ ১৫ দিন\n- কনটেন্ট ও ছবি আপনাদের পক্ষ থেকে সরবরাহ করতে হবে"}
 ]$j$::jsonb),
 (substr(md5('seed:web_design:en'), 1, 26), NULL, 'web_design', 'en', 'Website development (English)', $j$[
  {"heading":"Introduction","body":"Dear {{client_name}},\n\nWe had a look at {{domain}}. On behalf of {{brand_name}}, we would like to propose a stronger online presence for your business."},
  {"heading":"Opportunities we found on your site","body":"{{gaps_list}}"},
  {"heading":"Scope of work","body":"- Modern, mobile-friendly design\n- Fast loading and basic SEO setup\n- Contact form, WhatsApp and social links\n- Google Analytics and Facebook Pixel integration\n- 30 days of free support after hand-over"},
  {"heading":"Timeline","body":"The first version is usually handed over within 2–4 weeks of the start date."},
  {"heading":"Terms","body":"- 50% advance before work starts, 50% on hand-over\n- This proposal is valid for 15 days\n- Content and images are provided by you"}
 ]$j$::jsonb),
 (substr(md5('seed:marketing:bn'), 1, 26), NULL, 'marketing', 'bn', 'ডিজিটাল মার্কেটিং (বাংলা)', $j$[
  {"heading":"ভূমিকা","body":"প্রিয় {{client_name}},\n\n{{brand_name}} আপনাদের ব্যবসার জন্য একটি পরিমাপযোগ্য ডিজিটাল মার্কেটিং পরিকল্পনা প্রস্তাব করছে।"},
  {"heading":"আপনার সাইটে চিহ্নিত সুযোগ","body":"{{gaps_list}}"},
  {"heading":"আমরা যা করব","body":"- Facebook ও Instagram বিজ্ঞাপন সেটআপ ও পরিচালনা\n- Pixel ও Conversion ট্র্যাকিং ঠিক করা\n- মাসিক কনটেন্ট ক্যালেন্ডার\n- সাপ্তাহিক রিপোর্ট ও মাসিক রিভিউ"},
  {"heading":"প্রথম ৩০ দিনের পরিকল্পনা","body":"- সপ্তাহ ১: অডিট ও ট্র্যাকিং সেটআপ\n- সপ্তাহ ২: কনটেন্ট ও ক্যাম্পেইন প্রস্তুতি\n- সপ্তাহ ৩–৪: ক্যাম্পেইন চালু ও অপটিমাইজেশন"},
  {"heading":"শর্তাবলি","body":"- মাসের শুরুতে ফি পরিশোধ্য\n- বিজ্ঞাপন বাজেট ফি-এর বাইরে এবং সরাসরি আপনাদের অ্যাকাউন্ট থেকে খরচ হবে\n- ন্যূনতম চুক্তি ৩ মাস"}
 ]$j$::jsonb),
 (substr(md5('seed:marketing:en'), 1, 26), NULL, 'marketing', 'en', 'Digital marketing (English)', $j$[
  {"heading":"Introduction","body":"Dear {{client_name}},\n\n{{brand_name}} proposes a measurable digital marketing plan for your business."},
  {"heading":"Opportunities we found on your site","body":"{{gaps_list}}"},
  {"heading":"What we will do","body":"- Set up and manage Facebook and Instagram ads\n- Fix Pixel and conversion tracking\n- Monthly content calendar\n- Weekly reports and a monthly review"},
  {"heading":"First 30 days","body":"- Week 1: audit and tracking setup\n- Week 2: content and campaign preparation\n- Weeks 3–4: launch and optimisation"},
  {"heading":"Terms","body":"- Fees are payable at the start of each month\n- Ad spend is separate and billed directly to your ad account\n- Minimum term: 3 months"}
 ]$j$::jsonb),
 (substr(md5('seed:general:bn'), 1, 26), NULL, 'general', 'bn', 'সাধারণ প্রস্তাব (বাংলা)', $j$[
  {"heading":"ভূমিকা","body":"প্রিয় {{client_name}},\n\nআপনাদের সঙ্গে কাজ করার সুযোগ পেলে {{brand_name}} আনন্দিত হবে। নিচে আমাদের প্রস্তাব দেওয়া হলো।"},
  {"heading":"আপনার চাহিদা","body":"{{gaps_list}}"},
  {"heading":"আমাদের প্রস্তাব","body":"এখানে কাজের বিবরণ লিখুন।"},
  {"heading":"শর্তাবলি","body":"- প্রস্তাবের মেয়াদ ১৫ দিন\n- পেমেন্টের শর্ত: কাজ শুরুর আগে ৫০% অগ্রিম"}
 ]$j$::jsonb),
 (substr(md5('seed:general:en'), 1, 26), NULL, 'general', 'en', 'General proposal (English)', $j$[
  {"heading":"Introduction","body":"Dear {{client_name}},\n\n{{brand_name}} would be glad to work with you. Our proposal follows."},
  {"heading":"Your needs","body":"{{gaps_list}}"},
  {"heading":"Our proposal","body":"Describe the work here."},
  {"heading":"Terms","body":"- This proposal is valid for 15 days\n- Payment terms: 50% advance before work starts"}
 ]$j$::jsonb);

-- ------------------------------------------------------------------ RLS and grants
DO $$
DECLARE t text;
BEGIN
  FOREACH t IN ARRAY ARRAY['brand_kits', 'portfolio_items', 'proposals'] LOOP
    EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', t);
    EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY', t);
    EXECUTE format('CREATE POLICY tenant_isolation ON %I USING (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()))
                    WITH CHECK (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()))', t);
  END LOOP;
END $$;

ALTER TABLE proposal_templates ENABLE ROW LEVEL SECURITY;
ALTER TABLE proposal_templates FORCE ROW LEVEL SECURITY;
CREATE POLICY templates_read ON proposal_templates FOR SELECT USING (tenant_id IS NULL OR tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()));
CREATE POLICY templates_write ON proposal_templates FOR INSERT WITH CHECK (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()));
CREATE POLICY templates_update ON proposal_templates FOR UPDATE USING (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()))
  WITH CHECK (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()));

REVOKE ALL ON brand_kits, portfolio_items, proposal_templates, proposals FROM jarvis_app_rw, jarvis_admin;
GRANT SELECT, INSERT, UPDATE ON brand_kits, portfolio_items, proposal_templates, proposals TO jarvis_app_rw;
GRANT SELECT ON brand_kits, portfolio_items, proposal_templates, proposals TO jarvis_admin;
