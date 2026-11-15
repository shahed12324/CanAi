# JARVIS ONE — Project Handover

**প্রতিটা নতুন সেশন এই ফাইল আর `DEVELOPMENT_STATUS.md` পড়ে শুরু হবে।**

**Last updated:** 2026-11-02 · **Current phase:** ০ সম্পন্ন, অনুমোদনের অপেক্ষায় · **Next:** ফেজ ১

---

## 1. Current State

| বিষয় | অবস্থা |
|---|---|
| সম্পন্ন | ফেজ ০: ১২টা ডকুমেন্ট, ১২টা ডায়াগ্রাম, প্রযুক্তি সিদ্ধান্ত, ADR, ঝুঁকি |
| কোড | ফেজ ১ আংশিক: `apps/ai-engine` (টেস্টেড), `apps/web-overlay`, `infra/`; `apps/web` এখনো বানানো হয়নি |
| Git | লোকাল রিপো, `main` ব্রাঞ্চ, ফেজ ০ কমিট; রিমোট এখনো নেই |
| ডেলিভারি | `jarvis-one-phase-00.zip` |
| ফেজ ২ | সব কোড লেখা (২এ ২বি ২সি); DB স্তর যাচাইকৃত; Laravel স্তর মালিকের মেশিনে যাচাই বাকি |
| অপেক্ষমান | owner-এর ফেজ ০ অনুমোদন ও Open Decisions (DEVELOPMENT_STATUS) |

## 2. Key Architecture Decisions (দ্রুত মনে রাখার জন্য)

- Web: Laravel 13 + PHP 8.4 + Livewire 4 + Tailwind 4 (Blade); **আলাদা React web app নিষেধ**।
- Desktop: Tauri 2 + React + TS; Mobile: Expo + React Native; AI: Python 3.13 + FastAPI (শুধু internal)।
- DB: PostgreSQL 18 + pgvector; single DB, `tenant_id` + global scope + **RLS**।
- Laravel authoritative (auth, tenant, plan, credit); Python কখনো access/balance বদলায় না।
- Credit ledger append-only, reserve → settle, idempotency key।
- Laravel ↔ Python: HMAC + 60s JWT + nonce।
- Accounting হিসাব deterministic PHP, AI শুধু খসড়া।
- Official API only; WhatsApp cold outreach নয়।
- VPS-only (cPanel নয়)।
- ব্র্যান্ড নাম কনফিগ থেকে (নামের আইনি যাচাই বাকি)।

বিস্তারিত: `ARCHITECTURE.md` §1, §5।

## 3. Next Steps (ফেজ ১ পরিকল্পনা)

অনুমোদনের পর:

1. Packagist/npm/PyPI থেকে সঠিক patch সংস্করণ নিশ্চিত করা।
2. Monorepo ফোল্ডার কাঠামো তৈরি।
3. `apps/laravel`: Laravel 13 ইনস্টল; Livewire 4, Tailwind 4, Fortify, Sanctum, spatie/permission, Horizon, Reverb, Pest, Larastan, Pint; PostgreSQL কনফিগ; `app/Domain/*` কঙ্কাল; তিনটা base layout (admin, customer, public); ডিজাইন টোকেন; প্রথম Blade কম্পোনেন্ট সেট; `config/brand.php`।
4. `apps/ai-engine`: FastAPI কঙ্কাল, settings, `/health`, HMAC + JWT verify middleware, pytest।
5. Laravel → Python signed health call + টেস্ট।
6. `docker-compose.yml` (nginx, php-fpm, horizon, reverb, scheduler, postgres+pgvector, redis, ai-engine, mailpit, minio), Dockerfile, `.env.example`, Makefile।
7. GitHub Actions CI (lint, static analysis, Pest, pytest, secret scan, audit)।
8. Dev seeder (`@example.test`, শুধু local)।
9. টেস্ট চালানো, আসল রিপোর্ট, ডকুমেন্ট আপডেট, কমিট, ZIP।

**Acceptance:** Laravel ও Python টেস্ট পাস; signed health call কাজ করে; compose কনফিগ valid। এই পরিবেশে Docker daemon চালানো সীমিত হলে `docker compose up` owner-এর মেশিনে ম্যানুয়াল টেস্ট হিসেবে রিপোর্টে থাকবে।

## 4. Commands Executed (ফেজ ০)

- রেজিস্ট্রি থেকে সংস্করণ যাচাই (`npm view`, `pip index versions`); Packagist এই পরিবেশ থেকে পৌঁছানো যায়নি
- `git init -b main`
- Mermaid ডায়াগ্রাম পার্স যাচাই, secret স্ক্যান, `zip`

## 5. Known Errors

নেই।

---

## 6. Working Prompts (কাজের নিয়ম)

নতুন সেশনে owner এগুলো ব্যবহার করতে পারেন; Claude-ও এই নিয়মে চলবে।

### 6.1 Context Recovery (নতুন সেশন শুরু)

> JARVIS ONE প্রজেক্ট চালিয়ে যাও। আগে `PROJECT_HANDOVER.md` আর `DEVELOPMENT_STATUS.md` পড়ো, তারপর বর্তমান ফেজ, শেষ কমিট আর বাকি কাজ সংক্ষেপে বলো। কোনো কোড লেখার আগে বিদ্যমান কোড দেখে নাও। অনুমোদন ছাড়া পরের ফেজে যাবে না।

### 6.2 Phase Start

> ফেজ <N> শুরু করো। লক্ষ্য, তৈরি/বদলানো ফাইলের তালিকা, DB/API/UI পরিবর্তন, নিরাপত্তা ঝুঁকি, প্রয়োজনীয় credential আর acceptance criteria আগে লেখো, তারপর বাস্তবায়ন। শেষে টেস্ট চালিয়ে আসল রিপোর্ট, ডকুমেন্ট আপডেট, কমিট আর secret-মুক্ত ZIP দাও, তারপর থামো।

### 6.3 Bug Fixing

> এই বাগটা ঠিক করো: <লক্ষণ, এরর মেসেজ, কীভাবে ঘটে>। আগে বাগটা পুনরুৎপাদন করে এমন একটা ফেইলিং টেস্ট লেখো, তারপর মূল কারণ খুঁজে ন্যূনতম পরিবর্তনে ঠিক করো। টেস্ট বন্ধ বা দুর্বল করবে না। কাজ করা কোড অকারণে নতুন করে লিখবে না। শেষে মূল কারণ, পরিবর্তিত ফাইল আর টেস্ট রেজাল্ট জানাও, CHANGELOG আপডেট করো।

### 6.4 Code Review

> ফেজ <N>-এর কোড রিভিউ করো: tenant isolation (scope, RLS, policy), authorization, secret হ্যান্ডলিং, ইনপুট ভ্যালিডেশন, SSRF/XSS/SQLi, idempotency, এরর হ্যান্ডলিং, টেস্ট কভারেজ, আর কোডের মান। প্রতিটা সমস্যার তীব্রতা (critical/high/medium/low), ফাইল ও লাইন, আর সমাধান দাও। নিজে লেখা কোড বলে নরম হবে না।

### 6.5 Low Context

কনটেক্সট কমে এলে কাজ থামিয়ে এই ফাইল আর `DEVELOPMENT_STATUS.md` আপডেট করতে হবে: বর্তমান ফেজ, সম্পন্ন ও বাকি মডিউল, সিদ্ধান্ত, পরিবর্তিত ফাইল, চালানো কমান্ড, টেস্ট রেজাল্ট, জানা এরর, আর পরের ধাপ।


## ফেজ ৩ হ্যান্ডওভার
- অ্যাডমিন কোড: `app/Admin`, `app/Http/Controllers/Admin`, `app/Http/Middleware/AdminAccess.php`, `routes/admin.php`, `resources/views/admin`, `config/admin_settings.php`।
- নতুন সেটিং যোগ করতে `config/admin_settings.php`-এ এন্ট্রি দিন; নতুন প্ল্যাটফর্ম পারমিশন `PlatformRoles::PERMISSIONS`-এ।
- সব admin লেখা `$this->db()` (admin connection) দিয়ে, `AdminAudit::record` একই ট্রানজ্যাকশনে।


