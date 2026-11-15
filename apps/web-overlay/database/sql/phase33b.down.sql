DROP FUNCTION IF EXISTS pc_agent_rotate(text, text);
ALTER TABLE pc_devices DROP COLUMN IF EXISTS token_rotated_at;
