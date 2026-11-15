DROP FUNCTION IF EXISTS social_post_delete(char, char, char);
DROP FUNCTION IF EXISTS social_post_cancel(char, char, char);
DROP FUNCTION IF EXISTS social_post_mark_posted(char, char, char, text);
DROP FUNCTION IF EXISTS social_post_reopen(char, char, char);
DROP FUNCTION IF EXISTS social_post_decide(char, char, char, boolean, text);
DROP FUNCTION IF EXISTS social_post_submit(char, char, char);
DROP FUNCTION IF EXISTS social_post_save(boolean, char, char, char, text, text, text, text, char, text, timestamptz);
DROP TABLE IF EXISTS social_posts;
