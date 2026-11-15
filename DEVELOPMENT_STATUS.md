# JARVIS ONE — Development Status

**Last updated:** 2026-11-14 · **CAN AI handover:** দেখুন docs/CAN_AI_GAP_AUDIT.md (মাইলস্টোন A ☑, C-র লোকাল অংশ ☑ কোড+টেস্ট; Windows/e2e যাচাই নেই) · **Current phase:** কম্পিউটার এজেন্ট ☑ কোড + জানালা + Tauri শেল (`apps/pc-agent`, `apps/desktop`) · ২৫.২ অবতার ☑ কোড · ২৬ বাকি (মোবাইল step-up + হ্যান্ডঅফ ☑ কোড; ২১ বাকি ☑) · **Next phase:** ২১ ভিজ্যুয়াল ক্যানভা (ব্রাউজার লাগে), তারপর ২৫.২/২৬ এর কোড-যোগ্য অংশ

---

## Phase Overview

| ফেজ | নাম | অবস্থা |
|---|---|---|
| ০ | Planning & Documentation | ☑ সম্পন্ন |
| ১ | Dev Environment & Project Structure | ◐ ইঞ্জিন ও ইনফ্রা যাচাইকৃত; Laravel বেস অ্যাপ (`scripts/bootstrap-web.sh`) আপনার মেশিনে বানানো বাকি |
| ২ | Authentication & Multi-Tenancy | ☑ কোড; DB স্তর যাচাইকৃত; Laravel স্তর আপনার মেশিনে বাকি |
| ৩ | Super Admin Panel Core | ☑ কোড; Pest/Blade আপনার মেশিনে বাকি |
| ৪ | AI Provider & Model Manager | ☑ কোড; আসল প্রোভাইডার কী দিয়ে যাচাই বাকি |
| ৫ | Credits & Usage Engine | ☑ কোড |
| ৬ | Python AI Engine + Chat + Customer Dashboard v1 | ☑ কোড |
| ৭ | Messaging Gateway + Telegram | ☑ কোড; আসল Telegram যাচাই বাকি |
| ৮ | Website Lead Extractor + CRM v1 | ☑ ৮এ+৮বি; শুধু AI সারাংশ বাকি |
| ৯ | Portfolio + Proposal + Email Outreach | ☑ কোড (৯এ–৯সি + WhatsApp বাটন, IMAP, প্রস্তাব PDF সংযুক্তি); আসল Gmail/Outlook/IMAP সার্ভারে যাচাই বাকি |
| ১০ | Payment & Subscription | ☑ কোড; আসল গেটওয়ে sandbox বাকি |
| ১১ | Mobile App v1 | ☑ কোড; আসল ফোন/FCM/EAS বিল্ড বাকি |
| ১২ | Store Connectors + Product Manager | ☑ কোড (১২এ–১২সি); আসল দোকানে যাচাই বাকি |
| ১৩ | Unified Inbox + Support Agent | ☑ কোড (১৩এ–১৩সি + কমেন্ট-রিপ্লাই, যা ২৩.২সি-তে করা); আসল Meta/Telegram যাচাই বাকি |
| ১৪ | Sales Agent + Orders + Delivery | ☑ কোড; আসল কুরিয়ার API যাচাই বাকি |
| ১৫ | Accounting Engine | ☑ কোড; হিসাবরক্ষককে দিয়ে নিয়ম মেলানো বাকি |
| ১৬ | AI Bill Scan + Banking + Auto Entry | ☑ কোড |
| ১৭ | VAT & Tax | ☑ কোড; বাস্তব-পরীক্ষা বাকি |
| ১৮ | Auditor + Payroll + Financial Analyst | ☑ কোড; বাস্তব-পরীক্ষা বাকি |
| ১৯ | Memory + RAG + Vision | ☑ কোড |
| ১৯.২ | Productivity Suite + Communication | ☑ কোড (এ–ই); বাকি (পরে, ঐচ্ছিক): ইভেন্ট বদল/মোছা, টাস্ক↔ইভেন্ট স্বয়ংক্রিয় সিঙ্ক; আসল Google/Microsoft যাচাই |
| ২০ | Voice Assistant | ◐ ওয়েব ভয়েস ☑; ভয়েস থেকে ওয়ার্কফ্লো চালানো ☑ (কোড); বাকি (আসল ডিভাইস/ব্রাউজার লাগে): ওয়েক-ওয়ার্ড, মোবাইল মাইক, রিয়েল-টাইম স্ট্রিমিং |
| ২১ | Agent Engine + Workflow Builder + Coding Agent | ◐ ২১এ ইঞ্জিন ☑; Coding Assistant ওয়েব চ্যাট মোড ☑; বাকি: ক্যানভাস, লুপ/ডিলে/প্যারালাল, মোবাইল অনুমোদন, পাঠানোর টুল, Coding Agent, টেমপ্লেট |
| ২২.১ | Image Studio | ◐ ডিজাইন স্টুডিও ☑; বাকি: সম্পাদনা/ভার্সন, টেমপ্লেট লাইব্রেরি, লোগো, নোটিফিকেশন |
| ২২.২ | Video Studio & Video Editor | ☑ কোড (২২.২a–c); বাকি/পরে: পুরো AI ভিডিও জেনারেশন |
| ২৩ | Social Media Manager | ☑ কোড; আসল Facebook যাচাই বাকি |
| ২৩.২ | AI Social Media Manager & Growth Agent | ☑ কোড (a–d: প্ল্যান, গ্রোথ, কমেন্ট-রিপ্লাই, YouTube) |
| ২৪.১ | Meta Ads Manager | ☑ কোড; আসল Meta যাচাই বাকি |
| ২৪.২ | Google Ads Manager | ☑ কোড (a–d); বাকি (পরে): নেগেটিভ কীওয়ার্ড, Quality Score, PMax/Display/Shopping/YouTube, কীওয়ার্ড প্ল্যানার |
| ২৪.৩ | SEO + Marketing Manager Agent | ☑ কোড (a–d: রিপোর্ট, এজেন্সি মোড, SEO অডিট, Marketing Manager); আসল সার্ভারে যাচাই বাকি |
| ২৫ | Windows Desktop App + Local SQLite | ☐ |
| ২৫.২ | AI Character & Avatar | ◐ সার্ভার-অংশ ☑ (চরিত্র, পার্সোনা প্রম্পট, চ্যাট); ২D অবতার + অভিব্যক্তি + অনুমানভিত্তিক লিপ-সিংক প্রিভিউ ☑ (০.১০১.০); ভয়েস আউটপুট ☑ (০.১০২.০); ৩D/মার্কেটপ্লেস ☐ |
| ২৬ | Computer Control + Browser Agent + System Monitor | ◐ ২৬ক ☑ (পেয়ারিং, allowlist, অনুমোদন, ইমার্জেন্সি স্টপ, অডিট, মনিটর নমুনা — DB+PHP); ২৬খ ☑ (এজেন্ট API + `/app/computer` — কোড); বাকি: ডেস্কটপ/ব্রাউজার এজেন্ট, মোবাইল step-up/হ্যান্ডঅফ |
| ২৭ | White-label + Reseller + Enterprise | ☑ (কোড; ২৭a–c) |
| ২৭.২ | Developer Platform + Plugin Marketplace | ☐ |
| ২৮ | Security Audit + Full Testing | ◐ (অডিট ☑; HTTP-স্তর ও পূর্ণ রান আপনার মেশিনে) |
| ২৮.৫ | সেলফ-হোস্ট প্যাকেজ (নিজের VPS/কম্পিউটার) | ◐ (কোড+স্ট্যাটিক টেস্ট ☑; Docker বিল্ড/ইনস্টল আসল সার্ভারে বাকি; FFmpeg অফলোড ☐) |
| ২৯ | Production Deployment | ☐ |
| ৩০ | Commercial Launch | ☐ |
| ৩১+ | World Monitor ও অন্যান্য (ঐচ্ছিক) | ☐ |

---

## Phase 0 Report

### Objective
সম্পূর্ণ সিস্টেমের আর্কিটেকচার, প্রয়োজনীয়তা আর প্রযুক্তিগত নির্দিষ্টকরণ ঠিক করা, যাতে ফেজ ১ থেকে সরাসরি বাস্তবায়ন শুরু করা যায়।

### Repository State (inspection)
রিপোজিটরি খালি ছিল (কোনো বিদ্যমান কোড নেই)। নতুন git রিপো `main` ব্রাঞ্চে শুরু করা হয়েছে।

### Delivered
- ১২টা ডকুমেন্ট: README, PROJECT_PLAN, ARCHITECTURE, DATABASE_SCHEMA, API_DOCUMENTATION, SECURITY, ENVIRONMENT_SETUP, DEPLOYMENT_GUIDE, TESTING_GUIDE, DEVELOPMENT_STATUS, CHANGELOG, PROJECT_HANDOVER
- ১২টা Mermaid ডায়াগ্রাম (`docs/diagrams/`): system, auth, tenant isolation, AI request, credit lifecycle, provider routing, workflow execution, approval, payment, ER, production topology, phase roadmap
- প্রযুক্তি ও সংস্করণ সিদ্ধান্ত (১ নভেম্বর ২০২৬-এ npm, PyPI ও রিলিজ নোট থেকে যাচাই)
- ১৫টা Architecture Decision Record
- ১৬টা ঝুঁকি ও নির্ভরতা, ফেজভিত্তিক credential তালিকা
- ChatGPT ব্লুপ্রিন্ট পর্যালোচনা থেকে বাদ পড়া ফিচার যোগ (১৯.২, ২১, ২৫, ২৫.২, ২৬, ২৭.২, ৩১+)

### Test Report

```text
Total tests:                  0 (ফেজ ০-এ কোনো কোড নেই)
Passed:                       0
Failed:                       0
Skipped:                      0
Not implemented:              সব application কোড (ফেজ ১ থেকে)
Manual tests required:        ডকুমেন্ট রিভিউ (owner)
External credentials pending: নেই (ফেজ ০-এ লাগে না)
Security issues:              নেই
Known limitations:            দেখুন নিচে
```

ডকুমেন্ট যাচাই: সব Mermaid ডায়াগ্রাম পার্সার দিয়ে চেক করা হয়েছে (ফলাফল CHANGELOG-এ), আর ZIP-এ secret স্ক্যান চালানো হয়েছে।

### Known Limitations
- সংস্করণগুলো ১ নভেম্বর ২০২৬-এর; প্রতিটা ফেজের শুরুতে আবার যাচাই হবে।
- Packagist এই পরিবেশ থেকে সরাসরি পৌঁছানো যায়নি; Laravel 13 ও Livewire 4-এর তথ্য অফিসিয়াল রিলিজ নোট থেকে নেওয়া, ফেজ ১-এ `composer` দিয়ে সঠিক patch সংস্করণ নিশ্চিত হবে।
- Bangladesh VAT/Tax নিয়ম ফেজ ১৭-এ NBR-এর তখনকার নিয়ম দেখে যাচাই হবে।
- প্রোডাক্টের নামের ট্রেডমার্ক যাচাই বাকি (R14)।

### Open Decisions (owner)
1. ফেজ ০ অনুমোদন।
2. প্রোডাক্টের নাম: "JARVIS ONE" রাখা হবে কিনা, আইনি যাচাইয়ের পর।
3. World Monitor (লঞ্চের পরে) আর Face Authentication (ঐচ্ছিক) রাখা হবে কিনা; ডিফল্ট হিসেবে দুটোই ঐচ্ছিক রাখা হয়েছে।
4. GitHub রিপো: থাকলে নাম দিন, প্রতিটা ফেজ সেখানে push হবে; না থাকলে ZIP-এ ডেলিভারি চলবে।

## Phase 1 Report (আংশিক)

**বানানো হয়েছে ও যাচাই হয়েছে**
- `apps/ai-engine`: FastAPI, HMAC + 60s JWT + nonce (replay সুরক্ষা), `/healthz` (খোলা), `/internal/v1/health` (signed)। ডক্স/OpenAPI বন্ধ।
- `apps/web-overlay`: Laravel-পাশের `RequestSigner`, `AiEngineClient`, `ai:health` কমান্ড, `config/brand.php`, `config/ai_engine.php`, `DevSeeder`, Pest টেস্ট, Larastan/Pint কনফিগ।
- `infra/docker-compose.yml` (postgres+pgvector, redis, ai-engine; পোর্ট শুধু 127.0.0.1), Dockerfile, `Makefile`, `.github/workflows/ci.yml`, `.env.example`, `scripts/bootstrap-web.sh`।

**টেস্ট রিপোর্ট**
- Python: ২৫টা পাস, ০ skip (pytest)। ruff: পাস। mypy strict: পাস।
- PHP ↔ Python সামঞ্জস্য: আসল PHP signer-এর হেডার Python সার্ভার গ্রহণ করে (GET, query ক্রম বদল, বাংলা/UTF-8 body)।
- আসল uvicorn সার্ভারে HTTP কল: signed → ২০০; unsigned → ৪০১; একই হেডার আবার → ৪০১ (replay বন্ধ)।
- `docker compose config`: পাস (প্রয়োজনীয় secret না থাকলে ব্যর্থ হয়, যা ইচ্ছাকৃত)। YAML পার্স: পাস।

**যাচাই করা যায়নি (কারণসহ)**
- Laravel অ্যাপ: এই পরিবেশে `repo.packagist.org` অ্যাক্সেস-নীতিতে বন্ধ (৪০৩) এবং PHP ৮.৩.৬ আছে। তাই `composer create-project` চালানো যায়নি; Pest টেস্ট (`tests/Unit/RequestSignerTest.php`), Larastan, Pint চালানো হয়নি। `RequestSigner.php`-তে `php -l` পাস ও Python-এর সাথে ক্রস-টেস্ট পাস, কিন্তু Pest ফাইল নিজে এখনো অচালিত।
- `docker compose up` ও image build: এখানে Docker daemon নেই।
- `scripts/bootstrap-web.sh`-এর `install:broadcasting` ও `horizon:install` ধাপ অচালিত; আপনার মেশিনে প্রথম রানে সমন্বয় লাগতে পারে।

**Phase 1 সম্পূর্ণ হওয়ার শর্ত:** আপনার মেশিনে `make web-bootstrap && make web-test` সবুজ এবং `make up` + `docker compose exec web php artisan ai:health` সফল।

## Phase 2 Report (আংশিক: ২এ + ২বি)

**২এ — ডাটাবেস স্তর (আসল PostgreSQL 16-এ যাচাইকৃত)**
- `users`, `tenants`, `tenant_users`, `tenant_settings`, `invitations`, `audit_logs`, `login_logs`, `sessions`, `password_reset_tokens`; RLS + FORCE; admin bypass = GUC + role সদস্যপদ; append-only trigger; platform-admin escalation guard; invitation SECURITY DEFINER ফাংশন।
- **টেস্ট: ৫৩/৫৩ পাস** (`tests/db`)। Mutation check: ৬টা ইচ্ছাকৃত ভাঙা সংস্করণ (format check বাদ, FORCE বাদ, role-membership বাদ, bypass leak, email-verify বাদ, WITH CHECK বাদ) — সবকটায় টেস্ট ফেল করেছে; আসলটায় পাস।
- `scripts/db-provision.sh` টেস্টেড (দুইবার চালানো, পাসওয়ার্ডে quote/`$` সহ)।
- সীমা: লোকাল সার্ভার PostgreSQL **16**; প্রোডাকশন লক্ষ্য ১৮। ব্যবহৃত SQL ফিচার দুটোতেই আছে; CI-তে `postgres:18` সার্ভিসে চলবে।

**২বি — Laravel tenancy কোড (লেখা, `php -l` পাস, কিন্তু চালানো যায়নি)**
- `TenantContext`, `TenantScope`, `BelongsToTenant`, `ResolveTenant`, `TenancyServiceProvider` (কিউ propagation), `tenancy:check`, `User` মডেল (ULID, Fortify 2FA, Sanctum), মাইগ্রেশন, Pest ইউনিট টেস্ট।
- অচালিত কারণ: Laravel ইনস্টল করা যায়নি (Packagist বন্ধ)। `Queue::createPayloadUsing/before/after` API-র আচরণ ও Pest টেস্ট আপনার মেশিনে প্রথম রানে নিশ্চিত হবে।

