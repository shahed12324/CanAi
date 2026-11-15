# JARVIS ONE — API Documentation

**Version:** 0.1 (Phase 0) · **Status:** Conventions ও endpoint map। বিস্তারিত OpenAPI 3.1 spec (`apps/laravel/storage/api-docs/openapi.yaml`) প্রতিটা ফেজে endpoint তৈরির সাথে সাথে লেখা হবে।

---

## 1. API Surfaces

| Surface | কে ব্যবহার করে | Auth | Base |
|---|---|---|---|
| Public REST API | Mobile, Desktop, পরে তৃতীয় পক্ষ (Enterprise) | Sanctum bearer token | `/api/v1` |
| Web (Livewire) | Browser | Session + CSRF | (route, API নয়) |
| Internal AI API | Laravel → Python | HMAC signature + short-lived JWT | `http://ai-engine:8000/internal/v1` (private network) |
| Internal Callback API | Python → Laravel | HMAC signature + service JWT | `/internal/v1` (শুধু private network, Nginx-এ পাবলিক ব্লক) |
| Webhook Ingress | Gateway, Telegram, Meta, Stores, Couriers | প্রোভাইডার-নির্দিষ্ট signature | `/webhooks/{provider}` |

---

## 2. Conventions (Public API)

### 2.1 Request

- `Authorization: Bearer <token>`
- `X-Tenant-Id: <ulid>`: active tenant (ইউজার যদি একাধিক tenant-এর সদস্য হয়)। সদস্য না হলে `403`।
- `Accept-Language: bn | en`: error message-এর ভাষা।
- `Idempotency-Key: <uuid>`: সব POST যা টাকা/ক্রেডিট/বাইরের কাজ ঘটায় (পেমেন্ট, ক্রেডিট কেনা, approval decision, campaign send) সেখানে বাধ্যতামূলক। ২৪ ঘণ্টা সংরক্ষিত; একই key + ভিন্ন body = `422 idempotency_mismatch`।

### 2.2 Success Response

```json
{
  "data": { "id": "01JBX...", "type": "conversation", "attributes": { } },
  "meta": { "request_id": "req_01JBX..." }
}
```

তালিকা:

```json
{
  "data": [ ],
  "meta": { "request_id": "...", "per_page": 25, "next_cursor": "eyJ...", "prev_cursor": null }
}
```

- Pagination: **cursor-based** (`?cursor=&per_page=`, সর্বোচ্চ 100)। Admin টেবিলে offset pagination (Livewire)।
- Filtering: `?filter[status]=active&filter[created_from]=2026-11-01`
- Sorting: `?sort=-created_at`
- Sparse include: `?include=company,contact`

### 2.3 Error Response

```json
{
  "error": {
    "code": "insufficient_credits",
    "message": "আপনার ক্রেডিট ব্যালান্স যথেষ্ট নয়।",
    "details": { "required": 12.5, "available": 3.2 },
    "request_id": "req_01JBX..."
  }
}
```

| HTTP | code (উদাহরণ) |
|---|---|
| 400 | `bad_request` |
| 401 | `unauthenticated`, `token_expired` |
| 403 | `forbidden`, `tenant_access_denied`, `feature_not_in_plan`, `approval_required` |
| 404 | `not_found` (অন্য tenant-এর রিসোর্সও 404, যাতে অস্তিত্ব ফাঁস না হয়) |
| 409 | `conflict`, `already_processed` |
| 422 | `validation_failed` (`details.fields`), `idempotency_mismatch` |
| 402 | `insufficient_credits`, `subscription_inactive` |
| 423 | `period_locked` (accounting) |
| 429 | `rate_limited` (`Retry-After` হেডার) |
| 502/503 | `provider_unavailable`, `integration_awaiting_credentials` |

### 2.4 Rate Limits (ডিফল্ট, plan-ভিত্তিক কনফিগারযোগ্য)

