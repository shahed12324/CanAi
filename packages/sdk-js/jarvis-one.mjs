// JARVIS ONE public API client (Node 18+ / browsers' fetch; no dependencies).
//   import { Client } from './jarvis-one.mjs';
//   const c = new Client('jk_live_…', 'https://your-host/api/public/v1');
//   const run = await c.runWorkflow('<workflow id>', { name: 'value' });
//   console.log(await c.waitForRun(run.run_id));
// The key goes only in the Authorization header, over https (http only for localhost), is never in an error message or toString(),
// and redirects are not followed. Do not put a key in browser code: anyone who opens the page can read it.

const KEY = /^jk_live_[0-9a-f]{40}$/;
const ID = /^[0-9a-z]{26}$/;
const FINAL = new Set(['done', 'failed', 'cancelled']);

export class JarvisError extends Error {
  constructor(message, status = 0, code = null) { super(message); this.name = new.target.name; this.status = status; this.code = code; }
}
export class AuthError extends JarvisError {}
export class ScopeError extends JarvisError {}
export class NotFound extends JarvisError {}
export class RateLimited extends JarvisError {
  constructor(message, status = 429, code = null, retryAfter = null) { super(message, status, code); this.retryAfter = retryAfter; }
}
export class RequestFailed extends JarvisError {}

export class Client {
  #key; #base; #timeoutMs;

  constructor(key, baseUrl, { timeoutMs = 15000 } = {}) {
    if (typeof key !== 'string' || !KEY.test(key)) throw new TypeError('key must look like jk_live_ followed by 40 hex characters');
    let u;
    try { u = new URL(baseUrl); } catch { throw new TypeError('base_url is not valid'); }
    const local = u.hostname === 'localhost' || u.hostname === '127.0.0.1';
    if (u.protocol !== 'https:' && !(u.protocol === 'http:' && local)) throw new TypeError('base_url must be https (http is allowed only for localhost)');
    if (!u.hostname || u.username || u.password) throw new TypeError('base_url is not valid');
    this.#key = key; this.#base = String(baseUrl).replace(/\/+$/, ''); this.#timeoutMs = timeoutMs;
  }

  toString() { return `Client(${this.#base}, key=${this.#key.slice(0, 12)}...)`; }
  toJSON() { return { base: this.#base }; }
  [Symbol.for('nodejs.util.inspect.custom')]() { return this.toString(); }

  async me() { return (await this.#call('GET', '/me')).data; }
  async credits() { return (await this.#call('GET', '/credits')).data; }
  async workflows() { return (await this.#call('GET', '/workflows')).data; }
  async run(runId) { Client.#checkId(runId); return (await this.#call('GET', `/runs/${runId}`)).data; }

  async runWorkflow(workflowId, inputs = {}) {
    Client.#checkId(workflowId);
    if (inputs === null || typeof inputs !== 'object' || Array.isArray(inputs)) throw new TypeError('inputs must be an object of text values');
    const clean = {};
    for (const [k, v] of Object.entries(inputs)) {
      if (!['string', 'number'].includes(typeof v)) throw new TypeError('inputs must be an object of text values');
      clean[k] = String(v);
    }
    return (await this.#call('POST', `/workflows/${workflowId}/run`, { inputs: clean })).data;
  }

  /** Polls until done / failed / cancelled; rejects with a TimeoutError-like Error after `timeoutMs`. A run waiting for the person's approval keeps waiting: a key cannot approve. */
  async waitForRun(runId, { timeoutMs = 300000, pollMs = 2000, sleep = (ms) => new Promise((r) => setTimeout(r, ms)), now = () => Date.now() } = {}) {
    const end = now() + timeoutMs;
    for (;;) {
      const r = await this.run(runId);
      if (FINAL.has(r.status)) return r;
      if (now() + pollMs > end) { const e = new Error(`run ${runId} still ${r.status} after ${timeoutMs} ms`); e.name = 'TimeoutError'; throw e; }
      await sleep(pollMs);
    }
  }

  static #checkId(v) { if (typeof v !== 'string' || !ID.test(v)) throw new TypeError('not a valid id'); }

  async #call(method, path, body) {
    let res;
    try {
      res = await fetch(this.#base + path, {
        method, redirect: 'manual', signal: AbortSignal.timeout(this.#timeoutMs),
        headers: { Authorization: `Bearer ${this.#key}`, Accept: 'application/json', ...(body === undefined ? {} : { 'Content-Type': 'application/json' }) },
        body: body === undefined ? undefined : JSON.stringify(body),
      });
    } catch (e) {
      throw new RequestFailed('could not reach the server: ' + (e && e.name ? e.name : 'error'));
    }
    if (res.status >= 300 && res.status < 400) throw new RequestFailed(`unexpected redirect (HTTP ${res.status})`, res.status);
    const text = (await res.text()).slice(0, 2_000_000);
    if (!res.ok) throw Client.#error(res, text);
    let out;
    try { out = JSON.parse(text); } catch { throw new RequestFailed('the server did not answer with JSON'); }
    if (out === null || typeof out !== 'object' || Array.isArray(out)) throw new RequestFailed('unexpected answer');
    return out;
  }

  static #error(res, text) {
    let code = null, msg = '';
    try { const j = JSON.parse(text); code = j.error ?? null; msg = String(j.message ?? j.error ?? ''); } catch { /* not JSON */ }
    msg = (msg || `HTTP ${res.status}`).slice(0, 200);
    switch (res.status) {
      case 401: return new AuthError(msg, 401, code);
      case 403: return new ScopeError(msg, 403, code);
      case 404: return new NotFound(msg, 404, code);
      case 429: { const ra = Number(res.headers.get('retry-after')); return new RateLimited(msg, 429, code, Number.isFinite(ra) && res.headers.get('retry-after') !== null ? ra : null); }
      default: return new RequestFailed(msg, res.status, code);
    }
  }
}
