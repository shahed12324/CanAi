-- Phase 16a: bank / bKash / Nagad statement import and reconciliation.
-- A statement line is a fact from outside (the bank says so); the books are the journal. Reconciling = pairing each statement line with the journal line that records it,
-- or posting a new entry for a line the books do not have yet. Matching never changes the books; only bank_create_entry posts (through journal_post).
-- Only cash_account accounts (cash, bank, wallets) take statements. Positive amount = money in (debit), negative = money out (credit).

CREATE TABLE bank_imports (
  id          char(26) PRIMARY KEY,
  tenant_id   char(26)    NOT NULL REFERENCES tenants (id),
  account_id  char(26)    NOT NULL,
  filename    varchar(200),
  rows_total  integer     NOT NULL CHECK (rows_total BETWEEN 1 AND 5000),
  rows_new    integer     NOT NULL CHECK (rows_new >= 0),
  rows_dup    integer     NOT NULL CHECK (rows_dup >= 0),
  created_by  char(26)    NOT NULL,
  created_at  timestamptz NOT NULL DEFAULT now(),
  UNIQUE (id, tenant_id),
  FOREIGN KEY (account_id, tenant_id) REFERENCES accounts (id, tenant_id),
  CHECK (rows_new + rows_dup = rows_total)
);

CREATE TABLE bank_lines (
  id             char(26) PRIMARY KEY,
  tenant_id      char(26)    NOT NULL REFERENCES tenants (id),
  account_id     char(26)    NOT NULL,
  import_id      char(26)    NOT NULL,
  txn_date       date        NOT NULL CHECK (txn_date >= DATE '2000-01-01'),
  description    varchar(300) NOT NULL DEFAULT '',
  reference      varchar(80),
  amount_minor   bigint      NOT NULL CHECK (amount_minor <> 0 AND abs(amount_minor) <= 999999999999),
  balance_minor  bigint,
  dedupe_key     varchar(80) NOT NULL,
  status         text        NOT NULL DEFAULT 'unmatched' CHECK (status IN ('unmatched', 'matched', 'ignored')),
  journal_line_seq bigint REFERENCES journal_lines (seq),
  matched_by     char(26),
  matched_at     timestamptz,
  created_at     timestamptz NOT NULL DEFAULT now(),
  UNIQUE (id, tenant_id),
  UNIQUE (tenant_id, account_id, dedupe_key),
  FOREIGN KEY (account_id, tenant_id) REFERENCES accounts (id, tenant_id),
  FOREIGN KEY (import_id, tenant_id) REFERENCES bank_imports (id, tenant_id),
  CONSTRAINT status_shape CHECK ((status = 'matched') = (journal_line_seq IS NOT NULL))
);
CREATE UNIQUE INDEX bank_lines_one_per_journal_line ON bank_lines (journal_line_seq) WHERE journal_line_seq IS NOT NULL;
CREATE INDEX bank_lines_account_date ON bank_lines (tenant_id, account_id, txn_date, id);

DO $$ DECLARE t text; BEGIN
  FOREACH t IN ARRAY ARRAY['bank_imports', 'bank_lines'] LOOP
    EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', t);
    EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY', t);
    EXECUTE format('CREATE POLICY tenant_isolation ON %I USING (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin())) WITH CHECK (tenant_id = (SELECT app_tenant_id()) OR (SELECT app_is_admin()))', t);
    EXECUTE format('REVOKE ALL ON %I FROM jarvis_app_rw, jarvis_admin', t);
    EXECUTE format('GRANT SELECT ON %I TO jarvis_app_rw, jarvis_admin', t);
  END LOOP;
END $$;

