import Constants from 'expo-constants';
import * as Device from 'expo-device';
import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { Platform } from 'react-native';
import { ApiClient, ApiError, normalizeBaseUrl } from '@/api/client';
import { endpoints, type Api } from '@/api/endpoints';
import type { Bootstrap } from '@/api/types';
import { registerForPush } from '@/lib/push';
import { store } from '@/lib/storage';

export const APP_VERSION = Constants.expoConfig?.version ?? '1.0.0';
const DEFAULT_BASE = process.env.EXPO_PUBLIC_API_URL ?? '';

interface Session {
  status: 'loading' | 'out' | 'in';
  baseUrl: string;
  tenant: string | null;
  boot: Bootstrap | null;
  api: Api;
  signIn(baseUrl: string, email: string, password: string, code?: string): Promise<void>;
  pairWith(baseUrl: string, code: string): Promise<void>;
  signOut(): Promise<void>;
  switchTenant(id: string): Promise<void>;
  refreshBoot(): Promise<void>;
  /** true when this phone has a push address registered; false = screens poll instead. */
  pushOn: boolean;
}

const Ctx = createContext<Session | null>(null);
export const useSession = () => {
  const s = useContext(Ctx);
  if (!s) throw new Error('SessionProvider missing');
  return s;
};

export function SessionProvider({ children }: { children: ReactNode }) {
  const [status, setStatus] = useState<Session['status']>('loading');
  const [baseUrl, setBase] = useState(DEFAULT_BASE);
  const [tenant, setTenantState] = useState<string | null>(null);
  const [boot, setBoot] = useState<Bootstrap | null>(null);
  const [pushOn, setPushOn] = useState(false);
  const token = useRef<string | null>(null);
  const tenantRef = useRef<string | null>(null);
  const baseRef = useRef(DEFAULT_BASE);

  const forget = useCallback(async () => {
    token.current = null; tenantRef.current = null;
    await store.clearSession();
    setTenantState(null); setBoot(null); setStatus('out');
  }, []);

  // The client reads token/tenant through refs, so changing workspace never rebuilds it or loses an in-flight request.
  const client = useMemo(() => {
    if (!baseUrl) return null;
    try {
      return new ApiClient({ baseUrl, getToken: () => token.current, getTenant: () => tenantRef.current, onUnauthorized: () => { void forget(); }, appVersion: APP_VERSION, allowInsecureLocal: __DEV__ });
    } catch { return null; }
  }, [baseUrl, forget]);
  const api = useMemo(() => endpoints(client ?? new ApiClient({ baseUrl: 'https://invalid.invalid', getToken: () => null, getTenant: () => null })), [client]);

  const registerDevice = useCallback(async (a: Api) => {
    const push = await registerForPush();
    setPushOn(push !== null);
    await a.registerDevice(Device.deviceName ?? `${Platform.OS} phone`, Platform.OS === 'ios' ? 'ios' : 'android', push, APP_VERSION);
  }, []);

  const enter = useCallback(async (a: Api, wantTenant: string | null) => {
    tenantRef.current = null;
    const first = await a.bootstrap();                         // no workspace header yet: just who am I and which workspaces
    const chosen = first.workspaces.find((w) => w.id === wantTenant) ?? first.workspaces[0] ?? null;
    if (!chosen) throw new ApiError(0, 'no_workspace', 'You have no active workspace. Go to the website and create one.');
    tenantRef.current = chosen.id;
    const full = await a.bootstrap();                          // again with the workspace: now it also says what I may do there
    await store.set('tenant', chosen.id);
    setTenantState(chosen.id); setBoot(full); setStatus('in');
    registerDevice(a).catch(() => undefined);                  // push is a convenience: never block entering the app
  }, [registerDevice]);

  useEffect(() => {
    (async () => {
      const [t, tn, b] = await Promise.all([store.get('token'), store.get('tenant'), store.get('base')]);
      const base = b ?? DEFAULT_BASE;
      baseRef.current = base; setBase(base);
      if (!t || !base) { setStatus('out'); return; }
      token.current = t;
      try {
        await enter(endpoints(new ApiClient({ baseUrl: base, getToken: () => token.current, getTenant: () => tenantRef.current, onUnauthorized: () => { void forget(); }, appVersion: APP_VERSION, allowInsecureLocal: __DEV__ })), tn);
      } catch (e) {
        if (!(e instanceof ApiError) || e.code === 'unauthenticated') { await forget(); } else { setStatus('out'); }   // offline at start: show login, token stays stored
      }
    })();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const value: Session = {
    status, baseUrl, tenant, boot, api, pushOn,
    async signIn(base, email, password, code) {
      const clean = normalizeBaseUrl(base, __DEV__);
      const c = new ApiClient({ baseUrl: clean, getToken: () => token.current, getTenant: () => tenantRef.current, onUnauthorized: () => { void forget(); }, appVersion: APP_VERSION, allowInsecureLocal: __DEV__ });
      const a = endpoints(c);
      const r = await a.login(email.trim(), password, Device.deviceName ?? `${Platform.OS} phone`, code);
      token.current = r.token;
      await Promise.all([store.set('token', r.token), store.set('base', clean)]);
      baseRef.current = clean; setBase(clean);
      try { await enter(a, await store.get('tenant')); } catch (e) { await forget(); throw e; }
    },
    async pairWith(base, code) {
      const clean = normalizeBaseUrl(base, __DEV__);
      const c = new ApiClient({ baseUrl: clean, getToken: () => token.current, getTenant: () => tenantRef.current, onUnauthorized: () => { void forget(); }, appVersion: APP_VERSION, allowInsecureLocal: __DEV__ });
      const a = endpoints(c);
      const r = await a.pair(code, Device.deviceName ?? `${Platform.OS} phone`);
      token.current = r.token;
      await Promise.all([store.set('token', r.token), store.set('base', clean)]);
      baseRef.current = clean; setBase(clean);
      try { await enter(a, await store.get('tenant')); } catch (e) { await forget(); throw e; }
    },
    async signOut() {
      try { await api.logout(); } catch { /* offline: the token is still forgotten here */ }
      await forget();
    },
    async switchTenant(id) {
      tenantRef.current = id; await store.set('tenant', id); setTenantState(id);
      setBoot(await api.bootstrap());
      registerDevice(api).catch(() => undefined);              // a phone is registered per workspace
    },
    async refreshBoot() { setBoot(await api.bootstrap()); },
  };
  return <Ctx.Provider value={value}>{children}</Ctx.Provider>;
}
