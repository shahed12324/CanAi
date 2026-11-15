-- Phase 12a: native product catalog — stores, products, variants, stock ledger, Draft -> Approval -> Publish.
-- Rules kept in the database so no screen, import or sync can skip them:
--   * a product reaches 'published' only through catalog_submit -> catalog_decide(approve) -> catalog_publish, each by a named user;
--   * changing a pending/approved/published product's content sends it back to draft (the last published copy stays live);
--   * stock changes only through stock_adjust (append-only ledger, never negative);
--   * while the workspace is on emergency stop nothing can be approved or published.

CREATE TABLE store_connections (
  id               char(26)     PRIMARY KEY,
  tenant_id        char(26)     NOT NULL REFERENCES tenants (id),
  platform         text         NOT NULL CHECK (platform IN ('native', 'woocommerce', 'shopify', 'jarvis_connector')),
  name             varchar(120) NOT NULL,
  base_url         varchar(300),
  credentials_ct   text,                                                   -- vault ciphertext (12b); never plain
  key_version      smallint,
  status           text         NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'paused', 'error')),
  last_sync_at     timestamptz,
  last_error       varchar(200),
  created_at       timestamptz  NOT NULL DEFAULT now(),
  UNIQUE (id, tenant_id),
  CONSTRAINT store_shape CHECK ((platform = 'native' AND base_url IS NULL AND credentials_ct IS NULL)
                             OR (platform <> 'native' AND base_url IS NOT NULL AND credentials_ct IS NOT NULL))
);
CREATE UNIQUE INDEX store_connections_one_native ON store_connections (tenant_id) WHERE platform = 'native';

CREATE TABLE products (
  id               char(26)     PRIMARY KEY,
  tenant_id        char(26)     NOT NULL REFERENCES tenants (id),
  store_id         char(26)     NOT NULL,
  external_id      varchar(80),                                            -- id in WooCommerce / Shopify (12b)
  title            varchar(250) NOT NULL CHECK (btrim(title) <> ''),
  description      text         NOT NULL DEFAULT '' CHECK (length(description) <= 20000),
  status           text         NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'pending_approval', 'approved', 'published', 'archived')),
  price_minor      bigint       NOT NULL DEFAULT 0 CHECK (price_minor >= 0 AND price_minor <= 100000000000),
  sale_price_minor bigint,
  currency         char(3)      NOT NULL DEFAULT 'BDT' CHECK (currency ~ '^[A-Z]{3}$'),
  sku              varchar(80),
  category         varchar(120),
  tags             text[]       NOT NULL DEFAULT '{}' CHECK (cardinality(tags) <= 30),
  images           jsonb        NOT NULL DEFAULT '[]'::jsonb CHECK (jsonb_typeof(images) = 'array' AND jsonb_array_length(images) <= 10),
  seo              jsonb        NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(seo) = 'object'),
  ai_generated     boolean      NOT NULL DEFAULT false,                    -- the description came from the AI writer (still needs a human approval like any other)
  review_note      varchar(300),
  created_by       char(26)     REFERENCES users (id),
  submitted_by     char(26)     REFERENCES users (id),
  submitted_at     timestamptz,
  approved_by      char(26)     REFERENCES users (id),
  approved_at      timestamptz,
  published_by     char(26)     REFERENCES users (id),
  published_at     timestamptz,
  live             jsonb,                                                  -- the copy customers see: written only by catalog_publish
  created_at       timestamptz  NOT NULL DEFAULT now(),
  updated_at       timestamptz  NOT NULL DEFAULT now(),
  deleted_at       timestamptz,
  UNIQUE (id, tenant_id),
  FOREIGN KEY (store_id, tenant_id) REFERENCES store_connections (id, tenant_id),
  CONSTRAINT products_sale_below_price CHECK (sale_price_minor IS NULL OR (sale_price_minor >= 0 AND sale_price_minor < price_minor)),
  CONSTRAINT products_needs_approval CHECK (status NOT IN ('approved', 'published') OR (approved_by IS NOT NULL AND approved_at IS NOT NULL)),
  CONSTRAINT products_published_has_live CHECK (status <> 'published' OR (live IS NOT NULL AND published_at IS NOT NULL AND published_by IS NOT NULL))
);
CREATE UNIQUE INDEX products_sku_per_store ON products (store_id, lower(sku)) WHERE sku IS NOT NULL AND deleted_at IS NULL;
CREATE UNIQUE INDEX products_external ON products (store_id, external_id) WHERE external_id IS NOT NULL;
CREATE INDEX products_list ON products (tenant_id, status, updated_at DESC) WHERE deleted_at IS NULL;

