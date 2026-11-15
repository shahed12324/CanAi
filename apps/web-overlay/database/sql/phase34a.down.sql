-- Back to the phase 33a rules.  Refuses if rows of the new kinds exist (they are an audit trail and cannot be deleted).
DO $$ BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);                          -- the owner is subject to row-level security; without this the check below would see no rows
  IF EXISTS (SELECT 1 FROM pc_actions WHERE kind IN ('screen_read', 'screen_find', 'ui_click', 'screen_describe')) THEN
    RAISE EXCEPTION 'pc_actions holds phase-34a rows; cannot roll back' USING ERRCODE = '22023';
  END IF;
END $$;
DROP FUNCTION IF EXISTS pc_target_ok(text, jsonb, text[], text[], text[], text[]);
ALTER FUNCTION pc_target_ok_p33(text, jsonb, text[], text[], text[], text[]) RENAME TO pc_target_ok;
CREATE OR REPLACE FUNCTION pc_kind_risk(p_kind text) RETURNS text LANGUAGE sql IMMUTABLE AS $$
  SELECT CASE p_kind
    WHEN 'read_file' THEN 'low' WHEN 'list_dir' THEN 'low' WHEN 'wait_window' THEN 'low'
    WHEN 'write_file' THEN 'medium' WHEN 'open_app' THEN 'medium' WHEN 'browser_navigate' THEN 'medium' WHEN 'system_setting' THEN 'medium'
    WHEN 'copy_file' THEN 'medium' WHEN 'make_dir' THEN 'medium' WHEN 'create_xlsx' THEN 'medium' WHEN 'create_docx' THEN 'medium'
    WHEN 'browser_extract' THEN 'medium' WHEN 'window_info' THEN 'medium'
    WHEN 'delete_file' THEN 'high' WHEN 'run_command' THEN 'high' WHEN 'input_control' THEN 'high'
    WHEN 'move_file' THEN 'high' WHEN 'trash_file' THEN 'high' WHEN 'screenshot' THEN 'high'
    WHEN 'power' THEN 'critical' END
$$;