## ফেজ ৪এ হ্যান্ডওভার
- নতুন adapter: `apps/ai-engine/app/providers/` + `registry.py` + `ProviderSpec.AdapterName` + SQL CHECK + টেস্ট।
- credential লেখা/পড়ার একমাত্র পথ: `App\Ai\CredentialVault` + `ProviderGateway` + DB ফাংশন `provider_secret_for_call`। অন্য কোথাও `secret_encrypted` ধরবেন না।


## ফেজ ৫ হ্যান্ডওভার
- ক্রেডিট নড়ানোর একমাত্র পথ: `App\Credits\CreditService` (অ্যাপ লগইন) ও `App\Credits\AdminCredits` (অ্যাডমিন লগইন) → SQL ফাংশন। `wallets`/`credit_*`/`usage_records`-এ সরাসরি INSERT/UPDATE কখনো নয়।
- AI কল ইন্টিগ্রেশন (Phase 6): `reserve()` → provider → `settle()` (usage সহ) বা ব্যর্থ হলে `release()`। প্রতিটি কলে স্থিতিশীল idempotency key (যেমন `chat:<request_id>`)।
- পেমেন্ট ফেজ: verified webhook থেকে `grant('purchase', …, referenceType, referenceId)`; refund একইভাবে।
- SQL স্ট্রিং বদলালে `tests/db/test_credit_service_sql.py` চালান — এটি PHP সোর্স থেকে স্ট্রিং বের করে আসল PostgreSQL-এ চালায়।

## ফেজ ৬ হ্যান্ডওভার
- সম্পূর্ণ: চ্যাট (SSE), ড্যাশবোর্ড v1, প্রোফাইল, admin analytics, ডিপ্লয় কিট। Desktop/Android ইচ্ছাকৃতভাবে বাদ।
- পরবর্তী: ব্যবহারকারীর টেস্ট ফলাফল (VPS ইনস্টল লগ, `migrate`, Pest) পাওয়ার পর Phase 7 (Messaging + Telegram)।
- অমীমাংসিত: আসল provider key দিয়ে টেস্ট; ইনস্টলার/nginx/certbot বাস্তবে অচালিত।

## ফেজ ৭ হ্যান্ডওভার
- সম্পূর্ণ: Telegram gateway (webhook, link, চ্যাট ব্রিজ, ফাইল, অ্যাডমিন বট ম্যানেজার)। WhatsApp/Messenger ইচ্ছাকৃতভাবে পরের ফেজে; `channel_accounts.channel` CHECK তখন বাড়াতে হবে।
- মাইলফলক ১ (web + Telegram) কোডে সম্পূর্ণ; আসল বট ও আসল provider key দিয়ে ইউজারের end-to-end টেস্ট বাকি।
- পরবর্তী: Phase 8 (Website Lead Extractor + CRM v1) — ইউজারের টেস্ট ফলাফলের পর।

## ফেজ ৮এ হ্যান্ডওভার
- সম্পূর্ণ: ক্রলার ইঞ্জিন, লিড কোর টেবিল, গবেষণা জব + UI, লিড তালিকা/বিস্তারিত।
- পরবর্তী ৮বি: পাইপলাইন বোর্ড (drag/stage change + `lead_activities`), নোট, টাস্ক, ট্যাগ, ফলো-আপ, কোম্পানি/কন্টাক্ট ম্যানুয়াল যোগ-সম্পাদনা, লিড CSV import/export, (ঐচ্ছিক) AI সারাংশ ক্রেডিট-সহ।
- ইউজারকে বলা: আসল সাইটে কয়েকটি গবেষণা চালিয়ে ফলাফলের গুণমান (ফোন/ইমেইল ঠিক আছে কিনা) জানাতে।

## ফেজ ৮বি হ্যান্ডওভার
- সম্পূর্ণ: বোর্ড, নোট, টাস্ক, ট্যাগ, ফলো-আপ, CSV আমদানি/রপ্তানি। আপডেটে `php artisan migrate` লাগবে (`2026_11_02_700000_create_crm_tables`)।
- বাকি (ফেজ ৮ থেকে): AI সাইট-সারাংশ (Phase 6 chat-এর reserve→settle ফ্লো ব্যবহার করে), drag-and-drop, হাতে কোম্পানি/কন্টাক্ট। এরপর Phase 9 (পোর্টফোলিও + প্রস্তাব + ইমেইল আউটরিচ)।

## ফেজ ৯এ হ্যান্ডওভার
- সম্পূর্ণ: ব্র্যান্ড কিট, পোর্টফোলিও, ৬টি টেমপ্লেট, প্রস্তাব এডিটর, PDF/DOCX।
- পরবর্তী ৯বি: ইমেইল আউটরিচ (SMTP আগে) — ক্যাম্পেইন, **অনুমোদন ছাড়া পাঠানো যাবে না**, সাপ্রেশন, আনসাবস্ক্রাইব, দৈনিক সীমা/warm-up, SPF/DKIM/DMARC চেকার, ফলো-আপ, bounce/reply, প্রস্তাব অ্যাটাচ। ৯সি: Gmail API ও Microsoft Graph (ইউজারের OAuth key লাগবে)।

## ফেজ ৯বি হ্যান্ডওভার
- সম্পূর্ণ: SMTP আউটরিচ কোর (ক্যাম্পেইন, অনুমোদন-সারি, নিষিদ্ধ তালিকা, আনসাবস্ক্রাইব, দৈনিক সীমা/warm-up, DNS পরীক্ষা, ফলো-আপ ড্রাফট, হাতে reply/bounce চিহ্ন)।
- ৯সি বাকি: Gmail API + Microsoft Graph (OAuth), স্বয়ংক্রিয় reply/bounce শনাক্তকরণ, WhatsApp click-to-chat বোতাম, প্রস্তাব PDF সংযুক্তি।

## প্ল্যানে যোগ (২০টি আয়ের ক্ষেত্র — তালিকা থেকে, চলমান)
- এখন পর্যন্ত মিলিয়ে দেখা: ১, ২, ১৮, ১৯, ২০। ৩–১৭ নম্বর এখনো পাওয়া যায়নি।
- যোগ হয়েছে: এজেন্সি মোড ও ক্লায়েন্ট রিপোর্ট (২৪.৩), ডিজাইন ক্রিয়েটিভ (২২.১), SaaS বিলিং নোট (১০), Research Assistant ও Data Labeling (পোস্ট-লঞ্চ)। এগুলো শুধু প্ল্যানে লেখা হয়েছে, কোড বানানো হয়নি।

## ফেজ ৯সি হ্যান্ডওভার
- সম্পূর্ণ (কোড): Gmail/Outlook OAuth সংযোগ, API দিয়ে পাঠানো, স্বয়ংক্রিয় reply/bounce শনাক্তকরণ।
- আসল প্রদানকারীর সাথে **অযাচাইকৃত** — প্রথম কাজ: TESTING_GUIDE.md-র "Gmail / Outlook" হাতে-টেস্ট।
- বাকি: SMTP-র জন্য IMAP, WhatsApp click-to-chat বোতাম, প্রস্তাব PDF মেইলে সংযুক্তি। এরপর Phase 10 (পেমেন্ট ও সাবস্ক্রিপশন)।

