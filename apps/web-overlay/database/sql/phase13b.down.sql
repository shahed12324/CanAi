DROP FUNCTION IF EXISTS inbox_message_retry(bigint, text);
DROP FUNCTION IF EXISTS inbox_dispatch_ctx(bigint);
DROP FUNCTION IF EXISTS inbox_credentials(char);
ALTER TABLE inbox_messages DROP COLUMN IF EXISTS attempts;
DROP INDEX IF EXISTS inbox_channels_account;
ALTER TABLE inbox_channels DROP CONSTRAINT IF EXISTS channel_shape;
DROP FUNCTION IF EXISTS inbox_channel_by_key(char);
CREATE FUNCTION inbox_channel_by_key(p_key char(32)) RETURNS TABLE (id char(26), tenant_id char(26), kind text, status text, allowed_origins text[], greeting varchar, name varchar)
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), '');
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  RETURN QUERY SELECT c.id, c.tenant_id, c.kind, c.status, c.allowed_origins, c.greeting, c.name FROM inbox_channels c WHERE c.public_key = p_key;
  PERFORM set_config('app.bypass_rls', prev, true);
END $$;
REVOKE ALL ON FUNCTION inbox_channel_by_key(char) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION inbox_channel_by_key(char) TO jarvis_app_rw, jarvis_admin;