-- Import parsed statement rows. p_rows = [{"date":"2026-11-03","description":"...","reference":"...","amount_minor":-1500,"balance_minor":98500}, ...] (1..5000 rows).
-- Atomic: one bad row refuses the whole file and rows_new then holds the 1-based number of that row. Importing the same lines again is harmless: each line has a key
-- (date, amount, description, reference + how many identical lines came before it in the file) and known keys are counted in rows_dup, not inserted again.
-- result: ok | bad_account | bad_rows | bad_row
CREATE FUNCTION bank_import(p_import char(26), p_tenant char(26), p_user char(26), p_account char(26), p_filename text, p_rows jsonb) RETURNS TABLE (result text, rows_new integer, rows_dup integer)
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); a accounts%ROWTYPE; x jsonb; ord integer := 0; d date; amt bigint; bal bigint; total integer; ins integer;
BEGIN
  PERFORM acct_open(p_tenant);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO a FROM accounts WHERE id = p_account AND tenant_id = p_tenant AND active AND cash_account;
  PERFORM set_config('app.bypass_rls', prev, true);
  IF a.id IS NULL THEN RETURN QUERY SELECT 'bad_account'::text, 0, 0; RETURN; END IF;
  IF p_rows IS NULL OR jsonb_typeof(p_rows) <> 'array' OR jsonb_array_length(p_rows) < 1 OR jsonb_array_length(p_rows) > 5000 THEN RETURN QUERY SELECT 'bad_rows'::text, 0, 0; RETURN; END IF;
  total := jsonb_array_length(p_rows);
  FOR x IN SELECT * FROM jsonb_array_elements(p_rows) LOOP
    ord := ord + 1;
    BEGIN d := (x->>'date')::date; amt := (x->>'amount_minor')::bigint; bal := nullif(x->>'balance_minor', '')::bigint;
    EXCEPTION WHEN others THEN RETURN QUERY SELECT 'bad_row'::text, ord, 0; RETURN; END;
    IF d IS NULL OR d < DATE '2000-01-01' OR d > (now() AT TIME ZONE 'Asia/Dhaka')::date + 1 OR amt IS NULL OR amt = 0 OR abs(amt) > 999999999999 OR (bal IS NOT NULL AND abs(bal) > 999999999999999)
       OR length(coalesce(x->>'description', '')) > 300 OR length(coalesce(x->>'reference', '')) > 80 THEN
      RETURN QUERY SELECT 'bad_row'::text, ord, 0; RETURN;
    END IF;
  END LOOP;
  PERFORM set_config('app.bypass_rls', 'on', true);
  INSERT INTO bank_imports (id, tenant_id, account_id, filename, rows_total, rows_new, rows_dup, created_by) VALUES (p_import, p_tenant, p_account, left(p_filename, 200), total, total, 0, p_user);
  WITH r AS (
    SELECT o, (e->>'date')::date AS d, btrim(coalesce(e->>'description', '')) AS descr, nullif(btrim(coalesce(e->>'reference', '')), '') AS ref, (e->>'amount_minor')::bigint AS amt, nullif(e->>'balance_minor', '')::bigint AS bal
      FROM jsonb_array_elements(p_rows) WITH ORDINALITY AS t(e, o)),
  k AS (
    SELECT r.*, md5(r.d::text || '|' || r.amt::text || '|' || lower(r.descr) || '|' || coalesce(lower(r.ref), '')) AS h,
           row_number() OVER (PARTITION BY r.d, r.amt, lower(r.descr), coalesce(lower(r.ref), '') ORDER BY r.o) AS occ
      FROM r)
  INSERT INTO bank_lines (id, tenant_id, account_id, import_id, txn_date, description, reference, amount_minor, balance_minor, dedupe_key)
  SELECT lower(substr(md5(p_import || k.o::text || random()::text), 1, 26)), p_tenant, p_account, p_import, k.d, k.descr, k.ref, k.amt, k.bal, k.h || '#' || k.occ
    FROM k ORDER BY k.o
  ON CONFLICT (tenant_id, account_id, dedupe_key) DO NOTHING;
  GET DIAGNOSTICS ins = ROW_COUNT;
  UPDATE bank_imports SET rows_new = ins, rows_dup = total - ins WHERE id = p_import;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN QUERY SELECT 'ok'::text, ins, total - ins;
END $$;