CREATE TABLE product_variants (
  id               char(26)     PRIMARY KEY,
  tenant_id        char(26)     NOT NULL REFERENCES tenants (id),
  product_id       char(26)     NOT NULL,
  sku              varchar(80),
  options          jsonb        NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(options) = 'object'),   -- {"Size":"M","Color":"Red"}; {} = the only variant
  price_minor      bigint       CHECK (price_minor IS NULL OR price_minor >= 0),                       -- NULL = the product's price
  stock_qty        integer      NOT NULL DEFAULT 0 CHECK (stock_qty >= 0),                              -- changed only by stock_adjust
  track_stock      boolean      NOT NULL DEFAULT true,
  external_id      varchar(80),
  archived_at      timestamptz,
  created_at       timestamptz  NOT NULL DEFAULT now(),
  UNIQUE (id, tenant_id),
  FOREIGN KEY (product_id, tenant_id) REFERENCES products (id, tenant_id)
);
CREATE UNIQUE INDEX product_variants_options ON product_variants (product_id, options) WHERE archived_at IS NULL;
CREATE UNIQUE INDEX product_variants_sku ON product_variants (tenant_id, lower(sku)) WHERE sku IS NOT NULL AND archived_at IS NULL;
CREATE INDEX product_variants_product ON product_variants (product_id) WHERE archived_at IS NULL;

CREATE TABLE stock_movements (
  seq          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  tenant_id    char(26)     NOT NULL REFERENCES tenants (id),
  variant_id   char(26)     NOT NULL,
  delta        integer      NOT NULL CHECK (delta <> 0),
  qty_after    integer      NOT NULL CHECK (qty_after >= 0),
  reason       text         NOT NULL CHECK (reason IN ('initial', 'adjust', 'sale', 'return', 'import', 'sync', 'correction')),
  note         varchar(200),
  user_id      char(26)     REFERENCES users (id),
  created_at   timestamptz  NOT NULL DEFAULT now(),
  FOREIGN KEY (variant_id, tenant_id) REFERENCES product_variants (id, tenant_id)
);
CREATE INDEX stock_movements_variant ON stock_movements (variant_id, seq DESC);

CREATE TABLE product_events (
  seq          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  tenant_id    char(26)     NOT NULL REFERENCES tenants (id),
  product_id   char(26)     NOT NULL,
  action       text         NOT NULL CHECK (action IN ('submitted', 'approved', 'rejected', 'published', 'archived', 'restored')),
  user_id      char(26)     REFERENCES users (id),
  note         varchar(300),
  created_at   timestamptz  NOT NULL DEFAULT now(),
  FOREIGN KEY (product_id, tenant_id) REFERENCES products (id, tenant_id)
);
CREATE INDEX product_events_product ON product_events (product_id, seq DESC);

CREATE TABLE catalog_imports (
  id          char(26)     PRIMARY KEY,
  tenant_id   char(26)     NOT NULL REFERENCES tenants (id),
  user_id     char(26)     NOT NULL REFERENCES users (id),
  filename    varchar(200) NOT NULL,
  total       integer      NOT NULL DEFAULT 0,
  created     integer      NOT NULL DEFAULT 0,
  updated     integer      NOT NULL DEFAULT 0,
  failed      integer      NOT NULL DEFAULT 0,
  errors      jsonb        NOT NULL DEFAULT '[]'::jsonb CHECK (jsonb_typeof(errors) = 'array' AND jsonb_array_length(errors) <= 100),
  created_at  timestamptz  NOT NULL DEFAULT now()
);
CREATE INDEX catalog_imports_recent ON catalog_imports (tenant_id, created_at DESC);

