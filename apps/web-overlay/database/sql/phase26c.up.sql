-- Phase 26c (CAN AI handover, Milestone C): server rules for the six local file/document kinds the desktop agent now understands.
-- Risk: copy/mkdir/xlsx/docx = medium; move/trash = high (they remove or replace something, so a person approves every time and they can never be 'allow').
-- Every path (BOTH ends of copy/move) must be inside the device's allowed folders. Nothing here executes anything.

CREATE OR REPLACE FUNCTION pc_kind_risk(p_kind text) RETURNS text LANGUAGE sql IMMUTABLE AS $$
  SELECT CASE p_kind
    WHEN 'read_file' THEN 'low' WHEN 'list_dir' THEN 'low'
    WHEN 'write_file' THEN 'medium' WHEN 'open_app' THEN 'medium' WHEN 'browser_navigate' THEN 'medium' WHEN 'system_setting' THEN 'medium'
    WHEN 'copy_file' THEN 'medium' WHEN 'make_dir' THEN 'medium' WHEN 'create_xlsx' THEN 'medium' WHEN 'create_docx' THEN 'medium'
    WHEN 'delete_file' THEN 'high' WHEN 'run_command' THEN 'high' WHEN 'input_control' THEN 'high'
    WHEN 'move_file' THEN 'high' WHEN 'trash_file' THEN 'high'
    WHEN 'power' THEN 'critical' END
$$;

-- Does the action's target fall inside the device policy?  'ok' | 'bad_params' | 'outside_scope'
CREATE OR REPLACE FUNCTION pc_target_ok(p_kind text, p jsonb, p_roots text[], p_apps text[], p_commands text[], p_hosts text[]) RETURNS text
LANGUAGE plpgsql IMMUTABLE AS $$
DECLARE path text; r text; host text; url text; arg jsonb; pk text; inroot boolean;
BEGIN
  IF p_kind IN ('read_file', 'list_dir', 'write_file', 'delete_file') THEN
    IF jsonb_typeof(p -> 'path') IS DISTINCT FROM 'string' THEN RETURN 'bad_params'; END IF;
    path := replace(p ->> 'path', E'\\', '/');
    IF path ~ '[[:cntrl:]]' OR char_length(path) > 520 OR path !~ '^(/|[A-Za-z]:/)' OR path ~ '(^|/)\.\.(/|$)' THEN RETURN 'bad_params'; END IF;
    path := lower(regexp_replace(path, '/+$', ''));
    FOREACH r IN ARRAY p_roots LOOP
      r := lower(regexp_replace(replace(r, E'\\', '/'), '/+$', ''));
      IF r <> '' AND (path = r OR left(path, char_length(r) + 1) = r || '/') THEN RETURN 'ok'; END IF;
    END LOOP;
    RETURN 'outside_scope';
  ELSIF p_kind IN ('copy_file', 'move_file') THEN
    -- BOTH ends must be inside an allowed folder
    FOREACH pk IN ARRAY ARRAY['src', 'dst'] LOOP
      IF jsonb_typeof(p -> pk) IS DISTINCT FROM 'string' THEN RETURN 'bad_params'; END IF;
      path := replace(p ->> pk, E'\\', '/');
      IF path ~ '[[:cntrl:]]' OR char_length(path) > 520 OR path !~ '^(/|[A-Za-z]:/)' OR path ~ '(^|/)\.\.(/|$)' THEN RETURN 'bad_params'; END IF;
      path := lower(regexp_replace(path, '/+$', ''));
      inroot := false;
      FOREACH r IN ARRAY p_roots LOOP
        r := lower(regexp_replace(replace(r, E'\\', '/'), '/+$', ''));
        IF r <> '' AND (path = r OR left(path, char_length(r) + 1) = r || '/') THEN inroot := true; END IF;
      END LOOP;
      IF NOT inroot THEN RETURN 'outside_scope'; END IF;
    END LOOP;
    IF p ? 'overwrite' AND jsonb_typeof(p -> 'overwrite') IS DISTINCT FROM 'boolean' THEN RETURN 'bad_params'; END IF;
    RETURN 'ok';
  ELSIF p_kind IN ('make_dir', 'trash_file', 'create_xlsx', 'create_docx') THEN
    RETURN pc_target_ok('read_file', p, p_roots, p_apps, p_commands, p_hosts);
  ELSIF p_kind = 'open_app' THEN
    IF jsonb_typeof(p -> 'app') IS DISTINCT FROM 'string' THEN RETURN 'bad_params'; END IF;
    RETURN CASE WHEN lower(p ->> 'app') = ANY (SELECT lower(a) FROM unnest(p_apps) a) THEN 'ok' ELSE 'outside_scope' END;
  ELSIF p_kind = 'run_command' THEN
    IF jsonb_typeof(p -> 'command') IS DISTINCT FROM 'string' THEN RETURN 'bad_params'; END IF;
    IF p ? 'args' THEN
      IF jsonb_typeof(p -> 'args') IS DISTINCT FROM 'array' OR jsonb_array_length(p -> 'args') > 20 THEN RETURN 'bad_params'; END IF;
      FOR arg IN SELECT * FROM jsonb_array_elements(p -> 'args') LOOP
        IF jsonb_typeof(arg) IS DISTINCT FROM 'string' OR char_length(arg #>> '{}') > 200 OR (arg #>> '{}') ~ '[[:cntrl:]]' THEN RETURN 'bad_params'; END IF;
      END LOOP;
    END IF;
    RETURN CASE WHEN lower(p ->> 'command') = ANY (SELECT lower(c) FROM unnest(p_commands) c) THEN 'ok' ELSE 'outside_scope' END;
  ELSIF p_kind = 'browser_navigate' THEN
    IF jsonb_typeof(p -> 'url') IS DISTINCT FROM 'string' THEN RETURN 'bad_params'; END IF;
    url := p ->> 'url';
    IF char_length(url) > 2000 OR url ~ '[[:cntrl:][:space:]]' OR url !~* '^https://[a-z0-9]([a-z0-9.-]*[a-z0-9])?(:[0-9]{1,5})?([/?#]|$)' THEN RETURN 'bad_params'; END IF;
    host := lower(substring(url from '^[Hh][Tt][Tt][Pp][Ss]://([A-Za-z0-9.-]+)'));
    RETURN CASE WHEN EXISTS (SELECT 1 FROM unnest(p_hosts) h WHERE host = lower(h) OR host LIKE '%.' || lower(h)) THEN 'ok' ELSE 'outside_scope' END;
  ELSIF p_kind = 'input_control' THEN
    RETURN CASE WHEN p ->> 'action' IN ('type', 'click', 'key') THEN 'ok' ELSE 'bad_params' END;
  ELSIF p_kind = 'system_setting' THEN
    RETURN CASE WHEN p ->> 'setting' IN ('volume', 'brightness', 'wifi', 'bluetooth') AND p ? 'value' THEN 'ok' ELSE 'bad_params' END;
  ELSIF p_kind = 'power' THEN
    RETURN CASE WHEN p ->> 'action' IN ('shutdown', 'restart', 'sleep') THEN 'ok' ELSE 'bad_params' END;
  END IF;
  RETURN 'bad_params';
END $$;