**২সি (বাকি, ফেজ ১ আপনার মেশিনে সবুজ হওয়ার পর):** Fortify লগইন/রেজিস্টার/ভেরিফাই/রিসেট/2FA Livewire UI, workspace তৈরি ও পরিবর্তন, invite পাঠানো/গ্রহণ UI, spatie roles (ULID `model_id` প্যাচসহ), Sanctum token (ULID morph প্যাচ), device/session ম্যানেজমেন্ট, rate limit, feature টেস্ট।

### ২সি-১ (লজিক স্তর)
- `CreateNewUser` (Fortify): validation (পাসওয়ার্ড ≥১০, মিশ্র অক্ষর+সংখ্যা, soft-delete-সচেতন unique) + স্বয়ংক্রিয় personal workspace, এক transaction-এ।
- `CreateWorkspace` (personal/organization), `ManageInvitations` (create: পুরনো pending বদলায়, শুধু SHA-256 সংরক্ষণ, ৭ দিন; preview; accept → `InvitationException` কোডসহ)।
- যাচাই: একই SQL আকার restricted app role-এ চালানো `tests/db/test_app_contract.py` — **DB স্যুট এখন ৫৫/৫৫ পাস**। Pest `tests/Feature/TenancyFlowTest.php` লেখা কিন্তু অচালিত।
- বাকি ২সি: Fortify কনফিগ + Livewire UI (লগইন/রেজিস্টার/ভেরিফাই/রিসেট/2FA), workspace সুইচার, invite UI, spatie roles (ULID প্যাচ), Sanctum (ULID প্যাচ), ডিভাইস/সেশন, rate limit।

### ফেজ ২ — কোড সম্পূর্ণ (২সি শেষ)
**যা আছে:** Fortify লগইন/রেজিস্টার/ইমেইল ভেরিফাই/পাসওয়ার্ড রিসেট/2FA (QR + কনফার্ম + রিকভারি কোড) Blade ভিউ; workspace তৈরি ও সুইচ; সদস্য তালিকা/আমন্ত্রণ (ইমেইল)/গ্রহণ/অপসারণ; spatie roles (teams মোড, tenant প্রতি Owner/Admin/Manager/Member/Auditor + permission ম্যাট্রিক্স); Sanctum API (`/api/v1/auth/*`, 2FA সহ); সেশন ও টোকেন ম্যানেজমেন্ট; rate limit (login, 2FA, API, invite, global); লগইন লগ; suspended ইউজার ব্লক (লগইন ও চলমান সেশন)।
**সিদ্ধান্ত:** UI Blade+Fortify ফর্ম (Livewire নয়) — অথ-পেজে Livewire-এর লাভ নেই, ঝুঁকি বেশি; Livewire আসবে ইন্টারঅ্যাকটিভ মডিউলে (ফেজ ৩+)। `devices` টেবিল ফেজ ১১ (মোবাইল)-এ; এখন ডিভাইস = নামযুক্ত Sanctum টোকেন।
**যাচাই (এখানে):** DB স্যুট ৫৬/৫৬; সব PHP ফাইলে `php -l`; Blade কাঠামো ব্যালান্স; ক্লাস/route-মেথড/route-নাম ক্রস-চেক; bootstrap প্যাচ কমান্ড নকল ফাইলে (সফল ও ব্যর্থ উভয় পথ) পরীক্ষিত।
**যাচাই হয়নি (এখানে সম্ভব নয়):** Laravel চালানো, ২৯টা Pest টেস্ট (`AuthFlowTest` ১৬, `TenantContextTest` ৫, `TenancyFlowTest` ৪, `RequestSignerTest` ৪), Larastan, Pint, প্যাকেজের আসল migration-এ sed প্যাচ (শেপ না মিললে স্ক্রিপ্ট নিজেই থামে), `Queue::createPayloadUsing` আচরণ, Blade রেন্ডার।
**ফেজ ২ সম্পূর্ণ হওয়ার শর্ত:** আপনার মেশিনে `make web-test` সবুজ + `tenancy:check` পাস + ব্রাউজারে রেজিস্টার→ভেরিফাই→লগইন→2FA→invite→accept হাতে দেখা।


## Phase 3 Report — Super Admin Panel Core (কোড সম্পূর্ণ)

### Delivered
- উপরে CHANGELOG 0.4.0-এর তালিকা অনুযায়ী ডাটাবেস স্তর, admin গেট, ১১টা কন্ট্রোলার, ১৫+ Blade ভিউ, `admin:promote`, ১২টা Pest টেস্ট।

### এখানে সত্যিই যাচাই হয়েছে
- PostgreSQL (লোকাল 16): `tests/db` **১০০ টেস্ট পাস** (RLS FORCE সব টেবিলে, `effective_feature` সব শাখা, append-only, migration up/down/up)।
- Python AI engine: ২৫ টেস্ট পাস।
- সব PHP ফাইলে `php -l` পরিষ্কার; অ্যাডমিন রাউট → কন্ট্রোলার মেথড, ভিউতে ব্যবহৃত `admin.*` রাউট নাম, ভিউ-ডিরেক্টিভ ব্যালান্স, ব্যবহৃত পারমিশন নাম — সব মিলেছে।

### আপনাকে চালিয়ে দেখতে হবে (এখানে সম্ভব হয়নি)
1. `make db-provision` (এখন `DB_ADMIN_PASSWORD` লাগে) → `php artisan migrate --seed` (**`jarvis_owner` দিয়ে**)।
2. রেজিস্টার করে ইমেইল ভেরিফাই + 2FA চালু করুন → `php artisan admin:promote you@example.com`।
3. `vendor/bin/pest`, `vendor/bin/phpstan analyse`, `vendor/bin/pint --test`।
4. ব্রাউজারে `/admin`-এর প্রতিটি পেজ ঘুরে দেখুন; নন-অ্যাডমিন ইউজারে `/admin` → 403।
- `AdminTest` পার্ট ২ ডেটা কমিট করে: শুধু টেস্ট DB-তে (`DB_ADMIN_PASSWORD` সহ)।

### সিদ্ধান্ত / বিচ্যুতি
- প্ল্যানের মূল্য আপনার সিদ্ধান্ত — সিডে নেই; admin → প্ল্যান → মূল্য থেকে বসাবেন।
- `devices` টেবিল ফেজ ১১-এ (device = নামযুক্ত Sanctum টোকেন)।
- Admin UI আপাতত Blade; ইন্টারঅ্যাক্টিভ মডিউলে Livewire আসবে।


## Phase 4a Report — AI Provider কোর
### Delivered
Python adapters + signed endpoints, DB স্কিমা, credential vault, gateway (CHANGELOG 0.5.0 দেখুন)।
### এখানে সত্যিই যাচাই হয়েছে
- Python engine **৭১ টেস্ট** (mock provider/`httpx.MockTransport`): প্রতিটি adapter-এর রিকোয়েস্ট/রেসপন্স/স্ট্রিম, এরর ম্যাপিং, key redaction, লগে key নেই, 422-এ key নেই, SSRF, redirect। ruff + mypy strict পরিষ্কার।
- DB **১১৭ টেস্ট** (PG16): privilege, vault, cycle, pricing append-only, available_models, RLS, migration up/down/up।
### আপনাকে চালাতে হবে
1. `.env`-এ `AI_VAULT_KEY_1` বসান (`.env.example`-এর কমান্ড), `php artisan migrate` (jarvis_owner)।
2. `vendor/bin/pest tests/Unit` (CredentialVaultTest, ProviderGatewayTest), Larastan, Pint।
3. **আসল connection test**: আপনার একটা provider key লাগবে — 4b-তে অ্যাডমিন UI থেকে "Test" বোতামে চালানো হবে।
### 4b-তে বাকি
অ্যাডমিন UI (provider/credential/model/pricing/access/routing/health), কাস্টমার "available models" ও পছন্দ, health scheduled job, রাউটিং সিলেকশন লজিক।


## Phase 4b Report — Provider ম্যানেজার UI (Phase 4 কোড সম্পূর্ণ)
### এখানে সত্যিই যাচাই হয়েছে
- Python ৭১ টেস্ট + ruff/mypy; DB ১১৮ টেস্ট; `ModelRouter`/`HealthPolicy` সত্যিকারের PHP-তে ২৯ চেক; smoke স্ক্রিপ্ট লোকাল ফেক সার্ভারের বিরুদ্ধে (ভালো/খারাপ key, private-URL গার্ড)।
- সব PHP ফাইল `php -l` পরিষ্কার; রাউট↔মেথড↔ভিউ নাম, Blade ব্যালান্স মিলেছে।
### Acceptance অবস্থা
| শর্ত | অবস্থা |
|---|---|
| mock provider দিয়ে adapter টেস্ট | ✅ পাস |
| credential response/লগে নেই (টেস্ট) | ✅ Python + DB + Pest(অচালিত) |
| কমপক্ষে একটা আসল provider-এ connection test | ⏳ **আপনার key লাগবে** — নিচের কমান্ড |
### আপনাকে চালাতে হবে
```bash
cd apps/ai-engine && pip install -e '.[dev]'
export PROVIDER_API_KEY=...        # আপনার আসল key
python -m scripts.provider_smoke --adapter openai --model <আপনার-মডেল-কী>   # বা anthropic / gemini / groq / openrouter
```
আউটপুটে PASS/FAIL, ms ও টোকেন সংখ্যা আসবে (key কখনো না)। এরপর অ্যাডমিন UI → AI Provider → key দিয়ে **Test**।
এছাড়া: `migrate`, `vendor/bin/pest`, Larastan, Pint। বিদ্যমান ইনস্টলে Provider Manager রোলে `providers.manage` নিজে টিক দিন (রোল পেজ) — Super Admin স্বয়ংক্রিয়ভাবে পায়।
### সিদ্ধান্ত
- balanced = ০.৬×মান + ০.৪×খরচ-দক্ষতা (হিউরিস্টিক; পরে টিউন করা যাবে)।
- routing rule-এর `conditions` কলাম এখনো ব্যবহৃত নয় (Phase 6+)।
- কোনো provider/মডেল/মূল্য সিড করা নেই।

## Phase 11 Report — মোবাইল অ্যাপ v1
**যাচাই হয়েছে (এখানে):** DB ৩৫০ (step-up ছাড়া অনুমোদন নয়; লক; স্টপ থাকলে অনুমোদন/সেন্ড নয়; অন্য ওয়ার্কস্পেসের আইটেম/ডিভাইস ছোঁয়া যায় না; PIN কলাম অ্যাপ role লিখতে পারে না; স্টপ-লগ বদলানো যায় না; লগআউটে ফোন অবসর), মোবাইল ২৬ + route contract ৩, pure PHP ২১, `tsc --strict` পরিষ্কার, **`expo export --platform android` সফল (সব স্ক্রিন Hermes বান্ডেলে কম্পাইল)**। মিউটেশন (step-up/স্টপ/লক/টেন্যান্ট ফিল্টার/ডেড-টোকেন/ডুপ্লিকেট-নোট বাদ দিলে) টেস্ট ধরেছে।
**যাচাই হয়নি:** আসল ফোনে চালানো (UI চোখে দেখা হয়নি), Expo push/FCM (credentials নেই), EAS বিল্ড (আপনার Expo অ্যাকাউন্ট লাগে), আঙুলের ছাপ-সংরক্ষিত PIN, `migrate`, Pest (`MobileApiTest`), scheduler, iOS।
**বাকি/সীমা:** চ্যাট স্ট্রিম করে না (একবারে উত্তর); এজেন্ট/ওয়ার্কফ্লো মনিটর Phase 21-এ (এখন ক্যাম্পেইন ও লিড-জব দেখায়); প্ল্যাটফর্ম-অ্যাডমিন হেলথ নয়, ওয়ার্কস্পেস হেলথ; অনুমোদন শুধু আউটরিচ মেইল (নতুন ধরন `mobile_approvals`-এ UNION শাখা যোগ করলেই আসবে); অফলাইন মোড নেই; অ্যাপ লক-স্ক্রিন নেই (শুধু অনুমোদনে PIN); ডেস্কটপ/Tauri বাদ।

## Phase 10 Report — পেমেন্ট ও সাবস্ক্রিপশন
**যাচাই হয়েছে (এখানে):** DB ৩২১ — অর্ডারের অঙ্ক বদলানো যায় না, ভুল অঙ্ক/মুদ্রা/গেটওয়ে প্রয়োগ হয় না, duplicate IPN কিছু বদলায় না, এক txn দুই অর্ডারে নয়, কুপন (মেয়াদ/সীমা/একবার), VAT ও কুপনের half-up রাউন্ডিং, proration, গ্রেস → মেয়াদ শেষ → ফ্রি, বাতিল/পুনরায় চালু, ডাউনগ্রেড, ইনভয়েস গ্যাপ-হীন ও অপরিবর্তনীয়, রিফান্ড/ক্রেডিট নোট, এক ওয়ার্কস্পেস আরেকের অর্ডার ছুঁতে পারে না; PHP-র প্রতিটি SQL আসল PG-তে; অ্যাডাপ্টারের অনুরোধ/উত্তর নকল HTTP দিয়ে (Nagad-এ আসল RSA এনক্রিপ্ট/সাইন/ডিক্রিপ্ট); Stripe webhook signature (সঠিক/ভুল/পুরনো/বদলানো বডি)।
**যাচাই হয়নি:** আসল গেটওয়ে sandbox (কী নেই), `migrate`, Pest, Blade রেন্ডার, scheduler, গেটওয়ের IPN আসলে আপনার সার্ভারে পৌঁছায় কিনা।
**বাকি:** স্বয়ংক্রিয় নবায়ন, রিমাইন্ডার ইমেইল, ট্রায়াল UI, API-রিফান্ড, সার্ভার-সাইড ইনভয়েস PDF।

## Phase 9c Report — Gmail / Microsoft Graph + reply/bounce
**যাচাই হয়েছে (এখানে, নকল HTTP দিয়ে):** consent URL (PKCE, scope), code→token বিনিময় ও ইমেইল যাচাই, invalid_grant বনাম আউটেজ, rotating refresh token সংরক্ষণ, Gmail/Graph পাঠানোর অনুরোধের আকার ও ত্রুটি-ম্যাপিং, MIME হেডার-ইনজেকশন প্রতিরোধ, reply/bounce/auto শ্রেণিবিন্যাস, একবার-প্রক্রিয়া, পরিত্যক্ত notice অনুমান না করা; DB ২৬৮; PHP-র নতুন SQL আসল PG-তে।
**যাচাই হয়নি:** আসল Google/Microsoft OAuth ও API (প্রতিক্রিয়ার আকার ডকুমেন্টেশন-ভিত্তিক ধারণা), Graph `sendMail` MIME-এ হেডার বাস্তবে বজায় থাকে কিনা, Gmail `Message-ID` বদলায় কিনা (এজন্য থ্রেড-আইডিও রাখা হয়), বাস্তব bounce notice-এর ভাষা (ঠিকানা বের করার regex), `migrate`, Pest, Blade।
**বাকি:** SMTP-র জন্য IMAP, WhatsApp বোতাম, প্রস্তাব সংযুক্তি।

## Phase 9b Report — ইমেইল আউটরিচ কোর (SMTP)
**যাচাই হয়েছে (এখানে):** DB ২৫২ (অনুমোদন ছাড়া পাঠানো নিষেধ, নিষিদ্ধ ঠিকানা নিষেধ, আনসাবস্ক্রাইব, bounce→নিষিদ্ধ, cross-tenant, RLS), PHP-র প্রতিটি SQL আসল PG-তে, বিশুদ্ধ PHP লজিক ১৬ চেক (দৈনিক সীমা/warm-up, ভুল লগইনে থামা, ফলো-আপ, হোস্ট গার্ড, DNS পার্সার)।
**যাচাই হয়নি:** আসল SMTP সার্ভারে পাঠানো (Symfony Mailer, STARTTLS আচরণ), `migrate`, scheduler/cron, Pest, Blade রেন্ডার, বাস্তব DNS লুকআপ, আনসাবস্ক্রাইব পাতা ব্রাউজারে/মেইল ক্লায়েন্টের One-Click।
**বাকি:** ৯সি (Gmail/Graph, স্বয়ংক্রিয় reply/bounce), WhatsApp বোতাম, প্রস্তাব সংযুক্তি।

