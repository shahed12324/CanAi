# jarvis-one (JavaScript)

One file (`jarvis-one.mjs`), no dependencies, Node 18+. Make a key on the *Developer* page, then:

```js
import { Client } from './jarvis-one.mjs';
const c = new Client('jk_live_…', 'https://your-host/api/public/v1');
console.log(await c.me(), await c.credits());
const run = await c.runWorkflow('<workflow id>', { name: 'value' });
console.log(await c.waitForRun(run.run_id));
```

Errors: `AuthError` (401), `ScopeError` (403), `NotFound` (404), `RateLimited` (429, `.retryAfter`), `RequestFailed` (anything else).
Use it from a server, not from browser code — a key in a web page can be read by anyone who opens it.
Test: `npm test`.
