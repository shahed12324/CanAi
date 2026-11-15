-- Back to the phase 36a rules.  The stored chain is an audit trail: refuses if any row exists.
DO $$ BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  IF EXISTS (SELECT 1 FROM pc_audit_chain) THEN
    RAISE EXCEPTION 'pc_audit_chain holds rows; cannot roll back' USING ERRCODE = '22023';
  END IF;
END $$;
DROP FUNCTION IF EXISTS pc_audit_ingest(char, char, integer, jsonb);
DROP FUNCTION IF EXISTS pc_audit_head(char, char);
DROP FUNCTION IF EXISTS pc_audit_hash(bigint, text, text, text, text, text);
DROP FUNCTION IF EXISTS pc_audit_canonical(bigint, text, text, text, text, text);
DROP TABLE IF EXISTS pc_audit_chain;
