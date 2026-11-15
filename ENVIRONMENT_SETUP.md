# JARVIS ONE — Environment Setup

**Version:** 0.1 (Phase 0) · ফেজ ১-এ কমান্ডগুলো বাস্তবে চালিয়ে যাচাই করে এই ডকুমেন্ট আপডেট হবে।

---

## 1. Prerequisites (ডেভেলপার মেশিন)

| টুল | সংস্করণ | কাজ |
|---|---|---|
| Docker Engine + Compose v2 | সর্বশেষ stable | সব সার্ভিস চালানো |
| Git | 2.4x+ | ভার্সন কন্ট্রোল |
| Make | যেকোনো | শর্টকাট কমান্ড |
| (ঐচ্ছিক) PHP 8.4 + Composer 2 | | কন্টেইনারের বাইরে artisan চালাতে |
| (ঐচ্ছিক) Python 3.13 + uv/pip | | AI engine লোকালি |
| Node 24 LTS | | Vite, Desktop, Mobile |
| Rust stable + Tauri prerequisites | | শুধু ফেজ ২৫ থেকে (Windows: MSVC Build Tools, WebView2) |
| Android Studio / Expo Go | | শুধু ফেজ ১১ থেকে |

ন্যূনতম ৮ GB RAM (Docker-কে কমপক্ষে ৬ GB দিন)।

Windows-এ: WSL2 (Ubuntu 24.04) + Docker Desktop প্রস্তাবিত। প্রজেক্ট WSL ফাইলসিস্টেমের ভেতরে রাখুন (পারফরম্যান্সের জন্য)।

## 2. Local Services (docker-compose, ফেজ ১)

| সার্ভিস | পোর্ট (host) | বিবরণ |
|---|---|---|
| nginx | 8080 | `http://localhost:8080` |
| laravel (php-fpm) | — | অ্যাপ |
| horizon | — | কিউ worker |
| reverb | 8081 | WebSocket |
| scheduler | — | `schedule:work` |
| postgres (18 + pgvector) | 5432 | DB `jarvis`, test DB `jarvis_test` |
| redis (8) | 6379 | cache, queue |
| ai-engine (FastAPI) | 8000 (শুধু ডেভে expose) | internal API |
| mailpit | 8025 | ডেভ ইমেইল ইনবক্স |
| minio | 9000/9001 | S3-compatible storage |

## 3. First Run (ফেজ ১ শেষে কাজ করবে)

```bash
git clone <repo> jarvis-one && cd jarvis-one
cp .env.example .env                       # root (compose)
cp apps/laravel/.env.example apps/laravel/.env
cp apps/ai-engine/.env.example apps/ai-engine/.env
make up                                     # docker compose up -d --build
make laravel-init                           # key:generate, migrate, seed (dev only)
make test                                   # Pest + pytest
```

## 4. Environment Variables (মূল)

| ভেরিয়েবল | কোথায় | বিবরণ |
|---|---|---|
| `APP_KEY` | laravel | Laravel encryption key |
| `VAULT_KEY` | laravel | credential vault envelope key (APP_KEY থেকে আলাদা) |
| `DB_*` | laravel | PostgreSQL |
| `REDIS_*` | laravel, ai-engine | Redis |
| `AI_ENGINE_URL` | laravel | `http://ai-engine:8000` |
| `AI_ENGINE_HMAC_SECRET` | উভয় | internal signing secret (একই মান) |
| `AI_ENGINE_JWT_SECRET` | উভয় | short-lived JWT |
| `REVERB_*` | laravel | WebSocket |
| `FILESYSTEM_DISK`, `AWS_*` | laravel | object storage |
| `MAIL_*` | laravel | ডেভে mailpit |
| Provider key | **env-এ নয়** | Admin প্যানেলের vault-এ |

নিয়ম: `.env` কখনো কমিট নয়; নতুন ভেরিয়েবল যোগ হলে `.env.example` আপডেট বাধ্যতামূলক।

## 5. Useful Commands (ফেজ ১-এ Makefile)

| কমান্ড | কাজ |
|---|---|
| `make up` / `make down` | সার্ভিস চালু/বন্ধ |
| `make sh` | Laravel কন্টেইনারে শেল |
| `make artisan cmd="..."` | artisan |
| `make test` | সব টেস্ট |
| `make test-php` / `make test-py` | আলাদা টেস্ট |
| `make lint` | Pint, PHPStan, ruff |
| `make fresh` | DB রিসেট + dev seed |
| `make logs s=laravel` | লগ |

## 6. Dev Seed Data

- Dev seeder শুধু `APP_ENV=local`-এ চলে; প্রোডাকশনে ব্লক করা।
- Seed ইউজারের ইমেইল `@example.test` ডোমেইনে, স্পষ্টভাবে ডেমো চিহ্নিত।
- কোনো seed ডাটা আসল কাস্টমার ডাটা হিসেবে দেখানো হবে না।


## ডাটাবেস provisioning (ফেজ ২)

```bash
export PGHOST=127.0.0.1 PGPORT=5432 PGUSER=postgres PGPASSWORD=...        # superuser
export DB_DATABASE=jarvis DB_OWNER_PASSWORD="$(openssl rand -hex 24)" DB_APP_PASSWORD="$(openssl rand -hex 24)"
./scripts/db-provision.sh          # role + database বানায়; আবার চালানো নিরাপদ
```
`php artisan migrate` চলবে `jarvis_owner` দিয়ে; ওয়েব ও কিউ চলবে `jarvis_app` দিয়ে (দুটো আলাদা `.env`/connection)। এরপর `php artisan tenancy:check`।


## Laravel অ্যাপ প্রথমবার চালু করা (ক্রমানুসারে)