## Phase 9a Report — Portfolio + Brand Kit + Proposal (কোড সম্পূর্ণ; ৯বি আউটরিচ বাকি)
**যাচাই হয়েছে (এখানে):** Engine ১৪৭ (আসল Chromium-এ বাংলা PDF + DOCX), DB ২৩৩, PHP-র প্রতিটি SQL আসল PG-তে, বিশুদ্ধ PHP লজিক ১৫ চেক + ১২ মিউটেশন।
**যাচাই হয়নি:** Docker image বিল্ড (Chromium সহ), `migrate`, Pest, Blade রেন্ডার, **DOCX Word/LibreOffice-এ খুলে বাংলা দেখা** (XML-এ ফন্ট ঠিক আছে, কিন্তু Word-এ চোখে দেখিনি), আসল ব্রাউজারে ফর্ম/ডাউনলোড।
**বাকি:** ইমেইল আউটরিচ (৯বি), Gmail/Graph (৯সি), থাম্বনেইল, টেমপ্লেট এডিটর UI।

## Phase 8b Report — CRM v1 (কোড সম্পূর্ণ)
**যাচাই হয়েছে (এখানে):** DB ২১৯ টেস্ট (RLS, append-only, cross-tenant, `lead_move_stage`, PHP-র প্রতিটি SQL আসল PG-তে), বিশুদ্ধ PHP লজিক ১২ চেক + ১০ মিউটেশন, `php -l`, রুট/ভিউ-রেফারেন্স চেক।
**যাচাই হয়নি:** `migrate`, Pest (`LeadCrmTest`), Blade রেন্ডার (বোর্ড/শো/টাস্ক/আমদানি), ব্রাউজারে ফর্ম।
**বাকি:** AI সারাংশ, drag-and-drop, হাতে কোম্পানি/কন্টাক্ট যোগ।

## Phase 8a Report — Website Lead Extractor (কোড সম্পূর্ণ; ৮বি CRM বাকি)
**যাচাই হয়েছে (এখানে):** Engine ১৩১ টেস্ট + ruff + mypy strict (ফিক্সচার সাইটে extraction, SSRF/redirect/DNS-rebinding, robots, আসল Chromium), DB ২০৭ (RLS, ডুপ্লিকেট-রোধ, cross-tenant), PHP-র SQL আসল PG-তে, বিশুদ্ধ PHP লজিক ১৩ চেক + মিউটেশন, `php -l`।
**যাচাই হয়নি:** আসল ইন্টারনেটের সাইটে (এই পরিবেশের নেটওয়ার্ক সীমিত), আসল DNS MX, Docker image-এ নতুন নির্ভরতা বিল্ড, `migrate`, Pest, queue worker, Blade রেন্ডার।
**অসম্পূর্ণ:** CRM বোর্ড/নোট/টাস্ক/ট্যাগ/CSV (৮বি); AI সারাংশ।

## Phase 7 Report — Messaging Gateway + Telegram (কোড সম্পূর্ণ)
**যাচাই হয়েছে (এখানে):** DB ১৮৯ টেস্ট (webhook ফাংশন, RLS, token অপঠনযোগ্য, dedupe, link নিয়ম, removed সদস্য/suspended tenant আর resolve হয় না), PHP-র SQL আসল PG-তে, বিশুদ্ধ PHP ৬৯ চেক + মিউটেশন, `php -l`, route cross-check।
**যাচাই হয়নি:** আসল Telegram বটে end-to-end (BotFather টোকেন + HTTPS ডোমেইন লাগে), `php artisan migrate`, Pest, queue worker-এ job চলা, Blade রেন্ডার।
**অসম্পূর্ণ (ইচ্ছাকৃত):** ছবি/ভয়েস বিশ্লেষণ, approval বাটনের আসল ইঞ্জিন, স্ট্রিমিং ইডিট।

## Phase 6 Report — Chat + Dashboard v1 + Deploy Kit (কোড সম্পূর্ণ)
**যাচাই হয়েছে (এখানে):** DB ১৭২ টেস্ট (RLS, ট্রিগার, immutability, stale finalize), Python ৭১ + ruff/mypy, বিশুদ্ধ PHP ৪৩ চেক + মিউটেশন, `php -l` সব ফাইল, route↔controller↔view ক্রস-চেক, `bash -n`/shellcheck।
**যাচাই হয়নি (আপনাকে চালাতে হবে):** `php artisan migrate`, Pest (`ChatTest` সহ), Blade রেন্ডার, আসল provider স্ট্রিম, `install-vps.sh`, nginx, certbot, Docker, cPanel।
**বাকি:** আসল API key দিয়ে provider টেস্ট (Phase 4 থেকে)।

## Phase 5 Report — Credits & Usage Engine
### এখানে সত্যিই যাচাই হয়েছে
- DB ১৬৪ টেস্ট আসল PostgreSQL 16-এ: ১০০টি একযোগে reservation-এ ব্যালেন্স ঠিক; ছোট ওয়ালেটে ১০০ অনুরোধে ঠিক ৫০টি সফল; ১০০ reserve+settle চক্র; একই key-তে ৪০ ডুপ্লিকেট = একবার কার্যকর; settle/release রেস; ৪০০-ধাপ র‍্যান্ডম প্রপার্টি টেস্ট (প্রতি ধাপে ইনভ্যারিয়েন্ট); append-only (মালিকেও)।
- ৭টি মিউটেশন (lock বাদ, `current_user` গার্ড, ট্রিগার বাদ, গ্রান্ট রাখা, expired lot ব্যবহারযোগ্য, overrun অন্যের রিজার্ভেশন খায়, idempotency উপেক্ষা) — সবকটা টেস্টে ধরা পড়ে।
- PHP `CreditService`/`AdminCredits`/কাস্টমার কন্ট্রোলারের **আসল SQL স্ট্রিং** PHP সোর্স থেকে বের করে আসল PostgreSQL-এ চালানো হয়েছে (ভুল cast/কলাম ধরা পড়বে)।
- `CreditMath`/`Bars` সত্যিকারের PHP-তে চালানো; সব PHP `php -l` পরিষ্কার; রাউট↔মেথড↔ভিউ ক্রস-চেক।
### Acceptance অবস্থা
| শর্ত | অবস্থা |
|---|---|
| ১০০ একযোগে অনুরোধে সঠিক ব্যালেন্স | ✅ DB টেস্ট |
| duplicate idempotency | ✅ DB টেস্ট |
| append-only ট্রিগার | ✅ DB টেস্ট |
| কাস্টমার চার্ট/ইতিহাস, অ্যাডমিন সমন্বয়/প্যাকেজ | ⏳ কোড লেখা; **ব্রাউজারে ও Pest-এ আপনাকে চালাতে হবে** |
### আপনাকে চালাতে হবে
```bash
php artisan migrate && php artisan db:seed --class=PlatformSeeder   # নতুন credits.manage পারমিশন
vendor/bin/pest && vendor/bin/phpstan analyse && vendor/bin/pint --test
make db-test            # PostgreSQL max_connections >= 150
php artisan credits:reconcile && php artisan schedule:list
```
ব্রাউজারে: রেজিস্টার → `/app/credits` (১০০ ক্রেডিট দেখা), অ্যাডমিন → workspace → সমন্বয় (+/−), `/admin/credits`।
### সিদ্ধান্ত
- স্বাগত ক্রেডিট ১০০ (env-এ বদলানো যায়)। প্যাকেজ hidden শুরু; কেনার ফ্লো পেমেন্ট ফেজে।
- এজেন্ট চার্টে এখন `agent_id` দেখায় (এজেন্ট টেবিল পরের ফেজে)।
- AI চ্যাট এখনো `CreditService` ডাকে না — ইন্টিগ্রেশন Phase 6 (chat) এ: reserve → provider → settle/release।

## Phase 12a রিপোর্ট — দেশি পণ্য ক্যাটালগ
**যাচাই হয়েছে (এখানে):** DB ৩৭৫ (আসল PG16; ওয়ার্কফ্লো, edit→draft, স্টক লেজার, আইসোলেশন, জরুরি স্টপ, মোবাইল approval, PgCatalogRepo-র প্রতিটি SQL আসল PG-তে), engine ১৪৭, pure PHP (CSV ৭, সার্ভিস ৯, AI লেখক ৫, pg-array ১) + আগের সব, মোবাইল ২৬, route cross-check, ৪টি SQL mutation ধরা পড়েছে।
**অযাচাইকৃত:** `php artisan migrate`, Pest (`CatalogTest`), Blade স্ক্রিন ব্রাউজারে, আসল AI মডেলে বিবরণ, ফোনে পণ্য approval।
**জ্ঞাত সীমা:** maker-checker নেই (যিনি পাঠান তিনিও অনুমোদন করতে পারেন; দুটোই লগ হয়); প্ল্যানের পণ্য-সংখ্যা সীমা বলবৎ নয়; ছবি শুধু লিংক (আপলোড/AI ছবি Phase 22); বহু-কারেন্সি শুধু সংরক্ষণ, রূপান্তর নেই; অর্ডার ও স্টক-বিয়োগ Phase 14।

## Phase 24.3d রিপোর্ট — Marketing Manager Agent (ফেজ ২৪.৩ সম্পূর্ণ)
- নতুন ইঞ্জিন বা ডাটাবেস নয় — বিদ্যমান ওয়ার্কফ্লো ইঞ্জিন (ফেজ ২১) ব্যবহার। `app/Marketing/*` (MarketingAccess/RoleMarketingAccess, MarketingSnapshot), `app/Workflow/Tools/{MarketingSnapshotTool,SeoLatestTool}.php`, `Templates.php`-এ ৫টি টেমপ্লেট, `WorkflowServiceProvider`-এ টুল নিবন্ধন।
- **যাচাই হয়েছে (এখানে):** `MarketingTest` ১৪ (মুদ্রা না-মেশানো, শূন্য-ভিত্তি, বাসি-তথ্য সীমা ৪৭/৪৯ ঘণ্টা, শীর্ষ-৩, অনুমতি অস্বীকার, টেমপ্লেট বৈধতা ও প্রম্পট-নিয়ম, লেখার টুল শুধু অনুমোদনের পরে); ১১টি মিউটেশন সবই ধরা পড়েছে; টেস্টে প্রম্পটের ৩টি ফাঁক ধরা পড়ায় ঠিক করা হয়েছে; সব pure-PHP টেস্ট সবুজ।
- **অযাচাইকৃত:** আসল Laravel-এ ওয়ার্কার চালিয়ে শিডিউল/নোটিফিকেশন, আসল AI-র উত্তরের মান।
- **সীমা:** AI শুধু পরামর্শ লেখে; সংখ্যা কপি করা ডেটা থেকে (সিঙ্ক বাসি হলে ⚠ দেখায়)।
- **আপনাকে চালাতে হবে:** কিছু না (মাইগ্রেশন নেই) — শুধু `/app/workflows`-এ "মার্কেটিং" ক্যাটাগরির টেমপ্লেট থেকে তৈরি করুন।

## Phase 24.3c রিপোর্ট — SEO অডিট
- ইঞ্জিন: `app/seo/{rules,page,audit}.py`, `routes_seo.py`, `SafeFetcher.get_any`। Laravel: `app/Seo/*` (SeoEngine/HttpSeoEngine, SeoRepo/PgSeoRepo, SeoService, SeoText), `SeoController`, `SeoServiceProvider`, views `seo/index`+`show`, রুট `seo.*`, নেভ "SEO", মাইগ্রেশন `phase243c`।
- **যাচাই হয়েছে (এখানে):** ইঞ্জিন ২০ (সীমার প্রতিটি মান, কীওয়ার্ড শুধু হোমপেজে, ডুপ্লিকেট, ভাঙা পেজ, robots ব্লক, পেজ-সীমা, SSRF/প্রাইভেট ঠিকানা, স্বাক্ষরহীন অনুরোধ); ডাটাবেস ৯ (আসল PG16: ৩০/দিন সীমা ও ২৪-ঘণ্টা সরে যাওয়া, ৩০০ রাখা, ৩০০ KB সীমার ঠিক প্রান্ত, ক্লায়েন্ট একই ওয়ার্কস্পেসের সক্রিয় হওয়া, গোপনীয়তা, সরাসরি লেখা বন্ধ, মোছা, down→up রাউন্ডট্রিপ); PHP ইউনিট ১৪; Blade ডিরেক্টিভ মিল ও প্রতিটি `{{ }}` PHP-সিনট্যাক্স পরীক্ষা। ইঞ্জিন ও SQL ও PHP মিলিয়ে ~৩০ মিউটেশন, অসমতুল্য সব ধরা পড়েছে।
- **অযাচাইকৃত:** আসল Laravel অ্যাপে `migrate`, Blade স্ক্রিন ব্রাউজারে (Packagist না পাওয়ায় Blade কম্পাইল করা যায়নি), Pest ফিচার টেস্ট (লেখা হয়নি), **আসল ওয়েবসাইটে** (বিভিন্ন CMS/JS-ভারী সাইট; JS-রেন্ডার করা হয় না — শুধু সার্ভারের HTML দেখে)।
- **সীমা:** শুধু পেজের নিজস্ব HTML (JS দিয়ে বানানো লেখা দেখা যায় না); ব্যাকলিংক/র‍্যাঙ্ক/গতি (Core Web Vitals)/কীওয়ার্ড ভলিউম নেই; টার্গেট শব্দ শুধু হোমপেজে; সর্বোচ্চ ৮ পেজ; ক্লায়েন্ট-রিপোর্ট (২৪.৩a) এ SEO স্কোর এখনো যুক্ত নয়; AI-র লেখা টাইটেল/ডেসক্রিপশন পরামর্শ নেই (নিয়মভিত্তিক পরামর্শ আছে)।
- **আপনাকে চালাতে হবে:** `php artisan migrate`; ইঞ্জিন রিস্টার্ট (নতুন রুট)। তারপর TESTING_GUIDE-এর ২৪.৩c ধাপ।

## Phase 24.3b রিপোর্ট — এজেন্সি মোড (ক্লায়েন্ট)
- `app/Clients/*` (ClientForm, ClientRepo/PgClientRepo), `ClientController`, `clients/index` + `_fields`, রুট `clients.*`; `ReportRepo/Service/Controller` ক্লায়েন্ট-সচেতন। অনুমতি: দেখা `proposals.view`, বদলানো `proposals.manage`।
- যাচাই: ডাটাবেস টেস্ট (ক্লায়েন্ট ফাংশন, গোপনীয়তা, সরাসরি লেখা বন্ধ, রিপোর্টের SQL ক্লায়েন্ট-ফিল্টারসহ), ইউনিট (লোগো/রং/বার্তা/সার্ভিসে ক্লায়েন্ট পাস)।
- সীমা: ক্লায়েন্টের ফিল্টার এখন শুধু রিপোর্টে — বিজ্ঞাপন/সোশ্যাল/পোস্ট তালিকায় নেই; ক্লায়েন্টের পোস্ট বলতে "ক্লায়েন্টের পেজ দিয়ে প্রকাশিত"; পেজ ছাড়া/হাতে-করা পোস্ট ক্লায়েন্ট রিপোর্টে আসে না; ক্লায়েন্ট-ভিত্তিক আলাদা সদস্য-অনুমতি নেই (ওয়ার্কস্পেসের সবাই সব ক্লায়েন্ট দেখে); ব্রাউজারে UI দেখা হয়নি।

## Phase 24.3a রিপোর্ট — ক্লায়েন্ট মার্কেটিং রিপোর্ট
- `app/Reports/*` (ReportPeriod, ReportBuilder, ReportService, ReportRepo/PgReportRepo), `ReportController`, `reports/index.blade.php`, নেভ "রিপোর্ট", রুট `reports.index`/`reports.generate` (throttle 10/মিনিট)।
- অনুমতি: দেখতে `ads.view`; সোশ্যাল অংশ শুধু `social.view` থাকলে।
- যাচাই: ইউনিট ১৫ (মিউটেশনে ১টি সমতুল্য বাদে সব ধরা), ডাটাবেস ৪ (PHP-এর SQL ফাইল থেকে পড়ে অ্যাপ-রোলে চালানো), আসল ইঞ্জিন রেন্ডারারে বাংলা/ইংরেজি PDF+DOCX বানিয়ে ছবি দেখা হয়েছে।
- সীমা: মাসিক রিপোর্টে আগের মাসের তুলনা নেই (তথ্য ৩০ দিন রাখা হয়); সংখ্যা কপি করা তথ্য, পুরনো হতে পারে; "ফলাফল/কনভার্সন" প্ল্যাটফর্মের সেটআপ-নির্ভর; PDF-এ ইঞ্জিনে Chromium লাগে (না থাকলে DOCX); ব্রাউজারে UI দেখা হয়নি।
- বাকি (ফেজ ২৪.৩): b এজেন্সি মোড, c SEO টুল, d Marketing Manager Agent।