| গ্রুপ | সীমা |
|---|---|
| auth | 5/মিনিট/IP+email |
| সাধারণ API | 120/মিনিট/token |
| chat | plan-ভিত্তিক (যেমন Free 10/মিনিট) |
| webhook ingress | provider অনুযায়ী, signature যাচাই আগে |

### 2.5 Versioning

- URL-এ major version (`/api/v1`)। Breaking change হলে `/api/v2`, পুরনোটা কমপক্ষে ১২ মাস সাপোর্ট।
- Deprecation হেডার: `Deprecation`, `Sunset`।

---

## 3. Endpoint Groups (ফেজভিত্তিক)

| গ্রুপ | প্রধান endpoint | ফেজ |
|---|---|---|
| `/auth` | `POST token`, `POST two-factor`, `DELETE token`, `GET me`, `POST register`, `POST password/forgot` | ২ |
| `/tenants` | `GET /` (আমার tenants), `POST /`, `GET /{id}`, `POST /{id}/switch` | ২ |
| `/teams` | members, invitations, roles assign | ২ |
| `/devices` | list, revoke | ২ |
| `/plans` | public plan list | ৩ |
| `/models` | available models (plan-ফিল্টারড), preferences | ৪ |
| `/credits` | balance, transactions | ৫ |
| `/usage` | summary by model/agent/department | ৫ |
| `/chat` | `POST /conversations/{id}/messages` (stream via Reverb channel), `POST /stop` | ৬ |
| `/conversations` | CRUD, search, export | ৬ |
| `/channels` | `POST telegram/link-code`, list links, unlink | ৭ |
| `/leads`, `/crm` | research jobs, companies, contacts, leads, pipeline, tasks | ৮ |
| `/proposals`, `/email` | templates, proposals PDF, email accounts, campaigns, approvals | ৯ |
| `/subscriptions`, `/billing` | checkout init, invoices, payment history, cancel | ১০ |
| `/approvals` | `GET pending`, `POST /{id}/decide` (step-up token প্রয়োজন হলে) | ১১ |
| `/notifications` | list, mark read, push register | ১১ |
| `/emergency-stop` | `POST` (সব এজেন্ট/ওয়ার্কফ্লো বা নির্দিষ্ট scope) | ১১ |
| `/stores`, `/products` | connections, products CRUD, bulk import, publish | ১২ |
| `/inbox` | conversations, reply, assign, handover | ১৩ |
| `/orders`, `/delivery` | orders, confirm, courier booking, tracking | ১৪ |
| `/accounting` | accounts, journals, invoices, bills, reports | ১৫–১৬ |
| `/tax` | VAT/tax periods, drafts | ১৭ |
| `/payroll`, `/finance` | payroll runs, analyst Q&A | ১৮ |
| `/memory`, `/knowledge` | memories CRUD, knowledge bases, documents | ১৯ |
| `/calendar`, `/tasks`, `/reminders`, `/notes`, `/meetings` | Productivity Suite | ১৯.২ |
| `/voice` | transcribe, speak, settings | ২০ |
| `/agents`, `/workflows` | agents, runs, workflows, versions, runs | ২১ |
| `/creative` | images, video-projects, renders, brand-kits, assets | ২২ |
| `/social` | accounts, posts, calendar | ২৩ |
| `/ads/meta`, `/ads/google` | accounts, campaigns, change requests, insights | ২৪ |
| `/seo` | projects, audits | ২৪ |
| `/desktop` | `GET config`, `GET updates/{platform}/{version}`, `POST tasks/claim`, `POST tasks/{id}/result`, `POST sync` (লোকাল SQLite সিঙ্ক), `POST pairing/qr` | ২৫–২৬ |
| `/characters` | চরিত্র তালিকা, নির্বাচন, কাস্টম পার্সোনালিটি | ২৫.২ |
| `/developer`, `/plugins` | API key, developer apps, plugin store, install, MCP servers | ২৭.২ |
| `/admin/*` | শুধু platform admin (মূলত Livewire; প্রয়োজনীয় অংশ API) | ৩+ |

