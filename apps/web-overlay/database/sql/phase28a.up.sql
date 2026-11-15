-- Phase 28a: hardening found by the security audit (tests/db/test_security_audit.py).
--  * three SECURITY DEFINER functions could be executed by PUBLIC (any database role); only the app/admin roles should;
--  * two read-only views had INSERT/UPDATE/DELETE granted to the app role by default privileges.
REVOKE ALL ON FUNCTION order_create(char, char, char, text, char, text, text, text, text, text, jsonb, numeric) FROM PUBLIC;
REVOKE ALL ON FUNCTION order_transition(char, char, text, text) FROM PUBLIC;
REVOKE ALL ON FUNCTION license_seat_guard() FROM PUBLIC;
REVOKE INSERT, UPDATE, DELETE, TRUNCATE ON model_current_pricing, social_post_latest_stats FROM jarvis_app_rw, jarvis_admin;

-- Housekeeping functions act on EVERY workspace (they set the bypass themselves).  They belong to the scheduler (admin connection), not to the app role:
-- a compromised app connection must not be able to e.g. purge the webhook de-duplication table or finalise everyone's streaming messages.
DO $$ DECLARE f text; BEGIN
  FOREACH f IN ARRAY ARRAY['credit_expire_lots()', 'credit_release_stale()', 'chat_finalize_stale(integer)', 'lead_items_requeue_stale(integer)', 'channel_events_purge(integer)'] LOOP
    EXECUTE format('REVOKE ALL ON FUNCTION %s FROM PUBLIC, jarvis_app_rw', f);
    EXECUTE format('GRANT EXECUTE ON FUNCTION %s TO jarvis_admin', f);
  END LOOP;
END $$;
