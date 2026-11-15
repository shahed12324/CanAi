/**
 * Small, dependency-free HTTP client for the CANAI mobile API.
 * - fetch is injected, so the whole thing runs (and is tested) in plain Node.
 * - Every failure becomes an ApiError with a stable `code`, so screens never parse messages.
 * - The token travels only in the Authorization header, never in a URL.
 */
export type FetchLike = (url: string, init: { method: string; headers: Record<string, string>; body?: string; signal?: AbortSignal }) => Promise<{ status: number; text(): Promise<string> }>;

export class ApiError extends Error {
  constructor(
    public readonly status: number,
    public readonly code: string,
    message: string,
    public readonly fields: Record<string, string[]> = {},
  ) {
    super(message);
    this.name = 'ApiError';
  }
}

export interface ClientOptions {
  baseUrl: string;
  fetch?: FetchLike;
  getToken: () => string | null;
  getTenant: () => string | null;
  onUnauthorized?: () => void;
  appVersion?: string;
  timeoutMs?: number;
  /** Plain http is accepted only for a local development server. */
  allowInsecureLocal?: boolean;
}

export function normalizeBaseUrl(raw: string, allowInsecureLocal = false): string {
  const url = raw.trim().replace(/\/+$/, '');
  let u: URL;
  try { u = new URL(url); } catch { throw new ApiError(0, 'bad_url', 'The server address is not valid.'); }
  const local = ['localhost', '127.0.0.1', '10.0.2.2'].includes(u.hostname);
  if (u.protocol !== 'https:' && !(allowInsecureLocal && local && u.protocol === 'http:')) {
    throw new ApiError(0, 'insecure_url', 'The server address must start with https://');
  }
  if (u.username || u.password || u.search || u.hash) throw new ApiError(0, 'bad_url', 'Give only the domain in the address.');
  return `${u.protocol}//${u.host}${u.pathname === '/' ? '' : u.pathname}`;
}

export class ApiClient {
  private readonly base: string;
  private readonly f: FetchLike;

  constructor(private readonly o: ClientOptions) {
    this.base = normalizeBaseUrl(o.baseUrl, o.allowInsecureLocal) + '/api/v1';
    this.f = o.fetch ?? ((url, init) => fetch(url, init as RequestInit) as unknown as ReturnType<FetchLike>);
  }

  async request<T>(method: 'GET' | 'POST' | 'PUT' | 'DELETE', path: string, body?: unknown, opts: { tenant?: boolean; timeoutMs?: number } = {}): Promise<T> {
    const headers: Record<string, string> = { Accept: 'application/json', 'X-App-Version': this.o.appVersion ?? '0' };
    const token = this.o.getToken();
    if (token) headers.Authorization = `Bearer ${token}`;
    if (opts.tenant !== false) {
      const t = this.o.getTenant();
      if (t) headers['X-Tenant-Id'] = t;
    }
    if (body !== undefined) headers['Content-Type'] = 'application/json';

    const ctl = new AbortController();
    const timer = setTimeout(() => ctl.abort(), opts.timeoutMs ?? this.o.timeoutMs ?? 20000);
    let res: { status: number; text(): Promise<string> };
    try {
      res = await this.f(this.base + path, { method, headers, body: body === undefined ? undefined : JSON.stringify(body), signal: ctl.signal });
    } catch (e) {
      throw ctl.signal.aborted
        ? new ApiError(0, 'timeout', 'The server is not responding. Try again shortly.')
        : new ApiError(0, 'network', 'Could not connect to the internet or the server.');
    } finally {
      clearTimeout(timer);
    }

    let json: Record<string, unknown> = {};
    try { json = JSON.parse(await res.text()) as Record<string, unknown>; } catch { /* non-JSON body (proxy error page) */ }

    if (res.status >= 200 && res.status < 300) {
      if (!('data' in json)) throw new ApiError(res.status, 'bad_response', 'The server’s reply could not be understood.');
      return json.data as T;
    }
    if (res.status === 401) {
      this.o.onUnauthorized?.();
      throw new ApiError(401, 'unauthenticated', 'Log in again.');
    }
    const code = typeof json.code === 'string' ? json.code : res.status === 422 ? 'validation' : res.status === 429 ? 'rate_limited' : res.status >= 500 ? 'server_error' : `http_${res.status}`;
    const message = typeof json.message === 'string' && json.message ? json.message : res.status === 429 ? 'Too many requests. Try again shortly.' : 'Something went wrong.';
    const fields = json.errors && typeof json.errors === 'object' ? (json.errors as Record<string, string[]>) : {};
    throw new ApiError(res.status, code, message, fields);
  }
}