---

## 4. Realtime Channels (Reverb)

| Channel | কে শুনবে | Event |
|---|---|---|
| `private-tenant.{tenantId}.user.{userId}` | ইউজার | notifications, approval requests |
| `private-conversation.{id}` | conversation মালিক | `message.chunk`, `message.completed`, `tool.status` |
| `private-tenant.{tenantId}.runs` | permission-যুক্ত ইউজার | agent/workflow run status |
| `private-desktop.{deviceId}` | ডেস্কটপ অ্যাপ | handed-off tasks |
| `private-admin.health` | platform admin | queue/provider health |

Channel authorization Laravel-এ (tenant membership + permission)।

---

## 5. Internal AI API (Laravel → Python)

**Signing:** প্রতিটা রিকোয়েস্টে হেডার:
- `X-Jarvis-Timestamp` (±60s এর বাইরে reject)
- `X-Jarvis-Nonce` (Redis-এ ৫ মিনিট, replay reject)
- `X-Jarvis-Signature: HMAC-SHA256(secret, method + path + timestamp + nonce + sha256(body))`
- `Authorization: Bearer <JWT>`: claims `tenant_id`, `user_id`, `request_id`, `scopes`, `exp` (60s)

| Endpoint | কাজ |
|---|---|
| `POST /internal/v1/chat` | স্ট্রিমিং চ্যাট (routing plan, messages, tools allowlist, max tokens) |
| `POST /internal/v1/embeddings` | embedding তৈরি |
| `POST /internal/v1/documents/ingest` | ডকুমেন্ট parse, chunk, embed |
| `POST /internal/v1/agents/run` | এজেন্ট রান |
| `POST /internal/v1/workflows/execute` | ওয়ার্কফ্লো রান |
| `POST /internal/v1/voice/transcribe` · `/speak` | STT / TTS |
| `POST /internal/v1/vision/analyze` · `/ocr` | ভিশন / OCR |
| `POST /internal/v1/crawl` | লিড ক্রল |
| `POST /internal/v1/media/render` | ভিডিও রেন্ডার |
| `POST /internal/v1/providers/test` | কানেকশন টেস্ট |
| `GET /internal/v1/health` | হেলথ |

**Callback (Python → Laravel):** `POST /internal/v1/callbacks/{usage|run-log|run-finished|chunk}`, `POST /internal/v1/tools/{tool}` (tool execution request, Laravel permission চেক করে চালায়)।

---

## 6. Webhook Ingress

| Path | উৎস | যাচাই |
|---|---|---|
| `/webhooks/sslcommerz` | SSLCommerz IPN | validation API কল |
| `/webhooks/bkash`, `/webhooks/nagad` | MFS | গেটওয়ে query API দিয়ে যাচাই |
| `/webhooks/stripe` | Stripe | `Stripe-Signature` |
| `/webhooks/telegram/{secret-path}` | Telegram | `X-Telegram-Bot-Api-Secret-Token` |
| `/webhooks/meta` | WhatsApp, Messenger, Instagram | `X-Hub-Signature-256`, verify token |
| `/webhooks/stores/{connectionId}` | Woo/Shopify/Connector | HMAC (প্ল্যাটফর্ম অনুযায়ী) |
| `/webhooks/couriers/{courier}` | কুরিয়ার স্ট্যাটাস | প্রোভাইডার secret/IP |

সব webhook: আগে signature যাচাই → raw event লগ → `202` দ্রুত ফেরত → কিউতে প্রসেস → idempotent (external event id unique)।


---

## ফেজ ২ — বাস্তবায়িত `/api/v1/auth`