## ফেজ ১০ হ্যান্ডওভার
- সম্পূর্ণ (কোড): টাকার নিয়ম DB-তে (`billing_*` ফাংশন), চার গেটওয়ে অ্যাডাপ্টার + ডেমো, চেকআউট/IPN/ভেরিফাই, কুপন, ইনভয়েস (VAT), রিফান্ড রেকর্ড, গ্রেস/বাতিল/আপগ্রেড/ডাউনগ্রেড, admin বিলিং।
- **অযাচাইকৃত:** আসল গেটওয়ে sandbox। প্রথম কাজ: TESTING_GUIDE.md-র "Billing" হাতে-টেস্ট ১ (ডেমো), তারপর ২।
- অ্যাডাপ্টারে কিছু বদলাতে হলে শুধু `app/Billing/Gateways/<নাম>.php` — `PaymentService` ও DB অপরিবর্তিত থাকে।
- বাকি: স্বয়ংক্রিয় নবায়ন, রিমাইন্ডার ইমেইল, ট্রায়াল UI, API-রিফান্ড, ইনভয়েস PDF (সার্ভার-সাইড)। এরপর Phase 11 (Mobile App) বা আগের বাকি কাজ।

## ফেজ ১১ হ্যান্ডওভার
- সম্পূর্ণ (কোড): মোবাইল API, DB-তে PIN step-up/জরুরি স্টপ/ডিভাইস, push কর্মী, Expo অ্যাপ (`apps/mobile`), CI `mobile` জব, ডক।
- **অযাচাইকৃত:** আসল ফোনে UI, push (FCM credentials), EAS বিল্ড, `migrate`, Pest। প্রথম কাজ: TESTING_GUIDE "Mobile" হাতে-টেস্ট (Expo Go)।
- নতুন ধরনের অনুমোদন (অর্ডার, পেমেন্ট, ওয়ার্কফ্লো) যোগ করতে: `mobile_approvals()`-এ UNION শাখা + `mobile_decide()`-এ শাখা + `ApprovalController`-এর অনুমতি; অ্যাপে কিছু বদলাতে হয় না। নতুন স্বয়ংক্রিয় কাজ (সেন্ডার/ওয়ার্কফ্লো) লিখলে শুরুতে `emergency_active(tenant)` দেখুন — এটাই স্টপের চুক্তি।
- বাকি: চ্যাট স্ট্রিমিং, এজেন্ট/ওয়ার্কফ্লো মনিটর (Phase 21), অ্যাপ লক-স্ক্রিন, অফলাইন, iOS পরীক্ষা, প্ল্যাটফর্ম-অ্যাডমিন হেলথ। Phase 12 শুরু করবেন না যতক্ষণ আপনি না বলছেন।

## ফেজ ১২এ হ্যান্ডওভার
- সম্পূর্ণ (কোড): দেশি ক্যাটালগ (পণ্য, ভ্যারিয়েন্ট, স্টক লেজার, Draft→Approval→Publish, CSV ইমপোর্ট, AI বিবরণ, মোবাইল approval)।
- **অযাচাইকৃত:** `migrate`, Pest, Blade স্ক্রিন ব্রাউজারে, আসল AI মডেল।
- বাকি: **১২বি** WooCommerce/Shopify অ্যাডাপ্টার ও সিঙ্ক (`store_connections.credentials_ct` ইতিমধ্যে আছে — `CredentialVault` দিয়ে এনক্রিপ্ট), **১২সি** JARVIS Connector Laravel প্যাকেজ। সিঙ্কে `catalog_publish`-এর পথ বাইপাস করবেন না; বাইরে থেকে আসা পণ্যও খসড়া হয়ে ঢুকবে। স্টক সিঙ্ক `stock_adjust(..., 'sync')` দিয়ে।
- Phase 14 অর্ডার টেবিলের নাম `store_orders`। Phase 13 শুরু করবেন না যতক্ষণ আপনি না বলছেন।

## ফেজ ১২বি হ্যান্ডওভার
- সম্পূর্ণ (কোড): Woo/Shopify অ্যাডাপ্টার, pull/push/স্টক সিঙ্ক, সংযোগ স্ক্রিন, `catalog:sync`, গোপন-কী ফাংশন, ডক।
- **সবচেয়ে বড় অযাচাইকৃত অংশ: আসল দোকান।** প্রথম কাজ: TESTING_GUIDE "Store সংযোগ টেস্ট" একটি টেস্ট দোকানে। অ্যাডাপ্টারের ভিন্নতা ঠিক করতে হলে ফাইল `app/Catalog/Stores/{Woo,Shopify}Adapter.php`; সিঙ্কের নিয়ম `StoreSync.php`।
- নতুন প্ল্যাটফর্ম (১২সি Connector): `StoreAdapter` ইন্টারফেস ইমপ্লিমেন্ট + `StoreFactory::buildWith` ও `StoreGuard`-এ শাখা + `store_connections.platform` CHECK-এ `jarvis_connector` আগে থেকেই আছে। সিঙ্কের "বাইরে থেকে খসড়া, ভেতর থেকে শুধু অনুমোদিত" নিয়ম ভাঙবেন না।
- বাকি: **১২সি** JARVIS Connector Laravel প্যাকেজ। Phase 13 শুরু করবেন না যতক্ষণ আপনি না বলছেন।

## ফেজ ১২সি হ্যান্ডওভার
- সম্পূর্ণ (কোড): `packages/jarvis-connector` + `ConnectorAdapter`; Phase 12 শেষ। `ConnectorSignature` (JARVIS) ও `Jarvis\Connector\Signature` (প্যাকেজ) একই নিয়ম — একটি বদলালে অন্যটিও, cross-test ধরবে।
- **অযাচাইকৃত:** আসল Laravel শপে চালানো। পরবর্তী: Phase 13 (ব্যবহারকারী বললে)। অর্ডার টেবিলের নাম `store_orders` (Phase 10-এ `orders` আছে)।

## ফেজ ১৩এ হ্যান্ডওভার
- সম্পূর্ণ (কোড): ইনবক্স কোর (DB ফাংশনে নিয়ম), ওয়েব উইজেট, স্টাফ ইনবক্স, চ্যানেল পেজ। **অযাচাইকৃত:** `migrate`, Pest, ব্রাউজারে উইজেট/স্ক্রিন।
- **১৩বি-র জন্য:** বাইরের চ্যানেল আসা বার্তা `inbox_channel_by_key` → `TenantContext::run` → `inbox_contact_ensure` + `inbox_receive` (external_id = প্ল্যাটফর্মের message id, ডুপ্লিকেট রোধ); যাওয়া বার্তা `status='queued'` হিসেবে জমে — dispatcher পাঠিয়ে `inbox_message_status(seq,'sent'|'failed')` ডাকবে। ২৪-ঘণ্টা নিয়ম `inbox_send` নিজেই দেখে। webhook সই (Meta `X-Hub-Signature-256`) যাচাই কন্ট্রোলারে, ডাটাবেসে নয়।
- **১৩সি-র জন্য:** এজেন্ট `inbox_send(conv, NULL, 'agent', ...)` ডাকবে; `mode='human'` হলে DB নিজেই `human_mode` ফেরত দেয়; হস্তান্তরে `inbox_set_mode(conv, NULL, 'human', reason)`। উত্তর শুধু `products.live` (প্রকাশিত কপি) থেকে।

## ফেজ ১৩বি হ্যান্ডওভার
- সম্পূর্ণ (কোড): webhook + সই, অ্যাডাপ্টার, dispatcher, সেটআপ স্ক্রিন। **অযাচাইকৃত:** আসল Meta/Telegram, `migrate`, Pest।
- **১৩সি-র জন্য:** আসা বার্তা এখন `inbox_receive` হয়; AI এজেন্ট এক জায়গায় ঢোকাতে পারে `WebhookService::receive`-এর পর (বা `InboxRepo::receive` যেখানে ডাকা হয়) — `mode='bot'` ও জরুরি স্টপ না থাকলে উত্তর `inbox_send(conv, NULL, 'agent', ...)`; DB নিজেই human_mode/২৪-ঘণ্টা/জরুরি আটকায়, dispatcher পাঠায়। কমেন্ট-রিপ্লাই এখনো বাকি (আলাদা সাব-ধাপ হতে পারে)।