## Phase 24.2d রিপোর্ট — Google পরামর্শ ও বিরতি (ফেজ ২৪.২ সম্পূর্ণ)
`GadsAdvisor` (AdsAdvisor-এর নিয়ম + ট্র্যাকিং-নেই সুরক্ষা), `gads_pause_*`, `GadsPauser`, `gads:pause`। **আসল Google API-তে যাচাই হয়নি** (campaigns:mutate-এর updateMask ফর্ম্যাট ইত্যাদি)। পরামর্শের সীমা: শুধু ৭ দিনের কপি-করা সংখ্যা; কনভার্সন-অ্যাকশনের মান আপনার Google Ads সেটআপের উপর নির্ভর।

## Phase 24.2c রিপোর্ট — Google Ads-এ PAUSED তৈরি
অনুমোদিত Search ড্রাফট থেকে Google-এ ক্যাম্পেইন তৈরি (সবই বিরতি অবস্থায়, চালু করেন মানুষ)। **আসল Google Ads API-তে যাচাই হয়নি**: mutate এন্ডপয়েন্ট, ক্ষেত্রের নাম (`targetSpend`, `startDate/endDate`, `containsEuPoliticalAdvertising`), geo/ভাষার আইডি, API সংস্করণ (v24 বনাম নতুন) — ভুল হলে Google স্পষ্ট ত্রুটি দেবে, যা ড্রাফট পাতায় দেখাবে এবং ঠিক করে আবার চেষ্টা করা যাবে। Developer Token-এর Basic স্তর ছাড়া আসল অ্যাকাউন্টে চলবে না। বাকি: 24.2d (পরামর্শ/থামানো)।

## Phase 24.2b রিপোর্ট — Google Ads ড্রাফট ও অনুমোদন
Search ড্রাফট, AI শব্দ-প্রস্তাব, আলাদা খরচ-সীমা, অনুমোদন। Google-এ কিছু পাঠায় না। সীমা: AI-র কীওয়ার্ড ও বিজ্ঞাপন-নীতি পালন মানুষকে যাচাই করতে হবে (Google অনুমোদন দেয় তাদের নিজস্ব নীতিতে); PMax/Display/Shopping/YouTube এখনো নেই (শুধু Search); UI ব্রাউজারে দেখা হয়নি। বাকি: 24.2c PAUSED তৈরি, d পরামর্শ।

## Phase 24.2a রিপোর্ট — Google Ads সংযোগ ও শুধু-পড়া সিঙ্ক
Google OAuth দিয়ে সংযোগ, শুধু-পড়া সিঙ্ক ও ড্যাশবোর্ড। **আসল Google Ads API-র বিপরীতে যাচাই হয়নি** (REST endpoint, GAQL ক্ষেত্রের নাম, ত্রুটির ধরন, API ভার্সন v24 অনুমানভিত্তিক)। Developer Token-এর স্তর (Test/Explorer/Basic) অনুযায়ী আসল অ্যাকাউন্টে ব্লক হতে পারে। UI ব্রাউজারে দেখা হয়নি। বাকি: 24.2b ড্রাফট/AI কপি/সীমা/অনুমোদন, c PAUSED তৈরি, d পরামর্শ (Search Terms ইত্যাদি)।

## Phase 24.1d রিপোর্ট — অপ্টিমাইজেশন পরামর্শ
নিয়মভিত্তিক পরামর্শ + অনুমোদিত "বিরতি" (শুধু PAUSED)। সীমা: থ্রেশহোল্ড (৩ দিন, ৩০ ক্লিক, ২.৫×, ০.৬×, ৫ ফলাফল) অনুমান, আসল ডেটায় টিউন করতে হবে; "ফলাফল" Meta-র action type অনুযায়ী আনুমানিক; Meta-র ক্যাম্পেইন-আপডেট কল আসল API-তে যাচাই হয়নি; UI ব্রাউজারে দেখা হয়নি।

## Phase 24.1c রিপোর্ট — Meta-তে PAUSED তৈরি
অনুমোদিত ড্রাফট ব্যাকগ্রাউন্ডে (`ads:launch`) Meta-তে ক্যাম্পেইন/অ্যাড-সেট/ক্রিয়েটিভ/অ্যাড হিসেবে PAUSED তৈরি হয়। আইডি ধাপে ধাপে সংরক্ষিত, retry আংশিক-পুনরায়, অজানা ফলে মানুষের নিশ্চিতকরণ লাগে। **আসল Meta API-র বিপরীতে যাচাই হয়নি** (প্যারামিটার নাম, promoted_object, ত্রুটি-কোড অনুমানভিত্তিক)। আগ্রহ-তালিকা Meta targeting id-তে ম্যাপ হয় না; ছবির ক্রিয়েটিভ নেই (লিংক-প্রিভিউ); লিড/মেসেজ অসমর্থিত; UI ব্রাউজারে দেখা হয়নি।

## Phase 24.1b রিপোর্ট — বিজ্ঞাপন-ড্রাফট ও অনুমোদন
- **হয়েছে:** ড্রাফট তৈরি/সম্পাদনা, AI লেখা (ক্রেডিট reserve→একবার settle/release), খরচের সীমা, জমা → অনুমোদন/ফেরত, খসড়ায় ফেরানো, মোছা।
- **নিরাপত্তা:** Meta-তে কোনো কল নেই; বাজেট শুধু মানুষ দেয়; সীমা ছাড়া কিছু এগোয় না; অনুমোদনে সীমা আবার যাচাই।
- **যাচাই হয়নি:** ব্রাউজারে Blade/JS (AI বাটন ফর্ম পূরণ), আসল AI-র লেখার মান ও Meta নীতি মানা। AI-র আউটপুট মানুষকে পড়তে বলা হয়েছে।
- **বাকি:** 24.1c অনুমোদিত ড্রাফট Meta-তে PAUSED তৈরি (ads_management, ছবি/ক্রিয়েটিভ, অডিয়েন্স-আইডি ম্যাপিং, মুদ্রা-অফসেট); 24.1d অপ্টিমাইজেশন পরামর্শ।

## Phase 24.1a রিপোর্ট — Meta Ads (শুধু দেখা)
- **হয়েছে:** অ্যাকাউন্ট সংযোগ, ক্যাম্পেইন + দৈনিক ফলাফল সিঙ্ক, সারাংশ পাতা, অনুমতি।
- **নিরাপত্তা:** শুধু `ads_read` লাগে; কোড Meta-তে লিখতেই পারে না (শুধু GET)। টোকেন হেডারে, সিল করা। ইমার্জেন্সি স্টপে সিঙ্ক থামে।
- **যাচাই হয়নি:** আসল Meta Marketing API (ক্ষেত্রের নাম/পেজিং/`actions` ধরন), আসল ডেটা, ব্রাউজারে Blade।
- **সীমা:** "ফলাফল" আনুমানিক (অগ্রাধিকার: omni_purchase, purchase, lead, lead_grouped, messaging); মুদ্রা-অফসেটের অনিশ্চয়তায় দৈনিক বাজেট এখনো আনা হয়নি (24.1b-তে)।
- **বাকি (24.1b–d):** AI ক্যাম্পেইন ড্রাফট → অনুমোদন → PAUSED তৈরি (ads_management, বাজেট ক্যাপ) → অপ্টিমাইজেশন পরামর্শ।

## Phase 23.2d রিপোর্ট — YouTube
- **হয়েছে:** চ্যানেল সংযোগ (OAuth), লং ভিডিও আপলোড (অনুমোদনের পর, একবারই), টাইটেল/বিবরণ/ট্যাগ/প্রাইভেসি/থাম্বনেইল, AI SEO সাজেশন, কোটা সীমা, ক্যালেন্ডারে YouTube লেবেল ও সেটিংস পাতা।
- **নিরাপত্তা:** ডিফল্ট `private`; আপলোডের ফলাফল অজানা হলে `failed` (নিজে থেকে পুনরায় নয়); টোকেন শুধু হেডার/ফর্ম বডিতে।
- **যাচাই হয়নি:** আসল Google/YouTube API (multipart upload, thumbnails.set-এ যাচাইকৃত চ্যানেল লাগে, কোটা সংখ্যা), আসল AI মান, ব্রাউজারে Blade রেন্ডার।
- **বাকি:** YouTube ইনসাইট/কমেন্ট (এখন শুধু Facebook), "made for kids" ফ্ল্যাগ অনির্ধারিত রাখা হয়েছে।

## Phase 23.2c রিপোর্ট — কমেন্ট-রিপ্লাই
- `app/Social/{SocialComment*,PgSocialComment*,SocialReply*}`, `FacebookGraph::comments/replyToComment`, `SocialCommentController`, `social/comments` ভিউ, কমান্ড `social:comments` ও `social:reply`, `phase232c.up/down.sql`; টেস্ট: `tests/db/test_social_comments.py` (১১), `tests/Unit/SocialCommentTest.php` (৩০)।
- যাচাই: DB ১২৮৯ পাস; মিউটেশনে কোনো সারভাইভার নেই; আসল Facebook (কমেন্ট API ফিল্ড, pages_manage_engagement অনুমতি) ও আসল AI-তে যাচাই বাকি। বাকি: 23.2d।

## Phase 23.2b রিপোর্ট — গ্রোথ বিশ্লেষণ
- `app/Social/{SocialGrowth,SocialGrowthPrompt,SocialGrowthAdvice,SocialGrowthService}`, `social/growth` ভিউ, রুট `/app/social/growth` ও `/growth/advice`, `tests/Unit/SocialGrowthTest.php` (১৩টি)।
- যাচাই: পিওর-PHP লুপে নতুন ফাইল পাস; মিউটেশনে ১টি সমতুল্য সারভাইভার (`\p{M}` গণনায় প্রভাব ফেলে না); আসল AI/ব্রাউজারে যাচাই বাকি। বাকি: 23.2c/d।

## Phase 23.2a রিপোর্ট — AI কন্টেন্ট-প্ল্যান এজেন্ট
- ✅ `phase232a` (১৬ DB টেস্ট), `SocialPlanPrompt/SocialPlan/SocialPlanService`, কন্ট্রোলার `plan/generate/accept`, ভিউ `social/plan`; `SocialPlanTest` ২৬টি, মিউটেশনে দুটি সমতুল্য বাদে সব ধরা। DB মোট ১২৭৮ পাস।
- ❗ যাচাই হয়নি: আসল AI-র প্ল্যানের মান/বাংলা, ব্লেড রেন্ডার।
- বাকি: 23.2b গ্রোথ বিশ্লেষণ, 23.2c কমেন্ট-রিপ্লাই, 23.2d YouTube।

## Phase 23c রিপোর্ট — পোস্ট ইনসাইট
- ✅ `phase23c` (৯ DB টেস্ট), `FacebookGraph::stats`, `SocialInsights`, `social:insights`, ইনসাইট পাতা; PHP টেস্ট মোট ৩৫ (`SocialPublishTest`), মিউটেশনে একটি সমতুল্য বাদে সব ধরা।
- ❗ যাচাই হয়নি: আসল Graph সংখ্যা/মেট্রিক নাম, ব্লেড রেন্ডার। মাত্র সিস্টেম-পোস্ট করা পোস্টেই সংখ্যা আসে।
- ফেজ ২৩ কোড সমাপ্ত (২৩a, ২৩b, ২৩c)। বাকি: ২৩.২ (AI গ্রোথ এজেন্ট), Instagram/YouTube অটো-পোস্ট ও ইনসাইট, কমেন্ট-রিপ্লাই।

## Phase 23b রিপোর্ট — Facebook Page অটো-পোস্ট
- ✅ `phase23b` (২৪ DB টেস্ট), `FacebookGraph/Multipart/SocialPublisher/SocialAccounts/SocialSecrets/PostMedia`, `social:publish`, পেজ-সংযোগ UI, ফর্মে পেজ বাছা, ব্যর্থ হলে রিট্রাই। `SocialPublishTest` ২৭টি, মিউটেশনে সব ধরা।
- ❗ যাচাই হয়নি: আসল Graph API (এন্ডপয়েন্ট/ভার্সন/অনুমতি), আসল টোকেন-সিলিং (এখানকার pure-শিমে Laravel Encrypter নেই — `CredentialVaultTest` লারাভেল পরিবেশে চালান), ভিডিও আপলোড সীমা।
- বাকি: ২৩c ইনসাইট; Instagram/YouTube অটো-পোস্ট; ২৩.২।

## Phase 23a রিপোর্ট — সোশ্যাল কন্টেন্ট প্ল্যানার
- ✅ ডাটাবেস (`phase23a`, ৩৭ টেস্ট), `app/Social/*` (Repo, Service, Calendar, Text, Caption AI), `SocialController`, ভিউ `social/index|form|show`, রুট `app/social`, নেভ "সোশ্যাল", পারমিশন।
- টেস্ট: `tests/Unit/SocialTest.php` (৩৩), DB মোট ১২২৯ পাস; মিউটেশন চেকে বাকি ২টি সমতুল্য।
- ❗ যাচাই হয়নি: আসল ব্রাউজারে ব্লেড/JS, `php artisan migrate`, Pest ফিচার টেস্ট, আসল AI ক্যাপশন।
- বাকি: ২৩b (Facebook Page অটো-পোস্ট — আসল টোকেন লাগবে), ২৩c (ইনসাইট), ২৩.২।

## Phase 22.2c-গ রিপোর্ট — টাইমলাইন ও AI ছবি (ফেজ ২২.২ শেষ)
- সম্পন্ন। বাদ: পুরো AI ভিডিও জেনারেশন (প্রোভাইডার কী ছাড়া যাচাই অসম্ভব)। যাচাই: ইউনিট ১০৯, JS ১৬ (`node --test tests/js/*.test.js`, jsdom থাকলে), ডাটাবেস ১১৯২, ইঞ্জিন ৩৭৬।

## Phase 22.2c-খ রিপোর্ট — ব্যাকগ্রাউন্ড মিউজিক
- সম্পন্ন (স্লাইড-ভিডিওতে)। ক্লিপ এডিটরে মিউজিক নেই (ক্লিপের নিজের শব্দ থাকে)। বাকি: AI ভিডিও জেনারেশন, টাইমলাইন UI। যাচাই: ইঞ্জিন ৩৭৬, ইউনিট ৯৬।

## Phase 22.2c-ক রিপোর্ট — ক্লিপ এডিটর
- সম্পন্ন: ছাঁটা, নীরবতা কাটা, সাইজে ক্রপ (ইঞ্জিন + Laravel + পেজ)। বাকি (২২.২c-খ/গ): রয়্যালটি-ফ্রি মিউজিক, AI ভিডিও জেনারেশন, ড্র্যাগ-টাইমলাইন UI। যাচাই: ইঞ্জিন ৩৬৫, ইউনিট VideoTest ৯৩।

## Phase 22.2b রিপোর্ট — সাবটাইটেল ও AI স্ক্রিপ্ট
- সম্পন্ন: সাবটাইটেল (ইঞ্জিন + Laravel), AI স্ক্রিপ্ট-লেখক, ফর্মে বোতাম। সীমা: STT-ভিত্তিক শব্দ-সিঙ্ক নেই; নীরবতা কাটা ২২.২c-তে। যাচাই: ইঞ্জিন ৩৪৩, ইউনিট VideoTest ৬৫।

## Phase 22.2a রিপোর্ট — ভিডিও স্টুডিও (স্লাইড-ভিডিও)
- সম্পন্ন: ইঞ্জিন রেন্ডারার, ডাটাবেস কিউ, Laravel সার্ভিস/ওয়ার্কার/কন্ট্রোলার/পেজ, নেভিগেশন "ভিডিও"।
- সীমা: শুধু স্লাইড-ভিডিও; AI ভিডিও জেনারেশন, টাইমলাইন এডিটর, অটো সাবটাইটেল, নীরবতা কাটা, মিউজিক এখনো নেই (২২.২b/c)। বাস্তব ব্রাউজার/প্রোভাইডারে যাচাই হয়নি।
- যাচাই: ডাটাবেস ১১৯২, ইঞ্জিন ৩৩৩, ইউনিট VideoTest ৪৮ পাস।

