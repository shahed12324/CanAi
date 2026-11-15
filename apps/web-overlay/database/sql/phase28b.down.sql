DROP TRIGGER IF EXISTS conversations_mode_guard_trg ON conversations;
DROP FUNCTION IF EXISTS conversations_mode_guard();
ALTER TABLE conversations DROP COLUMN IF EXISTS mode;