## ফেজ ১৩সি হ্যান্ডওভার
- সম্পূর্ণ (কোড): AI এজেন্ট (`app/Inbox/Agent/*`, জব `RunSupportAgent`, স্ক্রিন `/app/inbox/agent`)। Phase 13 শেষ — শুধু **কমেন্ট-রিপ্লাই** বাকি (ঐচ্ছিক আলাদা ধাপ)। **অযাচাইকৃত:** আসল LLM-এর মান, `migrate`, Pest, আসল কিউ।
- হুক: `WidgetService`/`WebhookService`-এর ঐচ্ছিক `afterReceive(tenant, conv, seq)` — `InboxServiceProvider::agentHook` শুধু চালু চ্যানেলে জব ডিসপ্যাচ করে।
- **Phase 14 (অর্ডার) এর জন্য:** `Triggers`-এ `order_intent` এখন মানুষের কাছে পাঠায়; অর্ডার-ফ্লো এলে সেখানে ঢোকাতে হবে। অর্ডার টেবিলের নাম `store_orders` (billing `orders` আছে)।
- মাইগ্রেশন `2026_11_08_110000` phase13b+13c চালায় — ১৩বি-র আলাদা মাইগ্রেশন নেই।

## ফেজ ১৪এ হ্যান্ডওভার
- সম্পূর্ণ (কোড): অর্ডার কোর (DB নিয়ম + স্ক্রিন)। **অযাচাইকৃত:** `migrate`, Pest, Blade।
- **১৪বি-র জন্য:** এজেন্ট `order_create(..., source='agent', conv, ..., discount_pct)` ডাকবে (user NULL); সীমা DB বলবৎ করে; ফল `ok` হলে অর্ডার `pending` — মানুষ ফোনে নিশ্চিত করে (`needs_review` দেখে)। `PgOrderRepo::create(source: 'agent')` প্রস্তুত। 13c-র `Triggers::order_intent` বর্তমানে মানুষের কাছে পাঠায় — 14b-তে সেখানে অর্ডার-ফ্লো ঢোকাতে হবে।
- **১৪সি-র জন্য:** কুরিয়ার শিপমেন্ট `store_orders` এর `confirmed/packed` অবস্থা থেকে; `order_transition` দিয়েই `shipped/delivered/returned` করবে; ফেক-স্কোরে কুরিয়ার সাফল্যের হার যোগ করা যায় (`order_risk_calc`)।

## ফেজ ১৪বি হ্যান্ডওভার
- সম্পূর্ণ (কোড): চ্যাটে অর্ডার (`app/Inbox/Agent/Orders/*`, `SupportAgent`-এ সুইচ-সংযোগ)। **অযাচাইকৃত:** আসল LLM-এর এক্সট্রাকশন-মান, `migrate`, Pest।
- **১৪সি-র জন্য:** AI-অর্ডার `pending` + `source='agent'`; চ্যাট `human` (reason `order_placed`)। কুরিয়ার শিপমেন্ট `confirmed/packed` থেকে; `order_transition` দিয়েই `shipped/delivered/returned`। দৈনিক রিপোর্টে `store_orders` ও `store_order_events` ব্যবহার করুন।
- নির্ভরতা: `ModelCall` (ক্রেডিট-নিরাপদ মডেল কল) অন্য এজেন্টও ব্যবহার করতে পারে।

## ফেজ ১৪সি হ্যান্ডওভার
- সম্পূর্ণ (কোড): কুরিয়ার অ্যাকাউন্ট, ৩-ধাপ বুকিং, স্ট্যাটাস সিঙ্ক, দৈনিক রিপোর্ট। **Phase 14 শেষ।** পরবর্তী ধাপ আপনি না বললে শুরু হবে না।
- **অযাচাইকৃত — প্রথম কাজ:** আসল কুরিয়ার অ্যাকাউন্টে TESTING_GUIDE "কুরিয়ার টেস্ট"। অ্যাডাপ্টারের ভিন্নতা ঠিক করতে ফাইল `app/Couriers/{Steadfast,Pathao,Redx}Adapter.php` ও শব্দ-ম্যাপিং `CourierStatus.php`।
- নিয়ম যা ভাঙবেন না: বুকিং আগে `shipment_reserve`, তারপর বাইরের কল, তারপর `shipment_booked/fail`; অনিশ্চিত ফলাফলে অটো-রিট্রাই নয়; অর্ডার সরে শুধু `order_transition`-এ; কী শুধু `courier_credentials()`-এ।
- নতুন কুরিয়ার যোগ: `CourierAdapter` ইমপ্লিমেন্ট + `CourierServiceProvider`-এর match + `CourierSecrets::FIELDS` + SQL CHECK-এ নাম + `CourierStatus::map`।

## ফেজ ১৫এ হ্যান্ডওভার
- সম্পূর্ণ (কোড): হিসাবের কোর। **অযাচাইকৃত:** `migrate`, Blade স্ক্রিন, হিসাবরক্ষকের চোখে চার্ট। পরবর্তী: **১৫বি** ইনভয়েস/বিল/খরচ/পেমেন্ট, AR/AP, ক্যাশ-ব্যাংক বুক — সবগুলো **`journal_post`-এর মধ্য দিয়ে** (source = invoice|bill|expense|payment, `source_ref` = ডকুমেন্ট নম্বর)। **১৫সি** P&L/ব্যালান্স শিট/ক্যাশ ফ্লো `acct_trial_balance` ও `accounts.type` থেকে। আপনি না বললে শুরু হবে না।
- যা ভাঙবেন না: লাইন সরাসরি INSERT নয়; এন্ট্রি মোছা/বদলানো নয় (রিভার্সাল); অটো-পোস্টিং `system_key` দিয়ে অ্যাকাউন্ট খুঁজবে (কোড নয় — ব্যবহারকারী কোড বদলাতে পারে); পোস্ট ব্যর্থ হলে মূল কাজ (অর্ডার ইত্যাদি) থামবে না, কিন্তু একটি "পোস্ট বাকি" তালিকায় থাকবে।

## ফেজ ১৫বি হ্যান্ডওভার
- সম্পূর্ণ (কোড): ইনভয়েস/বিল/খরচ/পেমেন্ট, AR/AP বয়স-রিপোর্ট, ক্যাশ-ব্যাংক বুক। **অযাচাইকৃত:** `migrate`, Blade স্ক্রিন। পরবর্তী: **১৫সি** P&L, ব্যালান্স শিট, ক্যাশ ফ্লো — `acct_trial_balance` ও `accounts.type`/`cash_account` থেকে। আপনি না বললে শুরু হবে না।
- যা ভাঙবেন না: ডকুমেন্ট তৈরি/পেমেন্ট/বাতিল সবসময় `acct_*` ফাংশন দিয়ে (জার্নালসহ একই লেনদেনে); অ্যাকাউন্ট `system_key` দিয়ে খোঁজা; ডকুমেন্ট এডিট নয়, বাতিল+নতুন; ভ্যাট হার Phase 17-এ।

## ফেজ ১৫সি হ্যান্ডওভার
- **Phase 15 সম্পূর্ণ (কোড)।** অযাচাইকৃত: `migrate`, Blade স্ক্রিন, হিসাবরক্ষকের চোখে বিন্যাস। পরবর্তী: **Phase 16** (অর্ডার/কুরিয়ার থেকে অটো-পোস্টিং ও OCR) — আপনি না বললে শুরু হবে না।
- যা ভাঙবেন না: রিপোর্ট ফাংশনগুলো শুধু জার্নাল থেকে হিসাব করে (আলাদা মোট সংরক্ষণ নেই); ৫xxx = পণ্য-ব্যয়, ৭xxx = অ-পরিচালন, সম্পদ ≥১৬০০ = বিনিয়োগ, দায় ≥২৬০০/স্বত্ব = অর্থায়ন; নগদ = `accounts.cash_account`।