-- NB: PERFORM sets FOUND, so existence is tested on the row's id, never on FOUND after a set_config.
-- Helper: load one statement line the caller may touch (raises 'forbidden' for another workspace's line).
CREATE FUNCTION bank_line_lock(p_line char(26)) RETURNS bank_lines
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); b bank_lines%ROWTYPE;
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO b FROM bank_lines WHERE id = p_line FOR UPDATE;
  PERFORM set_config('app.bypass_rls', prev, true);
  IF b.id IS NULL THEN RAISE EXCEPTION 'forbidden' USING ERRCODE = '42501'; END IF;
  PERFORM credit_authorize(b.tenant_id, false);
  RETURN b;
END $$;

-- Pair a statement line with a journal line of the same account and the same amount (in = debit, out = credit). result: ok | not_open | bad_journal_line | taken
CREATE FUNCTION bank_match(p_line char(26), p_seq bigint, p_user char(26)) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); b bank_lines%ROWTYPE; j journal_lines%ROWTYPE;
BEGIN
  b := bank_line_lock(p_line);
  IF b.status <> 'unmatched' THEN RETURN 'not_open'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO j FROM journal_lines WHERE seq = p_seq AND tenant_id = b.tenant_id AND account_id = b.account_id AND debit_minor - credit_minor = b.amount_minor;
  IF NOT FOUND THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'bad_journal_line'; END IF;
  IF EXISTS (SELECT 1 FROM bank_lines WHERE journal_line_seq = p_seq) THEN PERFORM set_config('app.bypass_rls', prev, true); RETURN 'taken'; END IF;
  UPDATE bank_lines SET status = 'matched', journal_line_seq = p_seq, matched_by = p_user, matched_at = now() WHERE id = p_line;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

-- Undo a pairing. result: ok | not_matched
CREATE FUNCTION bank_unmatch(p_line char(26)) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); b bank_lines%ROWTYPE;
BEGIN
  b := bank_line_lock(p_line);
  IF b.status <> 'matched' THEN RETURN 'not_matched'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE bank_lines SET status = 'unmatched', journal_line_seq = NULL, matched_by = NULL, matched_at = NULL WHERE id = p_line;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

-- Set a line aside (e.g. a duplicate the bank itself reported) or bring it back. result: ok | not_open | not_ignored
CREATE FUNCTION bank_ignore(p_line char(26), p_on boolean) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); b bank_lines%ROWTYPE;
BEGIN
  b := bank_line_lock(p_line);
  IF p_on AND b.status <> 'unmatched' THEN RETURN 'not_open'; END IF;
  IF NOT p_on AND b.status <> 'ignored' THEN RETURN 'not_ignored'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  UPDATE bank_lines SET status = CASE WHEN p_on THEN 'ignored' ELSE 'unmatched' END WHERE id = p_line;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

-- The books lack this line: post it (bank vs the account the person picks) dated the statement day, and pair it. Source 'system', reference 'bank:<line id>'.
-- result: ok | not_open | bad_account | <journal_post result: locked | bad_date | bad_memo ...>
CREATE FUNCTION bank_create_entry(p_line char(26), p_entry char(26), p_user char(26), p_counter char(26), p_memo text) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); b bank_lines%ROWTYPE; c accounts%ROWTYPE; jr record; jseq bigint; memo text; lines jsonb;
BEGIN
  b := bank_line_lock(p_line);
  IF p_user IS NULL THEN RAISE EXCEPTION 'user required' USING ERRCODE = '22023'; END IF;
  IF b.status <> 'unmatched' THEN RETURN 'not_open'; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO c FROM accounts WHERE id = p_counter AND tenant_id = b.tenant_id AND active AND postable AND id <> b.account_id;
  PERFORM set_config('app.bypass_rls', prev, true);
  IF c.id IS NULL THEN RETURN 'bad_account'; END IF;
  memo := coalesce(nullif(btrim(coalesce(p_memo, '')), ''), nullif(b.description, ''), 'Bank statement line');
  lines := CASE WHEN b.amount_minor > 0
    THEN jsonb_build_array(jsonb_build_object('account_id', b.account_id, 'debit_minor', b.amount_minor, 'credit_minor', 0), jsonb_build_object('account_id', c.id, 'debit_minor', 0, 'credit_minor', b.amount_minor))
    ELSE jsonb_build_array(jsonb_build_object('account_id', c.id, 'debit_minor', -b.amount_minor, 'credit_minor', 0), jsonb_build_object('account_id', b.account_id, 'debit_minor', 0, 'credit_minor', -b.amount_minor)) END;
  SELECT * INTO jr FROM journal_post(p_entry, b.tenant_id, p_user, b.txn_date, left(memo, 200), 'system', 'bank:' || b.id, lines);
  IF jr.result <> 'ok' THEN RETURN jr.result; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT l.seq INTO jseq FROM journal_lines l WHERE l.entry_id = p_entry AND l.tenant_id = b.tenant_id AND l.account_id = b.account_id;
  UPDATE bank_lines SET status = 'matched', journal_line_seq = jseq, matched_by = p_user, matched_at = now() WHERE id = p_line;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN 'ok';
