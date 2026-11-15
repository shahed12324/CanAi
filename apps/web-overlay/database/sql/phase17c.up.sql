-- Phase 17c: tax calendar. A person keeps a list of recurring or one-off tax obligations (VAT return, VDS/TDS deposit, income-tax return, ...)
-- and ticks each due date done. Nothing here posts to the books and nothing here knows an NBR deadline: every day / month is DATA the person
-- (or their accountant) sets, because the deadlines change and sources disagree. The starter list is a DRAFT to verify.

CREATE TABLE tax_obligations (
  id          char(26)     PRIMARY KEY,
  tenant_id   char(26)     NOT NULL REFERENCES tenants (id),
  label       varchar(80)  NOT NULL CHECK (btrim(label) <> ''),
  kind        text         NOT NULL CHECK (kind IN ('vat_return', 'vds_deposit', 'tds_deposit', 'income_tax', 'advance_tax', 'license', 'other')),
  freq        text         NOT NULL CHECK (freq IN ('monthly', 'quarterly', 'yearly', 'once')),
  due_day     smallint     CHECK (due_day BETWEEN 1 AND 31),          -- 29..31 means "last day of a shorter month"
  due_month   smallint     CHECK (due_month BETWEEN 1 AND 12),        -- yearly: the month; quarterly: first month of the cycle (1..3)
  once_date   date,
  remind_days smallint     NOT NULL DEFAULT 7 CHECK (remind_days BETWEEN 0 AND 60),
  active      boolean      NOT NULL DEFAULT true,
  created_by  char(26)     NOT NULL REFERENCES users (id),
  created_at  timestamptz  NOT NULL DEFAULT now(),
  UNIQUE (id, tenant_id),
  CONSTRAINT schedule_shape CHECK (
    (freq = 'once'      AND once_date IS NOT NULL AND due_day IS NULL AND due_month IS NULL) OR
    (freq = 'monthly'   AND once_date IS NULL AND due_day IS NOT NULL AND due_month IS NULL) OR
    (freq = 'quarterly' AND once_date IS NULL AND due_day IS NOT NULL AND due_month BETWEEN 1 AND 3) OR
    (freq = 'yearly'    AND once_date IS NULL AND due_day IS NOT NULL AND due_month IS NOT NULL))
);

CREATE TABLE tax_obligation_done (
  obligation_id char(26)    NOT NULL,
  tenant_id     char(26)    NOT NULL REFERENCES tenants (id),
  due_date      date        NOT NULL,
  done_on       date        NOT NULL,
  note          varchar(200),
  done_by       char(26)    NOT NULL REFERENCES users (id),
  created_at    timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY (obligation_id, due_date),
  FOREIGN KEY (obligation_id, tenant_id) REFERENCES tax_obligations (id, tenant_id)
);

DO $$
DECLARE t text;
BEGIN
  FOREACH t IN ARRAY ARRAY['tax_obligations', 'tax_obligation_done'] LOOP
    EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', t);
    EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY', t);
    EXECUTE format('CREATE POLICY tenant_isolation ON %I USING (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin())) WITH CHECK (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()))', t);
    EXECUTE format('REVOKE ALL ON %I FROM jarvis_app_rw, jarvis_admin', t);
    EXECUTE format('GRANT SELECT ON %I TO jarvis_app_rw, jarvis_admin', t);
  END LOOP;
END $$;

