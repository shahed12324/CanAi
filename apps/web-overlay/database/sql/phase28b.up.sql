-- Phase 21 (Coding Assistant): a conversation has a mode. 'code' = the Coding Assistant system prompt.
-- The mode is fixed when the conversation is created (the app role can INSERT it; it never changes afterwards).
ALTER TABLE conversations ADD COLUMN mode text NOT NULL DEFAULT 'general' CHECK (mode IN ('general', 'code'));

CREATE FUNCTION conversations_mode_guard() RETURNS trigger LANGUAGE plpgsql
SET search_path = public, pg_temp AS $$
BEGIN
  IF NEW.mode IS DISTINCT FROM OLD.mode THEN
    RAISE EXCEPTION 'conversation mode cannot change' USING ERRCODE = '22023';
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER conversations_mode_guard_trg BEFORE UPDATE ON conversations
  FOR EACH ROW EXECUTE FUNCTION conversations_mode_guard();
REVOKE ALL ON FUNCTION conversations_mode_guard() FROM PUBLIC;