-- Append-only logs.
CREATE FUNCTION catalog_log_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION '% is append-only', TG_TABLE_NAME; END $$;
CREATE TRIGGER stock_movements_no_change BEFORE UPDATE OR DELETE ON stock_movements FOR EACH ROW EXECUTE FUNCTION catalog_log_immutable();
CREATE TRIGGER product_events_no_change BEFORE UPDATE OR DELETE ON product_events FOR EACH ROW EXECUTE FUNCTION catalog_log_immutable();

-- Editing the content of a product that is waiting / approved / live sends it back to draft (the live copy is untouched).
CREATE FUNCTION products_edit_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  NEW.updated_at := now();
  IF NEW.status = OLD.status AND (NEW.title, NEW.description, NEW.price_minor, NEW.sale_price_minor, NEW.currency, NEW.sku, NEW.category, NEW.tags, NEW.images, NEW.seo)
        IS DISTINCT FROM (OLD.title, OLD.description, OLD.price_minor, OLD.sale_price_minor, OLD.currency, OLD.sku, OLD.category, OLD.tags, OLD.images, OLD.seo) THEN
    IF OLD.status = 'archived' THEN RAISE EXCEPTION 'archived product cannot be edited' USING ERRCODE = 'P0001'; END IF;
    IF OLD.status IN ('pending_approval', 'approved', 'published') THEN NEW.status := 'draft'; END IF;
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER products_edit_guard BEFORE UPDATE ON products FOR EACH ROW EXECUTE FUNCTION products_edit_guard();

-- A variant change on a pending / approved / live product also needs a fresh approval.
CREATE FUNCTION product_variants_touch() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), '');
BEGIN
  IF TG_OP = 'UPDATE' AND (NEW.sku, NEW.options, NEW.price_minor, NEW.archived_at) IS NOT DISTINCT FROM (OLD.sku, OLD.options, OLD.price_minor, OLD.archived_at) THEN
    RETURN NEW;                                                            -- stock-only change: no re-approval
  END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE products SET status = 'draft' WHERE id = NEW.product_id AND tenant_id = NEW.tenant_id AND status IN ('pending_approval', 'approved', 'published');
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN NEW;
END $$;
CREATE TRIGGER product_variants_touch AFTER INSERT OR UPDATE ON product_variants FOR EACH ROW EXECUTE FUNCTION product_variants_touch();

DO $$
DECLARE t text;
BEGIN
  FOREACH t IN ARRAY ARRAY['store_connections', 'products', 'product_variants', 'stock_movements', 'product_events', 'catalog_imports'] LOOP
    EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', t);
    EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY', t);
    EXECUTE format('CREATE POLICY tenant_isolation ON %I USING (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()))
                    WITH CHECK (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()))', t);
    EXECUTE format('REVOKE ALL ON %I FROM jarvis_app_rw, jarvis_admin', t);
    EXECUTE format('GRANT SELECT ON %I TO jarvis_app_rw, jarvis_admin', t);
  END LOOP;
END $$;

-- The app edits content; it cannot touch workflow columns, the live copy or stock directly.
GRANT INSERT, UPDATE ON store_connections TO jarvis_app_rw;
GRANT INSERT (id, tenant_id, store_id, external_id, title, description, price_minor, sale_price_minor, currency, sku, category, tags, images, seo, ai_generated, created_by)
  ON products TO jarvis_app_rw;
GRANT UPDATE (external_id, title, description, price_minor, sale_price_minor, currency, sku, category, tags, images, seo, ai_generated, deleted_at) ON products TO jarvis_app_rw;
GRANT INSERT (id, tenant_id, product_id, sku, options, price_minor, track_stock, external_id) ON product_variants TO jarvis_app_rw;
GRANT UPDATE (sku, options, price_minor, track_stock, external_id, archived_at) ON product_variants TO jarvis_app_rw;
GRANT INSERT ON catalog_imports TO jarvis_app_rw;
GRANT UPDATE (total, created, updated, failed, errors) ON catalog_imports TO jarvis_app_rw;

