import test from 'node:test';
import assert from 'node:assert/strict';
import http from 'node:http';
import { Client, AuthError, ScopeError, NotFound, RateLimited, RequestFailed } from '../jarvis-one.mjs';

const KEY = 'jk_live_' + 'ab12'.repeat(10);
const WID = 'w'.repeat(26);
const RID = 'r'.repeat(26);

async function serve(script) {
  const seen = [];
  const s = http.createServer((req, res) => {
    let body = '';
    req.on('data', (c) => (body += c));
    req.on('end', () => {
      seen.push({ method: req.method, url: req.url, headers: req.headers, body });
      const [status, out, hdr] = script[`${req.method} ${req.url}`] ?? [404, { error: 'not_found' }, {}];
      const raw = typeof out === 'string' ? out : JSON.stringify(out);
      res.writeHead(status, { ...hdr, 'Content-Length': Buffer.byteLength(raw) });
      res.end(raw);
    });
  });
  await new Promise((r) => s.listen(0, '127.0.0.1', r));
  return { base: `http://127.0.0.1:${s.address().port}/api/public/v1`, seen, close: () => new Promise((r) => { s.closeAllConnections?.(); s.close(r); }) };
}

test('sends the key only as a bearer header and returns data', async () => {
  const s = await serve({ 'GET /api/public/v1/me': [200, { data: { user: { name: 'রহিম' } } }, {}] });
  try {
    assert.equal((await new Client(KEY, s.base).me()).user.name, 'রহিম');
    assert.equal(s.seen[0].headers.authorization, 'Bearer ' + KEY);
    assert.ok(!s.seen[0].url.includes(KEY));
  } finally { await s.close(); }
});

test('runWorkflow posts text inputs; run reads status back', async () => {
  const s = await serve({
    [`POST /api/public/v1/workflows/${WID}/run`]: [202, { data: { run_id: RID, status: 'queued' } }, {}],
    [`GET /api/public/v1/runs/${RID}`]: [200, { data: { run_id: RID, status: 'done' } }, {}],
  });
  try {
    const c = new Client(KEY, s.base);
    assert.equal((await c.runWorkflow(WID, { 'নাম': 'মান', n: 5 })).status, 'queued');
    assert.deepEqual(JSON.parse(s.seen[0].body), { inputs: { 'নাম': 'মান', n: '5' } });
    assert.equal(s.seen[0].headers['content-type'], 'application/json');
    assert.equal((await c.run(RID)).status, 'done');
  } finally { await s.close(); }
});

for (const [status, cls] of [[401, AuthError], [403, ScopeError], [404, NotFound], [429, RateLimited], [409, RequestFailed], [422, RequestFailed], [500, RequestFailed]]) {
  test(`HTTP ${status} becomes ${cls.name} with status and code, and no key`, async () => {
    const s = await serve({ 'GET /api/public/v1/credits': [status, { error: 'some_code', message: 'no' }, { 'Retry-After': '7' }] });
    try {
      await assert.rejects(new Client(KEY, s.base).credits(), (e) => {
        assert.ok(e instanceof cls); assert.equal(e.status, status); assert.equal(e.code, 'some_code');
        assert.ok(!String(e.message).includes(KEY) && !JSON.stringify(e).includes(KEY));
        if (status === 429) assert.equal(e.retryAfter, 7);
        return true;
      });
    } finally { await s.close(); }
  });
}

test('non-JSON bodies and long messages are handled', async () => {
  const s = await serve({
    'GET /api/public/v1/me': [502, '<html>bad gateway</html>', {}],
    'GET /api/public/v1/credits': [200, 'not json', {}],
    'GET /api/public/v1/workflows': [200, '[1,2]', {}],
    [`GET /api/public/v1/runs/${RID}`]: [400, { error: 'x', message: 'm'.repeat(1000) }, {}],
  });
  try {
    const c = new Client(KEY, s.base);
    await assert.rejects(c.me(), (e) => e instanceof RequestFailed && e.status === 502 && e.message.includes('502'));
    await assert.rejects(c.credits(), RequestFailed);
    await assert.rejects(c.workflows(), RequestFailed);
    await assert.rejects(c.run(RID), (e) => e.message.length === 200);
  } finally { await s.close(); }
});