## Phase 22.1 রিপোর্ট — ডিজাইন স্টুডিও
- কোড সম্পূর্ণ; DB ১১৫৫, ইঞ্জিন ৩০৭, StudioTest ২৯ পাস। বাস্তব ইমেজ-মডেল (OpenAI gpt-image-1 / dall-e-3) ও ব্রাউজারে দেখা বাকি — এখানে সম্ভব নয়।
- বাকি: ডিজাইন সম্পাদনা/ভার্সন, লোগো-জেনারেশন, টেমপ্লেট-লাইব্রেরি, ক্লায়েন্ট সিদ্ধান্ত জানালে মালিকের নোটিফিকেশন, ফেজ ২২.২ ভিডিও ☐।

## Phase 27.2d রিপোর্ট — JS SDK ও webhook বার্তার তালিকা
- `packages/sdk-js/`, `WebhookRepo::deliveries`, পেজে তালিকা।
- **যাচাই হয়েছে (এখানে):** Node-এ ১৭ টেস্ট লোকাল সার্ভারে + ১৫টি মিউটেশন (১টি সমতুল্য: রিডাইরেক্টে `!res.ok` আগেই ধরে); DB ২৬।
- **অযাচাইকৃত:** আসল সার্ভারের বিরুদ্ধে SDK; Blade তালিকা।
- **আপনাকে করতে হবে:** কিছু না (মাইগ্রেশন নেই)।

## Phase 27.2c রিপোর্ট — ব্যবহার-অ্যানালিটিক্স, OpenAPI, Python SDK
- `phase27c.up/down.sql`, `PgApiKeyRepo::usage`, `resources/openapi/public-v1.json`, `DeveloperApiController::openapi`, `packages/sdk-python/`।
- **যাচাই হয়েছে (এখানে):** DB (দিনপ্রতি গণনা, ভুল কী গোনা হয় না, ৯০ দিন ছাঁটাই, অন্য কীর সারি অক্ষত, গোপনীয়তা) + ৫টি মিউটেশন; `PublicApiSpecTest` ৩ (স্পেক = রুট, স্কোপ মেলে) + মিউটেশন; SDK ৩৬ টেস্ট লোকাল HTTP সার্ভারে + ১৩টি মিউটেশন (২টি সমতুল্য)।
- **অযাচাইকৃত:** আসল সার্ভারের বিরুদ্ধে SDK; Laravel স্তরে `openapi.json` রুট ও পেজের ব্যবহার-লাইন।
- **সীমা:** শুধু Python SDK (JS/PHP SDK নেই); স্যান্ডবক্স, MCP, প্লাগইন স্টোর বাকি; ব্যবহার-গণনা কী-প্রতি দিনে, এন্ডপয়েন্ট-ভিত্তিক নয়।
- **আপনাকে করতে হবে:** `php artisan migrate`।

## Phase 27.2b রিপোর্ট — Webhooks
- `phase27b.up/down.sql`, `app/Developer/{Webhooks,WebhookUrl,WebhookSigner,WebhookRepo,PgWebhookRepo,WebhookSender,CurlWebhookSender}`, `WebhooksWorkCommand`, ডেভেলপার পেজে Webhook অংশ।
- **যাচাই হয়েছে (এখানে):** DB ২৫ (এনকিউ একবারই, শুধু মালিকের নিজের রান, backoff, ৬ বারে dead, ১০ ব্যর্থতায় বন্ধ, লিজ, ১৪ দিন পরে মোছা, অ্যাপ সিক্রেট পড়তে/ওয়ার্কার ফাংশন ডাকতে পারে না) + SQL-এ ১৫টি মিউটেশন; PHP ৯ (URL নিয়ম, সাইন, প্রাইভেট DNS, ২xx-ই সফল) + ২০টি মিউটেশন (১টি সমতুল্য)।
- **অযাচাইকৃত:** আসল curl/TLS/DNS পাঠানো (`CurlWebhookSender`), Laravel কন্ট্রোলার/Blade, শিডিউলার — আপনার মেশিনে একটি আসল https ঠিকানায় (যেমন webhook.site) পরীক্ষা করুন।
- **সীমা:** একটি ইভেন্ট (`run.finished`); অন্য ইভেন্ট (ডিজাইন অনুমোদন, অর্ডার ইত্যাদি), ডেলিভারি লগ UI ও "টেস্ট পাঠান" বাটন নেই। IPv6 ঠিকানা অনুমোদিত নয়।
- **আপনাকে করতে হবে:** `php artisan migrate`; `schedule:run` ক্রন চালু থাকতে হবে (আগের মতোই)।

## Phase 27.2a রিপোর্ট — পাবলিক API কী ও ডেভেলপার API
- `phase27a.up/down.sql`, `app/Developer/{ApiKeys,ApiKeyRepo,PgApiKeyRepo,DeveloperText}`, `ApiKeyAuth`, `DeveloperApiController`, `DeveloperController`, `developer/index.blade.php`, `routes/api_public.php`।
- **যাচাই হয়েছে (এখানে):** DB `test_api_keys.py` ১৬ (বাতিল/মেয়াদ/সদস্যপদ/workspace বন্ধ, সীমা ১০, গোপনীয়তা, সরাসরি লেখা নিষিদ্ধ, last-used) + SQL-এ ৭টি মিউটেশন ধরা; `DeveloperKeysTest` ৬ + PHP-তে ১০টি মিউটেশন ধরা (১টি সমতুল্য)।
- **অযাচাইকৃত:** Laravel স্তর (middleware, কন্ট্রোলার, Blade, রেট-লিমিট) চালিয়ে দেখা যায়নি — Packagist নেই; আপনার মেশিনে `curl` দিয়ে দেখুন (নিচে)।
- **সীমা:** webhooks, SDK, ডেভেলপার স্যান্ডবক্স, ব্যবহার-অ্যানালিটিক্স, প্লাগইন স্টোর ও MCP এখনো নেই (২৭.২b+)। কী সব ওয়ার্কফ্লো-ই চালাতে পারে যা ব্যক্তি নিজে চালাতে পারে; বাইরে পাঠানোর ধাপে অনুমোদন আগের মতোই।
- **আপনাকে করতে হবে:** `php artisan migrate`; তারপর `/app/developer`-এ কী বানিয়ে: `curl -H "Authorization: Bearer jk_live_…" {APP_URL}/api/public/v1/me`।

## Phase 22.1c রিপোর্ট — ডিজাইন সম্পাদনা, ভার্সন, টেমপ্লেট
- `phase22c.up/down.sql`, `StudioService::edit/restore`, `StudioTemplates`, `StudioController::edit/restore`, ভিউ দুটি।
- **যাচাই হয়েছে (এখানে):** `StudioEditTest` ৯ (সীমা, ছাঁটাই, অন্যের ডিজাইন, unchanged, restore-এ বর্তমান রূপ সংরক্ষণ, টেমপ্লেটে বানানো দাম/ছাড় নেই), মিউটেশন ৫টি মরেছে; DB ১৪৭৮ পাস।
- **অযাচাইকৃত:** Blade ভিউ আসল ব্রাউজারে (কম্পাইল করা যায়নি, ডিরেক্টিভ গোনা হয়েছে)।
- **সীমা:** ছবি/ব্যাকগ্রাউন্ড বদল বা ব্র্যান্ড বদল সম্পাদনায় নেই (নতুন ডিজাইন বানান); ক্লায়েন্ট সিদ্ধান্তের নোটিফিকেশন ২২.১d-তে হয়েছে (ইন-অ্যাপ ইনবক্স + মোবাইল পুশ); লোগো আইডিয়া ২২.১e-তে হয়েছে (খসড়া নামানো; ব্র্যান্ড কিটে স্বয়ংক্রিয় বসানো ইচ্ছাকৃতভাবে নেই; আসল ইমেজ-মডেলে মান যাচাই হয়নি)। ২২.১ এখন কোডে সম্পূর্ণ।
- **২২.১d আপনাকে করতে হবে:** `php artisan migrate`।
- **আপনাকে করতে হবে:** `php artisan migrate`।

## Phase 19.2e রিপোর্ট — Google / Outlook ক্যালেন্ডার (OAuth)
- নতুন: `app/Calendar/{CalendarAccounts,CalendarApi,GoogleCalendarApi,GraphCalendarApi,GoogleCalendarOAuth,MicrosoftCalendarOAuth,CalendarFailure,CalendarApiException}`, `CalendarOAuthController`, `CalendarAddEventTool`; `CalendarService` ও `ConnectController`/ভিউ বাড়ানো; `LinkRepo::updateSecret`। SQL `phase192e` (kind `gcal`/`mcal`, `link_secret_update`)।
- **যাচাই হয়েছে (এখানে):** `CalendarOauthTest` ২১ (OAuth স্কোপ/ত্রুটি, দুই API-র পার্সিং ও অনুরোধ, সীমার প্রতিটি মান, ভুল মালিকের লিংকে লেখা বন্ধ, ঘূর্ণিত টোকেন, ব্যর্থতার দাগ, মিশ্রণ ও ডুপ্লিকেট, ওয়ার্কফ্লো অনুমোদন-নিয়ম); ২০+টি মিউটেশন ধরা পড়েছে (ফাঁক পাওয়া ৪টি টেস্ট শক্ত করা হয়েছে); DB ফাংশন টেস্ট (সীমা, মালিক, RLS, down→up)। সব DB (১৪৪৫) ও pure-PHP টেস্ট সবুজ।
- **অযাচাইকৃত (এই পরিবেশে অসম্ভব):** আসল Google/Microsoft লগইন ও API (উত্তরের আকার ডকুমেন্টেশন থেকে), ব্রাউজারে Blade, `php artisan migrate`।
- **সীমা:** শুধু প্রাইমারি ক্যালেন্ডার; একটি অনুরোধে সর্বোচ্চ ১০০ ইভেন্ট; ইভেন্ট বদল/মোছা বা দ্বি-মুখী স্বয়ংক্রিয় সিঙ্ক নেই; সংযুক্ত ক্যালেন্ডার ফল প্রতিবার সরাসরি আনা হয় (ক্যাশ নেই)।
- **আপনাকে করতে হবে:** `php artisan migrate`; Google Cloud-এ *Google Calendar API* চালু ও consent screen-এ `calendar.events` স্কোপ; redirect URI `{APP_URL}/app/connect/oauth/google/callback` ও `.../microsoft/callback` (Microsoft অ্যাপে `Calendars.ReadWrite` delegated permission)।

## Phase 19.2d রিপোর্ট — ক্যালেন্ডার ফিড, Slack/Discord, অডিও মিনিটস
- কোড সম্পূর্ণ; DB ১১১৩, ইঞ্জিন ২৭৭, ConnectTest ৩৩, WorkflowTest ৪৬ পাস। বাস্তব Google/Outlook/Slack/Discord/মাইক দিয়ে যাচাই বাকি (এই পরিবেশে সম্ভব নয়)।
- বাকি: OAuth-ভিত্তিক ক্যালেন্ডার লেখা/সিঙ্ক ☐।

## Phase 20b রিপোর্ট — ভয়েস থেকে ওয়ার্কফ্লো চালানো
- `app/Voice/{VoiceCommand,VoiceActions,WorkflowVoiceActions}.php`; `VoiceService::ask` প্রথমে কমান্ড দেখে, না হলে আগের মতো AI উত্তর।
- **যাচাই হয়েছে (এখানে):** `VoiceTest` ২৪ (নতুন ৭: বাংলা/ইংরেজি বাক্য, ৮-শব্দের সীমা, হুবহু বনাম অংশ-মিল, দ্ব্যর্থতা, ৩-অক্ষরের সীমা, ইনপুট-লাগা/বন্ধ ওয়ার্কফ্লো না চালানো, AI-খরচ শূন্য, অন্যের ওয়ার্কফ্লো অদৃশ্য); ১২টি মিউটেশন ধরা পড়েছে (১টি ফাঁক পেয়ে টেস্ট যোগ)।
- **সীমা:** ভয়েসে শুধু "চালাও" চলে — অনুমোদন, মুছে ফেলা, বদল নয়। শব্দ ভুল শুনলে নামে না মিললে কিছুই চলে না। বাংলা বলার মান আপনার STT প্রোভাইডারের উপর নির্ভর (অযাচাইকৃত)।
- **এখনো বাকি (আসল ডিভাইস/ব্রাউজার ছাড়া যাচাই অসম্ভব):** "হে জার্ভিস" ওয়েক-ওয়ার্ড (সবসময়-শোনা মাইক — গোপনীয়তা সিদ্ধান্তও লাগে), মোবাইল অ্যাপে মাইক (Expo ডিভাইস), রিয়েল-টাইম স্ট্রিমিং ও ফোনকল।
- **আপনাকে করতে হবে:** কিছু না (মাইগ্রেশন নেই)।

## Phase 20 রিপোর্ট — ভয়েস অ্যাসিস্ট্যান্ট
- **হয়েছে**: ওয়েবে মাইক→লেখা→উত্তর→কণ্ঠ; স্বাধীন "শোনা" ও "বলা" এন্ডপয়েন্ট; ক্রেডিটসহ।
- **এখনো নেই**: "হে জার্ভিস" ওয়েক-ওয়ার্ড; মোবাইল অ্যাপে মাইক; ফোনকল/রিয়েল-টাইম স্ট্রিমিং; ভয়েস দিয়ে কাজ চালানো (টুল/এজেন্ট কল); মিটিং অডিও ফাইল থেকে ট্রান্সক্রিপশন (১৯.২-র বাকি)।
- **আপনার করণীয়**: একটি OpenAI-সামঞ্জস্যপূর্ণ প্রোভাইডার (OpenAI বা Groq) যোগ করে তার মডেলে সক্ষমতা চালু: STT মডেল (যেমন `whisper-1` / Groq `whisper-large-v3`) → `audio_in`; TTS মডেল (যেমন `tts-1`) → `audio_out`। Groq-এ TTS নাও থাকতে পারে — তখন শুধু শোনা চলবে, বলা চলবে না (ত্রুটি দেখাবে)। সাইট https-এ না থাকলে ব্রাউজার মাইক দেবে না।
- **যাচাই হয়নি**: আসল ব্রাউজার মাইক, আসল STT/TTS প্রোভাইডার, বাংলা শনাক্তকরণের মান।

## Phase 21b রিপোর্ট — delay ধাপ ও each
- `WorkflowDefinition` (`delay`, `each`/`max_items` যাচাই), `WorkflowRunner` (parking ও লাইন-ধরে-চালানো), `WorkflowRepo::delay` + `wf_step_delay`; টেমপ্লেট `remind_later`।
- **যাচাই হয়েছে (এখানে):** WorkflowTest ৫৭ (নতুন ১১: সীমার প্রতিটি মান, ৩টি delay, অনুমোদন-নিয়ম, পার্ক→পুনরায়→শেষ, ভুল সময় ফেল, ১০-লাইন সীমা রানটাইমেও, ব্যর্থ লাইন, outside-tool প্রত্যাখ্যান); ২০+ মিউটেশন ধরা (১টি ফাঁক পেয়ে টেস্ট যোগ); DB ১৪৫৭ (নতুন ১২: পার্ক, সময় হলে ফেরা, পুরনো started_at-এ ভুল-ফেল না হওয়া, ক্রম, সীমা, বাতিল, অন্য ওয়ার্কস্পেস, down→up)।
- **অযাচাইকৃত:** আসল `php artisan migrate`/ওয়ার্কার ঘড়িতে দীর্ঘ অপেক্ষা (ঘড়ি `now()`-এ পরীক্ষিত, বাস্তবে ঘণ্টা অপেক্ষা নয়)।
- **ইচ্ছাকৃতভাবে নেই:** প্যারালাল ধাপ (এখনকার ক্রমিক চালানোই নিরাপদ ও যথেষ্ট); লুপ (গ্রাফ লুপ-মুক্ত থাকে — `each` সীমিত বিকল্প); বাইরে-পাঠানো ইমেইল/পোস্ট টুল (ঝুঁকি-বিচার আলাদা সিদ্ধান্ত)।
- **এখনো বাকি (ফেজ ২১):** ভিজ্যুয়াল ক্যানভাস (ব্রাউজারে যাচাই লাগে), Coding Agent।
- **আপনাকে করতে হবে:** `php artisan migrate`।

