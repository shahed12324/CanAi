DROP FUNCTION IF EXISTS video_job_fail(char, char, text);
DROP FUNCTION IF EXISTS video_job_done(char, char, text, bigint, numeric);
DROP FUNCTION IF EXISTS video_job_claim(integer);
DROP FUNCTION IF EXISTS video_job_delete(char, char, char);
DROP FUNCTION IF EXISTS video_job_cancel(char, char, char);
DROP FUNCTION IF EXISTS video_job_create(char, char, char, text, text, jsonb, text, text, jsonb, text, text, char, numeric, numeric);
DROP TABLE IF EXISTS video_jobs;
