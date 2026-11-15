DROP FUNCTION IF EXISTS review_public_decide(text, text, text);
DROP FUNCTION IF EXISTS review_public_view(text);
DROP FUNCTION IF EXISTS review_revoke(char, char, char);
DROP FUNCTION IF EXISTS review_create(char, char, char, char, text, text, text, text, integer);
DROP FUNCTION IF EXISTS design_delete(char, char, char);
DROP FUNCTION IF EXISTS design_save(char, char, char, text, text, text, text, text, text, text[], jsonb, text, text);
DROP TABLE IF EXISTS design_reviews;
DROP TABLE IF EXISTS designs;
