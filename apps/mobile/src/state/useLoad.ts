import { useCallback, useEffect, useRef, useState } from 'react';

/** Loads data when the screen opens and on pull-to-refresh. `reload` keeps the old data on screen while it runs. */
export function useLoad<T>(fn: () => Promise<T>, deps: unknown[] = []) {
  const [data, setData] = useState<T | null>(null);
  const [error, setError] = useState<unknown>(null);
  const [loading, setLoading] = useState(true);
  const alive = useRef(true);
  const fnRef = useRef(fn);
  fnRef.current = fn;
  useEffect(() => { alive.current = true; return () => { alive.current = false; }; }, []);
  /** Resolves true when the data loaded, false when it failed (the old data stays on screen). Never throws. */
  const reload = useCallback(async (): Promise<boolean> => {
    try {
      const d = await fnRef.current();
      if (alive.current) { setData(d); setError(null); }
      return true;
    } catch (e) {
      if (alive.current) setError(e);
      return false;
    } finally {
      if (alive.current) setLoading(false);
    }
  }, []);
  // eslint-disable-next-line react-hooks/exhaustive-deps
  useEffect(() => { setLoading(true); void reload(); }, deps);
  return { data, error, loading, reload, setData };
}