## Phase 21a রিপোর্ট — এজেন্ট ইঞ্জিন ভিত্তি
- **হয়েছে**: ওয়ার্কফ্লো (JSON), ৫ ধরনের ধাপ, ৬টি নিরাপদ টুল, ৫টি টেমপ্লেট, লগ, বাজেট, অনুমোদনে থামা/চালু, দৈনিক সময়সূচি, কমান্ড `workflows:work`।
- **এখনো নেই**: ভিজ্যুয়াল ক্যানভাস (এখন JSON/ফর্ম); লুপ/ডিলে/প্যারালাল নোড; বাইরে পাঠানো ও টাকার টুল (ইমেইল, পোস্ট, পেমেন্ট) — ইচ্ছাকৃতভাবে নেই; শুধু দৈনিক সময়সূচি; মোবাইল অ্যাপে অনুমোদন (এখন শুধু ওয়েব); Coding Agent।
- **যাচাই হয়নি (এই পরিবেশে অসম্ভব)**: `php artisan migrate`, ব্রাউজারে পেজ, Pest ফিচার টেস্ট, আসল AI মডেলে ধাপের মান।
- **করতে হবে**: `php artisan migrate`; cron `* * * * * php artisan schedule:run` (১৯.২এ-তেই বলা) — এখন `workflows:work`-ও এতে চলে; রান শুরু হতে সর্বোচ্চ ~১ মিনিট।

## Phase 19.2c রিপোর্ট — লেখা-সহকারী (মিটিং সারাংশ / ইমেইল)
- সম্পন্ন (কোড): তিনটি মোড, অ্যাকশন আইটেম → টাস্ক, ক্রেডিট হিসাব।
- অযাচাইকৃত: `php artisan migrate`; বাস্তব মডেলে বাংলা সারাংশের মান ও তারিখ-বোঝা ("আগামী রবিবার"); ব্রাউজারে পেজ (ফর্ম-রিটার্ন ও টিক-বক্স); Pest ফিচার টেস্ট।
- সীমা: অডিও/ভিডিও থেকে সরাসরি ট্রান্সক্রিপশন নেই (লেখা দিতে হয় — অডিও ফেজ ২০ ভয়েসের সাথে); ইমেইল ইনবক্স থেকে স্বয়ংক্রিয় টানা বা পাঠানো নেই (ফলে Gmail/Outlook সংযোগ ছাড়াই চলে); ফল সংরক্ষিত হয় না; বড় ট্রান্সক্রিপ্ট (>২০,০০০ অক্ষর) ভেঙে দিতে হয়; একটি উত্তরে সর্বোচ্চ ~২০০০ টোকেন।
- ১৯.২-এর বাকি: Google/Microsoft ক্যালেন্ডার সিঙ্ক, Slack/Discord (key লাগবে), অডিও ট্রান্সক্রিপশন (ফেজ ২০)।

## Phase 19.2b রিপোর্ট — ডকুমেন্ট / স্প্রেডশিট / প্রেজেন্টেশন তৈরি
- সম্পন্ন (কোড): AI-লিখিত কাঠামো → সংরক্ষণ → DOCX/XLSX/PPTX ডাউনলোড; ক্রেডিট হিসাব; ইঞ্জিনে ফাইল-লেখক।
- অযাচাইকৃত: `php artisan migrate`; **বাস্তব মডেলের উত্তরের মান** (বাংলা ভাষা, কাঠামো মানা, সংখ্যার সঠিকতা); ইঞ্জিন রিস্টার্ট ও নতুন প্যাকেজ ইনস্টল; Word/Excel/PowerPoint-এ ফাইল খুলে দেখা (এখানে python-docx/openpyxl/python-pptx দিয়ে পুনরায় পড়ে মিলিয়েছি, আসল অফিস অ্যাপে নয়); ব্রাউজারে পেজ।
- সীমা: শিটে সূত্র/চার্ট/ফরম্যাটিং নেই (মান বসানো); ডকে ছবি/পাদটীকা/স্টাইল-টেমপ্লেট নেই, স্লাইডে ছবি/থিম নেই (সাদামাটা ডিফল্ট ডিজাইন); এক চেষ্টায় আউটপুট ≤৬০০০ টোকেন (অনেক বড় ফাইল কাটা পড়ে "পড়া গেল না" হতে পারে — তখনও চার্জ হয়); সংরক্ষিত খসড়া সম্পাদনা-পেজ নেই (ডাউনলোড করে অফিস অ্যাপে সম্পাদনা); নলেজ বেস/মেমোরি থেকে তথ্য টানা হয় না; PDF এক্সপোর্ট নেই (প্রস্তাব ছাড়া)।

## Phase 19.2a রিপোর্ট — টাস্ক / রিমাইন্ডার / নোট / দৈনিক পরিকল্পনা
- সম্পন্ন (কোড): ব্যক্তিগত টাস্ক ও নোট, রিমাইন্ডার নোটিফিকেশন (অ্যাপ + ফোন পুশ), বিনা খরচের দৈনিক পরিকল্পনা।
- যাচাই: DB টেস্ট পাস, PHP pure টেস্ট ১৩টি পাস, mutation চালানো হয়েছে।
- অযাচাইকৃত: `php artisan migrate`, স্কেডিউলার (cron `schedule:run` প্রতি মিনিট) চালু আছে কিনা, ব্রাউজারে পেজ ও datetime-local ইনপুট, বাস্তব ফোন পুশ, Pest ফিচার টেস্ট।
- সীমা: কেবল নিজের জন্য (টিম/অন্যকে অ্যাসাইন নয়); পুনরাবৃত্ত (প্রতি সপ্তাহ) টাস্ক নেই; ইমেইল/SMS রিমাইন্ডার নেই (অ্যাপ ও ফোন পুশ); Google/Microsoft ক্যালেন্ডার সিঙ্ক, মিটিং ট্রান্সক্রিপশন, ইমেইল সারাংশ, ডকুমেন্ট-তৈরি, Slack/Discord — ১৯.২ এর পরের অংশে (key লাগবে)।

## Phase 19d রিপোর্ট — ছবি পড়া / OCR (ফেজ ১৯ সম্পন্ন)
- সম্পন্ন (কোড): ছবি ও স্ক্যান PDF থেকে লেখা বের করে নলেজ বেসে (সম্মতি-টিকসহ), চ্যাটে ছবি সংযুক্তি (বর্ণনা+লেখা), ক্রেডিট হিসাব, ইঞ্জিন এন্ডপয়েন্ট।
- যাচাই: DB ৯৯২ পাস, ইঞ্জিন ২৩২ + ruff, মোবাইল ২৬, PHP নতুন সব পাস।
- অযাচাইকৃত: `php artisan migrate`, **বাস্তব vision-সক্ষম মডেলে** OCR-এর মান (বাংলা হাতের লেখা/ঝাপসা ছবি/টেবিল), ব্রাউজারে আপলোড, Pest ফিচার টেস্ট, `ChatController::extract`-এর পথ (কন্ট্রোলার-স্তর ইউনিট টেস্ট ছাড়া; যুক্তি `ImageAttachment`-এ টেস্টেড)।
- সীমা: চ্যাট মডেল ছবির পিক্সেল দেখে না, শুধু AI-র পাঠানো লেখা/বর্ণনা দেখে — ছবির ভেতরের খুঁটিনাটি (যেমন রং, অবস্থান) হারাতে পারে; OCR ভুল করতে পারে (দামের সংখ্যা মিলিয়ে নিন); একই ছবি দুবার দিলে দুবারই পড়ার খরচ লাগে (ডুপ্লিকেট ধরা পড়ে পড়ার পরে); ≤৬ পৃষ্ঠা/ছবি-PDF প্রতিবারে; ক্যামেরা ইনপুট ও Face Authentication ডেস্কটপ/মোবাইল ফেজে (২৫–২৬) সরানো হয়েছে; স্ক্রিন-বোঝা (screen understanding) ডেস্কটপ ফেজে।

## Phase 19c রিপোর্ট — সোর্সসহ উত্তর
- সম্পন্ন (কোড): নথি থেকে প্রাসঙ্গিক অংশ প্রম্পটে, উত্তরে `[n]` উদ্ধৃতি, সংরক্ষিত ও প্রদর্শিত উৎস তালিকা (উল্লেখিত/অনুল্লেখিত আলাদা), চ্যাটে বন্ধ করার সুইচ।
- যাচাই: DB ৯৭৭ পাস, ইঞ্জিন ২০৭ + ruff, মোবাইল ২৬, PHP নতুন সব পাস।
- অযাচাইকৃত: `php artisan migrate`, Blade ও `chat.js` ব্রাউজারে, Pest ফিচার টেস্ট, **বাস্তব AI মডেল `[n]` ঠিকমতো বসায় কিনা** (না বসালে সব উৎস "উল্লেখ হয়নি" দেখাবে), `RepoKnowledgeContext` (TenantContext-সহ) ইউনিট-টেস্ট ছাড়া।
- সীমা: খোঁজ শব্দ-ভিত্তিক — প্রশ্নে নথির শব্দ না থাকলে অংশ আসবে না, আর "হ্যাঁ/আরও বলুন"-ধরনের ছোট ফলো-আপ প্রশ্নে খোঁজ দুর্বল (শুধু সর্বশেষ প্রশ্নটি দিয়ে খোঁজা হয়); `cited` শুধু নম্বর-চিহ্ন দেখে, দাবিটা নথির সাথে সত্যিই মেলে কিনা যাচাই করে না; মোবাইল অ্যাপে এখনও উৎস দেখানো হয় না; নথির অংশ ইনপুট টোকেন বাড়ায় (≤~১৫০০) তাই প্রতি উত্তরের খরচ কিছু বাড়ে।

## Phase 19b রিপোর্ট — নলেজ বেস
- সম্পন্ন (কোড): ডকুমেন্ট আপলোড (PDF/DOCX/TXT/MD/CSV/JSON/HTML), অংশে ভাগ, বাংলা-সহ full-text খোঁজ, চালু/বন্ধ, নাম বদল, মোছা, খোঁজার স্ক্রিন।
- যাচাই: DB ৯৫১ পাস, ইঞ্জিন ২০৭ + ruff, মোবাইল ২৬, PHP নতুন সব পাস।
- অযাচাইকৃত: `php artisan migrate` + `roles:sync`, Blade স্ক্রিন, Pest ফিচার টেস্ট, বাস্তব আপলোড (বড় PDF/DOCX), শেয়ার্ড হোস্টে `pdftotext`-এর উপস্থিতি।
- সীমা: স্ক্যান করা (ছবি) PDF থেকে লেখা বের হয় না (১৯ডি); খোঁজ শব্দ-ভিত্তিক, অর্থ-ভিত্তিক নয় (বাংলা বিভক্তি প্রিফিক্সে ধরা পড়ে, তবে "বাড়ি"/"গৃহ"-র মতো সমার্থক ধরে না); ডকুমেন্ট ওয়ার্কস্পেসের সবাই খুঁজতে পারে (ভূমিকা-ভিত্তিক গোপনীয়তা নেই); চ্যাটে সোর্সসহ উত্তর এখনও নেই (১৯সি); টেবিল/ছবি/শিরোনাম-কাঠামো সংরক্ষিত হয় না।

## Phase 19a রিপোর্ট — মেমোরি সেন্টার
- সম্পন্ন (কোড): ব্যক্তিগত ও ব্যবসার মেমোরি নোট (যোগ/সম্পাদনা/পিন/বন্ধ/মোছা), চ্যাটে ব্যবহার (বন্ধ করার সুইচসহ), সংবেদনশীল লেখার পাহারা, কথোপকথন থেকে AI-প্রস্তাব (গ্রহণ/বাতিল), সব মুছুন।
- যাচাই: DB ৯১৮ পাস, ইঞ্জিন ২০৭ + ruff, মোবাইল ২৬, PHP নতুন সব পাস।
- অযাচাইকৃত: `php artisan migrate` + `roles:sync`, Blade স্ক্রিন, Pest ফিচার টেস্ট, **বাস্তব AI মডেলের প্রস্তাবের মান**।
- সীমা: নোট বাছাই প্রাসঙ্গিকতা-ভিত্তিক নয় — সব চালু নোট (পিন আগে) ৬০০ টোকেন পর্যন্ত যায়; অর্থভিত্তিক খোঁজ ১৯বি/১৯সি-তে; গার্ড "token"/"pin" শব্দ ইচ্ছাকৃতভাবে আটকায়; প্রস্তাব নিতে ক্রেডিট লাগে; নোট ডাটাবেসে সাধারণ টেক্সট।

## Phase 18d রিপোর্ট — আর্থিক বিশ্লেষক
- সম্পন্ন (কোড): মাসিক আয়-খরচ-নগদের নির্ধারিত বিশ্লেষণ, বাংলা ব্যাখ্যা (AI + যাচাই / নির্ধারিত টেমপ্লেট), আনুমানিক ৩ মাসের পূর্বাভাস, সংরক্ষিত রিপোর্ট, ক্রেডিট হিসাব।
- বাগ-ফিক্স: `usage_records.source` CHECK-এর অভাবে `catalog`/`bill_scan`/`inbox` AI-ব্যবহারে ক্রেডিট কাটা ব্যর্থ হতো — ঠিক করা হয়েছে (ইতিমধ্যে চালু সার্ভারে `php artisan migrate` না চালানো পর্যন্ত এগুলো ক্রেডিট কাটবে না)।
- যাচাই: DB ৮৪১ পাস, ইঞ্জিন ২০৭ + ruff, মোবাইল ২৬, PHP নতুন ৩৩।
- অযাচাইকৃত: `php artisan migrate`, Blade স্ক্রিন, Pest ফিচার টেস্ট, **বাস্তব AI মডেলের লেখা** (প্রম্পট ঠিকমতো সংখ্যা হুবহু লিখছে কিনা — না লিখলে টেমপ্লেট দেখাবে), `routing_rules`-এ `analyst` টাস্ক না থাকলে ডিফল্ট রাউটিং।
- সীমা: পূর্বাভাস সরল ট্রেন্ড (মৌসুম/ঈদ/এককালীন ধরে না), নগদ প্রবাহে মালিকের বিনিয়োগ/ঋণও ঢোকে, বাজেট-তুলনা ও প্রশ্ন-উত্তর (চ্যাট) নেই, রিপোর্ট PDF/ইমেইল নেই।

## Phase 18c রিপোর্ট — পে-স্লিপ ও বেতন ফাইল
- সম্পন্ন (কোড): কর্মীপ্রতি পেমেন্ট-তথ্য (ব্যাংক/বিকাশ/নগদ/নগদ-টাকা), রান থেকে চ্যানেলভিত্তিক CSV, পে-স্লিপ (প্রিন্ট/PDF), তথ্যহীনদের তালিকা।
- যাচাই: DB ৮০৬ পাস, ইঞ্জিন ২০৭ + ruff, মোবাইল ২৬, `SalaryFileTest` ৬, mutation ১৬/১৬।
- অযাচাইকৃত: `php artisan migrate`/`roles:sync`, Blade স্ক্রিন ও প্রিন্ট, Pest ফিচার টেস্ট, আপনার ব্যাংকের টেমপ্লেটে CSV মিলবে কিনা।
- সীমা: PDF লাইব্রেরি নেই (ব্রাউজার থেকে প্রিন্ট→PDF); ব্যাংকের ফাইল-ফরম্যাট ব্যাংকভেদে আলাদা — সাধারণ CSV, নিজে মিলিয়ে নিতে হবে; কথায় টাকার অঙ্ক, স্লিপ ইমেইল, কর্মীর নিজস্ব লগইন নেই; নম্বরগুলো ডাটাবেসে সাধারণ টেক্সটে (অ্যাক্সেস শুধু `payroll.manage`)।

## Phase 18b রিপোর্ট — পে-রোল (কাঠামো ও মাসিক রান)
- সম্পন্ন (কোড): কর্মী, আয়/কর্তন কাঠামো (তারিখ-ভিত্তিক), মাস থেকে খসড়া রান, বোনাস ও এককালীন কর্তন, হিসাবে তোলা, পরিশোধ, বাতিল।
- যাচাই: DB ৭৮০ পাস, ইঞ্জিন ২০৭ + ruff, মোবাইল ২৬, `PayrollTextTest` ৪; হাইপোথেসিস প্রপার্টি টেস্ট: লাইনের যোগফল = আইটেমের যোগফল = রান = জার্নাল।
- অযাচাইকৃত: `php artisan migrate`/`roles:sync`, Blade স্ক্রিন, Pest ফিচার টেস্ট, বাস্তব বেতনের হিসাব হিসাবরক্ষকের সাথে।
- সীমা: আংশিক মাস (যোগদান/অব্যাহতি/বিনা বেতনের ছুটি) নিজে হিসাব হয় না — এককালীন কর্তন/বোনাসে করতে হয়; কাটা আয়কর ও PF সরকারি খাতে জমার হিসাব এখনো নেই (দেনা হিসেবে জমা থাকে); পরিশোধিত রান বাতিল করা যায় না; কর্মীভিত্তিক আলাদা পরিশোধ নেই (পুরো রান একসাথে)। পে-স্লিপ ও ব্যাংক বেতন ফাইল ১৮সি-তে।

