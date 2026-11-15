-- Phase 22.1d: when a client answers an approval link, the designer gets an in-app notification (the same inbox_notifications the mobile app already pushes).
CREATE OR REPLACE FUNCTION review_public_decide(p_hash text, p_decision text, p_comment text) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); r design_reviews%ROWTYPE; d_title text; c text := btrim(coalesce(p_comment, ''));
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
  SELECT title INTO d_title FROM designs WHERE id = r.design_id;
  -- Tell the designer, once per link (the link can be answered only once, and the id is derived from the link so a replay cannot add a second row).
  INSERT INTO inbox_notifications (id, tenant_id, user_id, kind, title, body, ref_kind, ref_id)
  VALUES (substr(md5(r.id || 'design_review') || md5(r.id), 1, 26), r.tenant_id, r.user_id, 'design_review',
    left(CASE WHEN p_decision = 'approved' THEN '✔ অনুমোদিত: ' ELSE '✎ পরিবর্তন চেয়েছেন: ' END || coalesce(d_title, 'ডিজাইন'), 160),
    left(CASE WHEN r.client_name <> '' THEN r.client_name || ' — ' ELSE '' END || coalesce(nullif(c, ''), 'কোনো মন্তব্য নেই'), 500), 'design', r.design_id)
  ON CONFLICT (id) DO NOTHING;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;