END $$;

-- Pair open lines with free journal lines of the same amount within p_window days: all same-day pairs first, then one day apart, and so on (oldest line first within a round). Returns how many were paired.
CREATE FUNCTION bank_auto_match(p_account char(26), p_user char(26), p_window integer) RETURNS integer
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE prev text := coalesce(current_setting('app.bypass_rls', true), ''); a accounts%ROWTYPE; b record; s bigint; n integer := 0; dd integer;
BEGIN
  PERFORM set_config('app.bypass_rls', 'on', true);
  SELECT * INTO a FROM accounts WHERE id = p_account;
  PERFORM set_config('app.bypass_rls', prev, true);
  IF a.id IS NULL THEN RAISE EXCEPTION 'forbidden' USING ERRCODE = '42501'; END IF;
  PERFORM credit_authorize(a.tenant_id, false);
  IF p_window IS NULL OR p_window < 0 OR p_window > 30 THEN p_window := 3; END IF;
  PERFORM set_config('app.bypass_rls', 'on', true);
  FOR dd IN 0..p_window LOOP                                  -- same day first, then one day apart, ... so a near pair is never robbed by a far one
    FOR b IN SELECT id, txn_date, amount_minor FROM bank_lines WHERE tenant_id = a.tenant_id AND account_id = p_account AND status = 'unmatched' ORDER BY txn_date, id LOOP
      SELECT l.seq INTO s FROM journal_lines l JOIN journal_entries e ON e.id = l.entry_id AND e.tenant_id = l.tenant_id
       WHERE l.tenant_id = a.tenant_id AND l.account_id = p_account AND l.debit_minor - l.credit_minor = b.amount_minor AND abs(e.entry_date - b.txn_date) = dd
         AND NOT EXISTS (SELECT 1 FROM bank_lines x WHERE x.journal_line_seq = l.seq)
       ORDER BY l.seq LIMIT 1;
      IF FOUND THEN
        UPDATE bank_lines SET status = 'matched', journal_line_seq = s, matched_by = p_user, matched_at = now() WHERE id = b.id;
        n := n + 1;
      END IF;
    END LOOP;
  END LOOP;
  PERFORM set_config('app.bypass_rls', prev, true);
  RETURN n;
END $$;

-- Reads (as the caller; RLS keeps them to one workspace).
-- For each open statement line, the free journal lines it could be paired with (same account, same amount, within p_window days), nearest first.
CREATE FUNCTION bank_suggestions(p_account char(26), p_window integer) RETURNS TABLE (line_id char(26), journal_line_seq bigint, entry_id char(26), entry_number integer, entry_date date, memo varchar, day_diff integer)
LANGUAGE sql STABLE AS $$
  SELECT b.id, l.seq, e.id, e.number, e.entry_date, e.memo, abs(e.entry_date - b.txn_date)
    FROM bank_lines b
    JOIN journal_lines l ON l.tenant_id = b.tenant_id AND l.account_id = b.account_id AND l.debit_minor - l.credit_minor = b.amount_minor
    JOIN journal_entries e ON e.id = l.entry_id AND e.tenant_id = l.tenant_id
   WHERE b.account_id = p_account AND b.status = 'unmatched' AND abs(e.entry_date - b.txn_date) <= least(greatest(coalesce(p_window, 3), 0), 30)
     AND NOT EXISTS (SELECT 1 FROM bank_lines x WHERE x.journal_line_seq = l.seq)
   ORDER BY b.txn_date, b.id, abs(e.entry_date - b.txn_date), l.seq