```bash
cp .env.example .env                    # secret বসান: openssl rand -hex 32 (HMAC ও JWT আলাদা)
make up                                 # postgres, redis, ai-engine
make db-provision                       # jarvis_owner / jarvis_app role
make web-bootstrap                      # apps/web তৈরি + ULID/teams প্যাচ (প্যাচ না মিললে স্ক্রিপ্ট থেমে যায়)
cd apps/web
DB_USERNAME=jarvis_owner DB_PASSWORD='<DB_OWNER_PASSWORD>' php artisan migrate --seed
php artisan tenancy:check               # jarvis_app দিয়ে; পাস না করলে ডিপ্লয় নয়
php artisan serve                       # http://127.0.0.1:8000  (npm run dev আলাদা টার্মিনালে)
```
Dev seed-এর পাসওয়ার্ড টার্মিনালে একবারই দেখায় (`admin@jarvis.test`, `owner@jarvis.test`)।


## অ্যাডমিন প্যানেল চালু করা (ফেজ ৩)
```bash
# .env: DB_ADMIN_USERNAME=jarvis_admin_login  DB_ADMIN_PASSWORD=...   (db-provision এটি বানায়)
DB_OWNER_PASSWORD=... DB_APP_PASSWORD=... DB_ADMIN_PASSWORD=... make db-provision
cd apps/web && php artisan migrate --seed          # DB_USERNAME=jarvis_owner দিয়ে
# রেজিস্টার → ইমেইল ভেরিফাই → /settings/security-এ 2FA চালু, তারপর:
php artisan admin:promote you@example.com
```
`/admin`-এ ঢুকে প্ল্যানের মূল্য বসান (সিডে মূল্য নেই)।


## Provider vault কী (ফেজ ৪)
```bash
php -r "echo 'base64:'.base64_encode(random_bytes(32)),PHP_EOL;"   # → AI_VAULT_KEY_1
```
`.env`: `AI_VAULT_CURRENT=1`, `AI_VAULT_KEY_1=base64:...`। রোটেশন: `AI_VAULT_KEY_2` যোগ → `AI_VAULT_CURRENT=2` → পুরনো credential নতুন করে সেভ। কী অফলাইনে ব্যাকআপ রাখুন।

## Credits (ফেজ ৫)
`.env`: `CREDITS_SIGNUP_GRANT=100` (নতুন workspace-এর স্বাগত ক্রেডিট; `0` = বন্ধ), `CREDITS_SIGNUP_GRANT_DAYS=` (খালি = মেয়াদহীন), `CREDITS_RESERVATION_TTL=900`।
scheduler চালু থাকতে হবে (`php artisan schedule:work` বা cron `* * * * * php artisan schedule:run`) — stale reservation ছাড়া, মেয়াদোত্তীর্ণ ক্রেডিট ও সতর্কতা ইমেইল এর উপর নির্ভর করে।
বিদ্যমান ইনস্টলে: `php artisan migrate`; Finance Admin রোলে `credits.manage` টিক দিন (রোল পেজ); পুরনো workspace-গুলোর ওয়ালেট নেই — প্রথম অ্যাডমিন সমন্বয়ে বা স্বাগত-গ্রান্টে তৈরি হয়।

## Chat ও ডিপ্লয় (ফেজ ৬)
- ভেরিয়েবল: `CHAT_MAX_OUTPUT` (2048), `CHAT_MAX_INPUT` (24000), `CREDITS_RATE_INPUT_PER_1K` (1), `CREDITS_RATE_OUTPUT_PER_1K` (3)।
- সার্ভারে ইনস্টল: `deploy/README.md` (VPS), `deploy/CPANEL.md` (cPanel ডেমো মোড)।
- মেইল ছাড়া ইউজার যাচাই: `php artisan user:verify email@example.com`।

## Telegram (ফেজ ৭)
- ভেরিয়েবল: `MESSAGING_MESSAGES_PER_MINUTE` (20), `MESSAGING_SHOW_COST` (true), `TELEGRAM_API_BASE` (ডিফল্ট api.telegram.org)।
- বটের টোকেন `.env`-এ নয় — অ্যাডমিন প্যানেলে (`/admin/channels`) দিলে এনক্রিপ্ট হয়ে DB-তে থাকে।
- আবশ্যক: সর্বসাধারণের জন্য খোলা **HTTPS** ডোমেইন (বৈধ সার্টিফিকেট) — Telegram শুধু তাতেই webhook পাঠায়। লোকাল মেশিনে টেস্ট করতে tunnel লাগে।
- কিউ: VPS-এ Horizon (redis) চালু থাকলে webhook দ্রুত 200 দেয়। cPanel-এ `QUEUE_CONNECTION=sync` হলে পুরো AI উত্তর webhook অনুরোধের ভেতরেই চলে (ধীর মডেলে Telegram টাইমআউট করে আবার পাঠাতে পারে — dedupe থাকায় দুবার উত্তর যায় না, তবে প্রথম উত্তর দেরিতে আসতে পারে)।

## Lead research (ফেজ ৮এ)
- Laravel: `LEADS_MAX_URLS_PER_JOB` (200), `LEADS_MAX_JOBS_PER_DAY` (20), `LEADS_MAX_ACTIVE_JOBS` (2)।
- Engine: `AI_ENGINE_CRAWLER_MIN_DELAY` (১.০ সেকেন্ড/হোস্ট), `AI_ENGINE_CRAWLER_CONCURRENCY` (4)। **`AI_ENGINE_CRAWLER_ALLOW_PRIVATE` প্রোডাকশনে কখনো নয়।**
- **engine image এখন ডিফল্টে Chromium সহ** (প্রস্তাবের PDF-এর জন্য বাধ্যতামূলক; JS-সাইট ক্রলও এতে চলে)। প্রথম বিল্ডে ~৪০০MB নামে। হালকা চাইলে `--build-arg WITH_RENDER=0` — তখন DOCX চলবে, PDF বোতাম বলবে "PDF এখন তৈরি করা যাচ্ছে না"।
- আপডেটের পর `php artisan roles:sync` (update.sh নিজে চালায়) — নইলে পুরনো workspace-এ লিড পারমিশন থাকবে না।
- Horizon চালু থাকতে হবে (প্রতি সাইট একটি job)।