## Phase 18a রিপোর্ট — অডিটর
- সম্পন্ন (কোড): ৮টি শনাক্তকরণ নিয়ম, রিভিউ (নিশ্চিত/বাদ+কারণ/আবার খোলা), সীমা-সেটিং, মাসিক চেকলিস্ট, ৬টি স্বয়ংক্রিয় স্বাস্থ্য-পরীক্ষা, অডিটর রোলের জন্য `accounting.audit` অনুমতি।
- যাচাই: DB ৭৩৬ পাস, ইঞ্জিন ২০৭ + ruff, মোবাইল ২৬, `AuditTextTest` ৬, mutation ৪০/৪০।
- অযাচাইকৃত: `php artisan migrate`/`roles:sync`, Blade স্ক্রিন, Pest ফিচার টেস্ট, বাস্তব ডাটায় ফাইন্ডিংয়ের মান (অতি-সতর্ক বা অতি-শিথিল কি না — সীমা বদলে ঠিক করা যায়)।
- সীমা: নিয়মগুলো নির্ধারিত (AI নয়) — বাংলা ব্যাখ্যা AI দিয়ে ১৮ডি-তে; ফাইন্ডিং থেকে সরাসরি সংশোধনের ব্যবস্থা নেই (ডকুমেন্টে গিয়ে ভয়েড/রিভার্সাল করতে হয়); বিক্রয়ের ডুপ্লিকেট শুধু একই পক্ষ+অঙ্ক+কাছাকাছি তারিখ দিয়ে ধরা হয়; অডিটর একাউন্টে শুধু দেখা ও রিভিউ — অডিটর-সীমিত-মেয়াদি লিংক এখনো নেই।

## Phase 17c রিপোর্ট — কর ক্যালেন্ডার
- সম্পন্ন (কোড): নিজের কাজের তালিকা, মেয়াদ-পেরোনো/শীঘ্রই/সামনে/সম্পন্ন অবস্থা, টিক ও টিক-তোলা, শুরুর খসড়া তালিকা, চালু/বন্ধ।
- যাচাই: DB ৭১৪ পাস, ইঞ্জিন ২০৭ + ruff, `TaxCalendarTest` ১০, mutation ১৫/১৫।
- অযাচাইকৃত: `php artisan migrate`, Blade স্ক্রিন, Pest ফিচার টেস্ট, শুরুর তালিকার দিনগুলোর সরকারি সঠিকতা।
- সীমা: শুধু মনে করায় (ইমেইল/পুশ নোটিফিকেশন নেই); টিক হাতে দিতে হয় (মূসক রিটার্ন/জমা নথিভুক্ত হলে নিজে টিক পড়ে না); মুছে ফেলা নেই (বন্ধ করা যায়)।

## Phase 17b রিপোর্ট — উৎসে কর (VDS/TDS) ও AIT
- সম্পন্ন (কোড): হার-নিয়ম (ডাটা), কর-কেটে পেমেন্ট (১–৫ আইটেম), সার্টিফিকেট নম্বর, জমা (challan), AIT অগ্রিম/বাতিল।
- যাচাই: DB ৬৬৬ পাস, ইঞ্জিন ২০৭ + ruff, `TaxItemsTest` ১৬ পাস, mutation পরীক্ষা (বাকি টিকে থাকাগুলো সমতুল্য/ব্যাকস্টপ)।
- অযাচাইকৃত: `php artisan migrate`, Blade স্ক্রিন, Pest ফিচার টেস্ট, বাস্তব NBR হার।
- সীমা: VDS/TDS এখনও মূসক-৯.১ খসড়ায় ঢোকে না; জমা বাতিল নেই; TDS সার্টিফিকেট তৈরি নেই; হার স্বয়ংক্রিয় প্রয়োগ নেই (হেল্পার মাত্র)।

## Phase 17a রিপোর্ট — মূসক (VAT) সেটিং, বই, রিটার্ন খসড়া
**যাচাই হয়েছে:** DB ৬২৬, নতুন pure PHP ২৩ (+ আগের সব pure), php -l, xcheck (পুরনো ৭), secret scan; ডাটাবেস নিয়ম আসল PostgreSQL-এ ও mutation-পরীক্ষিত।
**আপনাকে চালাতে হবে:** `php artisan migrate`; তারপর হাতে-পরীক্ষা (TESTING_GUIDE ১৭এ)।
**অযাচাইকৃত এখানে:** Blade স্ক্রিন, `migrate`; **আসল NBR নিয়ম** — হার (১৫%/৭.৫%/শূন্য শুরুর মান), রিটার্ন মাসিক না ত্রৈমাসিক, ইনপুট-ক্রেডিটের শর্ত (ব্যাংক-পেমেন্ট, ৬ মাস, ৬.৩ চালান) — ওয়েবের সারাংশ থেকে নেওয়া ও পরস্পরবিরোধী, তাই **হিসাবরক্ষকের রিভিউ জরুরি**।
**সীমা:** রিটার্ন NBR-এর ফরম/ফরম্যাটে নয়, কাজের খসড়া; VDS/TDS/AIT, ক্যালেন্ডার, সম্পূরক শুল্ক এখনো নেই।

## Phase 16d রিপোর্ট — AI বিল-স্ক্যান
**যাচাই হয়েছে:** DB ৫৯৭, engine ২০৭ (ruff পরিষ্কার), mobile, package ৩০, নতুন pure PHP ৩২, php -l, xcheck (পুরনো ৭), JS, secret scan; ডাটাবেস নিয়ম আসল PostgreSQL-এ ও mutation-পরীক্ষিত। (কয়েকটি পুরনো pure টেস্ট Laravel framework ছাড়া চলে না — পরিবর্তনের আগেও একই।)
**আপনাকে চালাতে হবে:** `pip install -e .` (engine-এ নতুন `pypdf`, `pypdfium2`, `pillow`) ও engine রিস্টার্ট; `php artisan migrate`; অ্যাডমিনে অন্তত একটি মডেলে **`vision`** ক্ষমতা ও প্ল্যানে অ্যাক্সেস; হাতে-পরীক্ষা (TESTING_GUIDE ১৬ডি)।
**অযাচাইকৃত এখানে:** আসল ভিশন মডেল কেমন পড়ে (বাংলা/হাতের লেখা/ঝাপসা), Blade স্ক্রিন, `migrate`, আসল ফাইল-আপলোড/ডিস্ক, Pest feature টেস্ট।
**সীমা:** ফাইল ≤৫MB; PDF ৩ পাতা পর্যন্ত ছবি; একই ফাইল একবারই স্ক্যান; AI-র খসড়া ভুল হতে পারে — পোস্ট সবসময় মানুষের নিশ্চিতকরণে।

## Phase 16c রিপোর্ট — অর্ডার/কুরিয়ার থেকে স্বয়ংক্রিয় পোস্টিং
**যাচাই হয়েছে:** DB ৫৭১, engine ১৪৭, mobile ২৬, package ৩০, pure PHP, php -l, xcheck (পুরনো ৭), JS, secret scan।
**আপনাকে চালাতে হবে:** `php artisan migrate`; scheduler (cron `schedule:run`) চালু থাকলে প্রতি ১০ মিনিটে নিজে চলে; হাতে-পরীক্ষা (TESTING_GUIDE ১৬সি)।
**অযাচাইকৃত এখানে:** Blade স্ক্রিন, `migrate`, scheduler/কমান্ডের আসল চালানো (SQL-টা টেস্টে মেলানো), আসল কুরিয়ার-delivered ওয়েবহুক/সিঙ্কের পর পোস্টিং, এবং হিসাবরক্ষকের চোখে নীতি (ডেলিভারিতে বিক্রি স্বীকৃতি, ছাড় বিক্রি থেকে বাদ, ডেলিভারি চার্জ আলাদা আয়)।
**সীমা:** চালুর আগের অর্ডার ওঠে না; খরচ হাতে বসানো না থাকলে COGS ওঠে না; লক-করা মাসে ডেলিভারির তারিখের এন্ট্রি আটকে "পোস্ট বাকি"-তে থাকে।

## Phase 16b রিপোর্ট — স্থায়ী সম্পদ ও অবচয়, ইনভেন্টরি মূল্যায়ন
**যাচাই হয়েছে:** DB ৫২৭, engine ১৪৭, mobile ২৬, package ৩০, pure PHP (AccText, AccReport, BankCsv), php -l, xcheck (পুরনো ৭), JS, secret scan; ডাটাবেস নিয়ম আসল PostgreSQL-এ ও mutation-পরীক্ষিত।
**আপনাকে চালাতে হবে:** `php artisan migrate`; তারপর হাতে পরীক্ষা (TESTING_GUIDE ১৬বি)।
**অযাচাইকৃত এখানে:** Blade স্ক্রিন ব্রাউজারে, Pest feature, `migrate`; **হিসাবরক্ষকের চোখে অবচয়ের নীতি** (পুরো-মাস-কেনার-মাসে, বিক্রির-মাসে-নেই, সরলরৈখিক) — আপনার/হিসাবরক্ষকের নীতির সাথে না মিললে জানান; কর-অবচয় (ITA-র হার) এটা নয়।
**সীমা:** স্টকের একক খরচ হাতে; অবচয় বাতিলের সুবিধা নেই।

## Phase 16a রিপোর্ট — স্টেটমেন্ট ইমপোর্ট ও রিকনসিলিয়েশন
**যাচাই হয়েছে:** DB ৫০৫, engine ১৪৭, mobile ২৬, package ৩০, pure PHP (AccText, AccReport, BankCsv), php -l, xcheck (পুরনো ৭), JS, secret scan; ডাটাবেস নিয়ম আসল PostgreSQL-এ ও mutation-পরীক্ষিত।
**আপনাকে চালাতে হবে:** `php artisan migrate`; তারপর **আপনার আসল ব্যাংক/bKash/Nagad CSV দিয়ে প্রিভিউ** — কলাম ও +/− ঠিক আছে কি না দেখুন (সবচেয়ে বড় অনিশ্চয়তা এটাই)।
**অযাচাইকৃত এখানে:** আসল স্টেটমেন্ট ফরম্যাট, Blade স্ক্রিন ব্রাউজারে, Pest feature, `migrate`, অপলোড (ক্যাশ ড্রাইভার সহ)।
**সীমা:** শুধু CSV; ১ লাইন = ১ জার্নাল লাইন; ফাইল ২ MB / ৫০০০ লাইন।

## Phase 15c রিপোর্ট — লাভ-ক্ষতি, ব্যালান্স শিট, ক্যাশ ফ্লো (Phase 15 সম্পূর্ণ)
**যাচাই হয়েছে:** DB ৪৯৪, engine ১৪৭, mobile ২৬, package ৩০, pure PHP (AccText ৪, AccReport ৫), php -l, xcheck (পুরনো ৭), JS, secret scan। Phase 15 acceptance: property-based balance invariant (১৫এ), locked-period (১৫এ/বি), report total tests (১৫সি — তিন রিপোর্ট পরস্পর ও ক্যাশ বুকের সাথে মেলে)।
**আপনাকে চালাতে হবে:** `php artisan migrate`; তারপর TESTING_GUIDE-এর ১৫সি ধাপ, আসল হিসাবরক্ষকের চোখে রিপোর্টের বিন্যাস দেখা।
**অযাচাইকৃত এখানে:** Blade স্ক্রিন ব্রাউজারে, Pest feature টেস্ট, `migrate` নিজে।
**সীমা:** অ্যাকাউন্ট-গ্রুপিং কোড-প্রিফিক্সে (৫=পণ্য-ব্যয়, ৭=অ-পরিচালন); ক্যাশ ফ্লো ডাইরেক্ট পদ্ধতি; বছর-শেষ ক্লোজিং নেই (মুনাফা "এখন পর্যন্ত" হিসেবে দেখায়)।

## Phase 15b রিপোর্ট — ইনভয়েস, বিল, খরচ, পেমেন্ট, AR/AP, ক্যাশ-ব্যাংক বুক
**যাচাই হয়েছে:** DB ৪৮৬, engine ১৪৭, mobile ২৬, package/cross ৩০, pure PHP AccText ৪, php -l, xcheck (পুরনো ৭টি false positive), JS syntax, secret scan। প্রতিটি নিয়ম (লক, অতিরিক্ত পেমেন্ট, ভুল অ্যাকাউন্ট ধরন, ডকুমেন্ট বাতিল, বয়স-বাকেট প্রান্ত) আসল PostgreSQL-এ টেস্ট ও mutation-পরীক্ষিত; property টেস্টে যেকোনো পরিচালনার পর "প্রাপ্য/দেনা ব্যালান্স = খোলা ডকুমেন্টের বাকি"।
**আপনাকে চালাতে হবে:** `php artisan migrate`, `php artisan roles:sync` (নতুন অনুমতি নেই, ১৫এ-রই), তারপর হাতে পরীক্ষা (TESTING_GUIDE)।
**অযাচাইকৃত এখানে:** Blade স্ক্রিন ব্রাউজারে, Pest feature টেস্ট, `migrate` নিজে।
**সীমা:** ভ্যাট হাতে; PDF/প্রিন্ট নেই; ডকুমেন্ট এডিট নেই (বাতিল করে নতুন); শুধু BDT।

## Phase 15a রিপোর্ট — হিসাবের কোর
**যাচাই হয়েছে (এখানে):** DB ৪৭২ (আসল PG16): অসমান/এক-লাইন/দুই-পাশ/ঋণাত্মক/বিশাল অঙ্ক/অন্য ওয়ার্কস্পেসের/বন্ধ/শিরোনাম অ্যাকাউন্ট সবই প্রত্যাখ্যাত ও কিছু লেখা হয় না; টেবিল-মালিক দিয়েও অসমান এন্ট্রি কমিট হয় না; UPDATE/DELETE সবার জন্য বন্ধ; রিভার্সাল একবার, উল্টানো যায় না; লক শুধু সামনে, আনলক শুধু পিছনে+কারণ+লগ; আইসোলেশন; **property-based** ব্যালান্স ইনভ্যারিয়েন্ট; ট্রায়াল ব্যালান্স/লেজারের সংখ্যা; PHP-র প্রতিটি SQL; down→up রাউন্ডট্রিপ। engine ১৪৭, প্যাকেজ/ক্রস ৩০, pure PHP সব পাস।
**অযাচাইকৃত:** `php artisan migrate`/`roles:sync`, Blade স্ক্রিন ব্রাউজারে (বিশেষ করে ৬-লাইনের এন্ট্রি ফর্ম), Pest ফিচার টেস্ট নেই (লেখা হয়নি)। **হিসাবের নিয়ম আপনার হিসাবরক্ষক/CA দিয়ে একবার মিলিয়ে নিন** — চার্ট অফ অ্যাকাউন্টস আমার প্রস্তাবিত ছোট-ব্যবসার টেমপ্লেট, কোনো আনুষ্ঠানিক মান নয়।
**জ্ঞাত সীমা:** শুধু BDT; একটি এন্ট্রি ৬০ লাইনের বেশি ফর্মে নয়; বছর-শেষ ক্লোজিং/রিটেইনড আর্নিংস স্থানান্তর স্বয়ংক্রিয় নয়; এন্ট্রির সঙ্গে ফাইল সংযুক্তি নেই; অ্যাকাউন্ট মুছা/মার্জ নেই (শুধু বন্ধ); ইনভয়েস/বিল/রিপোর্ট ১৫বি-১৫সি-তে।