-- Is d one of the due dates of this schedule? (day clamped to the month's last day)
CREATE FUNCTION tax_due_matches(p_freq text, p_day integer, p_month integer, p_once date, d date) RETURNS boolean
LANGUAGE sql IMMUTABLE AS $$
  SELECT CASE p_freq
    WHEN 'once' THEN d = p_once
    WHEN 'monthly' THEN extract(day FROM d) = least(p_day, extract(day FROM (date_trunc('month', d) + interval '1 month - 1 day')))
    WHEN 'quarterly' THEN ((extract(month FROM d)::int - 1) % 3) + 1 = p_month
      AND extract(day FROM d) = least(p_day, extract(day FROM (date_trunc('month', d) + interval '1 month - 1 day')))
    WHEN 'yearly' THEN extract(month FROM d) = p_month
      AND extract(day FROM d) = least(p_day, extract(day FROM (date_trunc('month', d) + interval '1 month - 1 day')))
    ELSE false END
$$;

-- Create (unknown id) or change (known id of this tenant) an obligation.  result: ok | bad_input | bad_schedule
CREATE FUNCTION tax_obligation_save(p_id char(26), p_tenant char(26), p_user char(26), p_label text, p_kind text, p_freq text, p_day integer, p_month integer, p_once date, p_remind integer) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); found boolean;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  IF p_label IS NULL OR btrim(p_label) = '' OR p_kind NOT IN ('vat_return', 'vds_deposit', 'tds_deposit', 'income_tax', 'advance_tax', 'license', 'other')
     OR p_freq IS NULL OR p_freq NOT IN ('monthly', 'quarterly', 'yearly', 'once') OR p_remind IS NULL OR p_remind NOT BETWEEN 0 AND 60 THEN RETURN 'bad_input'; END IF;
  IF NOT coalesce((p_freq = 'once' AND p_once IS NOT NULL AND p_day IS NULL AND p_month IS NULL)
       OR (p_freq = 'monthly' AND p_once IS NULL AND p_day BETWEEN 1 AND 31 AND p_month IS NULL)
       OR (p_freq = 'quarterly' AND p_once IS NULL AND p_day BETWEEN 1 AND 31 AND p_month BETWEEN 1 AND 3)
       OR (p_freq = 'yearly' AND p_once IS NULL AND p_day BETWEEN 1 AND 31 AND p_month BETWEEN 1 AND 12), false) THEN RETURN 'bad_schedule'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT true INTO found FROM tax_obligations WHERE id = p_id AND tenant_id = p_tenant;
  IF found THEN
    UPDATE tax_obligations SET label = left(btrim(p_label), 80), kind = p_kind, freq = p_freq, due_day = p_day, due_month = p_month, once_date = p_once, remind_days = p_remind WHERE id = p_id;
  ELSE
    IF EXISTS (SELECT 1 FROM tax_obligations WHERE id = p_id) THEN PERFORM set_config('app.bypass_rls', prev, true); RAISE EXCEPTION 'forbidden' USING ERRCODE = '42501'; END IF;
    INSERT INTO tax_obligations (id, tenant_id, label, kind, freq, due_day, due_month, once_date, remind_days, created_by)
    VALUES (p_id, p_tenant, left(btrim(p_label), 80), p_kind, p_freq, p_day, p_month, p_once, p_remind, p_user);
  END IF;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

-- Switch an obligation on/off (history is kept).  result: ok
CREATE FUNCTION tax_obligation_active(p_id char(26), p_tenant char(26), p_active boolean) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); n integer;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE tax_obligations SET active = coalesce(p_active, true) WHERE id = p_id AND tenant_id = p_tenant;
  GET DIAGNOSTICS n = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  IF n = 0 THEN RAISE EXCEPTION 'forbidden' USING ERRCODE = '42501'; END IF;
  RETURN 'ok';
END $$;

