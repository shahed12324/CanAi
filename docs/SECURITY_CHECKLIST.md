# ডিপ্লয়ের আগে নিরাপত্তা চেকলিস্ট (আপনার সার্ভারে)

- [ ] `APP_ENV=production`, `APP_DEBUG=false`, নতুন `APP_KEY`; `.env` গিট/ZIP-এ নেই, ফাইল-পারমিশন ৬০০
- [ ] HTTPS চালু + HTTP→HTTPS রিডাইরেক্ট; `SESSION_SECURE_COOKIE=true`, `SESSION_SAME_SITE=lax`
- [ ] হেডার: `Strict-Transport-Security`, `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy`, মোটামুটি `Content-Security-Policy`
- [ ] Postgres: `jarvis_owner/app/admin` আলাদা শক্তিশালী পাসওয়ার্ড; ডাটাবেস পোর্ট ইন্টারনেটে খোলা নয়; `scripts/db-provision.sh` চালানো; ব্যাকআপ + রিস্টোর পরীক্ষা
- [ ] AI ইঞ্জিন শুধু প্রাইভেট নেটওয়ার্কে (পাবলিক নয়); `ENGINE_SIGNING_SECRET` Laravel ও ইঞ্জিনে একই, শক্তিশালী
- [ ] প্রথম অ্যাডমিন `php artisan admin:promote …` দিয়ে; ২FA চালু
- [ ] `scripts/security-audit.sh` সবুজ; `php artisan migrate` পরে আবার `tests/db/test_security_audit.py`
- [ ] স্টেজিংয়ে OWASP ZAP baseline স্ক্যান; লগইন/পাসওয়ার্ড-রিসেটে rate-limit হাতে দেখা
- [ ] শিডিউলার (cron `schedule:run`) চালু — সাফাই কমান্ডগুলো এখন অ্যাডমিন কানেকশন ব্যবহার করে
- [ ] পাবলিক API/MCP/ওয়েবহুক: আসল কী দিয়ে একবার হাতে পরীক্ষা