## ফেজ ১৬এ হ্যান্ডওভার
- সম্পূর্ণ (কোড): স্টেটমেন্ট CSV ইমপোর্ট, ম্যাচিং, নতুন এন্ট্রি, রিকনসিলিয়েশন সারসংক্ষেপ। **অযাচাইকৃত:** আসল ব্যাংক/bKash/Nagad ফরম্যাট, `migrate`, Blade স্ক্রিন। পরবর্তী: **16b** স্থায়ী সম্পদ ও অবচয় + ইনভেন্টরি মূল্যায়ন; **16c** auto-posting (অর্ডার/কুরিয়ার/পেমেন্ট; ব্যর্থ পোস্ট মূল কাজ থামাবে না, "পোস্ট বাকি" তালিকায় থাকবে); **16d** AI বিল-স্ক্যান। আপনি না বললে শুরু হবে না।
- যা ভাঙবেন না: স্টেটমেন্ট লাইন কখনও মোছা/বদলানো হয় না (শুধু status); জার্নালে লিখতে হলে `journal_post`; ডুপ্লিকেট-কী ফর্ম্যাট বদলালে পুরনো ইমপোর্ট আবার ঢুকে যাবে; plpgsql-এ `PERFORM`-এর পর `FOUND` ভরসা নয়।

## ফেজ ১৬বি হ্যান্ডওভার
- সম্পূর্ণ (কোড): স্থায়ী সম্পদ-অবচয়-বিক্রি, ইনভেন্টরি মূল্যায়ন ও খাতা-সমন্বয়। **অযাচাইকৃত:** `migrate`, Blade স্ক্রিন, অবচয়-নীতি হিসাবরক্ষকের সাথে মিলিয়ে নেওয়া। পরবর্তী: **16c** auto-posting (অর্ডার/কুরিয়ার/পেমেন্ট থেকে — ব্যর্থ পোস্ট মূল কাজ থামাবে না, "পোস্ট বাকি" তালিকায় থাকবে); **16d** AI বিল-স্ক্যান। আপনি না বললে শুরু হবে না।
- যা ভাঙবেন না: অবচয় লাইন immutable ও মাসে একবার; মাসের পরিমাণ cumulative-floor সূত্রে (যোগফল ঠিক থাকার শর্ত); বিক্রির মাসে অবচয় নেই; `inv_value_rows`-এ সবসময় স্পষ্ট tenant; plpgsql-এ `PERFORM`-এর পর `FOUND` ভরসা নয়।

## ফেজ ১৬সি হ্যান্ডওভার
- সম্পূর্ণ (কোড): delivered→বিক্রি, returned→উল্টো, কুরিয়ার সেটেলমেন্ট ও পাওনার রিপোর্ট, "পোস্ট বাকি" তালিকা। **অযাচাইকৃত:** migrate, স্ক্রিন, scheduler, হিসাব-নীতি হিসাবরক্ষকের সাথে মেলানো। পরবর্তী: **16d** AI বিল-স্ক্যান — আপনি না বললে শুরু হবে না।
- যা ভাঙবেন না: অর্ডার-পথে শুধু queue-insert (ব্যর্থতা গিলে ফেলা); প্রতি row আলাদা sub-transaction; returns আগে প্রক্রিয়া; পোস্টকরা বিক্রির ফেরত-অপেক্ষমাণ থাকলে সেটেলমেন্ট নয়; `autopost_one`/`courier_receivable_rows` অ্যাপ রোলে খোলা নয়।

## ফেজ ১৬ডি হ্যান্ডওভার
- সম্পূর্ণ (কোড): বিল ছবি/PDF → খসড়া → মানুষের নিশ্চিতকরণ → বিল/খরচ। **অযাচাইকৃত:** আসল ভিশন-আউটপুটের মান, migrate, স্ক্রিন। ফেজ ১৬ এখানে শেষ। পরবর্তী ফেজ (১৭) — আপনি না বললে শুরু হবে না।
- যা ভাঙবেন না: AI কখনো সরাসরি পোস্ট করে না; ডকুমেন্টের লেখা অবিশ্বস্ত ডেটা; প্রতিটি ক্রেডিট reserve-এর ঠিক একটি settle/release (provider-কল হয়ে গেলে settle, না হলে release); একই sha256 জীবিত স্ক্যানে একবার; `bill_scan_post` স্ক্যান-সারি লক করে একবারই ডকুমেন্ট বানায়; ফাইল শুধু প্রাইভেট ডিস্কে ও যাচাই-করা রুটে; ইঞ্জিনের ছবি পুনঃএনকোড (EXIF বাদ) সরাবেন না।

## ফেজ ১৭এ হ্যান্ডওভার
- সম্পূর্ণ (কোড): মূসক সেটিং, তারিখ-ভিত্তিক হার, ক্রয়-বিক্রয় বই, রিটার্ন খসড়া/ফাইল-নথিভুক্তি, CSV। **অযাচাইকৃত:** migrate, স্ক্রিন, আসল NBR নিয়ম (হিসাবরক্ষকের রিভিউ)। পরবর্তী: **17b** VDS/TDS/AIT, **17c** ট্যাক্স ক্যালেন্ডার — আপনি না বললে শুরু হবে না।
- যা ভাঙবেন না: নিয়ম/হার/সময়কাল ডেটা, কোডে হার বসাবেন না; রিটার্নের অঙ্ক সার্ভারে বই থেকে; ফাইল-করা সময় অপরিবর্তনীয়; বইয়ে মূসক `acct_docs.vat_minor` থেকে — আলাদা করে পোস্ট নয়।

## ফেজ ১৭বি হস্তান্তর
উৎসে কর (VDS/TDS/AIT): `app/Vat/{TaxItems,TaxRepo,PgTaxRepo}`, `AccountingTaxController`, `phase17b.up.sql`। পরবর্তী: ১৭সি (কর ক্যালেন্ডার) — ব্যবহারকারী না বলা পর্যন্ত শুরু নয়।

## ফেজ ১৭সি হস্তান্তর
কর ক্যালেন্ডার: `app/Vat/{TaxCalendar,TaxCalRepo,PgTaxCalRepo}`, `AccountingTaxCalController`, `phase17c.up.sql`। ফেজ ১৭ এর কোড সম্পূর্ণ; পরবর্তী ফেজ ১৮ — ব্যবহারকারী না বলা পর্যন্ত শুরু নয়।

## ফেজ ১৮এ হস্তান্তর
অডিটর: `app/Audit/{AuditText,AuditRepo,PgAuditRepo}`, `AccountingAuditController`, `phase18a.up.sql`। ফেজ ১৮ চারভাগে: ১৮এ অডিটর ☑, ১৮বি পে-রোল কাঠামো, ১৮সি পে-স্লিপ ও বেতন ফাইল, ১৮ডি বিশ্লেষক (বাংলা ব্যাখ্যা + পূর্বাভাস)। পরেরটি ব্যবহারকারী না বলা পর্যন্ত শুরু নয়।

## ফেজ ১৮বি হস্তান্তর
পে-রোল: `app/Payroll/{PayrollText,PayrollRepo,PgPayrollRepo}`, `AccountingPayrollController`, `phase18b.up.sql`। পরবর্তী: ১৮সি (পে-স্লিপ + বেতন ফাইল) — ব্যবহারকারী না বলা পর্যন্ত শুরু নয়।

## ফেজ ১৮সি হস্তান্তর
বেতন ফাইল/পে-স্লিপ: `app/Payroll/SalaryFile`, রিপো/কন্ট্রোলারে `payDetails`/`savePayDetails`/`slips`/`file`, `phase18c.up.sql`। পরবর্তী: ১৮ডি (বিশ্লেষক) — ব্যবহারকারী বললে শুরু।