-- Tick one due date done.  result: ok | bad_due (not a due date of this obligation) | bad_date (done_on missing / future) | already
CREATE FUNCTION tax_cal_done(p_obligation char(26), p_tenant char(26), p_user char(26), p_due date, p_done date, p_note text) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); o tax_obligations%ROWTYPE;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO o FROM tax_obligations WHERE id = p_obligation AND tenant_id = p_tenant;
  IF o.id IS NULL THEN PERFORM set_config('app.bypass_rls', prev, true); RAISE EXCEPTION 'forbidden' USING ERRCODE = '42501'; END IF;
  IF p_due IS NULL OR NOT tax_due_matches(o.freq, o.due_day, o.due_month, o.once_date, p_due) THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'bad_due'; END IF;
  IF p_done IS NULL OR p_done > (now() AT TIME ZONE 'Asia/Dhaka')::date OR p_done < DATE '2012-07-01' THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'bad_date'; END IF;
  IF EXISTS (SELECT 1 FROM tax_obligation_done WHERE obligation_id = p_obligation AND due_date = p_due) THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'already'; END IF;
  INSERT INTO tax_obligation_done (obligation_id, tenant_id, due_date, done_on, note, done_by) VALUES (p_obligation, p_tenant, p_due, p_done, left(nullif(btrim(coalesce(p_note, '')), ''), 200), p_user);
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

-- Take a tick back (mistaken click).  result: ok | missing
CREATE FUNCTION tax_cal_undo(p_obligation char(26), p_tenant char(26), p_due date) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); n integer;
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  PERFORM set_config('app.bypass_rls', 'on', true);
  DELETE FROM tax_obligation_done WHERE obligation_id = p_obligation AND tenant_id = p_tenant AND due_date = p_due;
  GET DIAGNOSTICS n = ROW_COUNT;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN CASE WHEN n = 0 THEN 'missing' ELSE 'ok' END;
END $$;

-- Starter list (a DRAFT - days are placeholders to confirm with the accountant).  ids: p_ids has 5 fresh ids.  result: ok | already | bad_ids
CREATE FUNCTION tax_cal_seed(p_tenant char(26), p_user char(26), p_ids text[]) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), '');
BEGIN
  PERFORM credit_authorize(p_tenant, false);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  IF p_ids IS NULL OR coalesce(array_length(p_ids, 1), 0) <> 5 THEN RETURN 'bad_ids'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  IF EXISTS (SELECT 1 FROM tax_obligations WHERE tenant_id = p_tenant) THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'already'; END IF;
  INSERT INTO tax_obligations (id, tenant_id, label, kind, freq, due_day, due_month, remind_days, created_by) VALUES
    (p_ids[1], p_tenant, 'মূসক রিটার্ন (খসড়া - যাচাই করুন)', 'vat_return', 'monthly', 15, NULL, 7, p_user),
    (p_ids[2], p_tenant, 'VDS জমা (খসড়া - যাচাই করুন)', 'vds_deposit', 'monthly', 15, NULL, 7, p_user),
    (p_ids[3], p_tenant, 'TDS জমা (খসড়া - যাচাই করুন)', 'tds_deposit', 'monthly', 15, NULL, 7, p_user),
    (p_ids[4], p_tenant, 'আয়কর রিটার্ন (খসড়া - যাচাই করুন)', 'income_tax', 'yearly', 30, 11, 30, p_user),
    (p_ids[5], p_tenant, 'অগ্রিম কর কিস্তি (খসড়া - যাচাই করুন)', 'advance_tax', 'quarterly', 15, 3, 14, p_user);
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

REVOKE ALL ON FUNCTION tax_due_matches(text, integer, integer, date, date), tax_obligation_save(char, char, char, text, text, text, integer, integer, date, integer), tax_obligation_active(char, char, boolean),
  tax_cal_done(char, char, char, date, date, text), tax_cal_undo(char, char, date), tax_cal_seed(char, char, text[]) FROM PUBLIC, jarvis_app_rw, jarvis_admin;
GRANT EXECUTE ON FUNCTION tax_due_matches(text, integer, integer, date, date), tax_obligation_save(char, char, char, text, text, text, integer, integer, date, integer), tax_obligation_active(char, char, boolean),
  tax_cal_done(char, char, char, date, date, text), tax_cal_undo(char, char, date), tax_cal_seed(char, char, text[]) TO jarvis_app_rw, jarvis_admin;