-- ---------------------------------------------------------------------------------------------------------------------
-- Stock: the only way a quantity changes. Returns the new quantity.
CREATE FUNCTION stock_adjust(p_variant char(26), p_delta integer, p_reason text, p_note text DEFAULT NULL, p_user char(26) DEFAULT NULL) RETURNS integer
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); v product_variants%ROWTYPE; q integer;
BEGIN
  IF p_delta = 0 THEN RAISE EXCEPTION 'zero_delta' USING ERRCODE = 'P0001'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO v FROM product_variants WHERE id = p_variant;
  IF NOT FOUND THEN PERFORM set_config('app.bypass_rls', prev, true); RAISE EXCEPTION 'forbidden' USING ERRCODE = '42501'; END IF;
  PERFORM set_config('app.bypass_rls', prev, true);
  PERFORM credit_authorize(v.tenant_id, false);
  PERFORM set_config('app.bypass_rls', 'on', true);
  IF v.archived_at IS NOT NULL THEN PERFORM set_config('app.bypass_rls', prev, true); RAISE EXCEPTION 'variant_archived' USING ERRCODE = 'P0001'; END IF;
  IF NOT v.track_stock THEN PERFORM set_config('app.bypass_rls', prev, true); RAISE EXCEPTION 'not_tracked' USING ERRCODE = 'P0001'; END IF;
  UPDATE product_variants SET stock_qty = stock_qty + p_delta WHERE id = p_variant AND stock_qty + p_delta >= 0 RETURNING stock_qty INTO q;
  IF NOT FOUND THEN PERFORM set_config('app.bypass_rls', prev, true); RAISE EXCEPTION 'insufficient_stock' USING ERRCODE = 'P0001'; END IF;
  INSERT INTO stock_movements (tenant_id, variant_id, delta, qty_after, reason, note, user_id) VALUES (v.tenant_id, p_variant, p_delta, q, p_reason, left(p_note, 200), p_user);
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN q;
END $$;

-- ---------------------------------------------------------------------------------------------------------------------
-- Workflow. catalog_apply_decision is shared by the web (catalog_decide) and the phone (mobile_decide); it does no authorisation itself.
CREATE FUNCTION catalog_apply_decision(p_tenant char(26), p_product char(26), p_user char(26), p_decision text, p_note text) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); n integer;
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  IF p_decision = 'approve' AND emergency_active(p_tenant) THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'emergency'; END IF;
  IF p_decision = 'approve' THEN
    UPDATE products SET status = 'approved', approved_by = p_user, approved_at = now(), review_note = NULL
     WHERE id = p_product AND tenant_id = p_tenant AND status = 'pending_approval' AND deleted_at IS NULL;
  ELSE
    UPDATE products SET status = 'draft', review_note = left(p_note, 300)
     WHERE id = p_product AND tenant_id = p_tenant AND status = 'pending_approval' AND deleted_at IS NULL;
  END IF;
  GET DIAGNOSTICS n = ROW_COUNT;
  IF n = 1 THEN
    INSERT INTO product_events (tenant_id, product_id, action, user_id, note) VALUES (p_tenant, p_product, CASE WHEN p_decision = 'approve' THEN 'approved' ELSE 'rejected' END, p_user, left(p_note, 300));
  END IF;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN n = 0 THEN 'not_pending' WHEN p_decision = 'approve' THEN 'approved' ELSE 'rejected' END;
END $$;

-- Loads a product for the caller's workspace or raises.
CREATE FUNCTION catalog_guard(p_product char(26)) RETURNS products LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); p products%ROWTYPE;
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO p FROM products WHERE id = p_product AND deleted_at IS NULL;
  PERFORM set_config('app.bypass_rls', prev, true);
  IF NOT FOUND THEN RAISE EXCEPTION 'forbidden' USING ERRCODE = '42501'; END IF;
  PERFORM credit_authorize(p.tenant_id, false);
  RETURN p;
END $$;

