-- Phase 33a (CAN AI Desktop, Phase D): two read-only "look at the screen" kinds for the Windows agent.
--   window_info  medium  no parameters; returns the name of the app in front and its window title (outside data: tainted; sensitive windows are never shown)
--   wait_window  low     process and/or title_contains (1-120 chars each, at least one), optional timeout 1-30 seconds; polls until that window is in front
-- Neither touches files, the keyboard or the mouse.  The device repeats the checks locally.  A step may also carry a "verify" of type window_foreground.

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

ALTER FUNCTION pc_target_ok(text, jsonb, text[], text[], text[], text[]) RENAME TO pc_target_ok_p26;

CREATE FUNCTION pc_target_ok(p_kind text, p jsonb, p_roots text[], p_apps text[], p_commands text[], p_hosts text[]) RETURNS text
LANGUAGE plpgsql IMMUTABLE AS $$
DECLARE k text;
BEGIN
  IF p_kind = 'window_info' THEN
    RETURN CASE WHEN jsonb_typeof(p) = 'object' AND p = '{}'::jsonb THEN 'ok' ELSE 'bad_params' END;
  ELSIF p_kind = 'wait_window' THEN
    IF jsonb_typeof(p) IS DISTINCT FROM 'object' OR NOT (p ? 'process' OR p ? 'title_contains') THEN RETURN 'bad_params'; END IF;
    FOREACH k IN ARRAY ARRAY['process', 'title_contains'] LOOP
      IF p ? k AND (jsonb_typeof(p -> k) IS DISTINCT FROM 'string' OR char_length(btrim(p ->> k)) NOT BETWEEN 1 AND 120) THEN RETURN 'bad_params'; END IF;
    END LOOP;
    IF p ? 'timeout' AND (jsonb_typeof(p -> 'timeout') IS DISTINCT FROM 'number' OR (p ->> 'timeout') !~ '^[0-9]{1,2}$' OR (p ->> 'timeout')::int NOT BETWEEN 1 AND 30) THEN RETURN 'bad_params'; END IF;
    IF EXISTS (SELECT 1 FROM jsonb_object_keys(p) x WHERE x NOT IN ('process', 'title_contains', 'timeout')) THEN RETURN 'bad_params'; END IF;
    RETURN 'ok';
  END IF;
  RETURN pc_target_ok_p26(p_kind, p, p_roots, p_apps, p_commands, p_hosts);
END $$;

DO $$ DECLARE f text; BEGIN
  FOREACH f IN ARRAY ARRAY['pc_target_ok(text, jsonb, text[], text[], text[], text[])', 'pc_target_ok_p26(text, jsonb, text[], text[], text[], text[])', 'pc_kind_risk(text)'] LOOP
    EXECUTE format('REVOKE ALL ON FUNCTION %s FROM PUBLIC, jarvis_app_rw, jarvis_admin', f);
    EXECUTE format('GRANT EXECUTE ON FUNCTION %s TO jarvis_app_rw, jarvis_admin', f);
  END LOOP;
END $$;