test('a redirect is not followed', async () => {
  const s = await serve({ 'GET /api/public/v1/me': [302, '', { Location: 'http://127.0.0.1:9/steal' }] });
  try {
    await assert.rejects(new Client(KEY, s.base).me(), (e) => e instanceof RequestFailed && e.status === 302);
    assert.equal(s.seen.length, 1);
  } finally { await s.close(); }
});

test('an unreachable server is a clean error without the key', async () => {
  await assert.rejects(new Client(KEY, 'http://127.0.0.1:1/api', { timeoutMs: 1000 }).me(), (e) => e instanceof RequestFailed && !e.message.includes(KEY));
});

test('malformed keys and base urls are refused', () => {
  for (const k of ['', 'jk_live_', 'jk_live_' + 'a'.repeat(39), 'jk_live_' + 'a'.repeat(41), 'jk_live_' + 'A'.repeat(40), 'sk_live_' + 'a'.repeat(40), null, 5, ' jk_live_' + 'a'.repeat(40)])
    assert.throws(() => new Client(k, 'https://example.com/api'), TypeError);
  for (const u of ['http://example.com/api', 'ftp://example.com', 'https://', 'https://u:p@example.com/', 'example.com/api', ''])
    assert.throws(() => new Client(KEY, u), TypeError);
  new Client(KEY, 'https://example.com/api/public/v1'); new Client(KEY, 'http://localhost:8000/api/public/v1');
});

test('ids and inputs are checked before anything is sent', async () => {
  const s = await serve({});
  try {
    const c = new Client(KEY, s.base);
    for (const bad of ['', 'short', 'A'.repeat(26), 'a'.repeat(27), '../x', 'a'.repeat(25) + '/']) {
      await assert.rejects(c.run(bad), TypeError); await assert.rejects(c.runWorkflow(bad), TypeError);
    }
    for (const bad of [{ a: [1] }, { a: null }, 'x', null, [1]]) await assert.rejects(c.runWorkflow(WID, bad), TypeError);
    assert.equal(s.seen.length, 0);
  } finally { await s.close(); }
});

test('the key never shows in toString, JSON or inspect', () => {
  const c = new Client(KEY, 'https://example.com/api');
  assert.ok(!String(c).includes(KEY) && String(c).includes(KEY.slice(0, 12)));
  assert.ok(!JSON.stringify(c).includes(KEY));
  assert.ok(!Object.values(c).some((v) => v === KEY));
});

test('waitForRun polls until final and times out', async () => {
  const c = new Client(KEY, 'https://example.com/api');
  let t = 0; const slept = [];
  const states = ['queued', 'running', 'waiting_approval', 'done'];
  c.run = async () => ({ status: states.shift() });
  const out = await c.waitForRun(RID, { timeoutMs: 100000, pollMs: 2000, sleep: async (ms) => { slept.push(ms); t += ms; }, now: () => t });
  assert.equal(out.status, 'done'); assert.deepEqual(slept, [2000, 2000, 2000]);
  for (const f of ['failed', 'cancelled']) { c.run = async () => ({ status: f }); assert.equal((await c.waitForRun(RID)).status, f); }
  t = 0; c.run = async () => ({ status: 'waiting_approval' });
  await assert.rejects(c.waitForRun(RID, { timeoutMs: 5000, pollMs: 2000, sleep: async (ms) => { t += ms; }, now: () => t }), (e) => e.name === 'TimeoutError');
  assert.ok(t <= 5000);
});

test('a 429 without a usable Retry-After has retryAfter null', async () => {
  const s = await serve({ 'GET /api/public/v1/me': [429, { error: 'busy' }, {}], 'GET /api/public/v1/credits': [429, { error: 'busy' }, { 'Retry-After': 'soon' }] });
  try {
    const c = new Client(KEY, s.base);
    await assert.rejects(c.me(), (e) => e instanceof RateLimited && e.retryAfter === null);
    await assert.rejects(c.credits(), (e) => e instanceof RateLimited && e.retryAfter === null);
  } finally { await s.close(); }
});