-- draft -> pending_approval. Returns submitted | not_draft | incomplete:<what is missing>
CREATE FUNCTION catalog_submit(p_product char(26), p_user char(26)) RETURNS text LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); p products%ROWTYPE; nv integer; n integer;
BEGIN
  p := catalog_guard(p_product);
  IF p.status <> 'draft' THEN RETURN 'not_draft'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT count(*) INTO nv FROM product_variants WHERE product_id = p.id AND archived_at IS NULL;
  PERFORM set_config('app.bypass_rls', prev, true);
  IF btrim(p.description) = '' THEN RETURN 'incomplete:description'; END IF;
  IF p.price_minor <= 0 THEN RETURN 'incomplete:price'; END IF;
  IF nv = 0 THEN RETURN 'incomplete:variant'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE products SET status = 'pending_approval', submitted_by = p_user, submitted_at = now(), review_note = NULL WHERE id = p.id AND status = 'draft';
  GET DIAGNOSTICS n = ROW_COUNT;
  IF n = 1 THEN INSERT INTO product_events (tenant_id, product_id, action, user_id) VALUES (p.tenant_id, p.id, 'submitted', p_user); END IF;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN n = 1 THEN 'submitted' ELSE 'not_draft' END;
END $$;

-- Returns approved | rejected | not_pending | emergency
CREATE FUNCTION catalog_decide(p_product char(26), p_user char(26), p_decision text, p_note text DEFAULT NULL) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE p products%ROWTYPE;
BEGIN
  IF p_decision NOT IN ('approve', 'reject') THEN RAISE EXCEPTION 'bad_decision' USING ERRCODE = 'P0001'; END IF;
  p := catalog_guard(p_product);
  RETURN catalog_apply_decision(p.tenant_id, p.id, p_user, p_decision, p_note);
END $$;

-- approved -> published, copying what customers will see. Returns published | not_approved | emergency
CREATE FUNCTION catalog_publish(p_product char(26), p_user char(26)) RETURNS text LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); p products%ROWTYPE; n integer;
BEGIN
  p := catalog_guard(p_product);
  PERFORM set_config('app.bypass_rls', 'on', true);
  IF emergency_active(p.tenant_id) THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'emergency'; END IF;
  UPDATE products SET status = 'published', published_by = p_user, published_at = now(),
         live = jsonb_build_object('title', title, 'description', description, 'price_minor', price_minor, 'sale_price_minor', sale_price_minor,
                  'currency', currency, 'sku', sku, 'category', category, 'tags', to_jsonb(tags), 'images', images, 'seo', seo,
                  'variants', coalesce((SELECT jsonb_agg(jsonb_build_object('id', v.id, 'sku', v.sku, 'options', v.options, 'price_minor', v.price_minor) ORDER BY v.created_at, v.id)
                                          FROM product_variants v WHERE v.product_id = products.id AND v.archived_at IS NULL), '[]'::jsonb))
   WHERE id = p.id AND status = 'approved';
  GET DIAGNOSTICS n = ROW_COUNT;
  IF n = 1 THEN INSERT INTO product_events (tenant_id, product_id, action, user_id) VALUES (p.tenant_id, p.id, 'published', p_user); END IF;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN n = 1 THEN 'published' ELSE 'not_approved' END;
END $$;

-- Takes a product off sale (the live copy is removed). Returns archived | already
CREATE FUNCTION catalog_archive(p_product char(26), p_user char(26)) RETURNS text LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); p products%ROWTYPE; n integer;
BEGIN
  p := catalog_guard(p_product);
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE products SET status = 'archived', live = NULL WHERE id = p.id AND status <> 'archived';
  GET DIAGNOSTICS n = ROW_COUNT;
  IF n = 1 THEN INSERT INTO product_events (tenant_id, product_id, action, user_id) VALUES (p.tenant_id, p.id, 'archived', p_user); END IF;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN n = 1 THEN 'archived' ELSE 'already' END;
END $$;

-- archived -> draft (needs approval again before it can be sold). Returns restored | not_archived
CREATE FUNCTION catalog_restore(p_product char(26), p_user char(26)) RETURNS text LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); p products%ROWTYPE; n integer;
BEGIN
  p := catalog_guard(p_product);
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE products SET status = 'draft' WHERE id = p.id AND status = 'archived';
  GET DIAGNOSTICS n = ROW_COUNT;
  IF n = 1 THEN INSERT INTO product_events (tenant_id, product_id, action, user_id) VALUES (p.tenant_id, p.id, 'restored', p_user); END IF;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN n = 1 THEN 'restored' ELSE 'not_archived' END;
