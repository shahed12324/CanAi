# Phase 5 design — Credits & Usage Engine (money rules)

**Principle:** money moves only through SECURITY DEFINER SQL functions. The app/admin logins have
SELECT only on `wallets`, `credit_transactions`, `credit_lots`, `credit_reservations`, `usage_records`.
So no application bug (or SQL injection through the app login) can edit a balance directly.

* `wallets.balance` = credits owned (reserved ones included). `reserved` = held for in-flight requests.
  `available = usable balance − reserved`. CHECK: `0 ≤ reserved ≤ balance`.
* Every wallet is locked `FOR UPDATE` first, so operations per tenant are serialised; that also makes
  idempotency race-free (the key check happens *after* the lock).
* Ledger `credit_transactions` is append-only (trigger). Invariants (checked by `credit_reconcile`):
  `balance = Σ amount`, `reserved = Σ reserved_delta = Σ held reservations`, `balance = Σ lot.remaining`.
* Credits live in **lots** (`credit_lots`) so grants can expire. Debits take expiring lots first.
  Expired-but-unswept lots are not *usable* for new reservations (exact expiry, no sweep lag).
* Flow: `credit_reserve` → (call provider) → `credit_settle(actual)` or `credit_release`.
  Overrun beyond the reservation is charged only from available credits; the rest is recorded as
  `shortfall` (never a negative balance). Late settle after a stale-sweep still charges (cost incurred).
* Every money function requires an idempotency key; replay returns the original result, a key reused
  for different parameters raises `idempotency_conflict`.
* Authorisation inside the functions: caller's tenant context must match, or the *login* role
  (`session_user`) must be in `jarvis_admin` with the bypass flag. Adjustments are admin-only.
