-- Phase 35a (CAN AI Desktop, Phase 2 "advanced browser automation"): kind browser_run, high risk.
--   params: optional "url" (https, allowed host) + "steps": 1-20 operations, each an object whose keys are limited per operation:
--     goto{url}  fill{label|placeholder|css, value}  select{label|css, value}  check{label|css}  click{text|label|css}  wait_text{text, timeout 1-15}  expect_text{text}  extract{css}
--   strings 1-200 chars (value up to 500); every goto URL must pass the same https + allowed-host check as browser_navigate; clicks on pay/buy/transfer/delete-account words are refused.
-- The device repeats all checks, runs a fresh browser per job and blocks every request to hosts that are not allowed.  Its output is outside data (tainted).

CREATE OR REPLACE FUNCTION pc_kind_risk(p_kind text) RETURNS text LANGUAGE sql IMMUTABLE AS $$
  SELECT CASE p_kind
    WHEN 'read_file' THEN 'low' WHEN 'list_dir' THEN 'low' WHEN 'wait_window' THEN 'low'
    WHEN 'write_file' THEN 'medium' WHEN 'open_app' THEN 'medium' WHEN 'browser_navigate' THEN 'medium' WHEN 'system_setting' THEN 'medium'
    WHEN 'copy_file' THEN 'medium' WHEN 'make_dir' THEN 'medium' WHEN 'create_xlsx' THEN 'medium' WHEN 'create_docx' THEN 'medium'
    WHEN 'browser_extract' THEN 'medium' WHEN 'window_info' THEN 'medium'
    WHEN 'delete_file' THEN 'high' WHEN 'run_command' THEN 'high' WHEN 'input_control' THEN 'high'
    WHEN 'move_file' THEN 'high' WHEN 'trash_file' THEN 'high' WHEN 'screenshot' THEN 'high'
    WHEN 'screen_read' THEN 'high' WHEN 'screen_find' THEN 'high' WHEN 'ui_click' THEN 'high' WHEN 'screen_describe' THEN 'high'
    WHEN 'browser_run' THEN 'high'
    WHEN 'power' THEN 'critical' END
$$;

ALTER FUNCTION pc_target_ok(text, jsonb, text[], text[], text[], text[]) RENAME TO pc_target_ok_p34;

