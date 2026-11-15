import { describe, expect, it, vi } from 'vitest';
import { ApiClient, ApiError, normalizeBaseUrl, type FetchLike } from '@/api/client';
import { endpoints } from '@/api/endpoints';

type Call = { url: string; method: string; headers: Record<string, string>; body?: string };

function mk(replies: Array<{ status: number; body?: unknown; raw?: string } | Error>, over: Partial<ConstructorParameters<typeof ApiClient>[0]> = {}) {
  const calls: Call[] = [];
  const f: FetchLike = async (url, init) => {
    calls.push({ url, method: init.method, headers: init.headers, body: init.body });
    const r = replies.shift();
    if (r instanceof Error) throw r;
    if (!r) throw new Error('no reply queued');
    return { status: r.status, text: async () => r.raw ?? JSON.stringify(r.body ?? {}) };
  };
  const onUnauthorized = vi.fn();
  const client = new ApiClient({ baseUrl: 'https://app.example.com/', fetch: f, getToken: () => 'tok123', getTenant: () => 'T'.repeat(26), onUnauthorized, appVersion: '1.2.3', ...over });
  return { client, calls, onUnauthorized, api: endpoints(client) };
}

describe('base url', () => {
  it('accepts https and strips trailing slashes', () => {
    expect(normalizeBaseUrl('https://a.example.com///')).toBe('https://a.example.com');
    expect(normalizeBaseUrl(' https://a.example.com/sub/ ')).toBe('https://a.example.com/sub');
  });
  it('refuses plain http, credentials, queries and junk', () => {
    for (const bad of ['http://a.example.com', 'ftp://a.example.com', 'https://u:p@a.example.com', 'https://a.example.com?x=1', 'a.example.com', '']) {
      expect(() => normalizeBaseUrl(bad), bad).toThrow(ApiError);
    }
  });
  it('allows http only for a local dev server and only when asked', () => {
    expect(() => normalizeBaseUrl('http://10.0.2.2:8000')).toThrow();
    expect(normalizeBaseUrl('http://10.0.2.2:8000', true)).toBe('http://10.0.2.2:8000');
    expect(() => normalizeBaseUrl('http://evil.example.com', true)).toThrow();
  });
});

describe('requests', () => {
  it('sends the token and workspace in headers, never in the URL', async () => {
    const { api, calls } = mk([{ status: 200, body: { data: { balance: '5' } } }]);
    await api.dashboard();
    expect(calls[0]!.url).toBe('https://app.example.com/api/v1/mobile/dashboard');
    expect(calls[0]!.headers.Authorization).toBe('Bearer tok123');
    expect(calls[0]!.headers['X-Tenant-Id']).toBe('T'.repeat(26));
    expect(calls[0]!.headers['X-App-Version']).toBe('1.2.3');
    expect(calls[0]!.url).not.toContain('tok123');
  });
  it('login carries no token or workspace and sends the device name', async () => {
    const { api, calls } = mk([{ status: 200, body: { data: { token: 'x' } } }]);
    await api.login('a@b.test', 'pw', 'Pixel 8');
    expect(calls[0]!.headers['X-Tenant-Id']).toBeUndefined();
    expect(JSON.parse(calls[0]!.body!)).toEqual({ email: 'a@b.test', password: 'pw', device_name: 'Pixel 8' });
  });
  it('url-encodes path parts so a ref cannot change the route', async () => {
    const { api, calls } = mk([{ status: 200, body: { data: { status: 'approved' } } }]);
    await api.decide('outreach_message', '../x?y=1', 'approve');
    expect(calls[0]!.url).toBe('https://app.example.com/api/v1/mobile/approvals/outreach_message/..%2Fx%3Fy%3D1');
  });
  it('unwraps data', async () => {
    const { api } = mk([{ status: 200, body: { data: [{ ref: 'a' }] } }]);
    expect(await api.approvals()).toEqual([{ ref: 'a' }]);
  });
});

describe('errors', () => {
  it('turns server errors into ApiError with the server code and message', async () => {
    const { api } = mk([{ status: 403, body: { message: 'Enter PIN', code: 'step_up_required' } }]);
    await expect(api.decide('k', 'r', 'approve')).rejects.toMatchObject({ status: 403, code: 'step_up_required', message: 'Enter PIN' });
  });
  it('keeps field errors from validation', async () => {
    const { api } = mk([{ status: 422, body: { message: 'bad', errors: { email: ['Wrong'] } } }]);
    await expect(api.login('a', 'b', 'c')).rejects.toMatchObject({ code: 'validation', fields: { email: ['Wrong'] } });
  });
  it('signals logout on 401', async () => {
    const { api, onUnauthorized } = mk([{ status: 401, body: { message: 'Unauthenticated.' } }]);
    await expect(api.dashboard()).rejects.toMatchObject({ code: 'unauthenticated' });
    expect(onUnauthorized).toHaveBeenCalledTimes(1);
  });
  it('does not log out on other failures', async () => {
    const { api, onUnauthorized } = mk([{ status: 403, body: {} }, { status: 500, raw: '<html>oops</html>' }, { status: 429, body: {} }]);
    await expect(api.dashboard()).rejects.toBeInstanceOf(ApiError);
    await expect(api.dashboard()).rejects.toMatchObject({ code: 'server_error' });
    await expect(api.dashboard()).rejects.toMatchObject({ code: 'rate_limited' });
    expect(onUnauthorized).not.toHaveBeenCalled();
  });
  it('maps a dead network and a timeout to their own codes', async () => {
    const { api } = mk([new Error('Network request failed')]);
    await expect(api.dashboard()).rejects.toMatchObject({ status: 0, code: 'network' });
    const slow: FetchLike = (_u, init) => new Promise((_res, rej) => init.signal!.addEventListener('abort', () => rej(new Error('aborted'))));
    const c = new ApiClient({ baseUrl: 'https://a.example.com', fetch: slow, getToken: () => null, getTenant: () => null, timeoutMs: 20 });
    await expect(c.request('GET', '/x')).rejects.toMatchObject({ code: 'timeout' });
  });
  it('refuses a 200 that is not our JSON (captive portal, proxy page)', async () => {
    const { api } = mk([{ status: 200, raw: '<html>wifi login</html>' }]);
    await expect(api.dashboard()).rejects.toMatchObject({ code: 'bad_response' });
  });
});
