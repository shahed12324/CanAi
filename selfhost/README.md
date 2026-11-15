# JARVIS ONE — সেলফ-হোস্ট (নিজের VPS-এ Docker)

পুরো স্ট্যাক (Laravel অ্যাপ, নিউজ-কিউ Horizon, scheduler, AI engine, PostgreSQL 18 + pgvector, Redis, nginx, ঐচ্ছিক Caddy/HTTPS) কন্টেইনারে চলে। শুধু nginx/Caddy ইন্টারনেটে খোলা; ডাটাবেস, Redis ও engine-এর কোনো পোর্ট বাইরে খোলা নেই।

## দরকার
- Ubuntu/Debian VPS (২ vCPU, ৪ GB RAM-এর ওপরে ভালো), Docker + Compose v2, `openssl`, ইন্টারনেট (বিল্ডের সময় packagist/npm/Docker Hub)।

## ইনস্টল
```bash
cd selfhost
./install.sh                                   # IP দিয়ে, HTTP
./install.sh --domain app.example.com --email you@example.com   # HTTPS (Caddy, আপনা-আপনি সার্টিফিকেট)
./install.sh --license-key JVSH1.xxxx.yyyy     # লাইসেন্স কী সহ (JARVIS_LICENSE_PUBKEY .env-এ বসাতে হবে, নিচে দেখুন)
```
`install.sh` আবার চালালে আপনার `.env` ও ডেটা অক্ষত থাকে। প্রথমবার ইমেজ বিল্ড কয়েক মিনিট নেয় (Chromium বাদ দিতে `--no-render`; তাহলে PDF প্রস্তাবনা 503 দেবে, DOCX চলবে)।

ইনস্টলের পর: `./artisan.sh user:verify you@example.com` → লগইন → 2FA → `./artisan.sh admin:promote you@example.com` → `/admin`।

## প্রতিদিনের কাজ
| কাজ | কমান্ড |
|---|---|
| স্বাস্থ্য পরীক্ষা | `./healthcheck.sh` |
| ব্যাকআপ (DB + `.env` + storage) | `./backup.sh` · cron: `15 2 * * * cd /opt/jarvis/selfhost && ./backup.sh` |
| ব্যাকআপ থেকে ফেরত | `./restore.sh /var/backups/jarvis/db-<stamp>.dump` (নতুন সার্ভারে আগে `env-<stamp>` কে `.env` বানান) |
| আপডেট | `./update.sh jarvis-one-phase-NN.zip` (আগে নিজে ব্যাকআপ নেয়, `.env` ছোঁয় না, মাইগ্রেট করে) |
| artisan কমান্ড | `./artisan.sh <command>` |
| লগ | `docker compose --env-file .env -f docker-compose.yml logs -f web horizon ai-engine` |

**ব্যাকআপ অবশ্যই সার্ভারের বাইরেও কপি করুন।** `AI_VAULT_KEY_1` ও `APP_KEY` হারালে সংরক্ষিত provider key/সেশন পড়া যায় না।

## লাইসেন্স কী (অফলাইন, Ed25519)
কোনো লাইসেন্স সার্ভারে যোগাযোগ হয় না। বিক্রেতা (আপনি) গোপন কী দিয়ে `{গ্রাহক, সিট, মেয়াদ}` সই করেন; গ্রাহকের সার্ভারে শুধু পাবলিক কী থাকে — তাই কী বানানো/বদলানো যায় না।

বিক্রেতার নিজের কম্পিউটারে (গ্রাহকের সার্ভারে কখনো নয়):
```bash
php scripts/license-issue.php keygen                       # একবার: PUBLIC/SECRET জোড়া; SECRET অফলাইনে রাখুন (chmod 600)
php scripts/license-issue.php issue secret.hex "Acme Ltd" 25 365   # গ্রাহক, সিট, দিন
php scripts/license-issue.php verify <public-hex> <key>
```
গ্রাহকের সার্ভারে: `.env`-এ `JARVIS_LICENSE_PUBKEY=<public-hex>` বসিয়ে `docker compose --env-file .env -f docker-compose.yml up -d`, তারপর `./license.sh JVSH1.…` (সক্রিয় করে), `./license.sh` (অবস্থা: missing/valid/expiring/expired/invalid)। `--tenant=<ওয়ার্কস্পেস id>` দিলে সিট ও মেয়াদ Phase 27c-র Enterprise লাইসেন্সে প্রয়োগ হয় (নতুন সদস্য সিট-সীমা মানে)।

## লোকাল Ollama ওয়ার্কার
`local-worker/README.md` দেখুন।

## যা এখানে যাচাই হয়নি
Docker daemon/আসল VPS ছিল না: compose ফাইল পার্স ও স্ট্যাটিক টেস্ট হয়েছে, কিন্তু ইমেজ বিল্ড, `install.sh` পূর্ণ রান, Caddy সার্টিফিকেট, backup/restore বাস্তবে চালানো আপনার সার্ভারে প্রথমবার হবে। সমস্যা হলে `/var/log`-এর বদলে উপরের `logs` কমান্ডের আউটপুট পাঠান।