CREATE FUNCTION pc_target_ok(p_kind text, p jsonb, p_roots text[], p_apps text[], p_commands text[], p_hosts text[]) RETURNS text
LANGUAGE plpgsql IMMUTABLE AS $$
DECLARE s jsonb; lim int; op text; k text; v jsonb; allowed text[]; n int := 0; txt text;
BEGIN
  IF p_kind = 'browser_run' THEN
    IF jsonb_typeof(p) IS DISTINCT FROM 'object' OR jsonb_typeof(p -> 'steps') IS DISTINCT FROM 'array' THEN RETURN 'bad_params'; END IF;
    IF EXISTS (SELECT 1 FROM jsonb_object_keys(p) x WHERE x NOT IN ('url', 'steps')) THEN RETURN 'bad_params'; END IF;
    IF jsonb_array_length(p -> 'steps') NOT BETWEEN 1 AND 20 THEN RETURN 'bad_params'; END IF;
    IF p ? 'url' THEN
      IF pc_target_ok_p34('browser_navigate', jsonb_build_object('url', p -> 'url'), p_roots, p_apps, p_commands, p_hosts) <> 'ok' THEN RETURN 'outside_scope'; END IF;
    END IF;
    FOR s IN SELECT x FROM jsonb_array_elements(p -> 'steps') x LOOP
      n := n + 1;
      IF jsonb_typeof(s) IS DISTINCT FROM 'object' OR jsonb_typeof(s -> 'op') IS DISTINCT FROM 'string' THEN RETURN 'bad_params'; END IF;
      op := s ->> 'op';
      allowed := CASE op
        WHEN 'goto' THEN ARRAY['url'] WHEN 'fill' THEN ARRAY['label', 'placeholder', 'css', 'value'] WHEN 'select' THEN ARRAY['label', 'css', 'value']
        WHEN 'check' THEN ARRAY['label', 'css'] WHEN 'click' THEN ARRAY['text', 'label', 'css'] WHEN 'wait_text' THEN ARRAY['text', 'timeout']
        WHEN 'expect_text' THEN ARRAY['text'] WHEN 'extract' THEN ARRAY['css'] END;
      IF allowed IS NULL THEN RETURN 'bad_params'; END IF;
      txt := '';
      FOR k, v IN SELECT * FROM jsonb_each(s) LOOP
        IF k = 'op' THEN CONTINUE; END IF;
        IF NOT k = ANY (allowed) THEN RETURN 'bad_params'; END IF;
        IF k = 'timeout' THEN
          IF jsonb_typeof(v) IS DISTINCT FROM 'number' OR (v #>> '{}') !~ '^[0-9]{1,2}$' OR (v #>> '{}')::int NOT BETWEEN 1 AND 15 THEN RETURN 'bad_params'; END IF;
        ELSE
          IF jsonb_typeof(v) IS DISTINCT FROM 'string' THEN RETURN 'bad_params'; END IF;
          lim := 200;                                                                  -- (a CASE ... THEN inside an IF condition confuses the plpgsql parser)
          IF k = 'value' THEN lim := 500; END IF;
          IF char_length(v #>> '{}') > lim OR (k <> 'value' AND char_length(v #>> '{}') < 1) THEN RETURN 'bad_params'; END IF;
          txt := txt || ' ' || (v #>> '{}');
        END IF;
      END LOOP;
      IF op = 'goto' THEN
        IF NOT s ? 'url' OR pc_target_ok_p34('browser_navigate', jsonb_build_object('url', s -> 'url'), p_roots, p_apps, p_commands, p_hosts) <> 'ok' THEN RETURN 'outside_scope'; END IF;
      ELSIF op IN ('fill', 'select') THEN
        IF NOT s ? 'value' OR NOT (s ? 'label' OR s ? 'placeholder' OR s ? 'css') THEN RETURN 'bad_params'; END IF;
      ELSIF op = 'check' THEN
        IF NOT (s ? 'label' OR s ? 'css') THEN RETURN 'bad_params'; END IF;
      ELSIF op = 'click' THEN
        IF NOT (s ? 'text' OR s ? 'label' OR s ? 'css') THEN RETURN 'bad_params'; END IF;
        IF txt ~* '\m(pay|payment|buy|purchase|checkout|check out|place order|confirm order|order now|donate|subscribe|transfer|send money|withdraw|wire)\M|delete (my )?account|close account' THEN RETURN 'bad_params'; END IF;
      ELSIF op IN ('wait_text', 'expect_text') THEN
        IF NOT s ? 'text' THEN RETURN 'bad_params'; END IF;
      END IF;
      IF n = 1 AND NOT p ? 'url' AND op <> 'goto' THEN RETURN 'bad_params'; END IF;
    END LOOP;
    RETURN 'ok';
  END IF;
  RETURN pc_target_ok_p34(p_kind, p, p_roots, p_apps, p_commands, p_hosts);
END $$;

DO $$ DECLARE f text; BEGIN
  FOREACH f IN ARRAY ARRAY['pc_target_ok(text, jsonb, text[], text[], text[], text[])', 'pc_target_ok_p34(text, jsonb, text[], text[], text[], text[])', 'pc_kind_risk(text)'] LOOP
    EXECUTE format('REVOKE ALL ON FUNCTION %s FROM PUBLIC, jarvis_app_rw, jarvis_admin', f);
    EXECUTE format('GRANT EXECUTE ON FUNCTION %s TO jarvis_app_rw, jarvis_admin', f);
  END LOOP;
END $$;