## Phase 14c রিপোর্ট — কুরিয়ার + দৈনিক রিপোর্ট (Phase 14 শেষ)
**যাচাই হয়েছে (এখানে):** DB ৪৬১ (আসল PG16: বুকিং-ধাপ, দুইবার বুক অসম্ভব, শুধু নিশ্চিত/প্যাক, ফেরত/বাতিলে স্টক একবার, শেষ-পার্সেল নড়ে না, হাতে বদলানো অর্ডার জোর করে নয়, ২০ ব্যর্থ চেক → নজর, জরুরি স্টপে বুকিং বন্ধ কিন্তু বাস্তব স্ট্যাটাস লেখা চলে, কী পড়া যায় না, আইসোলেশন, সরাসরি লেখা নিষেধ, রিপোর্টের সংখ্যা, ঢাকা-দিনের সীমানা, PHP-র প্রতিটি SQL); engine ১৪৭; pure PHP নতুন ২২ (অ্যাডাপ্টার অনুরোধের গড়ন মক HTTP-তে, 4xx/5xx/টাইমআউট পার্থক্য, কী-scrub, স্ট্যাটাস ম্যাপিং, সার্ভিসের ক্রম, রিপোর্ট টেক্সট); প্যাকেজ/ক্রস ৩০; মোবাইল ২৬; ~২০ mutation ধরা পড়েছে।
**অযাচাইকৃত — সবচেয়ে গুরুত্বপূর্ণ:** **আসল কুরিয়ার API।** Steadfast/Pathao/RedX-এর অফিসিয়াল নথি এই পরিবেশ থেকে খোলা যায়নি; URL, হেডার, ফিল্ডের নাম ও স্ট্যাটাস-শব্দ প্রচলিত রূপ ধরে লেখা। কোনো একটি ভুল হলে বুকিং "কুরিয়ার প্রত্যাখ্যান" বা "নজর দরকার" দেখাবে — নীরবে ভুল অর্ডার নড়বে না। তাছাড়া `migrate`/`roles:sync`, Pest, Blade স্ক্রিন, আসল কিউ/শিডিউলার।
**জ্ঞাত সীমা:** Pathao city/zone/area পাঠানো হয় না; RedX এলাকা ঠিকানার লেখা মিলিয়ে ধরে (না মিললে কর্মী area id দেন); কুরিয়ার "Return"/"Cancelled" বললেই অর্ডার returned ও স্টক ফেরত (মাল পথে থাকলেও); আংশিক ডেলিভারি স্বয়ংক্রিয় নয় (নজর দরকার); পোলিং ১৫ মিনিটে একবার; লেবেল প্রিন্ট/ওয়েবহুক/COD-টাকা মেলানো/রিপোর্ট পাঠানো নেই; ফেক-স্কোরে কুরিয়ারের সফলতার হার সরাসরি নেই (ফোনের অর্ডার-ইতিহাসের মধ্য দিয়ে পরোক্ষে আছে)।

## Phase 14b রিপোর্ট — চ্যাটে অর্ডার
**যাচাই হয়েছে (এখানে):** DB ৪৪১; engine ১৪৭; pure PHP `OrderFlowTest` ১৭ (সারাংশ+আপসেল, "হ্যাঁ"-তে অর্ডার ও মডেল না-ডাকা, হ্যাঁ-র আগে/পরিবর্তনের পরে/দাম নড়লে অর্ডার হয় না, অনুপস্থিত তথ্য, ভ্যারিয়েন্ট প্রশ্ন, স্টক নেই, ভুয়া variant_id, ভুল ফোন, জাঙ্ক আউটপুট/প্রোভাইডার ব্যর্থতা/ক্রেডিট শেষ, বাতিল, টার্ন সীমা, DB-র প্রতিটি প্রত্যাখ্যান, SupportAgent-এর সুইচ/মোড/অভিযোগ-অগ্রাধিকার); আসল PG-তে নতুন SQL; ১৫ mutation ধরা পড়েছে।
**অযাচাইকৃত — সবচেয়ে গুরুত্বপূর্ণ:** **আসল LLM কতটা নির্ভুলভাবে বাংলা/Banglish থেকে পণ্য-সংখ্যা-ঠিকানা বের করে** (এখানে মডেল নকল)। কোড ভুল তথ্যে অর্ডার করতে দেয় না (সারাংশে গ্রাহক সব দেখে "হ্যাঁ" বলে), কিন্তু বারবার ভুল বুঝলে গ্রাহক বিরক্ত হবেন — আগে নিজে ১০–২০টি আসল কথোপকথনে পরীক্ষা করুন। এছাড়া `migrate`, Pest, Blade সেটিংস স্ক্রিন, আসল চ্যানেলে এন্ড-টু-এন্ড।
**জ্ঞাত সীমা:** অর্ডারের বার্তা শুধু বাংলা হরফে; জেলা শুধু "ঢাকা" শব্দ দেখে ভেতর/বাইরে ধরে (গ্রাহক সারাংশে ভুল দেখলে বদলাতে পারে); একই চ্যাটে দুটি আলাদা অর্ডার একসাথে নয়; অর্ডারের পর চ্যাট মানুষের হাতে (AI আর উত্তর দেয় না যতক্ষণ না ফেরত দেওয়া হয়); কিউপন/ছাড় AI দিতে পারে না।

## Phase 14a রিপোর্ট — অর্ডার কোর
**যাচাই হয়েছে (এখানে):** DB ৪৩৬ (আসল PG16: live-দাম, জাল দাম উপেক্ষা, ওভারসেল অসম্ভব ও ব্যর্থতায় সব হোল্ড ফেরত, ছাড়-সীমা, ফেক-স্কোরের প্রতিটি নিয়ম, স্ট্যাটাস পথ ও স্টক ঠিক একবার ফেরত, জরুরি স্টপ, আইসোলেশন, সরাসরি লেখা নিষেধ, `PgOrderRepo`-র প্রতিটি SQL, down→up রাউন্ডট্রিপ); engine ১৪৭; pure PHP ৫ নতুন; প্যাকেজ/ক্রস ৩০; ৯ mutation ধরা পড়েছে।
**অযাচাইকৃত:** `php artisan migrate`/`roles:sync`, Pest (নতুন ৩), Blade স্ক্রিন ব্রাউজারে (বিশেষ করে নতুন-অর্ডার ফর্ম), আসল ক্যাটালগ-ডেটায় পুরো প্রবাহ।
**জ্ঞাত সীমা:** শুধু COD; ঢাকার ভেতর/বাইরে দুই ধাপের ডেলিভারি চার্জ (জেলাভিত্তিক নয়); ফেক-স্কোর সরল নিয়ম — কুরিয়ারের সাফল্যের হার/বাইরের ব্ল্যাকলিস্ট নেই (14c-র কুরিয়ার-ডেটা এলে বাড়ানো যায়); কিউপন/ফ্রি-ডেলিভারি সীমা নেই; আংশিক বাতিল/এক্সচেঞ্জ নেই; ফোনের অ্যাপে অর্ডার নেই।

## Phase 13c রিপোর্ট — AI সাপোর্ট এজেন্ট (Phase 13 সম্পূর্ণ, কমেন্ট-রিপ্লাই বাদে)
**যাচাই হয়েছে (এখানে):** DB ৪২০ (আসল PG16: `agent_off` খসড়া/বন্ধ মোডে, ঘণ্টায় ১২ সীমা, মোড CHECK, ওয়ার্কস্পেস-আইসোলেশন, খসড়া upsert, `PgAgentRepo`-র প্রতিটি SQL আসল PG-তে — প্রকাশিত/অপ্রকাশিত, স্টক, অন্য ওয়ার্কস্পেস; 13c→13b→13 down তারপর আবার up রাউন্ডট্রিপ); engine ১৪৭; pure PHP `SupportAgentTest` ২২ (trigger, গার্ড, Knowledge, প্রম্পট-বেড়া, রানারের প্রতিটি পথ: sent/drafted/skipped/handoff, ক্রেডিট প্রতি ব্যর্থতায় ফেরত, মডেল না ডেকে হস্তান্তর, hook); প্যাকেজ/ক্রস ৩০; মোবাইল ২৬; php -l সব; সিক্রেট স্ক্যান; ১১ mutation ধরা পড়েছে।
**অযাচাইকৃত — গুরুত্বপূর্ণ:** (১) **আসল LLM-এর উত্তর কেমন হয়** — বিশেষ করে Banglish ও বাংলা; গার্ড দাম/লিংক/শতাংশ আটকায়, কিন্তু ভুল তথ্য (যেমন ভুল রঙ বলা) সব ধরতে পারে না; তাই আগে **খসড়া** মোডে চালান। (২) `php artisan migrate` (নতুন মাইগ্রেশন), `roles:sync`, Pest `InboxTest`-এর নতুন ২টি, Blade স্ক্রিন, আসল কিউ ওয়ার্কার/`QUEUE_CONNECTION=sync`-এ নেস্টেড টেন্যান্ট কনটেক্সট (কোড নেস্টিং সামলায়, কিন্তু চালানো হয়নি)। (৩) আসল Meta/Telegram/উইজেটে এন্ড-টু-এন্ড।
**জ্ঞাত সীমা:** Banglish কীওয়ার্ড তালিকা একটি ন্যূনতম ভিত্তি (সব বানান ধরে না); Banglish প্রশ্নে বাংলা-হরফের পণ্যনাম খোঁজা মিস করতে পারে (তখন নমুনা দেখে মডেল সম্ভবত হস্তান্তর করবে); একটি চ্যাটে একসাথে অনেক বার্তা এলে শেষটির জন্যই উত্তর হয়; অর্ডার নেওয়া নেই; কমেন্ট-রিপ্লাই নেই; AI ছবি দেখে না; উত্তরের কোয়ালিটি/খরচ ড্যাশবোর্ড নেই।

## Phase 13b রিপোর্ট — Messenger / Instagram / WhatsApp / Telegram
**যাচাই হয়েছে (এখানে):** DB ৪১০; সই-যাচাই (ভুল/অনুপস্থিত/বদলানো বডি), payload parser, webhook ক্রম, অ্যাডাপ্টারের অনুরোধের গড়ন, dispatcher-এর সব সিদ্ধান্ত (জরুরি স্টপ, ২৪-ঘণ্টা, retry সীমা, আংশিক-পাঠানো), টোকেন ত্রুটি বার্তায় ফাঁস হয় না; ৭ mutation ধরা পড়েছে।
**অযাচাইকৃত — সবচেয়ে গুরুত্বপূর্ণ:** **কোনো আসল Meta/Telegram অ্যাকাউন্টের বিরুদ্ধে একবারও চালানো হয়নি।** Graph API-র অনুরোধ/উত্তরের গড়ন ও সংস্করণ (v21.0), webhook payload-এর গড়ন ডকুমেন্টেশন থেকে; Meta App Review/Business Verification, Instagram ও WhatsApp-এর অনুমতি/টেমপ্লেট নীতি, Telegram `setWebhook` (https পাবলিক ঠিকানা লাগে) — সবই আসল অ্যাকাউন্টে পরীক্ষা বাকি। `migrate`, Pest, Blade স্ক্রিন।
**জ্ঞাত সীমা:** কমেন্ট-রিপ্লাই নেই; ছবি/ফাইল শুধু placeholder (ডাউনলোড/পাঠানো নেই); WhatsApp ২৪ ঘণ্টার বাইরে টেমপ্লেট বার্তা নেই (কঠোরভাবে বন্ধ); Messenger-এর human-agent ৭-দিনের ট্যাগ নেই; পাঠানোর ব্যর্থতা কর্মী শুধু বার্তার পাশে "ব্যর্থ" দেখে (পুনঃপ্রেরণ বোতাম নেই); webhook অনুরোধ সিঙ্ক্রোনাসভাবে প্রসেস হয় (কিউ নেই) — বড় ভিড়ে লাগবে।

## Phase 13a রিপোর্ট — ইনবক্স কোর + ওয়েব উইজেট
**যাচাই হয়েছে (এখানে):** DB ৪০১ (আসল PG16; ২৪-ঘণ্টা নিয়ম, জরুরি স্টপ, human/bot হস্তান্তর, টোকেন যাচাই, ফ্লাড সীমা, আইসোলেশন, লেখা-নিষেধ); উইজেট সার্ভিস ৯ টেস্ট (origin, টোকেন, ডুপ্লিকেট, ৪২৯); PHP-SQL আসল PG-তে; ৬ mutation ধরা পড়েছে।
**অযাচাইকৃত:** `php artisan migrate`/`roles:sync`, Pest `InboxTest`, Blade স্ক্রিন ও **উইজেট JS আসল ব্রাউজারে/আসল সাইটে** (শুধু সিনট্যাক্স-চেক হয়েছে), CORS preflight আসল ব্রাউজারে।
**জ্ঞাত সীমা:** শুধু ওয়েব উইজেট (বাকি চ্যানেল 13b); AI উত্তর 13c-তে যুক্ত হয়েছে; সংযুক্তি/ছবি নেই; স্টাফ স্ক্রিনে নতুন বার্তা এলে ৮ সেকেন্ডে পেজ রিলোড (লাইভ-স্ট্রিম নয়); Messenger-এর ৭-দিনের human-agent ট্যাগ ধরা হয়নি (কঠোর ২৪ ঘণ্টা); ভিজিটরের নাম/ইমেইল সংগ্রহ নেই।

## Phase 12c রিপোর্ট — JARVIS Connector
**যাচাই হয়েছে (এখানে):** প্যাকেজ ২৩ টেস্ট (সই/replay/stale/ভুল সিক্রেট/বডি টেম্পার; sqlite-এ সত্যিকারের SQL: তৈরি, আপডেট, ভ্যারিয়েন্ট, স্টক, পেজিং, soft delete); JARVIS adapter ↔ প্যাকেজ cross-test (পণ্য তৈরি→পড়া→আপডেট→স্টক→পেজ, ভুল সিক্রেট ও পুরনো ঘড়ি প্রত্যাখ্যান)।
**অযাচাইকৃত — গুরুত্বপূর্ণ:** প্যাকেজ কখনো **আসল Laravel অ্যাপের ভেতরে** চলেনি (service provider auto-discovery, route middleware, `Cache`, `DB` facade, artisan কমান্ড); আসল MySQL স্কিমা; `php artisan migrate`/Pest। প্রথম কাজ: টেস্ট শপে বসিয়ে `jarvis-connector:check`।
**জ্ঞাত সীমা:** শুধু পণ্য/স্টক (অর্ডার Phase 14); ছবি শপে লেখা হয় না; ক্যাটাগরি/ট্যাগ শপে পাঠানো হয় না; ডিলিট সিঙ্ক নেই; ভ্যারিয়েন্টে অপশন JSON-অবজেক্ট কলাম লাগে।

## Phase 12b রিপোর্ট — WooCommerce + Shopify
**যাচাই হয়েছে (এখানে):** DB ৩৮২ (আসল PG16; গোপন কী ফাংশন-ছাড়া পড়া যায় না, একসাথে এক রান, stale রান পরিষ্কার, আইসোলেশন, PgStoreRepo-র প্রতিটি SQL), অ্যাডাপ্টার ও সিঙ্ক লজিক নকল HTTP/নকল দোকানের বিরুদ্ধে ২৩ চেক, ঠিকানা-গার্ড, engine ১৪৭, মোবাইল ২৬, ৫টি mutation।
**অযাচাইকৃত — সবচেয়ে গুরুত্বপূর্ণ:** **কোনো আসল WooCommerce/Shopify দোকানের বিরুদ্ধে একবারও চালানো হয়নি।** অ্যাডাপ্টারের অনুরোধ/উত্তরের আকার আমি ডকুমেন্টেশন থেকে লিখেছি; আসল দোকান ছোট ভিন্নতা (প্লাগইন, ভাষা, ফিল্ড) দেখালে ঠিক করতে হতে পারে। এছাড়া `migrate`, Pest, Blade ব্রাউজারে, Shopify API সংস্করণ আজও সমর্থিত কিনা।
**জ্ঞাত সীমা:** Woo-তে ক্যাটাগরি/ট্যাগ পড়া হয় কিন্তু পাঠানো হয় না (Woo আইডি চায়); প্রতি variable পণ্যে প্রথম ১০০ variation; দোকানে সরানো/মুছে ফেলা পণ্য আমাদের কাছে থাকে (মুছে ফেলা সিঙ্ক হয় না); আমাদের আর্কাইভ দোকানে যায় না; দোকান সংযোগ মোছার বোতাম নেই (শুধু বন্ধ রাখা); ওয়েবহুক নেই (নিয়মিত পোলিং); অর্ডার সিঙ্ক Phase 14।