## Proposals (ফেজ ৯এ)
- নতুন env নেই। ফন্ট `apps/ai-engine/app/docs/fonts/` (Noto Sans / Noto Sans Bengali, SIL OFL — লাইসেন্স ফাইল পাশেই)।
- আপডেটের পর `php artisan migrate` এবং `php artisan roles:sync` (নইলে পুরনো workspace-এ `proposals.*` পারমিশন থাকবে না), তারপর ai-engine রিবিল্ড।

## Outreach (ফেজ ৯বি)
- মেইল পাঠায় `php artisan outreach:run`, যা scheduler চালায় প্রতি মিনিটে — তাই cron-এ `* * * * * php /path/artisan schedule:run` থাকতে হবে (cPanel-এ Cron Jobs)। queue worker লাগে না। নতুন env নেই; SMTP তথ্য UI থেকে, পাসওয়ার্ড AI vault key দিয়ে এনক্রিপ্ট হয় (`AI_VAULT_KEY_*` হারালে বা বদলালে পুরনো ঠিকানার পাসওয়ার্ড আবার দিতে হবে)।
- `APP_URL` সঠিক ও https হতে হবে — আনসাবস্ক্রাইব লিংক এটি দিয়ে বানানো হয় (ভুল হলে লিংক ভাঙা যাবে)।
- আপডেটের পর: `php artisan migrate`, `php artisan roles:sync`।
- shared hosting-এ বাইরে SMTP পোর্ট (465/587) বন্ধ থাকতে পারে; সেক্ষেত্রে হোস্টিং সাপোর্টকে জিজ্ঞাসা করুন।

## Gmail / Outlook সংযোগ (ফেজ ৯সি)
**ক্যালেন্ডার সংযোগ (ফেজ ১৯.২ই):** একই Google/Microsoft অ্যাপ-key চলে। অতিরিক্ত: Google Cloud-এ *Google Calendar API* চালু করুন ও consent screen-এ `.../auth/calendar.events` স্কোপ যোগ করুন; Microsoft অ্যাপে delegated permission `Calendars.ReadWrite` যোগ করুন। Redirect URI: `{APP_URL}/app/connect/oauth/google/callback` ও `{APP_URL}/app/connect/oauth/microsoft/callback`।

**Redirect URI (দুটোই `APP_URL` সঠিক ও https হতে হবে):** `{APP_URL}/app/outreach/oauth/gmail/callback` এবং `{APP_URL}/app/outreach/oauth/graph/callback`।
**Google:** Google Cloud Console → নতুন প্রজেক্ট → *Gmail API* চালু → *OAuth consent screen* → *Credentials → OAuth client ID → Web application* → ওপরের Redirect URI। scope যোগ: `gmail.send`, `gmail.readonly`, `openid`, `email`। `.env`: `OUTREACH_GOOGLE_CLIENT_ID`, `OUTREACH_GOOGLE_CLIENT_SECRET`।
  - ⚠ **Publishing status "Testing" থাকলে Google refresh token ৭ দিনে মেয়াদ শেষ করে** — সংযোগ প্রতি সপ্তাহে ভাঙবে। "In production"-এ নিন (যাচাইহীন অ্যাপে "unverified" সতর্কতা আসে ও সর্বোচ্চ ১০০ জন ব্যবহারকারী)। `gmail.readonly` **restricted scope** — ১০০ জনের বেশি/প্রকাশ্য বিক্রির জন্য Google-এর যাচাই (ও নিরাপত্তা মূল্যায়ন) লাগে। নিজের Google Workspace-এ "Internal" টাইপে এসব ঝামেলা নেই।
**Microsoft:** Microsoft Entra → App registrations → New → Redirect URI (Web) → Certificates & secrets → New client secret (মেয়াদ শেষ হলে নতুন বানাতে হবে) → API permissions (Delegated): `Mail.Send`, `Mail.Read`, `User.Read`, `offline_access`। `.env`: `OUTREACH_MS_CLIENT_ID`, `OUTREACH_MS_CLIENT_SECRET`, `OUTREACH_MS_TENANT` (`common` = যেকোনো অ্যাকাউন্ট; শুধু নিজের প্রতিষ্ঠান হলে tenant id)।
**তারপর:** `php artisan config:clear`; `php artisan migrate`; `php artisan roles:sync` অপরিবর্তিত। বোতাম ধূসর থাকলে key বসেনি।