সফল রেসপন্স `{ "data": ... }`। ভ্যালিডেশন ত্রুটি Laravel-এর ডিফল্ট ৪২২ ফরম্যাটে; কিছু ত্রুটিতে অতিরিক্ত `code` থাকে (`email_unverified`, `two_factor_required`)। Tenant-স্কোপড রুটে `X-Tenant-Id` হেডার লাগে এবং সদস্যপদ আগে যাচাই হয়।

| Method | Path | বিবরণ |
|---|---|---|
| POST | `/auth/login` | `email, password, device_name, [code \| recovery_code]` → Bearer টোকেন (৩০ দিন, সর্বোচ্চ ২০টি; পুরনোগুলো বাদ)। রেট লিমিট ১০/মিনিট (ইমেইল+IP) |
| POST | `/auth/logout` | বর্তমান টোকেন বাতিল |
| GET | `/auth/me` | ইউজার + সক্রিয় workspace তালিকা |
| GET | `/auth/tokens` · DELETE `/auth/tokens/{id}` | টোকেন/ডিভাইস তালিকা ও বাতিল |
| GET | `/workspace` | `X-Tenant-Id`-এর workspace তথ্য |

ওয়েব: Fortify রুট (`/login`, `/register`, `/forgot-password`, `/reset-password`, `/email/verify`, `/two-factor-challenge`, `/user/two-factor-authentication`, …), `/app`, `/app/members`, `/invitations/{token}`, `/settings/security`। লগইন ৫/মিনিট (ইমেইল+IP)।

## ফেজ ১১ — মোবাইল API (`/api/v1/mobile`)
সব রুটে `Authorization: Bearer <Sanctum টোকেন>`; `bootstrap` ছাড়া সবগুলোতে `X-Tenant-Id` (ResolveTenant সদস্যপদ যাচাই করে)। লগইন: `POST /api/v1/auth/login {email,password,device_name[,code]}` → `{data:{token,expires_at,user}}` (৩০ দিন)। উত্তর `{data: …}`; ত্রুটিতে `{message, code}`।

| রুট | কাজ | নোট |
|---|---|---|
| `GET bootstrap` | ব্যবহারকারী, ওয়ার্কস্পেস তালিকা, `can{approve,outreach,emergency,health,billing}`, `min_app_version` | header থাকলে `can` ভরে |
| `POST device` `{name,platform,push_token?,app_version?}` | এই ফোন এই ওয়ার্কস্পেসে নিবন্ধন (পুনরায় করলে আপডেট) | → `{device_id,has_pin}` |
| `PUT device/push`, `DELETE device` | push address বদল / ফোন মুছুন | `428 device_not_registered` যদি নিবন্ধিত না |
| `POST pin {pin,password}` | PIN সেট/বদল | `422 bad_password`/`weak_pin` |
| `POST step-up {pin}` | সার্ভারে PIN যাচাই → ৫ মিনিটের জানালা | `422 bad_pin`, `423 pin_locked`, `409 no_pin` |
| `GET dashboard`, `usage`, `automations`, `health` | সারাংশ; `health` শুধু `workspace.manage` | |
| `GET approvals` | অপেক্ষমাণ আইটেম | `outreach.view` |
| `POST approvals/{kind}/{ref} {decision: approve\|reject}` | সিদ্ধান্ত | `outreach.approve`; approve-এ `403 step_up_required`, `423 emergency_stop`, `409 not_pending`, `422 suppressed` |
| `GET emergency`, `POST emergency {action: stop\|resume, reason?}` | জরুরি স্টপ | `emergency.control`; resume-এ `403 step_up_required` |
| `GET notifications[?unread=1]`, `POST notifications/read {all\|ids}` | অ্যাপ-ভেতরের নোট | |
| `GET chat/conversations`, `GET chat/conversations/{id}`, `POST chat/send {text,conversation_id?}` | চ্যাট (একবারে উত্তর) | ক্রেডিট ত্রুটিতে `error{code,message}` |