## ফেজ ১৮ডি হস্তান্তর
বিশ্লেষক: `app/Analyst/{Facts,Forecast,AnalystText,NarrativeCheck,AnalystService,…}`, `AccountingAnalystController`, `phase18d.up.sql`। ফেজ ১৮ সম্পন্ন (কোড)। পরবর্তী: ফেজ ১৯ (Memory + RAG + Vision) — ব্যবহারকারী বললে শুরু। বাকি দায়: ৯বি–১৮ডি বাস্তব-পরীক্ষা, NBR/পে-রোল অ্যাকাউন্ট্যান্ট রিভিউ, ফেজ ২৩.২ স্কোপ চূড়ান্ত।

## ফেজ ১৯এ হস্তান্তর
- মেমোরি: `app/Memory/*`, `phase19a.*.sql`। নোট চ্যাটে যায় `RepoMemoryContext` দিয়ে (`ChatService`-এর ঐচ্ছিক শেষ প্যারামিটার); প্রস্তাব `MemorySuggestService` (source `memory`)। পরবর্তী: ১৯বি নলেজ বেস — pgvector নেই/cPanel-এ নাও থাকতে পারে, তাই FTS/trigram সর্বদা, এমবেডিং ঐচ্ছিক (float4[]) — শুরুর আগে সিদ্ধান্ত নিন।

## ফেজ ১৯বি হস্তান্তর
- নলেজ বেস: `app/Knowledge/*`, `phase19b.*.sql`। খোঁজের এন্ট্রি `KnowledgeService::search()` / SQL `kb_search(query, k)` — ১৯সি (সোর্সসহ উত্তর) এটাই ব্যবহার করবে: চ্যাট প্রশ্ন → `kb_search` → অংশগুলো প্রম্পটে (শিরোনাম + seq সোর্স হিসেবে) → উত্তরে সোর্স দেখানো। এমবেডিং/অর্থভিত্তিক খোঁজ এখনও নেই; দরকার হলে `kb_chunks`-এ `float4[]` কলাম যোগ করে ঐচ্ছিকভাবে।

## ফেজ ১৯সি হস্তান্তর
- ধারা: `ChatService` → `KnowledgeContext::retrieve()` (→ `KnowledgePrompt::block`, `kb_search`) → সিস্টেম প্রম্পট; শেষে `KnowledgePrompt::markCited` + `KnowledgeContext::record()` → `chat_sources_save`। `done` ইভেন্টে `sources`। মোবাইল অ্যাপ/Telegram-এ উৎস দেখানো বাকি। ১৯ডি (OCR/ছবি) নলেজ বেসে ছবি-PDF/ছবির লেখা যোগ করার জায়গা `TextExtractor`।

## ফেজ ১৯ডি হস্তান্তর
- ছবি পড়া: ইঞ্জিন `app/vision/*`, `app/routes_vision.py`; Laravel `app/Vision/*`; ব্যবহারকারী `KnowledgeService::add(..., ocr: true)` ও `ChatController::extract` → `ImageAttachment`। **ফেজ ১৯ শেষ।** পরবর্তী: ফেজ ১৯.২ (Productivity Suite + Communication) — আগে স্কোপ চূড়ান্ত করুন। ক্যামেরা/Face Auth ও স্ক্রিন-বোঝা ফেজ ২৫–২৬-এ।

## ফেজ ১৯.২এ হস্তান্তর
- `app/Productivity/*` (TaskInput, DailyPlan, PgTaskRepo, ProductivityText), `ProductivityController`, `TasksRemindCommand` (`tasks:remind`), পেজ `productivity/{tasks,notes}.blade.php`। ১৯.২ এর বাকি: Google/Microsoft ক্যালেন্ডার সিঙ্ক, মিটিং ট্রান্সক্রিপশন, ইমেইল সারাংশ, ডকুমেন্ট তৈরি, Slack/Discord — প্রতিটির আগে স্কোপ ও key ঠিক করতে হবে। পরের ধাপ ব্যবহারকারী বললে তবেই।

## ফেজ ১৯.২বি হস্তান্তর
- ইঞ্জিন `app/office/{models,build}.py`, `app/routes_office.py`; Laravel `app/Office/*`, `OfficeController`, পেজ `office/index.blade.php`। ১৯.২ এর বাকি: Google/Microsoft ক্যালেন্ডার, মিটিং ট্রান্সক্রিপশন, ইমেইল সারাংশ, Slack/Discord (key লাগবে)। পরের ধাপ ব্যবহারকারী বললে তবেই।

## ফেজ ১৯.২সি হস্তান্তর
- `app/Assist/*`, `AssistController`, পেজ `assist/index.blade.php`। ১৯.২ বাকি: ক্যালেন্ডার সিঙ্ক, Slack/Discord (key), অডিও ট্রান্সক্রিপশন (ফেজ ২০ সংলগ্ন)। পরের ধাপ ব্যবহারকারী বললে তবেই।

## ফেজ ২১এ — এজেন্ট ইঞ্জিন (হস্তান্তর নোট)
- কোড `app/Workflow/*`; নতুন টুল যোগ করতে `Tool` ইন্টারফেস বানিয়ে `WorkflowServiceProvider`-এর `ToolRegistry`-তে দিন। ঝুঁকির শ্রেণি `Risk` — external/money টুল যোগের আগে অনুমোদন-ধাপের নিয়ম (`WorkflowDefinition::paths`) ও runner-এর দ্বিতীয় যাচাই পড়ুন।
- রান-এর স্থিতি ডাটাবেস-ফাংশনে (`wf_*`); কাজ-তোলা `WorkflowsWorkCommand`; AI ধাপ `CreditedAiStep` (reserve→settle/release)।
- বাকি: ভিজ্যুয়াল ক্যানভাস, লুপ/ডিলে/প্যারালাল, মোবাইল অনুমোদন, বাইরে-পাঠানো টুল (ইমেইল/পোস্ট) অনুমোদনসহ।

## ফেজ ২০ — ভয়েস (হস্তান্তর নোট)
- `app/Voice/*` (সার্ভিস, দাম, অডিও-যাচাই, বাছাইকারী), ইঞ্জিনে `routes_voice.py`। নতুন প্রোভাইডার-ধরন (যেমন ElevenLabs) যোগ করতে ইঞ্জিনের `SPEECH_ADAPTERS` ও `routes_voice.py` বাড়াতে হবে; Laravel-এর অংশ বদলাতে হবে না।
- বাকি: ওয়েক-ওয়ার্ড, মোবাইল মাইক, স্ট্রিমিং, ভয়েস-থেকে-এজেন্ট।

## ফেজ ১৯.২ডি — সংযোগ (হস্তান্তর নোট)
- `app/Links/*` (ওয়েবহুক, ভল্ট), `app/Calendar/*` (Ics পার্সার, ফেচার, সার্ভিস), টুল `calendar.upcoming`/`chat.post`, `ConnectController` + `connect/index`।
- বাকি: OAuth ক্যালেন্ডার লেখা/সিঙ্ক; পূর্ণ RRULE।

## ফেজ ২২.১ — ডিজাইন স্টুডিও (হস্তান্তর নোট)
- ইঞ্জিন: `app/studio/compose.py` (বাংলা-সচেতন ফন্ট রান, লেআউট, ৫ সাইজ), `routes_studio.py` (generate/compose)। Laravel: `app/Studio/*` (StudioService = একমাত্র পেইড ধাপ AI ছবি; DesignRepo; ইমেজ পিকার ক্ষমতা `image_gen`), `StudioController`, `ReviewController` (পাবলিক)।
- নতুন ইমেজ-প্রোভাইডার ধরন যোগ করতে ইঞ্জিনের `IMAGE_ADAPTERS` ও `generate()` বাড়ান। নতুন সাইজ: ইঞ্জিনের `SIZES` + Laravel `StudioService::SIZES/GEN_SIZE` + DB-র CHECK তালিকা (`designs.sizes`, `design_reviews.size_key`) + `StudioText::SIZES`।
- বাকি: সম্পাদনা/ভার্সন, টেমপ্লেট লাইব্রেরি, মালিককে নোটিফিকেশন।

