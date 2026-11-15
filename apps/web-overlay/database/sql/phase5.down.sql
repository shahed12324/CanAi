DROP FUNCTION IF EXISTS credit_reconcile(char), credit_wallet_configure(char, numeric, numeric), credit_expire_lots(),
  credit_release_stale(), credit_release(char, text), credit_settle(char, numeric, text, jsonb),
  credit_reserve(char, numeric, text, text, integer), credit_adjust(char, text, numeric, text, text, char),
  credit_grant(char, text, numeric, text, text, text, timestamptz, char, text),
  credit_post(char, text, numeric, numeric, text, text, text, timestamptz, char, text),
  credit_check_low(char), credit_consume_lots(char, numeric), credit_wallet_ensure(char), credit_usable(char),
  credit_authorize(char, boolean), credit_caller_is_admin(), credit_new_id();
DROP TABLE IF EXISTS provider_cost_records, credit_packages, credit_alerts, usage_records, credit_reservations,
  credit_lots, credit_transactions, wallets CASCADE;
