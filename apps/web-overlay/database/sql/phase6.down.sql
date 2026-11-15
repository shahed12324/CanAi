DROP FUNCTION IF EXISTS chat_finalize_stale(integer);
DROP TABLE IF EXISTS chat_attachments, messages, conversations CASCADE;
DROP FUNCTION IF EXISTS chat_same_tenant();
DROP FUNCTION IF EXISTS messages_guard();
