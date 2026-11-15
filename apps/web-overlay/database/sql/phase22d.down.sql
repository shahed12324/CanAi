-- Phase 22.1d down: back to the version without the designer notification.
CREATE OR REPLACE FUNCTION review_public_decide(p_hash text, p_decision text, p_comment text) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); r design_reviews%ROWTYPE; c text := btrim(coalesce(p_comment, ''));
BEGIN
  IF p_hash IS NULL OR p_hash !~ '^[0-9a-f]{64}$' THEN RETURN 'not_found'; END IF;
  IF p_decision IS NULL OR p_decision NOT IN ('approved', 'changes') OR char_length(c) > 1000 OR (p_decision = 'changes' AND c = '') THEN RETURN 'bad_input'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO r FROM design_reviews WHERE token_hash = p_hash FOR UPDATE;
  IF NOT FOUND THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'not_found'; END IF;
  IF r.status = 'revoked' THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'revoked'; END IF;
  IF r.status <> 'pending' THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'decided'; END IF;
  IF r.expires_at <= now() THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'expired'; END IF;
  UPDATE design_reviews SET status = p_decision, comment = nullif(c, ''), decided_at = now() WHERE id = r.id;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;
