import * as Notifications from 'expo-notifications';
import { useEffect, useRef } from 'react';
import { AppState } from 'react-native';
import { nextRefreshMs, pushRefreshes } from '@/lib/refresh';
import { useSession } from '@/state/session';

/** Keeps a list fresh: instantly when a push arrives, when the app comes back to the foreground, and by slow polling (the fallback when push is off).
 *  `reload` resolves true when it worked and false (or throws) when it failed, so failures back off. */
export function useAutoRefresh(reload: () => Promise<boolean | void>, onlyWhenFocused = true) {
  const { pushOn } = useSession();
  const fn = useRef(reload); fn.current = reload;
  useEffect(() => {
    let stopped = false, timer: ReturnType<typeof setTimeout> | null = null, failures = 0;
    let active = AppState.currentState === 'active';
    const run = async () => {
      try { failures = (await fn.current()) === false ? failures + 1 : 0; } catch { failures += 1; }
    };
    const schedule = () => {
      if (timer) clearTimeout(timer);
      const ms = nextRefreshMs({ pushOn, appActive: active, screenOpen: onlyWhenFocused, failures });
      if (stopped || ms === null) return;
      timer = setTimeout(async () => { await run(); schedule(); }, ms);
    };
    const app = AppState.addEventListener('change', (st) => {
      active = st === 'active';
      if (active) { void run(); }                         // coming back: look straight away
      schedule();
    });
    const push = Notifications.addNotificationReceivedListener((n) => {
      if (pushRefreshes(n.request.content.data)) { void run(); }
    });
    schedule();
    return () => { stopped = true; if (timer) clearTimeout(timer); app.remove(); push.remove(); };
  }, [pushOn, onlyWhenFocused]);
}
