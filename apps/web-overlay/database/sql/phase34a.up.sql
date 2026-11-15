-- Phase 34a (CAN AI Desktop, Phase 1 "AI screen understanding"): four kinds that look at the FRONT window of the Windows agent, and one that clicks what it found.
--   screen_read      high  no parameters; recognises the text of the front window (outside data: tainted)
--   screen_find      high  text (1-120), optional role (letters, max 30); lists matching elements with coordinates (tainted)
--   ui_click         high  text (1-120), optional role, index (1-20), button (left|right), min_confidence (0.5-1); clicks ONE clear match, else nothing
--   screen_describe  high  optional question (1-300); local summary, or a model's description only if the owner switched image upload on
-- All four need an allowed app in front and refuse sensitive windows; the device repeats every check.  Steps may carry a verify of type screen_text / element_present.

CREATE OR REPLACE FUNCTION pc_kind_risk(p_kind text) RETURNS text LANGUAGE sql IMMUTABLE AS $$
  SELECT CASE p_kind
    WHEN 'read_file' THEN 'low' WHEN 'list_dir' THEN 'low' WHEN 'wait_window' THEN 'low'
    WHEN 'write_file' THEN 'medium' WHEN 'open_app' THEN 'medium' WHEN 'browser_navigate' THEN 'medium' WHEN 'system_setting' THEN 'medium'
    WHEN 'copy_file' THEN 'medium' WHEN 'make_dir' THEN 'medium' WHEN 'create_xlsx' THEN 'medium' WHEN 'create_docx' THEN 'medium'
    WHEN 'browser_extract' THEN 'medium' WHEN 'window_info' THEN 'medium'
    WHEN 'delete_file' THEN 'high' WHEN 'run_command' THEN 'high' WHEN 'input_control' THEN 'high'
    WHEN 'move_file' THEN 'high' WHEN 'trash_file' THEN 'high' WHEN 'screenshot' THEN 'high'
    WHEN 'screen_read' THEN 'high' WHEN 'screen_find' THEN 'high' WHEN 'ui_click' THEN 'high' WHEN 'screen_describe' THEN 'high'
    WHEN 'power' THEN 'critical' END
$$;

ALTER FUNCTION pc_target_ok(text, jsonb, text[], text[], text[], text[]) RENAME TO pc_target_ok_p33;

CREATE FUNCTION pc_target_ok(p_kind text, p jsonb, p_roots text[], p_apps text[], p_commands text[], p_hosts text[]) RETURNS text
LANGUAGE plpgsql IMMUTABLE AS $$
BEGIN
  IF p_kind IN ('screen_read', 'screen_find', 'ui_click', 'screen_describe') THEN
    IF jsonb_typeof(p) IS DISTINCT FROM 'object' THEN RETURN 'bad_params'; END IF;
    IF p_kind = 'screen_read' THEN
      RETURN CASE WHEN p = '{}'::jsonb THEN 'ok' ELSE 'bad_params' END;
    END IF;
    IF p_kind = 'screen_describe' THEN
      IF EXISTS (SELECT 1 FROM jsonb_object_keys(p) x WHERE x <> 'question') THEN RETURN 'bad_params'; END IF;
      IF p ? 'question' AND (jsonb_typeof(p -> 'question') IS DISTINCT FROM 'string' OR char_length(btrim(p ->> 'question')) NOT BETWEEN 1 AND 300) THEN RETURN 'bad_params'; END IF;
      RETURN 'ok';
    END IF;
    -- screen_find / ui_click
    IF jsonb_typeof(p -> 'text') IS DISTINCT FROM 'string' OR char_length(btrim(p ->> 'text')) NOT BETWEEN 1 AND 120 THEN RETURN 'bad_params'; END IF;
    IF p ? 'role' AND (jsonb_typeof(p -> 'role') IS DISTINCT FROM 'string' OR (p ->> 'role') !~ '^[A-Za-z ]{1,30}$') THEN RETURN 'bad_params'; END IF;
    IF p_kind = 'screen_find' THEN
      IF EXISTS (SELECT 1 FROM jsonb_object_keys(p) x WHERE x NOT IN ('text', 'role')) THEN RETURN 'bad_params'; END IF;
      RETURN 'ok';
    END IF;
    IF EXISTS (SELECT 1 FROM jsonb_object_keys(p) x WHERE x NOT IN ('text', 'role', 'index', 'button', 'min_confidence')) THEN RETURN 'bad_params'; END IF;
    IF p ? 'index' AND (jsonb_typeof(p -> 'index') IS DISTINCT FROM 'number' OR (p ->> 'index') !~ '^[0-9]{1,2}$' OR (p ->> 'index')::int NOT BETWEEN 1 AND 20) THEN RETURN 'bad_params'; END IF;
    IF p ? 'button' AND (p -> 'button') NOT IN ('"left"'::jsonb, '"right"'::jsonb) THEN RETURN 'bad_params'; END IF;
    IF p ? 'min_confidence' AND (jsonb_typeof(p -> 'min_confidence') IS DISTINCT FROM 'number' OR (p ->> 'min_confidence')::numeric NOT BETWEEN 0.5 AND 1) THEN RETURN 'bad_params'; END IF;
    RETURN 'ok';
  END IF;
  RETURN pc_target_ok_p33(p_kind, p, p_roots, p_apps, p_commands, p_hosts);
END $$;

DO $$ DECLARE f text; BEGIN
  FOREACH f IN ARRAY ARRAY['pc_target_ok(text, jsonb, text[], text[], text[], text[])', 'pc_target_ok_p33(text, jsonb, text[], text[], text[], text[])', 'pc_kind_risk(text)'] LOOP
    EXECUTE format('REVOKE ALL ON FUNCTION %s FROM PUBLIC, jarvis_app_rw, jarvis_admin', f);
    EXECUTE format('GRANT EXECUTE ON FUNCTION %s TO jarvis_app_rw, jarvis_admin', f);
  END LOOP;
END $$;
