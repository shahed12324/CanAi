DROP FUNCTION IF EXISTS kb_search(text, integer);
DROP FUNCTION IF EXISTS kb_query(text);
DROP FUNCTION IF EXISTS kb_document_delete(char, char);
DROP FUNCTION IF EXISTS kb_document_update(char, char, text, boolean);
DROP FUNCTION IF EXISTS kb_document_save(char, char, char, text, text, text, bigint, text[]);
DROP TABLE IF EXISTS kb_chunks, kb_documents;
