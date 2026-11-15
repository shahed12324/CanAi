import { describe, expect, it } from 'vitest';
import { MAX_BACKOFF_MS, POLL_NO_PUSH_MS, POLL_WITH_PUSH_MS, nextRefreshMs, pushRefreshes, routeForPush } from '@/lib/refresh';

const s = (o = {}) => ({ pushOn: false, appActive: true, screenOpen: true, failures: 0, ...o });

describe('when to refresh', () => {
  it('polls quickly when push is not available (the fallback)', () => {
    expect(nextRefreshMs(s())).toBe(POLL_NO_PUSH_MS);
  });
  it('polls slowly when push works (safety net only)', () => {
    expect(nextRefreshMs(s({ pushOn: true }))).toBe(POLL_WITH_PUSH_MS);
    expect(POLL_WITH_PUSH_MS).toBeGreaterThan(POLL_NO_PUSH_MS);
  });
  it('does not poll in the background or when the screen is not open', () => {
    expect(nextRefreshMs(s({ appActive: false }))).toBeNull();
    expect(nextRefreshMs(s({ screenOpen: false }))).toBeNull();
  });
  it('backs off when the phone is offline, up to a ceiling, and recovers', () => {
    const a = nextRefreshMs(s({ failures: 1 }))!, b = nextRefreshMs(s({ failures: 2 }))!;
    expect(a).toBe(POLL_NO_PUSH_MS * 2); expect(b).toBe(POLL_NO_PUSH_MS * 4);
    expect(nextRefreshMs(s({ failures: 50 }))).toBe(MAX_BACKOFF_MS);
    expect(nextRefreshMs(s({ failures: -3 }))).toBe(POLL_NO_PUSH_MS);
    expect(nextRefreshMs(s({ failures: 0 }))).toBe(POLL_NO_PUSH_MS);
  });
});

describe('push payloads', () => {
  it('refresh only for our own approval kinds and never trust odd data', () => {
    expect(pushRefreshes({ kind: 'pc_approval' })).toBe(true);
    expect(pushRefreshes({ kind: 'approvals_pending' })).toBe(true);
    for (const bad of [null, undefined, 5, 'pc_approval', {}, { kind: 5 }, { kind: 'other' }, []]) expect(pushRefreshes(bad)).toBe(false);
  });
  it('open only our own screens', () => {
    expect(routeForPush({ kind: 'pc_approval' })).toBe('/computer');
    expect(routeForPush({ kind: 'approvals_pending' })).toBe('/approvals');
    for (const other of [{ kind: 'x', url: 'https://evil.example' }, null, 'x', { kind: '../../etc' }]) expect(routeForPush(other)).toBe('/notifications');
  });
});
