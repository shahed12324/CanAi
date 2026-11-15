/** When should a screen that shows things waiting for approval ask the server again?  Pure, so it can be tested without a phone.
 *  Push is the fast path; polling is the safety net and the ONLY path when push is not available (no permission, simulator, no EAS project, Expo down). */
export interface RefreshState {
  pushOn: boolean;        // this phone has a push address registered with the server
  appActive: boolean;     // the app is in the foreground
  screenOpen: boolean;    // the screen that shows the list is the one on screen
  failures: number;       // consecutive failed refreshes (offline / server down)
}

export const POLL_NO_PUSH_MS = 12_000;      // push not available: look every 12 s while the screen is open
export const POLL_WITH_PUSH_MS = 60_000;    // push available: a slow safety net in case a push never arrives
export const MAX_BACKOFF_MS = 5 * 60_000;

/** Milliseconds until the next refresh, or null = do not poll now (background or screen closed: nothing to look at, nothing to spend). */
export function nextRefreshMs(s: RefreshState): number | null {
  if (!s.appActive || !s.screenOpen) return null;
  const base = s.pushOn ? POLL_WITH_PUSH_MS : POLL_NO_PUSH_MS;
  const f = Math.max(0, Math.min(8, Math.floor(s.failures)));
  return Math.min(MAX_BACKOFF_MS, base * 2 ** f);   // an offline phone backs off instead of hammering the server
}

/** A push that should refresh the lists right away. Only our own kinds, and only the fields we expect. */
export function pushRefreshes(data: unknown): boolean {
  const k = typeof data === 'object' && data !== null ? (data as { kind?: unknown }).kind : undefined;
  return k === 'pc_approval' || k === 'approvals_pending';
}

/** Where tapping a push goes: only our own screens. */
export function routeForPush(data: unknown): '/computer' | '/approvals' | '/notifications' {
  const k = typeof data === 'object' && data !== null ? (data as { kind?: unknown }).kind : undefined;
  if (k === 'pc_approval') return '/computer';
  if (k === 'approvals_pending') return '/approvals';
  return '/notifications';
}