END $$;

-- ---------------------------------------------------------------------------------------------------------------------
-- The phone's Approval Inbox learns about products (replaces the phase 11 definitions; outreach behaviour is unchanged).
CREATE OR REPLACE FUNCTION mobile_approvals(p_limit integer DEFAULT 50)
RETURNS TABLE (kind text, ref char(26), title text, summary text, created_at timestamptz) LANGUAGE sql STABLE AS $$
  SELECT * FROM (
    SELECT 'outreach_message'::text AS kind, m.id AS ref, m.subject::text AS title,
           ('To ' || m.to_email || ' — ' || left(regexp_replace(m.body, '\s+', ' ', 'g'), 160))::text AS summary, m.created_at
      FROM outreach_messages m WHERE m.status = 'pending_approval'
    UNION ALL
    SELECT 'product'::text, p.id, p.title::text,
           ('Price ' || to_char(p.price_minor / 100.0, 'FM999999999990.00') || ' ' || p.currency
             || CASE WHEN p.ai_generated THEN ' — AI-written description' ELSE '' END)::text, coalesce(p.submitted_at, p.created_at)
      FROM products p WHERE p.status = 'pending_approval' AND p.deleted_at IS NULL
  ) x ORDER BY x.created_at DESC LIMIT least(greatest(p_limit, 1), 100)
$$;

CREATE OR REPLACE FUNCTION mobile_decide(p_device char(26), p_user char(26), p_kind text, p_ref char(26), p_decision text) RETURNS text LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE d mobile_devices%ROWTYPE; prev text := coalesce(current_setting('app.bypass_rls', true), ''); n integer; tid char(26); r text;
BEGIN
  d := mobile_device_guard(p_device, p_user);
  IF p_decision NOT IN ('approve', 'reject') THEN RAISE EXCEPTION 'bad_decision' USING ERRCODE = 'P0001'; END IF;
  IF p_kind NOT IN ('outreach_message', 'product') THEN RETURN 'unknown_kind'; END IF;
  tid := d.tenant_id;
  IF p_decision = 'approve' AND (d.stepup_until IS NULL OR d.stepup_until <= now()) THEN RETURN 'step_up_required'; END IF;
  IF p_kind = 'product' THEN
    RETURN catalog_apply_decision(tid, p_ref, p_user, p_decision, NULL);
  END IF;
  IF p_decision = 'approve' THEN
    PERFORM set_config('app.bypass_rls', 'on', true);
    IF emergency_active(tid) THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'emergency'; END IF;
    BEGIN
      UPDATE outreach_messages SET status = 'approved', approved_by = p_user, approved_at = now()
       WHERE id = p_ref AND tenant_id = tid AND status = 'pending_approval';
      GET DIAGNOSTICS n = ROW_COUNT;
    EXCEPTION WHEN raise_exception THEN
      PERFORM set_config('app.bypass_rls', prev, true);
      IF SQLERRM LIKE '%suppressed%' THEN RETURN 'suppressed'; END IF;
      RAISE;
    END;
    PERFORM set_config('app.bypass_rls', prev, true);
    RETURN CASE WHEN n = 1 THEN 'approved' ELSE 'not_pending' END;
  END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE outreach_messages SET status = 'rejected' WHERE id = p_ref AND tenant_id = tid AND status = 'pending_approval';
  GET DIAGNOSTICS n = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN n = 1 THEN 'rejected' ELSE 'not_pending' END;
END $$;

REVOKE ALL ON FUNCTION catalog_log_immutable(), product_variants_touch(), catalog_apply_decision(char, char, char, text, text), catalog_guard(char),
  stock_adjust(char, integer, text, text, char), catalog_submit(char, char), catalog_decide(char, char, text, text), catalog_publish(char, char),
  catalog_archive(char, char), catalog_restore(char, char) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION stock_adjust(char, integer, text, text, char), catalog_submit(char, char), catalog_decide(char, char, text, text),
  catalog_publish(char, char), catalog_archive(char, char), catalog_restore(char, char) TO jarvis_app_rw, jarvis_admin;
