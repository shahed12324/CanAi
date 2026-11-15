# JARVIS ONE — ডিপ্লয় কিট

## কী কোথায় চলবে
| উপাদান | VPS (পূর্ণ সিস্টেম) | cPanel শেয়ার্ড হোস্ট |
|---|---|---|
| Laravel ওয়েব (nginx + PHP-FPM) | ✅ | ✅ (PHP 8.3+ থাকলে) |
| PostgreSQL (RLS + আলাদা role) | ✅ Docker | ❌ নেই / role বানানো যায় না → **VPS-এর DB ব্যবহার** |
| Python AI ইঞ্জিন (3.13) | ✅ Docker | ❌ → **VPS-এ চলে** |
| Redis / Horizon / scheduler | ✅ | scheduler = cPanel Cron; কিউ = `sync` |

**ছোট কথা:** পুরো সিস্টেম VPS-এ। cPanel শুধু ওয়েব-ফ্রন্ট হতে পারে, ডাটাবেস ও AI ইঞ্জিন তখনও VPS-এ থাকবে (`CPANEL.md`)।

## VPS ইনস্টল (Ubuntu 22.04 / 24.04, root)
```bash
unzip jarvis-one-phase-06.zip -d /root/jarvis-src && cd /root/jarvis-src/jarvis-one
sudo ./deploy/install-vps.sh --domain jarvis.example.com --email you@example.com
# ডোমেইন ছাড়া টেস্ট:  sudo ./deploy/install-vps.sh      (সার্ভারের IP-তে HTTP)
```
স্ক্রিপ্ট যা করে: প্যাকেজ → Docker (PostgreSQL 18, Redis, AI ইঞ্জিন) → DB role → Laravel তৈরি/বিল্ড → migrate (owner role দিয়ে) → `tenancy:check` → nginx → Horizon + scheduler → HTTPS (ডোমেইন থাকলে) → স্ব-পরীক্ষা। লগ: `/var/log/jarvis-install.log`। আবার চালালে সিক্রেট বদলায় না।
DNS: ডোমেইনের A রেকর্ড আগে VPS-এর IP-তে দিন, নইলে HTTPS ধাপ ব্যর্থ হবে (সাইট HTTP-তে চলবে; ঠিক করে স্ক্রিপ্ট আবার চালান)।

## ইনস্টলের পর (প্রথমবার)
```bash
cd /opt/jarvis-one/apps/web
sudo -u jarvis php artisan user:verify you@example.com      # মেইল সার্ভার ছাড়াই ইমেইল ভেরিফাই
# ব্রাউজারে /settings/security → 2FA চালু, তারপর:
sudo -u jarvis php artisan admin:promote you@example.com
```
`/admin` → **প্ল্যান** (মূল্য বসান) → **AI Provider** (নাম, API key, Test, মডেল + মূল্য, চালু) → `/app/chat`।
ইমেইল পাঠাতে `/opt/jarvis-one/apps/web/.env`-এ `MAIL_MAILER=smtp` ও `MAIL_HOST/PORT/USERNAME/PASSWORD` দিয়ে `sudo -u jarvis php artisan config:cache`।

## নতুন ফেজ আপডেট
নতুন ZIP `/opt/jarvis-one`-এর উপর unzip করুন (`apps/web` ও `.env` মুছবেন না), তারপর `sudo /opt/jarvis-one/deploy/update.sh` — migrate, build, ক্যাশ, রিস্টার্ট ও স্ব-পরীক্ষা করে।

## প্রতিদিনের কাজ
- `deploy/healthcheck.sh` — সব সার্ভিস/টেনান্ট আইসোলেশন/ক্রেডিট লেজার মেলানো পরীক্ষা।
- `deploy/backup.sh` — DB dump + `.env` (vault key সহ)। cron-এ দিন ও **সার্ভারের বাইরে কপি** রাখুন।
- লগ: `apps/web/storage/logs/laravel.log`, `docker compose -f infra/docker-compose.yml logs ai-engine`, `journalctl -u jarvis-horizon`।

## সমস্যা হলে
| লক্ষণ | দেখুন |
|---|---|
| ইনস্টল মাঝপথে থামল | `/var/log/jarvis-install.log` — শেষ `==>` ধাপ; সমাধান করে একই কমান্ড আবার চালান |
| `composer`/`npm` মেমরি ফুরাল | সোয়াপ যোগ করুন (2 GB) |
| চ্যাটে "AI সার্ভিসে সংযোগ হয়নি" | `docker ps`; `curl 127.0.0.1:8000/healthz`; `.env`-এ HMAC/JWT secret Laravel ও ইঞ্জিনে এক কিনা |
| চ্যাটে "এই মডেলটি ব্যবহার করা যাচ্ছে না" | provider-এ key আছে, চালু, মডেলে মূল্য ও plan-অ্যাক্সেস আছে কিনা (`/admin/providers`) |
| উত্তর একসাথে আসে, ধীরে ধীরে নয় | মাঝে কোনো প্রক্সি/CDN (Cloudflare) বাফার করছে; ওই রুটে বাফার বন্ধ করুন |
| 500 এরর | `storage/logs/laravel.log`; `storage` মালিকানা `jarvis:www-data` কিনা |

## যা এখানে পরীক্ষা করা যায়নি (সৎ তালিকা)
স্ক্রিপ্টগুলো `bash -n` ও shellcheck পাস করেছে, nginx টেমপ্লেটের ব্রেস মিলেছে — কিন্তু আসল Ubuntu সার্ভারে, `apt`/Docker/certbot/Composer সহ চালানো আমার পরিবেশে সম্ভব নয়। তাই প্রথম রানে কিছু আটকালে `/var/log/jarvis-install.log`-এর শেষ ২০ লাইন পাঠান, ঠিক করে দেব।


## Telegram বট (Phase 7)
1. Telegram-এ @BotFather → `/newbot` → টোকেন নিন।
2. `https://আপনার-ডোমেইন/admin/channels` → নাম + টোকেন দিয়ে "যাচাই করে যুক্ত করুন" → "চালু করুন"।
3. `/app/settings/telegram` থেকে কোড নিন → বটে `/link কোড` লিখুন → এরপর প্রশ্ন করুন।
- `jarvis-horizon` সার্ভিস চালু থাকতে হবে (`systemctl status jarvis-horizon`); বন্ধ থাকলে বট সাড়া দেবে না।
- সমস্যা হলে অ্যাডমিন পেজের "Telegram-এর অবস্থা দেখুন" ও ইভেন্ট লগ দেখুন।
- এই পথ এখানে আসল বট দিয়ে চালানো যায়নি — প্রথম রানের ফলাফল আমাকে জানান।
