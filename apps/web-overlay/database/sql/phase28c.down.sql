DROP TRIGGER IF EXISTS conversations_character_guard_trg ON conversations;
DROP FUNCTION IF EXISTS conversations_character_guard();
ALTER TABLE conversations DROP COLUMN IF EXISTS character_id;
DROP TRIGGER IF EXISTS characters_limit_trg ON characters;
DROP FUNCTION IF EXISTS characters_limit();
DROP TABLE IF EXISTS characters;