## পেমেন্ট গেটওয়ে (ফেজ ১০)
একটি গেটওয়ে চালু হয় কেবল তার সব কী থাকলে (`/admin/billing`-এ "চালু" তালিকা দেখুন)। বদলানোর পর `php artisan config:clear`। **Return URL:** `{APP_URL}/billing/return/{gateway}` · **IPN/Webhook URL:** `{APP_URL}/billing/ipn/{gateway}` (`APP_URL` সঠিক https হতে হবে; গেটওয়ে নিজে এই URL-এ POST করে)।
- **SSLCommerz:** sandbox store খুলে `BILLING_SSLCOMMERZ_STORE_ID`, `..._STORE_PASSWORD`, `..._SANDBOX=true`। মার্চেন্ট প্যানেলে IPN URL: `{APP_URL}/billing/ipn/sslcommerz`। লাইভে `SANDBOX=false`।
- **bKash (Tokenized Checkout):** `BILLING_BKASH_APP_KEY`, `..._APP_SECRET`, `..._USERNAME`, `..._PASSWORD`, `..._SANDBOX`। callback হিসেবে return URL দেওয়া হয় (অনুরোধের সাথে)।
- **Nagad:** `BILLING_NAGAD_MERCHANT_ID`, `BILLING_NAGAD_MERCHANT_PRIVATE_KEY` (PEM বা শুধু base64 অংশ), `BILLING_NAGAD_PG_PUBLIC_KEY`, `BILLING_NAGAD_BASE` (ডিফল্ট লাইভ `https://api.mynagad.com/api/dfs`)। ⚠ Nagad sandbox `http` — অ্যাপ শুধু https বলে `BASE` http হলে Nagad নিজে বন্ধ থাকে।
- **Stripe:** `BILLING_STRIPE_SECRET_KEY`, `BILLING_STRIPE_WEBHOOK_SECRET`, `BILLING_STRIPE_CURRENCIES=USD`। Stripe ড্যাশবোর্ডে webhook endpoint `{APP_URL}/billing/ipn/stripe`, ইভেন্ট: `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `checkout.session.async_payment_failed`, `checkout.session.expired`। USD দাম অ্যাডমিন → প্ল্যানে আলাদা বসাতে হবে (BDT-র সাথে মিলিয়ে নয়)।
- **ডেমো:** `BILLING_DEMO=true` — কোনো আসল টাকা নয়; ইচ্ছাকৃতভাবে চালু না করলে বন্ধ। **লাইভ সার্ভারে true রাখবেন না।**
- **দাম:** অ্যাডমিন → প্ল্যান → দাম (মুদ্রা + মাসিক/বার্ষিক)। দাম VAT ছাড়া; BDT অর্ডারে VAT যোগ হয় (ডিফল্ট ১৫%, `/admin/billing`-এ বদলানো যায়)। বিক্রেতার নাম/ঠিকানা/BIN একই পাতায় ভরুন।
- **Cron:** scheduler (`* * * * * php artisan schedule:run`) চালু থাকলে `billing:run` প্রতি ১০ মিনিটে গ্রেস/মেয়াদ/রিফিল সামলায়। cPanel-এ cron না থাকলে মেয়াদ ও গ্রেস আপডেট হবে না (তবে `effective_feature` গ্রেস সীমা ধরেই সুবিধা বন্ধ করে)।
- `php artisan migrate` (Phase 10 migration `2026_11_04_100000_create_billing_tables`), তারপর `php artisan roles:sync` (নতুন `billing.manage`)। প্ল্যাটফর্ম রোল (Finance Admin) আগে থেকে থাকলে অ্যাডমিন → রোলে `billing.manage` হাতে দিন।

## মোবাইল (ফেজ ১১)
- সার্ভার: `php artisan migrate` (`2026_11_05_100000_create_mobile_tables`), `php artisan roles:sync` (নতুন `emergency.control`)। `.env`: `MOBILE_PUSH=expo` (বা `none`), `EXPO_ACCESS_TOKEN` (ঐচ্ছিক — Expo-তে "enhanced security" চালু করলে), `MOBILE_MIN_APP_VERSION`। scheduler: `* * * * * php artisan schedule:run` (`mobile:push` প্রতি মিনিটে)।
- অ্যাপ: Node 22; `apps/mobile/README.md`-তে EAS APK বিল্ড (Expo অ্যাকাউন্ট, `eas init`, `eas.json`-এ আপনার ডোমেইন, Android push-এর জন্য FCM V1 credentials `eas credentials`)। অ্যাপ id `com.jarvisone.app` — নিজের নামে প্রকাশের আগে `app.json`-এ বদলান।
- সার্ভার অবশ্যই **https**; cPanel/VPS দুটোতেই চলে (push ছাড়া কিছুই কোনো আলাদা সার্ভিস লাগে না)।

## পণ্য ক্যাটালগ (ফেজ ১২এ)
- `php artisan migrate` (`2026_11_06_100000_create_catalog_tables`), `php artisan roles:sync` (নতুন `catalog.view|manage|approve`)। নতুন env নেই। AI বিবরণের জন্য অন্তত একটি মডেল/provider চালু ও ক্রেডিট থাকা চাই।

## দোকান সংযোগ (ফেজ ১২বি)
- `php artisan migrate` (`2026_11_07_100000_create_store_sync_tables`), `php artisan roles:sync` (নতুন `stores.manage`)। cron-এ `schedule:run` (প্রতি মিনিটে) থাকলে `catalog:sync` নিজে চলে। ঐচ্ছিক env: `SHOPIFY_API_VERSION` (ডিফল্ট 2026-07), `CATALOG_SYNC_EVERY_MINUTES` (ডিফল্ট 15)।
- সার্ভার থেকে আপনার দোকানে বের হওয়া https খোলা থাকতে হবে। WooCommerce-এ permalink "Plain" না হয়ে অন্য কিছু হতে হবে এবং REST API চালু।

## Connector (12c)
- JARVIS-এ নতুন migration নেই। শপে: `JARVIS_CONNECTOR_SECRET` (`php artisan jarvis-connector:secret`), https বাধ্যতামূলক; ইনস্টল ধাপ `packages/jarvis-connector/README.md`।

## Inbox (13a)
- `php artisan migrate` (`2026_11_08_100000_create_inbox_tables`), `php artisan roles:sync`। `APP_URL` সঠিক (https) থাকতে হবে — embed কোড এটি থেকে বানানো হয়। cPanel-এও চলে (অতিরিক্ত সার্ভিস লাগে না); উইজেট প্রতি ৪ সেকেন্ডে poll করে, তাই ভিড়ের সাইটে সার্ভার লোড দেখুন (পরে SSE/WebSocket)।

## Inbox channels (13b)
- `php artisan migrate` (মাইগ্রেশন `2026_11_08_110000_create_inbox_agent` — phase13b + phase13c; **১৩বি ZIP-এ এটি ছিল না, ১৩সি ZIP ব্যবহার করুন**)। scheduler চালু থাকতে হবে (`* * * * * php artisan schedule:run`) — `inbox:dispatch` প্রতি মিনিটে। cPanel-এ cron আছে কি দেখুন। Meta/Telegram webhook-এর জন্য সাইট **পাবলিক https**-এ থাকতে হবে (localhost-এ হবে না; টেস্টে ngrok/cloudflared)। `INBOX_GRAPH_VERSION` (ডিফল্ট v21.0) — Meta পুরনো সংস্করণ বন্ধ করলে বাড়ান।

## AI সাপোর্ট এজেন্ট (13c)
- `php artisan migrate`, `php artisan roles:sync` (নতুন অনুমতি নেই, তবু নিরাপদ)। AI উত্তর **কিউ-জব**: ভিপিএসে `php artisan queue:work` চালু রাখুন; cPanel ডেমোতে `QUEUE_CONNECTION=sync` চলবে (গ্রাহকের অনুরোধের ভেতরেই AI কল হয় — ৫–২০ সেকেন্ড ধীর হতে পারে; উইজেট তাই `client_id` দিয়ে নিরাপদ পুনঃচেষ্টা করে)।
- ওয়ার্কস্পেসের ক্রেডিট থাকতে হবে এবং প্ল্যানে অন্তত একটি AI মডেল চালু থাকতে হবে, নইলে এজেন্ট প্রতিটি চ্যাট মানুষের কাছে পাঠাবে।

## অর্ডার (14a)
- `php artisan migrate` (`2026_11_09_100000_create_orders`) ও `php artisan roles:sync` (নতুন অনুমতি `orders.*`)। নতুন কোনো .env নেই। `/app/orders/settings`-এ ডেলিভারি চার্জ ও ছাড়-সীমা ঠিক করুন (ডিফল্ট ৬০/১২০ টাকা, ছাড় ০%)।

## চ্যাটে অর্ডার (14b)
- `php artisan migrate` (`2026_11_09_110000_create_order_chat`)। নতুন .env নেই। চালু করতে: `/app/orders/settings` → "চ্যাটে AI অর্ডার নিতে পারবে" এবং ইনবক্স → AI এজেন্টে চ্যানেল **অটো**। ক্রেডিট ও AI মডেল চালু থাকতে হবে।

## কুরিয়ার (14c)
- `php artisan migrate` (`2026_11_09_120000_create_couriers`) ও `php artisan roles:sync` (নতুন অনুমতি `couriers.manage`)। নতুন .env নেই।
- **শিডিউলার চালু থাকতে হবে:** `couriers:sync` প্রতি ১৫ মিনিটে, `orders:daily-report` রাত ১২:১০ (ঢাকা)। ভিপিএস/cPanel দুই জায়গাতেই `* * * * * php /path/artisan schedule:run` cron লাগে। প্রতি রানে সর্বোচ্চ ১০০ পার্সেল চেক হয় (কোডে ডিফল্ট; আলাদা .env নেই)।
- কুরিয়ারের কী: Steadfast (API Key + Secret Key), Pathao (Client ID/Secret + ইমেইল/পাসওয়ার্ড + Store ID), RedX (Access Token + ঐচ্ছিক Pickup Store ID) — `/app/orders/couriers`-এ।

## হিসাব (15a)
- `php artisan migrate` (`2026_11_10_100000_create_accounting`) ও `php artisan roles:sync` (নতুন অনুমতি `accounting.*`)। নতুন .env নেই, নতুন শিডিউল নেই। প্রথম ব্যবহারে `/app/accounting/accounts` থেকে চার্ট তৈরি করুন। টেস্ট চালাতে (ঐচ্ছিক) `pip install hypothesis`।

## ফেজ ১৫বি
নতুন কোনো env/প্যাকেজ নেই। `php artisan migrate` (মাইগ্রেশন `2026_11_10_110000_create_accounting_docs`) চালালেই হবে; cPanel/VPS দুটোতেই একই।

## ফেজ ১৫সি
নতুন কোনো env/প্যাকেজ নেই। `php artisan migrate` (`2026_11_10_120000_create_accounting_reports`) চালালেই হবে।

## ফেজ ১৬এ
নতুন প্যাকেজ নেই। `php artisan migrate` (`2026_11_11_100000_create_bank_reconciliation`)। আপলোড করা CSV সাময়িকভাবে Laravel cache-এ থাকে (file/redis/database যেকোনো ড্রাইভার চলে; cPanel-এ `storage/framework/cache` লেখার অনুমতি লাগে)। PHP `upload_max_filesize` ও `post_max_size` ≥ ২M রাখুন।

## ফেজ ১৬বি
নতুন প্যাকেজ/env নেই। `php artisan migrate` (`2026_11_11_110000_create_assets_inventory`)।

## ফেজ ১৬সি
নতুন প্যাকেজ/env নেই। `php artisan migrate` (`2026_11_12_110000_create_autopost`)। cPanel-এ cron `* * * * * php artisan schedule:run` থাকলে `accounting:autopost` প্রতি ১০ মিনিটে চলে; না থাকলেও স্ক্রিনের বোতামে চলে।

## ফেজ ১৬ডি
**ইঞ্জিন:** নতুন প্যাকেজ `pypdf`, `pypdfium2`, `pillow` (`pip install -e .`; সবই wheel, কম্পাইলার লাগে না) — তারপর engine রিস্টার্ট। **Laravel:** `php artisan migrate` (`2026_11_13_110000_create_bill_scans`)। বিল ফাইল `storage/app/private/bill-scans/` এ থাকে — লেখার অনুমতি লাগে, ব্যাকআপে ধরুন। PHP `upload_max_filesize`/`post_max_size` ≥ ৬M। অ্যাডমিনে অন্তত একটি মডেলে `vision` ক্ষমতা ও প্ল্যান-অ্যাক্সেস (নইলে "মডেল নেই" দেখাবে)। স্ক্যান ~১ মিনিট পর্যন্ত লাগতে পারে — web server/proxy timeout ≥ ২ মিনিট রাখুন (nginx `proxy_read_timeout`/`fastcgi_read_timeout`)।

## ফেজ ১৭এ
নতুন প্যাকেজ/env নেই। `php artisan migrate` (`2026_11_14_100000_create_vat`)।

## ফেজ ১৭বি
`php artisan migrate` চালান (2026_11_14_110000_create_tax_withholding)। চার্টে `tax_payable` হিসাব থাকতে হবে; `tax_advance` হিসাব প্রথম ব্যবহারে নিজে তৈরি হয়।

## ফেজ ১৭সি
`php artisan migrate` চালান (2026_11_15_100000_create_tax_calendar)। কোনো নতুন কনফিগ/এনভ নেই।

## ফেজ ১৮এ
`php artisan migrate` (2026_11_16_100000_create_auditor), তারপর `php artisan roles:sync` — নতুন `accounting.audit` অনুমতি সব workspace-এর Owner/Admin/Auditor রোলে বসবে।

## ফেজ ১৮বি
`php artisan migrate` (2026_11_17_100000_create_payroll), তারপর `php artisan roles:sync` (নতুন `payroll.view`/`payroll.manage`)। চার্টে "বেতন ও মজুরি" (6100) ও "বেতন পরিশোধযোগ্য" (2400) থাকতে হবে — স্ট্যান্ডার্ড চার্টে আছে।

## ফেজ ১৮সি
`php artisan migrate` (2026_11_18_100000_create_payroll_pay)। নতুন অনুমতি নেই। PDF লাইব্রেরি লাগে না — ব্রাউজার থেকে প্রিন্ট → "PDF হিসেবে সেভ"।

## ফেজ ১৮ডি
`php artisan migrate` (2026_11_19_100000_create_analyst) — এটি `usage_records` CHECK ঠিক করে, তাই বিল-স্ক্যান/পণ্য-লেখক/সাপোর্ট এজেন্টের ক্রেডিট কাটা এরপর থেকে কাজ করবে। AI ব্যাখ্যার জন্য অ্যাডমিন রাউটিংয়ে `analyst` টাস্ক-টাইপে (text) মডেল বাছা যায়; না বাছলে ডিফল্ট রাউটিং।

## ফেজ ১৯এ (মেমোরি)
- `php artisan migrate` (`2026_11_20_100000_create_memory`) এবং `php artisan roles:sync` (নতুন অনুমতি `memory.manage`)। `bootstrap-web.sh`-এ `MemoryServiceProvider` আছে। নতুন env নেই।

## ফেজ ১৯বি (নলেজ বেস)
- `php artisan migrate` (`2026_11_24_100000_create_knowledge`) এবং `php artisan roles:sync` (নতুন অনুমতি `knowledge.manage`)। `bootstrap-web.sh`-এ `KnowledgeServiceProvider` আছে। PHP `zip` এক্সটেনশন (DOCX-এর জন্য) ও ঐচ্ছিক `pdftotext` (poppler-utils, PDF-এর জন্য; cPanel-এ না থাকলে PDF-কে DOCX/TXT করে দিতে হবে)। আপলোড সীমা: `upload_max_filesize`/`post_max_size` ≥ ৫ MB রাখুন। pgvector বা কোনো PostgreSQL এক্সটেনশন লাগে না।

## ফেজ ১৯সি (সোর্সসহ উত্তর)
- `php artisan migrate` (`2026_11_25_100000_create_chat_sources`)। নতুন অনুমতি/env/প্যাকেজ নেই। `public/js/chat.js` বদলেছে — ব্রাউজার ক্যাশ/CDN থাকলে পরিষ্কার করুন।

## ফেজ ১৯ডি (ছবি পড়া)
- `php artisan migrate` (`2026_11_26_100000_vision_usage_source`) এবং **AI ইঞ্জিন নতুন কোড দিয়ে রিস্টার্ট** (নতুন রুট `/internal/v1/vision/read`; পুরনো ইঞ্জিনে ছবি পড়া 404 দেবে)। নতুন Python প্যাকেজ নেই (Pillow, pypdf, pypdfium2 আগের)। অন্তত একটি `vision` ক্ষমতার মডেল প্ল্যানে থাকতে হবে; ঐচ্ছিক রাউটিং-রুল টাস্ক `vision_read`। ছবি আপলোডের জন্য PHP `upload_max_filesize`/`post_max_size` ≥ ৬ MB।

## ফেজ ১৯.২এ (টাস্ক/রিমাইন্ডার)
- `php artisan migrate` (`2026_11_27_100000_create_productivity`)। রিমাইন্ডারের জন্য Laravel শিডিউলার প্রতি মিনিট চলতে হবে (cPanel cron: `* * * * * cd /path && php artisan schedule:run >> /dev/null 2>&1` — মোবাইল পুশ/ইনবক্সের জন্য আগেই থাকার কথা)। নতুন প্যাকেজ বা কী নেই।

## ফেজ ১৯.২বি (ফাইল তৈরি)
- `php artisan migrate` (`2026_11_28_100000_create_generated_files`)। **AI ইঞ্জিনে নতুন প্যাকেজ ইনস্টল ও রিস্টার্ট:** `pip install -e .` (বা `pip install openpyxl python-pptx`) — নতুন রুট `/internal/v1/office/*`। পুরনো ইঞ্জিনে ডাউনলোড 'ফাইলটি বানানো যায়নি' দেখাবে। অন্তত একটি টেক্সট AI মডেল প্ল্যানে লাগবে।

## ফেজ ১৯.২সি (লেখা-সহকারী)
- `php artisan migrate` (`2026_11_29_100000_assist_usage_source`)। ইঞ্জিনে নতুন কিছু নেই। অন্তত একটি টেক্সট AI মডেল লাগবে।

## এজেন্ট ইঞ্জিন (ফেজ ২১এ)
- নতুন এনভ ভ্যারিয়েবল নেই। `php artisan migrate` (`2026_11_30_100000_create_workflows`) চালান; কমান্ড `workflows:work` শিডিউলারে নিজে যোগ হয় — cPanel/VPS-এ cron `* * * * * php artisan schedule:run` থাকলেই চলবে।

## ভয়েস (ফেজ ২০)
- ঐচ্ছিক: `CREDITS_VOICE_LISTEN_PER_MB=1` (শোনা, প্রতি MB), `CREDITS_VOICE_SPEAK_PER_1K=1` (বলা, প্রতি ১০০০ অক্ষর)। নতুন মাইগ্রেশন নেই। ইঞ্জিন redeploy লাগবে (নতুন রুট `/internal/v1/voice/*`; নতুন প্যাকেজ নেই)। ভয়েস প্রোভাইডার ও মডেল অ্যাডমিন প্যানেল থেকে (`audio_in`/`audio_out`)।

## সংযোগ (ফেজ ১৯.২ডি)
- নতুন এনভ নেই। `php artisan migrate` (`2026_12_01_100000_create_user_links`)। অডিও→মিনিটসের জন্য ফেজ ২০-এর ভয়েস প্রোভাইডার/`audio_in` মডেল লাগবে।

## ডিজাইন স্টুডিও (ফেজ ২২.১)
- `php artisan migrate` (`2026_12_02_100000_create_studio`); **AI ইঞ্জিন নতুন কোড দিয়ে রিস্টার্ট** (নতুন রুট `/internal/v1/studio/*`, বান্ডেলড ফন্ট `app/studio/fonts/`)। নতুন Python প্যাকেজ নেই (Pillow আগের)।
- **সার্ভারে `libfribidi0` লাগবে** বাংলা যুক্তাক্ষর ঠিকমতো আঁকতে (Ubuntu/Debian: `sudo apt install libfribidi0`; Pillow-র নিজস্ব wheel-এ বাকিটা আছে)। না থাকলে বাংলা ডিজাইন আঁকতে অস্বীকার করে ও কারণ দেখায় — ভুল অক্ষর আঁকে না। পরীক্ষা: `python3 -c "from PIL import features; print(features.check('raqm'))"` → `True`।
- ঐচ্ছিক: `CREDITS_IMAGE_PER_IMAGE=5` (AI ছবি প্রতি)। অ্যাডমিনে একটি মডেলে `image_gen` ক্ষমতা + প্ল্যান-অ্যাক্সেস; ঐচ্ছিক রাউটিং-রুল টাস্ক `image_gen`।
- ক্যাশ: আঁকা ছবি ১ ঘণ্টা Laravel ক্যাশে থাকে (ফাইল/Redis যেকোনো ড্রাইভার চলে)। ZIP ডাউনলোডের জন্য PHP `zip` এক্সটেনশন (না থাকলে বোতাম লুকানো থাকে)। আপলোডের জন্য `upload_max_filesize` ≥ ৫M।

## ভিডিও স্টুডিও (ফেজ ২২.২a)
- ইঞ্জিন হোস্টে `ffmpeg` (libx264, aac) ও `ffprobe` লাগবে: `sudo apt install ffmpeg`। ইঞ্জিন রিস্টার্ট করুন।
- Laravel: `CREDITS_VIDEO_PER_SECOND=0.5` (প্রতি সেকেন্ড ক্রেডিট), migration `2026_12_03_100000_create_video_jobs`।
- cron: `* * * * * php artisan schedule:run` (ভিডিও ওয়ার্কার `video:work` প্রতি মিনিটে)।
- প্রক্সি/FastCGI টাইমআউট ওয়ার্কারের জন্য অন্তত ৫ মিনিট (ওয়ার্কার কনসোলে চলে, ওয়েব রিকোয়েস্টে নয়, তবু ইঞ্জিনের সামনের প্রক্সি ≥৫ মিনিট রাখুন)।
- ভয়েস-ওভার চাইলে অ্যাডমিনে একটি মডেলে `audio_out` সক্ষমতা।

## সাবটাইটেল ও AI স্ক্রিপ্ট (ফেজ ২২.২b)
- নতুন কনফিগ নেই। সাবটাইটেল সময় মাপতে `ffprobe` লাগে (ffmpeg-এর সাথেই আসে); না থাকলে ভিডিওর পুরো দৈর্ঘ্যে ভাগ হয়। AI স্ক্রিপ্টের জন্য একটি চালু চ্যাট মডেল লাগে।

## ক্লিপ এডিটর (ফেজ ২২.২c-ক)
- PHP: `upload_max_filesize = 30M`, `post_max_size = 30M` (cPanel: MultiPHP INI Editor)। প্রক্সি `client_max_body_size 30m`।
- ইঞ্জিন হোস্টে `ffmpeg` + `ffprobe` (আগের মতোই)। ইঞ্জিনের রিকোয়েস্ট-বডি বড় (বেস৬৪-এ ~৩৪ MB পর্যন্ত) — ইঞ্জিনের সামনের প্রক্সিতেও ৪০ MB বডি-সীমা রাখুন।

## টাইমলাইন ও AI ছবি (ফেজ ২২.২c-গ)
- নতুন ফাইল `public/js/video-timeline.js` (ডিপ্লয়ে public/ কপি হয়েছে কিনা দেখুন)। AI ছবির জন্য একটি মডেলে `image_gen` সক্ষমতা ও প্রোভাইডার টাইমআউট ≥১২০ সেকেন্ড (ওয়ার্কার প্রতি ছবি সিরিয়ালে আঁকে)।

## সোশ্যাল প্ল্যানার (ফেজ ২৩a)
- মাইগ্রেশন `2026_12_04_100000_create_social`; নতুন প্রোভাইডার `SocialServiceProvider` (`scripts/bootstrap-web.sh` লিস্টে আছে); নতুন পারমিশনের জন্য `php artisan roles:sync`। সময়-অঞ্চল `APP_TIMEZONE` (ডিফল্ট Asia/Dhaka)।

## Facebook পেজ অটো-পোস্ট (ফেজ ২৩b)
- মাইগ্রেশন `2026_12_05_100000_create_social_publish`; `roles:sync` (নতুন `social.connect`); ক্রন `* * * * * php artisan schedule:run` (`social:publish`)। ঐচ্ছিক `.env`: `FACEBOOK_GRAPH_VERSION` (ডিফল্ট v24.0 — Facebook পুরনো ভার্সন বন্ধ করে, developers.facebook.com/docs/graph-api/changelog দেখুন)।
- ভিডিও আপলোডে PHP মেমরি ও সময় লাগে (≤১০০MB, টাইমআউট ১৮০ সেকেন্ড); ছবি ≤১০MB।

## পোস্ট ইনসাইট (ফেজ ২৩c)
- মাইগ্রেশন `2026_12_06_100000_create_social_stats`; কমান্ড `social:insights` ক্রনে নিজে চলে (`schedule:run`)। নতুন কোনো `.env` লাগে না।

## AI কন্টেন্ট-প্ল্যান (ফেজ ২৩.২a)
- মাইগ্রেশন `2026_12_07_100000_create_social_plans`; একটি চ্যাট মডেল চালু ও ক্রেডিট থাকতে হবে। নতুন `.env` নেই।

## গ্রোথ বিশ্লেষণ (ফেজ ২৩.২b)
- নতুন মাইগ্রেশন/`.env` নেই। AI পরামর্শের জন্য একটি চ্যাট মডেল ও ক্রেডিট লাগে; সংখ্যার জন্য ইনসাইট ক্রন (`social:insights`) চালু থাকতে হবে।

## কমেন্ট-রিপ্লাই (ফেজ ২৩.২c)
- মাইগ্রেশন `2026_12_08_100000_create_social_comments`; ক্রন `schedule:run` ইতিমধ্যে `social:reply` (প্রতি মিনিট) ও `social:comments` (প্রতি ১০ মিনিট) চালায়। Page টোকেনে **pages_read_engagement** ও **pages_manage_engagement** (Facebook অ্যাপ রিভিউ লাগতে পারে) দরকার; নতুন `.env` নেই।

## YouTube (ফেজ ২৩.২d)
- `SOCIAL_YOUTUBE_CLIENT_ID`, `SOCIAL_YOUTUBE_CLIENT_SECRET` — Google Cloud OAuth ক্লায়েন্ট; YouTube Data API v3 চালু; স্কোপ youtube.upload + youtube.readonly; redirect URI `APP_URL/app/social/youtube/callback`। Google অ্যাপ যাচাই/টেস্ট-ইউজার সেটআপ লাগতে পারে।
- ঐচ্ছিক: `SOCIAL_YOUTUBE_DAILY_UPLOADS` (৫), `SOCIAL_YOUTUBE_DAILY_UPLOADS_PER_WORKSPACE` (৩)। আপলোড ≈১৬০০ ইউনিট, দৈনিক ১০,০০০ ইউনিট পুরো ইনস্টলেশনের জন্য।
- মাইগ্রেশন চালান; cron `* * * * * php artisan schedule:run` (social:youtube প্রতি মিনিটে)।

## Meta Ads (ফেজ ২৪.১a)
- আলাদা কোনো env লাগে না (`FACEBOOK_GRAPH_VERSION` ব্যবহার হয়)। cron `schedule:run` থাকলেই `ads:sync` চলে।
- Meta Business Manager-এ System User বানিয়ে বিজ্ঞাপন-অ্যাকাউন্ট দিন; টোকেনে শুধু `ads_read`।

## ফেজ ২৪.১c — বিজ্ঞাপন তৈরি
- মাইগ্রেশন `2026_12_12_100000_create_ads_launch`; `php artisan roles:sync`; cron `* * * * * php artisan schedule:run` (এখন `ads:launch` প্রতি মিনিট)।
- `.env`: `ADS_LAUNCHES_PER_HOUR` (ডিফল্ট ৩)। Meta টোকেনে `ads_read` + `ads_management`।

## ফেজ ২৪.১d — বিরতি
- মাইগ্রেশন `2026_12_13_100000_create_ads_pause`; cron আগের মতোই (`ads:pause` প্রতি মিনিট, schedule:run-এর ভেতরে)। টোকেনে `ads_management`।

## ফেজ ২৪.২a — Google Ads
- মাইগ্রেশন `2026_12_14_100000_create_gads`; cron আগের মতোই (`gads:sync` প্রতি ৩০ মিনিট)।
- `.env`: `GOOGLE_ADS_DEVELOPER_TOKEN`, (ঐচ্ছিক) `GOOGLE_ADS_CLIENT_ID/SECRET`, `GOOGLE_ADS_API_VERSION=v24`। config ক্যাশ করা থাকলে `php artisan config:cache` আবার।

## ফেজ ২৪.২b — Google Ads ড্রাফট
- মাইগ্রেশন `2026_12_15_100000_create_gads_drafts`; `php artisan roles:sync` (আগের `ads.*` অনুমতিই ব্যবহার হয়, নতুন অনুমতি নেই)।

## ফেজ ২৪.২c — Google Ads তৈরি
- মাইগ্রেশন `2026_12_16_100000_create_gads_launch`; cron আগের মতো (`schedule:run`) — `gads:launch` প্রতি মিনিট।
- ঘণ্টায় সীমা Meta-র সাথে একই `ADS_LAUNCHES_PER_HOUR` (ডিফল্ট ৩)। আসল অ্যাকাউন্টে Developer Token-এর Basic স্তর লাগবে।

## ফেজ ২৪.২d — Google বিরতি
- মাইগ্রেশন `2026_12_17_100000_create_gads_pause`; cron আগের মতো — `gads:pause` প্রতি মিনিট। নতুন .env নেই।

## ক্লায়েন্ট রিপোর্ট (ফেজ ২৪.৩a)
নতুন `.env` নেই। PDF-এর জন্য ইঞ্জিনে Chromium (ফেজ ৯এ-র মতোই); না থাকলে DOCX ব্যবহার করুন। মাইগ্রেশন নেই।

## এজেন্সি মোড (ফেজ ২৪.৩b)
নতুন `.env` নেই। শুধু মাইগ্রেশন `2026_12_18_100000_create_clients` চালান।
