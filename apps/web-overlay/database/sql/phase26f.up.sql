-- Phase 26f (CAN AI handover, Milestone F): server rules for screen / input / web-reading kinds.
--   screenshot      high   (privacy: the screen may show anything)   path must be a .png inside an allowed folder; optional region [x,y,w,h] of integers
--   input_control   high   (always approved on the phone with PIN)   type: text 1-200 chars; key: 'ctrl+s' style; click/move: integer x,y; scroll: dy -20..20
--   browser_extract medium (reads a page as text; its output is untrusted data)   https URL on an allowed host, exactly like browser_navigate
-- Nothing here executes anything.  The device repeats every check locally (foreground-app allowlist, sensitive windows, emergency stop).

CREATE OR REPLACE FUNCTION pc_kind_risk(p_kind text) RETURNS text LANGUAGE sql IMMUTABLE AS $$
  SELECT CASE p_kind
    WHEN 'read_file' THEN 'low' WHEN 'list_dir' THEN 'low'
    WHEN 'write_file' THEN 'medium' WHEN 'open_app' THEN 'medium' WHEN 'browser_navigate' THEN 'medium' WHEN 'system_setting' THEN 'medium'
    WHEN 'copy_file' THEN 'medium' WHEN 'make_dir' THEN 'medium' WHEN 'create_xlsx' THEN 'medium' WHEN 'create_docx' THEN 'medium'
    WHEN 'browser_extract' THEN 'medium'
    WHEN 'delete_file' THEN 'high' WHEN 'run_command' THEN 'high' WHEN 'input_control' THEN 'high'
    WHEN 'move_file' THEN 'high' WHEN 'trash_file' THEN 'high' WHEN 'screenshot' THEN 'high'
    WHEN 'power' THEN 'critical' END
$$;

ALTER FUNCTION pc_target_ok(text, jsonb, text[], text[], text[], text[]) RENAME TO pc_target_ok_base;

CREATE FUNCTION pc_target_ok(p_kind text, p jsonb, p_roots text[], p_apps text[], p_commands text[], p_hosts text[]) RETURNS text
LANGUAGE plpgsql IMMUTABLE AS $$
DECLARE act text; v jsonb; i int; r text;
BEGIN
  IF p_kind = 'screenshot' THEN
    IF jsonb_typeof(p -> 'path') IS DISTINCT FROM 'string' OR lower(p ->> 'path') !~ '\.png$' THEN RETURN 'bad_params'; END IF;
    IF p ? 'overwrite' AND jsonb_typeof(p -> 'overwrite') IS DISTINCT FROM 'boolean' THEN RETURN 'bad_params'; END IF;
    IF p ? 'region' THEN
      IF jsonb_typeof(p -> 'region') IS DISTINCT FROM 'array' OR jsonb_array_length(p -> 'region') <> 4 THEN RETURN 'bad_params'; END IF;
      FOR i IN 0..3 LOOP
        v := p -> 'region' -> i;
        IF jsonb_typeof(v) IS DISTINCT FROM 'number' OR (v #>> '{}') !~ '^[0-9]{1,5}$' THEN RETURN 'bad_params'; END IF;
      END LOOP;
      IF (p -> 'region' ->> 2)::int < 1 OR (p -> 'region' ->> 3)::int < 1 THEN RETURN 'bad_params'; END IF;
    END IF;
    RETURN pc_target_ok_base('read_file', p, p_roots, p_apps, p_commands, p_hosts);
  ELSIF p_kind = 'browser_extract' THEN
    RETURN pc_target_ok_base('browser_navigate', p, p_roots, p_apps, p_commands, p_hosts);
  ELSIF p_kind = 'input_control' THEN
    act := p ->> 'action';
    IF act = 'type' THEN
      RETURN CASE WHEN jsonb_typeof(p -> 'text') = 'string' AND char_length(p ->> 'text') BETWEEN 1 AND 200 AND (p ->> 'text') !~ '[[:cntrl:]]' THEN 'ok' ELSE 'bad_params' END;
    ELSIF act = 'key' THEN
      RETURN CASE WHEN jsonb_typeof(p -> 'keys') = 'string' AND lower(p ->> 'keys') ~ '^[a-z0-9]{1,9}(\+[a-z0-9]{1,9}){0,3}$'
                   AND lower(p ->> 'keys') !~ '(^|\+)(win|meta|cmd|super)(\+|$)' THEN 'ok' ELSE 'bad_params' END;
    ELSIF act IN ('click', 'move') THEN
      FOREACH r IN ARRAY ARRAY['x', 'y'] LOOP
        IF jsonb_typeof(p -> r) IS DISTINCT FROM 'number' OR (p ->> r) !~ '^[0-9]{1,5}$' THEN RETURN 'bad_params'; END IF;
      END LOOP;
      IF p ? 'button' AND (p ->> 'button') NOT IN ('left', 'right') THEN RETURN 'bad_params'; END IF;
      RETURN 'ok';
    ELSIF act = 'scroll' THEN
      RETURN CASE WHEN jsonb_typeof(p -> 'dy') = 'number' AND (p ->> 'dy') ~ '^-?[0-9]{1,2}$' AND abs((p ->> 'dy')::int) <= 20 THEN 'ok' ELSE 'bad_params' END;
    END IF;
    RETURN 'bad_params';
  END IF;
  RETURN pc_target_ok_base(p_kind, p, p_roots, p_apps, p_commands, p_hosts);
END $$;

DO $$ DECLARE f text; BEGIN
  FOREACH f IN ARRAY ARRAY['pc_target_ok(text, jsonb, text[], text[], text[], text[])', 'pc_target_ok_base(text, jsonb, text[], text[], text[], text[])', 'pc_kind_risk(text)'] LOOP
    EXECUTE format('REVOKE ALL ON FUNCTION %s FROM PUBLIC, jarvis_app_rw, jarvis_admin', f);
    EXECUTE format('GRANT EXECUTE ON FUNCTION %s TO jarvis_app_rw, jarvis_admin', f);
  END LOOP;
END $$;
