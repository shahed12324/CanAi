# JARVIS ONE — একবারে চালানোর নির্দেশ

এই zip-এ পুরো প্রজেক্ট আছে (Laravel overlay, AI engine, মোবাইল, SDK, SQL, selfhost, ডক)।

## সবচেয়ে সহজ: নতুন Ubuntu সার্ভারে এক কমান্ড
```bash
unzip jarvis-one-full.zip -d jarvis && cd jarvis/selfhost
sudo ./quickstart.sh          # Docker, ফায়ারওয়াল, ইনস্টল, ব্যাকআপ-cron — সব নিজে করে; দুটি প্রশ্ন (ডোমেইন, ইমেইল) করে
```
শেষে যে তিনটি ধাপ ছাপে সেগুলো করুন (রেজিস্টার → `user:verify` → `admin:promote`)। এই স্ক্রিপ্ট এখানে চালানো হয়নি (Docker/আসল সার্ভার ছিল না)।

## সবচেয়ে সহজ (হাতে): নিজের সার্ভারে Docker দিয়ে (সবকিছু একসাথে)
```bash
unzip jarvis-one-full.zip -d jarvis && cd jarvis/selfhost
./install.sh                                    # IP দিয়ে, HTTP
# অথবা ডোমেইনসহ HTTPS:
./install.sh --domain app.example.com --email you@example.com
```
এটি PostgreSQL+pgvector, Redis, Laravel, queue worker, scheduler, AI engine, nginx একসাথে তোলে এবং সব মাইগ্রেশন চালায়।
এরপর: `./healthcheck.sh` → `./artisan.sh user:verify you@example.com` → লগইন → `./artisan.sh admin:promote you@example.com`।
বিস্তারিত: `selfhost/README.md`।

## শুধু টেস্ট চালাতে (এই মেশিনে)
```bash
php scripts/runpure.php apps/web-overlay/tests/Unit/WebhookTest.php     # যেকোনো PHP ইউনিট টেস্ট
JARVIS_TEST_PG_ADMIN_DSN=postgresql://postgres@127.0.0.1:5432/postgres python -m pytest tests/db -q   # DB টেস্ট (PostgreSQL লাগবে)
bash scripts/security-audit.sh
```

## আগে জেনে রাখুন
- Laravel-এর middleware/controller/Blade/route/scheduler/Docker build আমার পরিবেশে কখনো চালানো যায়নি (Packagist/Docker নেই) — প্রথম চালানোয় ছোটখাটো সমস্যা আসতে পারে; লগ পাঠালে ঠিক করে দেব।
- আসল সার্ভিসের কী (AI প্রোভাইডার, পেমেন্ট, ইমেইল ইত্যাদি) `.env`-এ আপনাকে বসাতে হবে।
- `APP_KEY` ও `AI_VAULT_KEY_1` হারালে ডেটা উদ্ধার হয় না — ব্যাকআপ সার্ভারের বাইরে রাখুন।
