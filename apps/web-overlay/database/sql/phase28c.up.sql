-- Phase 25.2: AI characters (personality + speaking style + voice/avatar preferences). Tenant-isolated; members of a workspace
-- can chat with any of its characters; only the creator edits/deletes (enforced in the repository AND by created_by in the WHERE).
CREATE TABLE characters (
  id           char(26)     PRIMARY KEY,
  tenant_id    char(26)     NOT NULL REFERENCES tenants (id),
  created_by   char(26)     NOT NULL REFERENCES users (id),
  name         varchar(60)  NOT NULL CHECK (length(btrim(name)) > 0),
  kind         text         NOT NULL CHECK (kind IN ('male', 'female', 'custom')),
  personality  varchar(1000) NOT NULL CHECK (length(btrim(personality)) > 0),
  speaking_style varchar(500) NOT NULL DEFAULT '',
  language     text         NOT NULL DEFAULT 'bn' CHECK (language IN ('bn', 'en', 'auto')),
  voice        varchar(60),
  avatar_style text         NOT NULL DEFAULT 'none' CHECK (avatar_style IN ('none', '2d', '3d')),
  greeting     varchar(300) NOT NULL DEFAULT '',
  created_at   timestamptz  NOT NULL DEFAULT now(),
  updated_at   timestamptz  NOT NULL DEFAULT now(),
  deleted_at   timestamptz
);
CREATE INDEX characters_tenant ON characters (tenant_id, created_at) WHERE deleted_at IS NULL;
ALTER TABLE characters ENABLE ROW LEVEL SECURITY;
ALTER TABLE characters FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON characters USING (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()))
  WITH CHECK (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()));

-- At most 20 live characters per workspace.
CREATE FUNCTION characters_limit() RETURNS trigger LANGUAGE plpgsql SET search_path = public, pg_temp AS $$
BEGIN
  IF (SELECT count(*) FROM characters WHERE tenant_id = NEW.tenant_id AND deleted_at IS NULL) >= 20 THEN
    RAISE EXCEPTION 'character limit reached' USING ERRCODE = '54000';
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER characters_limit_trg BEFORE INSERT ON characters FOR EACH ROW EXECUTE FUNCTION characters_limit();
REVOKE ALL ON FUNCTION characters_limit() FROM PUBLIC;

-- A conversation may be bound to a character of the SAME workspace, fixed at creation.
ALTER TABLE conversations ADD COLUMN character_id char(26) REFERENCES characters (id);
CREATE FUNCTION conversations_character_guard() RETURNS trigger LANGUAGE plpgsql SET search_path = public, pg_temp AS $$
BEGIN
  IF TG_OP = 'INSERT' THEN
    IF NEW.character_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM characters WHERE id = NEW.character_id AND tenant_id = NEW.tenant_id AND deleted_at IS NULL) THEN
      RAISE EXCEPTION 'unknown character' USING ERRCODE = '22023';
    END IF;
  ELSIF NEW.character_id IS DISTINCT FROM OLD.character_id THEN
    RAISE EXCEPTION 'conversation character cannot change' USING ERRCODE = '22023';
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER conversations_character_guard_trg BEFORE INSERT OR UPDATE ON conversations FOR EACH ROW EXECUTE FUNCTION conversations_character_guard();
REVOKE ALL ON FUNCTION conversations_character_guard() FROM PUBLIC;
