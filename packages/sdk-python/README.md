# jarvis-one (Python)

One file, no dependencies. Make a key on the *Developer* page, then:

```python
from jarvis_one import Client
c = Client("jk_live_…", base_url="https://your-host/api/public/v1")
print(c.me(), c.credits())
run = c.run_workflow("<workflow id>", {"name": "value"})
print(c.wait_for_run(run["run_id"]))
```

Errors: `AuthError` (401), `ScopeError` (403), `NotFound` (404), `RateLimited` (429, `.retry_after`), `RequestFailed` (anything else).
The key is sent only in the `Authorization` header, never printed, and redirects are not followed.
Test: `python -m pytest packages/sdk-python/tests -q`.
