-- Back to the phase 26a rules. Refuses if rows of the new kinds exist (they are an audit trail and cannot be deleted).
DO $$ BEGIN
  IF EXISTS (SELECT 1 FROM pc_actions WHERE kind IN ('copy_file','move_file','make_dir','trash_file','create_xlsx','create_docx')) THEN
    RAISE EXCEPTION 'pc_actions holds new-kind rows; cannot roll back' USING ERRCODE = '22023';
  END IF;
END $$;
CREATE OR REPLACE FUNCTION pc_kind_risk(p_kind text) RETURNS text LANGUAGE sql IMMUTABLE AS $$
  SELECT CASE p_kind
    WHEN 'read_file' THEN 'low' WHEN 'list_dir' THEN 'low'
    WHEN 'write_file' THEN 'medium' WHEN 'open_app' THEN 'medium' WHEN 'browser_navigate' THEN 'medium' WHEN 'system_setting' THEN 'medium'
    WHEN 'delete_file' THEN 'high' WHEN 'run_command' THEN 'high' WHEN 'input_control' THEN 'high'
    WHEN 'power' THEN 'critical' END
$$;
