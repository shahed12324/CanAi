-- restore the phase 11 versions of the two functions this phase replaced
DROP FUNCTION IF EXISTS mobile_decide(char, char, text, char, text);
DROP FUNCTION IF EXISTS mobile_approvals(integer);
CREATE FUNCTION mobile_approvals(p_limit integer DEFAULT 50)
RETURNS TABLE (kind text, ref char(26), title text, summary text, created_at timestamptz) LANGUAGE sql STABLE AS $$
  SELECT 'outreach_message'::text, m.id, m.subject::text, ('To ' || m.to_email || ' — ' || left(regexp_replace(m.body, '\s+', ' ', 'g'), 160))::text, m.created_at
    FROM outreach_messages m WHERE m.status = 'pending_approval'
   ORDER BY m.created_at DESC LIMIT least(greatest(p_limit, 1), 100)
$$;

-- Approve / reject one item from the phone. Approving needs a live step-up and no emergency stop.
-- Returns approved | rejected | not_pending | suppressed | step_up_required | emergency | unknown_kind
CREATE FUNCTION mobile_decide(p_device char(26), p_user char(26), p_kind text, p_ref char(26), p_decision text) RETURNS text LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE d mobile_devices%ROWTYPE; prev text := coalesce(current_setting('app.bypass_rls', true), ''); n integer; tid char(26);
BEGIN
  d := mobile_device_guard(p_device, p_user);
  IF p_decision NOT IN ('approve', 'reject') THEN RAISE EXCEPTION 'bad_decision' USING ERRCODE = 'P0001'; END IF;
  IF p_kind <> 'outreach_message' THEN RETURN 'unknown_kind'; END IF;
  tid := d.tenant_id;
  IF p_decision = 'approve' THEN
    IF d.stepup_until IS NULL OR d.stepup_until <= now() THEN RETURN 'step_up_required'; END IF;
    PERFORM set_config('app.bypass_rls', 'on', true);
    IF emergency_active(tid) THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'emergency'; END IF;
    BEGIN
      UPDATE outreach_messages SET status = 'approved', approved_by = p_user, approved_at = now()
       WHERE id = p_ref AND tenant_id = tid AND status = 'pending_approval';
      GET DIAGNOSTICS n = ROW_COUNT;
    EXCEPTION WHEN raise_exception THEN
      PERFORM set_config('app.bypass_rls', prev, true);
      IF SQLERRM LIKE '%suppressed%' THEN RETURN 'suppressed'; END IF;
      RAISE;
    END;
    PERFORM set_config('app.bypass_rls', prev, true);
    RETURN CASE WHEN n = 1 THEN 'approved' ELSE 'not_pending' END;
  END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE outreach_messages SET status = 'rejected' WHERE id = p_ref AND tenant_id = tid AND status = 'pending_approval';
  GET DIAGNOSTICS n = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN n = 1 THEN 'rejected' ELSE 'not_pending' END;
END $$;

DROP FUNCTION IF EXISTS catalog_restore(char, char);
DROP FUNCTION IF EXISTS catalog_archive(char, char);
DROP FUNCTION IF EXISTS catalog_publish(char, char);
DROP FUNCTION IF EXISTS catalog_decide(char, char, text, text);
DROP FUNCTION IF EXISTS catalog_submit(char, char);
DROP FUNCTION IF EXISTS catalog_guard(char);
DROP FUNCTION IF EXISTS catalog_apply_decision(char, char, char, text, text);
DROP FUNCTION IF EXISTS stock_adjust(char, integer, text, text, char);
DROP TABLE IF EXISTS catalog_imports;
DROP TABLE IF EXISTS product_events;
DROP TABLE IF EXISTS stock_movements;
DROP TABLE IF EXISTS product_variants;
DROP TABLE IF EXISTS products;
DROP TABLE IF EXISTS store_connections;
DROP FUNCTION IF EXISTS product_variants_touch();
DROP FUNCTION IF EXISTS products_edit_guard();
DROP FUNCTION IF EXISTS catalog_log_immutable();