## ফেজ ২২.২a — ভিডিও স্টুডিও
- কোড: `apps/ai-engine/app/video/*`, `routes_video.py`; `apps/web-overlay/app/Video/*`, `VideoController`, `VideoWorkCommand`, `VideoServiceProvider`, views `video/*`; DB `phase22b`।
- প্রবাহ: তৈরি → ক্রেডিট hold → ফাইল সংরক্ষণ → `video_job_create` → ওয়ার্কার claim → ইঞ্জিন রেন্ডার → settle/release → ফাইল।
- বাকি (২২.২b/c): অটো সাবটাইটেল, নীরবতা কাটা, AI স্ক্রিপ্ট, AI ভিডিও জেনারেশন, টাইমলাইন, মিউজিক।

## ফেজ ২২.২b — সাবটাইটেল ও AI স্ক্রিপ্ট
- কোড: `render_caption` (compose.py), `plan_captions`/`probe_duration` (video/render.py); Laravel `VideoCaptions`, `VideoScript(Prompt|Service)`, `VideoController::script`। সাবটাইটেল-ফ্ল্যাগ `video_jobs.brand->captions`।
- বাকি (২২.২c): নীরবতা কাটা (আপলোড-ভিডিও), AI ভিডিও জেনারেশন, টাইমলাইন এডিটর, রয়্যালটি-ফ্রি মিউজিক।

## ফেজ ২২.২c-ক — ক্লিপ এডিটর
- কোড: `apps/ai-engine/app/video/edit.py`, routes `/video/probe|edit`; Laravel `VideoService::createClip()` + `work()`-এর clip শাখা, `VideoController::clip`। ক্লিপ = `video_jobs`-এর সারি, `scenes[0].clip=true`; সোর্স `<id>-clip.vid`।
- বাকি: রয়্যালটি-ফ্রি মিউজিক, AI ভিডিও জেনারেশন, টাইমলাইন UI।

## ফেজ ২২.২c-খ — মিউজিক
- ইঞ্জিন `video/render.py`: `MUSIC`, `music_graph()`; Laravel `VideoService::MUSIC`, ফ্ল্যাগ `video_jobs.brand->music`। লাইসেন্স-করা আসল মিউজিক ফাইল চাইলে আলাদা ফেজে (ফাইল-লাইব্রেরি + লাইসেন্স রেকর্ড) যোগ করুন।

## ফেজ ২২.২c-গ — টাইমলাইন ও AI ছবি (২২.২ সমাপ্ত)
- `VideoService::drawScenes()` (ওয়ার্কারে ছবি আঁকা + আংশিক চার্জ), ফ্রন্টএন্ড `public/js/video-timeline.js` + blade-এর ইনলাইন স্ক্রিপ্ট; টেস্ট `tests/js/`।
- বাকি/ভবিষ্যৎ: পুরো AI ভিডিও জেনারেশন (প্রোভাইডার API যাচাই করে), ক্লিপে সাবটাইটেল/মিউজিক, মাল্টি-ক্লিপ জোড়া দেওয়া।

## ফেজ ২৩a — সোশ্যাল কন্টেন্ট প্ল্যানার
- `app/Social/*`, `SocialController`, `resources/views/social/*`, `phase23a.up.sql`। নিজে থেকে পোস্ট হয় না — ২৩b (Facebook Graph, টোকেন vault-এ), ২৩c (ইনসাইট) বাকি।

## ফেজ ২৩b — Facebook Page অটো-পোস্ট
- `app/Social/{FacebookGraph,Multipart,GraphResult,SocialPublisher,SocialAccounts,SocialSecrets,TokenSealer,PostMedia,StudioPostMedia,SocialPublishRepo,LongHttpJson}`, `SocialPublishCommand`, `phase23b.up.sql`, পাতা `social/accounts`। আসল Facebook-এ যাচাই বাকি (টোকেন লাগবে); ২৩c ইনসাইট বাকি।

## ফেজ ২৩c — পোস্ট ইনসাইট
- `FacebookGraph::stats`, `SocialInsights`, `SocialInsightsCommand`, `phase23c.up.sql`, `social/insights` ভিউ। ফেজ ২৩ কোড সমাপ্ত; আসল Facebook-এ যাচাই বাকি।

## ফেজ ২৩.২a — AI কন্টেন্ট-প্ল্যান এজেন্ট
- `app/Social/{SocialPlanPrompt,SocialPlan,SocialPlanService}`, `phase232a.up.sql`, `social/plan` ভিউ। শুধু-ড্রাফট নীতি। বাকি: 23.2b/c/d।

## ফেজ ২৩.২b — গ্রোথ বিশ্লেষণ
- `app/Social/SocialGrowth*`, `social/growth` ভিউ। শুধু-পড়া; নতুন DB নেই। বাকি: 23.2c/d।

## ফেজ ২৩.২c — কমেন্ট-রিপ্লাই
- `app/Social/SocialComment*`, `SocialReply*`, `phase232c.sql`, `social/comments` ভিউ। অনুমোদন ছাড়া কিছু যায় না; অজানা ফলাফল retry হয় না। বাকি: 23.2d (YouTube)।

## Phase 23.2d (YouTube) হস্তান্তর
- কোড: `app/Social/Youtube*.php`, `SocialYoutubeCommand`, `SocialYoutubeController`, `resources/views/social/youtube.blade.php`, SQL `database/sql/phase232d.*`।
- ব্যবহারকারীর বাকি: আসল Google OAuth ক্লায়েন্ট, সব হাতে-পরীক্ষা, মাইগ্রেশন, cron।
- জানা সীমা: YouTube ইনসাইট/কমেন্ট নেই; আসল API যাচাই হয়নি।

## Phase 24.1a (Meta Ads, শুধু-পড়া) হস্তান্তর
- কোড: `app/Ads/*`, `AdsController`, `resources/views/ads/index.blade.php`, `AdsSyncCommand`, SQL `database/sql/phase241a.*`।
- ব্যবহারকারীর বাকি: মাইগ্রেশন + roles:sync, System User টোকেন, আসল Meta-র সাথে হাতে-পরীক্ষা।
- পরের ধাপ: 24.1b AI ক্যাম্পেইন ড্রাফট (অনুমোদনে), 24.1c PAUSED তৈরি, 24.1d অপ্টিমাইজেশন পরামর্শ।

## Phase 24.1b (বিজ্ঞাপন-ড্রাফট) হস্তান্তর
- কোড: `app/Ads/Ads{Text,DraftForm,Copy,CopyPrompt,CopyService,DraftRepo}.php`, `AdsDraftController`, `resources/views/ads/{drafts,draft}.blade.php`, SQL `database/sql/phase241b.*`।
- ব্যবহারকারীর বাকি: মাইগ্রেশন + roles:sync, সীমা বসানো, ব্রাউজারে সব পাতা ও AI বাটন যাচাই।
- পরের ধাপ 24.1c: অনুমোদিত ড্রাফট Meta-তে PAUSED তৈরি (একবার-তৈরি নিরাপত্তা, বাজেট মুদ্রা-অফসেট, ক্রিয়েটিভ ছবি)।

## ফেজ ২৪.১c হ্যান্ডওভার
অনুমোদিত Meta ড্রাফট PAUSED তৈরি হয় (`AdsLauncher`, `MetaAdsLaunchApi`, `AdsLaunchPlan`, `ads_launch_*` ফাংশন)। বাকি: ২৪.১d অপ্টিমাইজেশন পরামর্শ। অযাচাইকৃত: আসল Meta API, ব্রাউজার UI।

## ফেজ ২৪.১d হ্যান্ডওভার
`AdsAdvisor` (নিয়ম), `AdsAdviceRepo`, `AdsPauser`, `ads:pause`, `MetaAdsLaunchApi::pauseCampaign`। ফেজ ২৪.১ সম্পূর্ণ। পরবর্তী: ২৪.২ Google Ads।

