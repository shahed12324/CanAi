-- Phase 36a (CAN AI Desktop, Phase 3 "planner"): kind make_report, medium risk.
--   params: src (a .csv/.txt inside an allowed folder), dst (a .xlsx inside an allowed folder), optional group_by (column name, 1-100), optional overwrite (boolean).
--   The device reads the CSV and computes every number itself (sum/average/min/max/count, totals per group); a model plans the step but never sees or calculates the data.
--   Plans may also carry per-step "needs" / "when" / "continue_on_error" (checked by the device; this function does not look at them).

CREATE OR REPLACE FUNCTION pc_kind_risk(p_kind text) RETURNS text LANGUAGE sql IMMUTABLE AS $$
  SELECT CASE p_kind
    WHEN 'read_file' THEN 'low' WHEN 'list_dir' THEN 'low' WHEN 'wait_window' THEN 'low'
    WHEN 'write_file' THEN 'medium' WHEN 'open_app' THEN 'medium' WHEN 'browser_navigate' THEN 'medium' WHEN 'system_setting' THEN 'medium'
    WHEN 'copy_file' THEN 'medium' WHEN 'make_dir' THEN 'medium' WHEN 'create_xlsx' THEN 'medium' WHEN 'create_docx' THEN 'medium'
    WHEN 'browser_extract' THEN 'medium' WHEN 'window_info' THEN 'medium' WHEN 'make_report' THEN 'medium'
    WHEN 'delete_file' THEN 'high' WHEN 'run_command' THEN 'high' WHEN 'input_control' THEN 'high'
    WHEN 'move_file' THEN 'high' WHEN 'trash_file' THEN 'high' WHEN 'screenshot' THEN 'high'
    WHEN 'screen_read' THEN 'high' WHEN 'screen_find' THEN 'high' WHEN 'ui_click' THEN 'high' WHEN 'screen_describe' THEN 'high'
    WHEN 'browser_run' THEN 'high'
    WHEN 'power' THEN 'critical' END
$$;

ALTER FUNCTION pc_target_ok(text, jsonb, text[], text[], text[], text[]) RENAME TO pc_target_ok_p35;

CREATE FUNCTION pc_target_ok(p_kind text, p jsonb, p_roots text[], p_apps text[], p_commands text[], p_hosts text[]) RETURNS text
LANGUAGE plpgsql IMMUTABLE AS $$
DECLARE r text;
BEGIN
  IF p_kind = 'make_report' THEN
    IF jsonb_typeof(p) IS DISTINCT FROM 'object' THEN RETURN 'bad_params'; END IF;
    IF EXISTS (SELECT 1 FROM jsonb_object_keys(p) x WHERE x NOT IN ('src', 'dst', 'group_by', 'overwrite')) THEN RETURN 'bad_params'; END IF;
    IF jsonb_typeof(p -> 'src') IS DISTINCT FROM 'string' OR jsonb_typeof(p -> 'dst') IS DISTINCT FROM 'string' THEN RETURN 'bad_params'; END IF;
    IF lower(p ->> 'src') !~ '\.(csv|txt)$' OR lower(p ->> 'dst') !~ '\.xlsx$' THEN RETURN 'bad_params'; END IF;
    IF p ? 'group_by' AND (jsonb_typeof(p -> 'group_by') IS DISTINCT FROM 'string' OR char_length(btrim(p ->> 'group_by')) NOT BETWEEN 1 AND 100) THEN RETURN 'bad_params'; END IF;
    RETURN pc_target_ok_p35('copy_file', p - 'group_by', p_roots, p_apps, p_commands, p_hosts);       -- both ends inside an allowed folder, overwrite must be boolean
  END IF;
  RETURN pc_target_ok_p35(p_kind, p, p_roots, p_apps, p_commands, p_hosts);
END $$;

DO $$ DECLARE f text; BEGIN
  FOREACH f IN ARRAY ARRAY['pc_target_ok(text, jsonb, text[], text[], text[], text[])', 'pc_target_ok_p35(text, jsonb, text[], text[], text[], text[])', 'pc_kind_risk(text)'] LOOP
    EXECUTE format('REVOKE ALL ON FUNCTION %s FROM PUBLIC, jarvis_app_rw, jarvis_admin', f);
    EXECUTE format('GRANT EXECUTE ON FUNCTION %s TO jarvis_app_rw, jarvis_admin', f);
  END LOOP;
END $$;