$$;

-- Where an account stands on a day. Books = journal; statement = the balance printed on the newest line up to that day (NULL when the file had no balance column).
-- Only journal lines dated from the first statement line on count as "not on the statement" (earlier history cannot be on it).
CREATE FUNCTION bank_reconciliation(p_account char(26), p_asof date) RETURNS TABLE (book_minor bigint, statement_minor bigint, since date, matched_count bigint, open_lines bigint, open_lines_minor bigint, ignored_count bigint, open_book_lines bigint, open_book_minor bigint)
LANGUAGE sql STABLE AS $$
  WITH b AS (SELECT * FROM bank_lines WHERE account_id = p_account AND txn_date <= p_asof),
       fst AS (SELECT min(txn_date) AS d FROM bank_lines WHERE account_id = p_account)
  SELECT (SELECT coalesce(sum(l.debit_minor - l.credit_minor), 0) FROM journal_lines l JOIN journal_entries e ON e.id = l.entry_id AND e.tenant_id = l.tenant_id WHERE l.account_id = p_account AND e.entry_date <= p_asof)::bigint,
         (SELECT balance_minor FROM b WHERE balance_minor IS NOT NULL ORDER BY txn_date DESC, id DESC LIMIT 1),
         (SELECT d FROM fst),
         (SELECT count(*) FROM b WHERE status = 'matched'),
         (SELECT count(*) FROM b WHERE status = 'unmatched'),
         (SELECT coalesce(sum(amount_minor), 0)::bigint FROM b WHERE status = 'unmatched'),
         (SELECT count(*) FROM b WHERE status = 'ignored'),
         (SELECT count(*) FROM journal_lines l JOIN journal_entries e ON e.id = l.entry_id AND e.tenant_id = l.tenant_id
           WHERE l.account_id = p_account AND e.entry_date <= p_asof AND e.entry_date >= (SELECT d FROM fst) AND NOT EXISTS (SELECT 1 FROM bank_lines x WHERE x.journal_line_seq = l.seq)),
         (SELECT coalesce(sum(l.debit_minor - l.credit_minor), 0)::bigint FROM journal_lines l JOIN journal_entries e ON e.id = l.entry_id AND e.tenant_id = l.tenant_id
           WHERE l.account_id = p_account AND e.entry_date <= p_asof AND e.entry_date >= (SELECT d FROM fst) AND NOT EXISTS (SELECT 1 FROM bank_lines x WHERE x.journal_line_seq = l.seq))
$$;

REVOKE ALL ON FUNCTION bank_import(char, char, char, char, text, jsonb), bank_line_lock(char), bank_match(char, bigint, char), bank_unmatch(char), bank_ignore(char, boolean),
  bank_create_entry(char, char, char, char, text), bank_auto_match(char, char, integer) FROM PUBLIC, jarvis_app_rw, jarvis_admin;
GRANT EXECUTE ON FUNCTION bank_import(char, char, char, char, text, jsonb), bank_match(char, bigint, char), bank_unmatch(char), bank_ignore(char, boolean),
  bank_create_entry(char, char, char, char, text), bank_auto_match(char, char, integer) TO jarvis_app_rw, jarvis_admin;
REVOKE ALL ON FUNCTION bank_suggestions(char, integer), bank_reconciliation(char, date) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION bank_suggestions(char, integer), bank_reconciliation(char, date) TO jarvis_app_rw, jarvis_admin;
