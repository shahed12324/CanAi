"""Phase 28: the standing security audit of the database.  New migrations must keep passing these; each rule records WHY.

Rules
 1. Every SECURITY DEFINER function pins its search_path (otherwise a caller can shadow objects it uses).
 2. No SECURITY DEFINER function is executable by PUBLIC (only named roles).
 3. Housekeeping functions that touch every workspace are not executable by the app role.
 4. Views are read-only for the app role.
 5. Application roles are not superusers and cannot bypass RLS by attribute.
 6. Secrets are never selectable in plain columns by the app role (hash / ciphertext only, or no access).
 7. Every function the app role can run that takes a tenant id authorises it (credit_authorize / acct_open / *_guard ...), unless on the reviewed list.
"""
import re

import pytest

# Functions that are reviewed and intentionally callable by the app role WITHOUT a tenant check: each looks a thing up by an unguessable secret/id
# (token hash, ULID) the caller already holds, or is public by design (unsubscribe link, public review link), and reveals/changes only that one thing.
REVIEWED_NO_TENANT_CHECK = {
    "api_key_resolve", "billing_order_by_ref", "billing_order_tenant", "channel_account_authenticate", "channel_account_for_send", "channel_event_claim", "channel_event_finish",
    "channel_link_redeem", "channel_resolve", "invitation_accept", "invitation_preview", "outreach_unsubscribe", "provider_secret_for_call", "review_public_decide", "review_public_view",
    "seo_audit_allowed",
}
HOUSEKEEPING = ["credit_expire_lots()", "credit_release_stale()", "chat_finalize_stale(integer)", "lead_items_requeue_stale(integer)", "channel_events_purge(integer)"]
GUARDS = re.compile(r"credit_authorize|acct_open|app_tenant_id\(|app_is_admin|current_setting\('app\.tenant_id'|[a-z_]*_guard\(|[a-z_]*_lock\(|mobile_device_guard", re.I)


def q(owner, sql, args=()):
    return owner.execute(sql, args).fetchall()


def test_every_definer_function_pins_search_path(owner):
    bad = q(owner, """SELECT p.proname FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
                      WHERE n.nspname = 'public' AND p.prosecdef AND coalesce(p.proconfig::text, '') NOT LIKE '%%search_path%%' ORDER BY 1""")
    assert bad == []


def test_no_definer_function_is_executable_by_public(owner):
    bad = q(owner, """SELECT p.proname FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
                      WHERE n.nspname = 'public' AND p.prosecdef AND (p.proacl IS NULL OR EXISTS (SELECT 1 FROM unnest(p.proacl) a WHERE a::text LIKE '=%%')) ORDER BY 1""")
    assert bad == []


@pytest.mark.parametrize("fn", HOUSEKEEPING)
def test_housekeeping_is_not_runnable_by_the_app_role(owner, fn):
    assert owner.execute("SELECT has_function_privilege('jarvis_app_rw', %s::regprocedure, 'EXECUTE')", (fn,)).fetchone()[0] is False
    assert owner.execute("SELECT has_function_privilege('jarvis_admin', %s::regprocedure, 'EXECUTE')", (fn,)).fetchone()[0] is True


def test_views_are_read_only_for_the_app_role(owner):
    bad = q(owner, """SELECT c.relname, p FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace, unnest(ARRAY['INSERT','UPDATE','DELETE','TRUNCATE']) p
                      WHERE n.nspname = 'public' AND c.relkind IN ('v','m') AND has_table_privilege('jarvis_app_rw', c.oid, p) ORDER BY 1, 2""")
    assert bad == []


def test_application_roles_have_no_dangerous_attributes(owner):
    rows = q(owner, "SELECT rolname, rolsuper, rolbypassrls, rolcreaterole, rolcreatedb FROM pg_roles WHERE rolname IN ('jarvis_app', 'jarvis_app_rw', 'jarvis_admin', 'jarvis_admin_user')")
    assert len(rows) == 4
    for name, sup, bypass, createrole, createdb in rows:
        assert (sup, bypass, createrole, createdb) == (False, False, False, False), name


def test_secret_columns_are_not_readable_by_the_app_role(owner):
    """Provider keys, channel tokens, webhook signing secrets and other secrets: no SELECT for the app role on the secret column."""
    must_be_unreadable = [("provider_credentials", "secret_encrypted"), ("webhook_endpoints", "secret_encrypted"), ("channel_accounts", "token_encrypted"), ("channel_accounts", "webhook_secret_hash")]
    for table, col in must_be_unreadable:
        exists = owner.execute("SELECT count(*) FROM information_schema.columns WHERE table_name = %s AND column_name = %s", (table, col)).fetchone()[0]
        if exists:
            assert owner.execute("SELECT has_column_privilege('jarvis_app_rw', %s, %s, 'SELECT')", (f"public.{table}", col)).fetchone()[0] is False, f"{table}.{col}"


def test_api_keys_store_only_a_hash(owner):
    cols = {r[0] for r in q(owner, "SELECT column_name FROM information_schema.columns WHERE table_name = 'api_keys'")}
    assert "key_hash" in cols and not {"key", "secret", "token", "api_key"} & cols


def test_tenant_taking_functions_authorise_the_tenant(owner):
    """Any app-runnable definer function whose first parameters include a tenant id must call an authorisation guard (or be on the reviewed list)."""
    rows = q(owner, """SELECT p.proname, p.prosrc, pg_get_function_arguments(p.oid) FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
                       WHERE n.nspname = 'public' AND p.prosecdef AND p.prokind = 'f' AND has_function_privilege('jarvis_app_rw', p.oid, 'EXECUTE') ORDER BY 1""")
    unguarded = sorted({name for name, src, args in rows if re.search(r"\bp_tenant\b", args) and not GUARDS.search(src) and name not in REVIEWED_NO_TENANT_CHECK})
    assert unguarded == [], f"takes a tenant id but never authorises it: {unguarded}"


def test_reviewed_list_contains_only_functions_that_exist(owner):
    names = {r[0] for r in q(owner, "SELECT proname FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace WHERE n.nspname = 'public'")}
    assert REVIEWED_NO_TENANT_CHECK <= names


def test_rls_policies_never_allow_all(owner):
    """A policy whose USING is literally true would switch tenant isolation off for that table."""
    bad = q(owner, "SELECT tablename, policyname FROM pg_policies WHERE schemaname = 'public' AND (qual = 'true' OR with_check = 'true')")
    assert bad == []
