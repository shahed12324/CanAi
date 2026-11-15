# cPanel-এ চালানো (ওয়েব-ফ্রন্ট cPanel-এ, ডাটাবেস + AI ইঞ্জিন VPS-এ)

> সৎ সীমা: শেয়ার্ড cPanel-এ PostgreSQL role/RLS ও Python 3.13 সার্ভিস চলে না। তাই **পুরো সিস্টেম একা cPanel-এ চলবে না**। এই পথে শুধু PHP অংশ cPanel-এ থাকে; ডাটাবেস ও AI ইঞ্জিন আপনার VPS-এ। VPS লাগবেই।

## আগে জেনে নিন (৫ মিনিট)
1. cPanel → **MultiPHP Manager**: ডোমেইনে PHP **8.3 বা তার বেশি**।
2. **Select PHP Version → Extensions**: `pdo_pgsql`, `mbstring`, `intl`, `xml`, `zip`, `curl`, `fileinfo`, `tokenizer`, `ctype` চালু।
3. হোস্ট **বাইরের দিকে port 5432** (PostgreSQL) খোলা রাখে কিনা — অনেক শেয়ার্ড হোস্ট বন্ধ রাখে। না খোলা থাকলে এই পথ কাজ করবে না (VPS-এ পুরোটা চালান)।
`cpanel-precheck.php` (বান্ডলে আছে) এগুলো এক ক্লিকে পরীক্ষা করে।

## ধাপ
**১. VPS ঠিক করুন** (একবার)
```bash
sudo ./deploy/install-vps.sh --domain vps.your-domain.example --email you@example.com   # HTTPS লাগবে
sudo ./deploy/enable-remote.sh --allow-ip <cPanel-সার্ভারের-IP> --domain vps.your-domain.example
./deploy/make-cpanel-bundle.sh            # → /tmp/jarvis-cpanel.zip (vendor ও build সহ তৈরি)
```
cPanel সার্ভারের IP: cPanel ড্যাশবোর্ডের ডান পাশে "Shared IP Address"। শুধু ওই IP ডাটাবেস ও ইঞ্জিনে ঢুকতে পারবে (TLS বাধ্যতামূলক)।

**২. cPanel-এ আপলোড**
- File Manager → হোম ডিরেক্টরিতে (`public_html`-এর **বাইরে**) `jarvis-cpanel.zip` আপলোড → Extract → `jarvis/` ফোল্ডার।
- ডোমেইনের **Document Root** বদলে `jarvis/public` করুন (Domains → Manage)। না পারলে: `public_html` খালি করে `jarvis/public`-এর ভেতরের সব ফাইল সেখানে কপি করুন এবং `public_html/index.php`-এ দুটো পথ (`../jarvis/vendor/autoload.php`, `../jarvis/bootstrap/app.php`) ঠিক করুন।

**৩. `.env` তৈরি**
`jarvis/.env.example` → `jarvis/.env` কপি করে `enable-remote.sh`-এর শেষে ছাপা মানগুলো বসান (DB_HOST, পাসওয়ার্ড, AI_ENGINE_URL, তিনটি secret VPS-এর `.env` থেকে হুবহু)। তারপর Terminal (বা SSH) থেকে:
```bash
cd ~/jarvis && php artisan key:generate && php artisan config:cache && php artisan storage:link
```
**Terminal/SSH না থাকলে:** `APP_KEY` VPS-এ `php artisan key:generate --show` চালিয়ে এনে `.env`-এ বসান; `storage:link`-এর দরকার এই ফেজে নেই।

**৪. পরীক্ষা**
ব্রাউজারে `https://your-domain/cpanel-precheck.php` (আগে ফাইলে DB_HOST ও ENGINE_URL বসান) → সব `[ OK ]` হলে **ফাইলটি মুছে ফেলুন**।

**৫. Cron (scheduler)** — cPanel → Cron Jobs, প্রতি মিনিটে:
```
* * * * * /usr/local/bin/php /home/<cpanel-user>/jarvis/artisan schedule:run >> /dev/null 2>&1
```
(PHP-এর পথ ভিন্ন হলে `which php` / হোস্টের ডকুমেন্টেশন দেখুন; 8.3 এর পথ দিন, যেমন `/opt/cpanel/ea-php83/root/usr/bin/php`।)

**৬. মেইল:** cPanel → Email Accounts-এ `noreply@your-domain` বানিয়ে `.env`-এর `MAIL_*` ভরুন (SMTP `localhost`, পোর্ট 465 SSL)।

## Telegram বট (ঐচ্ছিক)
cPanel ডোমেইনে বৈধ HTTPS থাকলে অ্যাডমিন প্যানেলের "Telegram বট" থেকে চালু করা যায়। তবে `QUEUE_CONNECTION=sync` থাকায় AI উত্তর webhook অনুরোধের ভেতরেই তৈরি হয়; ধীর মডেলে দেরি হতে পারে। ভালো অভিজ্ঞতার জন্য বটটি VPS-এর ডোমেইনে চালান।

## পরিচিত সীমা
- migrate cPanel থেকে নয়, **VPS থেকে** চালান (owner role শুধু VPS-এ): `deploy/update.sh` বা `php artisan migrate` ওখানে। নতুন ফেজে VPS আগে আপডেট, তারপর নতুন বান্ডল আপলোড।
- স্ট্রিমিং: LiteSpeed/Apache কিছু হোস্টে আউটপুট জমিয়ে রাখে → উত্তর টুকরো টুকরো না এসে একবারে আসতে পারে (কাজ করে, শুধু "টাইপিং" ইফেক্ট থাকে না)। VPS-এ nginx-এ এটা সমস্যা নয়।
- কিউ `sync`: ভারী ব্যাকগ্রাউন্ড কাজ (পরের ফেজ) এখানে ধীর হবে। এগুলো VPS-এ চালানোই ঠিক।
- নতুন IP বদলালে (হোস্ট মাইগ্রেশন) `enable-remote.sh` আবার চালান।