## ফেজ ২৪.২a হ্যান্ডওভার
`app/GoogleAds/*` (Api, Accounts, Sync, Repo), `GoogleAdsController`, `gads:sync`। পরবর্তী: ২৪.২b ড্রাফট ও অনুমোদন।

## ফেজ ২৪.২b হ্যান্ডওভার
`GadsDraftForm/GadsLines/GadsCopy*/GadsDraftRepo`, `GadsDraftController`, `gads_*` ড্রাফট ফাংশন। পরবর্তী: ২৪.২c অনুমোদিত ড্রাফট PAUSED তৈরি (budget → campaign → ad group → keywords → RSA; Meta-র লঞ্চারের নকশা অনুযায়ী)।

## ফেজ ২৪.২c হ্যান্ডওভার
`GadsLaunchPlan` (বিশুদ্ধ), `GoogleAdsLaunchApi` (শুধু create), `GadsLauncher` (ধাপে ধাপে, পুনরারম্ভযোগ্য), `PgGadsLaunchRepo`, `gads:launch`। পরবর্তী: ২৪.২d — Google ক্যাম্পেইনের পরামর্শ/থামানো (Meta ২৪.১d-র মতো), `gads_campaigns` তথ্য থেকে।

## ফেজ ২৪.২d হ্যান্ডওভার — ফেজ ২৪.২ সম্পূর্ণ
`GadsAdvisor`, `GadsAdviceRepo/PgGadsAdviceRepo`, `GadsPauser`, `GoogleAdsLaunchApi::pauseCampaign`, `gads:pause`। বাকি Google কাজ (PMax/Display/Shopping/YouTube বিজ্ঞাপন, কীওয়ার্ড প্ল্যানার, Search Terms) পরবর্তী ফেজে। পরের ধাপ: PROJECT_PLAN.md দেখুন।

## ফেজ ২৪.৩a — ক্লায়েন্ট রিপোর্ট
`app/Reports/*` ডেটা (ads/gads/social) → `ReportBuilder` (বিশুদ্ধ, বাংলা/ইংরেজি) → ফেজ ৯এ-র `ProposalEngine` (PDF/DOCX)। মাসিকে তুলনা নেই (৩০ দিন রিটেনশন)। বাকি ২৪.৩: এজেন্সি মোড, SEO, Manager Agent।

## ফেজ ২৪.৩b — এজেন্সি মোড
`clients` + অ্যাকাউন্টে `client_id`; ক্লায়েন্টের ব্র্যান্ড = নিজস্ব non-default `brand_kits`। রিপোর্ট `ReportRepo` তৃতীয় আর্গ হিসেবে client id নেয়। বাকি: ক্লায়েন্ট-ফিল্টার বিজ্ঞাপন/সোশ্যাল পেজে, ক্লায়েন্ট-ভিত্তিক সদস্য-অনুমতি।

## ফেজ ২৪.৩c — SEO অডিট
নিয়মভিত্তিক (AI/ক্রেডিট নয়)। ইঞ্জিন `app/seo/*` বিদ্যমান `SafeFetcher` (SSRF + robots) ব্যবহার করে; নিয়ম ও বাংলা পরামর্শ `app/seo/rules.py`-এ (নতুন নিয়ম যোগ করতে সেখানে একটি সারি + `page.py`-তে শর্ত)। Laravel `SeoService` ক্রলের **আগে** `seo_audit_allowed` দেখে (২৪ ঘণ্টায় ৩০), ইঞ্জিনের উত্তর `SeoText::clean` দিয়ে ছেঁকে `seo_audits`-এ রাখে (শুধু ফাংশন দিয়ে লেখা)। অনুমতি `ads.view`/`ads.manage`। বাকি: ক্লায়েন্ট-রিপোর্টে SEO স্কোর যোগ; AI-লেখা টাইটেল/ডেসক্রিপশন পরামর্শ (ঐচ্ছিক, ক্রেডিটসহ)।

### Marketing Manager Agent (ফেজ ২৪.৩d)
নতুন ইঞ্জিন/টেবিল নেই; ওয়ার্কফ্লো ইঞ্জিনের (ফেজ ২১) দুটি শুধু-পড়া টুল: `marketing.snapshot` (`MarketingSnapshot::text` — বিশুদ্ধ নিয়ম: ভিন্ন মুদ্রা যোগ হয় না, শূন্য ভিত্তিতে শতাংশ নেই, বাসি তথ্যে ⚠, খরচ-আছে-ফল-নেই ⚠) ও `seo.latest`। দুটোই চালানোর সময় `MarketingAccess` দিয়ে মালিকের `ads.view`/`social.view` আবার দেখে। ৫টি টেমপ্লেট `Templates.php`-এ (ক্যাটাগরি marketing); AI শুধু পরামর্শ লেখে, একমাত্র লেখা = অনুমোদনের পরে নিজের টাস্ক। নতুন টেমপ্লেটের প্রম্পটে DATA-বেড়া ও সত্যবাদিতার নিয়ম থাকতেই হবে (`MarketingTest` এটি বাধ্য করে)।

### Google / Outlook ক্যালেন্ডার OAuth (ফেজ ১৯.২ই)
`user_links`-এ kind `gcal`/`mcal` (name = ইমেইল, host = google|microsoft, secret = এনক্রিপ্ট-করা refresh token)। `CalendarAccounts` সব লজিক ধরে: access token রিফ্রেশ (ঘূর্ণিত টোকেন `link_secret_update`-এ), ইভেন্ট পড়া, একটি ইভেন্ট যোগ (সময় UTC; অতিথি নেই; `sendUpdates=none`)। ব্যর্থতার কারণ (`auth`/`revoked`/`api`) লিংকে দাগ হয়ে থাকে, সাময়িক বিভ্রাট বা সার্ভার-key-না-থাকা থাকে না। `CalendarService::upcoming` ICS ও OAuth মিশিয়ে ডুপ্লিকেট বাদ দেয়। `calendar.add_event` টুল `Risk::EXTERNAL`। নতুন প্রোভাইডার (যেমন Apple) যোগ করতে: `OAuthProvider` + `CalendarApi` বানিয়ে `ConnectServiceProvider`-এ দুই ম্যাপে বসান, `CalendarAccounts::KINDS` ও SQL kind-তালিকা বাড়ান।

### ভয়েস কমান্ড (ফেজ ২০b)
`VoiceService::ask` ট্রান্সক্রিপ্ট পেয়ে আগে `VoiceCommand::run` চালায় (শুধু "ক্রিয়া + নাম", ≤৮ শব্দ); মিললে AI ছাড়াই উত্তর। ভয়েসের নাগালে শুধু `VoiceActions`-এর দুটি কাজ — তালিকা ও চালু; নতুন ভয়েস-কাজ যোগ করলে প্রতিটির ঝুঁকি বিচার করুন (অনুমোদন/মোছা/টাকা কখনো ভয়েসে নয়)।

### ওয়ার্কফ্লো delay ও each (ফেজ ২১b)
`delay`: runner `repo->delay` (→ SQL `wf_step_delay`) দিয়ে রানকে `queued` + `not_before` করে থামে; `wf_run_claim` শুধু সময়-হওয়া রান নেয় এবং `claimed_at` বসায় (আটকে-থাকা-পরীক্ষা এখন `claimed_at`/`started_at`-এর `coalesce`)। `each`: tool-ধাপে ≤১০ লাইন, শুধু READ/WRITE_OWN টুল — যাচাই (`WorkflowDefinition`) ও রানটাইম (`WorkflowRunner`) দুই জায়গাতেই আটকানো আছে; নতুন ধরনের ধাপ যোগ করলে দুই জায়গাতেই এবং `paths()`-এ হাঁটার নিয়মে যোগ করুন।
