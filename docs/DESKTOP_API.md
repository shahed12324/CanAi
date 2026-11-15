# Desktop ↔ Laravel contract: `/api/pc/v1/desktop/*`

The desktop window never talks to Laravel directly. The local agent forwards `GET|POST /api/cloud/<path>` to `<server>/api/pc/v1/desktop/<path>` with the **device token** (`Authorization: Bearer <token>`); the token never reaches the page. Query strings are limited to `q` and `limit`. A 404 from the server is shown to the person as "update your server".

**Status (0.132.0):** both sides exist. Client: apps/pc-agent (fake server in `tests/conftest.py` speaks this contract). Server: `apps/web-overlay/app/Http/Controllers/Api/Pc/DesktopController.php` + `routes/api_pc.php`. The Laravel side has been **syntax-checked and statically contract-tested but never booted against a live Laravel/PostgreSQL stack** — expect to fix small things on the first real call. An older server (404) still makes the window say "update the server".

Security rules for the Laravel implementation (all enforced server-side, never trusted from the window):
- the device token resolves to ONE user + tenant; every call runs as that user, with their RLS/credit/permission rules;
- no endpoint approves anything: approvals stay on the website/phone (the window has no approve button);
- `plan` only drafts (PcPlanner) — nothing runs; `workflows/{id}/run` uses the normal engine (approvals, risk levels, credit caps);
- throttled like the other device endpoints; bodies ≤ 64 KB.

| Method + path | Request | Response |
|---|---|---|
| GET `bootstrap` | – | `{user:{name}, models:[{id,name}], credits:int, pending_approvals:int}` |
| GET `tools` | – | `{tools:[{key,label,risk}]}` (risk: read, write_own, external) |
| GET `approvals` | – | `{items:[{id,title,kind,risk}]}` (read-only list) |
| GET `chat/conversations[?q=]` | – | `{items:[{id,title}]}` |
| GET `chat/conversations/{id}` | – | `{messages:[{role,text}]}` |
| POST `chat` | `{message, conversation_id?, model_id?}` | `{conversation_id, reply}` |
| POST `chat/stream` | same body | `text/event-stream`: `event: meta` `{conversation_id,…}`, many `event: delta` `{text}`, then `event: done` `{credits,…}` or `event: error` `{code,message,partial}` |
| GET `agents` | – | `{items:[{id,name,description,status}]}` |
| POST `agents/{id}/run` | `{input, model_id?}` | `{status, output}` |
| GET `workflows` | – | `{items:[{id,name,status}]}` |
| GET `workflows/runs` | – | `{items:[{id,name,status,started}]}` |
| POST `workflows/{id}/run` | `{}` | `{run_id}` |
| POST `workflows/runs/{id}/cancel` | `{}` | `{ok:true}` |
| GET `memory[?q=]` | – | `{items:[{id,text}]}` |
| POST `memory` | `{text}` | `{id}` |
| POST `memory/{id}/delete` | `{}` | `{ok:true}` |
| POST `rotate` (not under `desktop/`) | `{new_token}` (64 hex, made by the agent) | `{ok:true}` / 409 `{error:"rotate_refused"}` |
| POST `plan` | `{goal}` | `{status:"ok"}` or `{status, message}` / 422 `{error}` |


## Voice (Phase C client; server in Phase E)
| Local path | Server path | Body | Reply |
|---|---|---|---|
| `POST /api/cloud/voice/listen` | `POST /api/pc/v1/desktop/voice/listen` | `{audio_b64, mime, language}` (≤ 2.5 MB raw JSON) | `{text}` |
| `POST /api/cloud/voice/speak` | `POST /api/pc/v1/desktop/voice/speak` | `{text, language}` | `{audio_b64, mime}` |
Both use the existing credit-metered VoiceService. A 501/404 makes the window show "update the server".


## Implementation notes (Phase E)
- The owner is the device's user (`PcAgentAuth` sets `pc_user`); no web session is involved. A revoked device (token hash removed) gets 401 everywhere at once.
- `chat` keeps an existing conversation's model; `persona` (`friendly`|`calm`|`playful`) adds one fixed tone line to the system prompt and `source` (`voice`) asks for short plain sentences because the reply is read aloud (both endpoints, `App\Chat\ReplyStyle`). Only these known values are accepted - anything else is ignored, the window's text is never copied into the prompt. A character conversation keeps its own persona (only the voice rule applies). They change tone/format only, never the assistant's rules.
- `approvals` lists the owner's pending PC actions, pending PC plans and workflow approvals, read-only. Deciding stays on the website/phone (PIN step-up for high risk).
- `plan` refuses in Companion mode and only drafts. `workflows/{id}/run` takes `{inputs:{key:value}}` when the workflow asks for inputs.
- Token rotation: the agent writes `token_next` to disk, then calls `rotate` with the OLD token; lost answers are recovered by trying the new token first on the next start. Signed plans are signed at pull time from the current token hash, so nothing approved is stranded.

## Screen kinds (0.134.0, queued like any other kind; high risk, approved on the website/phone)
| kind | params | result |
|---|---|---|
| `screen_read` | `{}` | window, monitor, confidence, recognised text (secrets removed) |
| `screen_find` | `{text, role?}` | matches with centre coordinates and score |
| `ui_click` | `{text, role?, index?, button?, min_confidence?}` | clicks one clear match, else refuses |
| `screen_describe` | `{question?}` | local summary; model description only if `vision_upload` is on |
| `browser_run` | `{url?, steps:[{op,...}]}` ops: `goto{url}` `fill{label\|placeholder\|css, value}` `select{label\|css, value}` `check{label\|css}` `click{text\|label\|css}` `wait_text{text,timeout}` `expect_text{text}` `extract{css?}` | one line per operation, page text marked as outside data |
| `make_report` | `{src.csv, dst.xlsx, group_by?, overwrite?}` | "Report made from N rows..." (numbers computed on the device) |
Plan steps may also carry `needs: [earlier ids]`, `when: {step, contains\|not_contains}`, `continue_on_error: bool`.
Step `verify` types: `screen_text {contains}`, `element_present {text, role?}`, `xlsx_report {path}`.

## Audit chain sync (device token, `/api/pc/v1/...`, not under `desktop/`)
| Call | Meaning |
|---|---|
| GET `audit/head` | `{status:"ok", seq, h}` - rows stored for this device and the hash of the last one (64 zeros when empty) |
| POST `audit/sync` `{start, rows}` | `rows` = 1..200 objects with exactly `t, task, step, event, detail, prev, h` (the agent's audit.jsonl lines). 200 `{status:"ok", seq, h}`; 409 `seq_mismatch` / `chain_broken` with the server's real `seq`/`h`/`at`; 422 `bad_input`; 404 unknown/revoked device; 413 over 300 KB |
The hash is `sha256` over `json.dumps({t,task,step,event,detail,prev}, sort_keys=True, ensure_ascii=False, separators=(",",":"))`; SQL recomputes it (`pc_audit_hash`), tested byte for byte against the agent's Python for Bengali, emoji, quotes, newlines and control characters.
