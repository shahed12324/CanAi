# Jarvis One — নিরাপত্তা অডিট (ফেজ ২৮)

তারিখ: ২০২৬-১১-০৯। পরিসর: ডাটাবেস (Postgres RLS/ফাংশন/গ্রান্ট), PHP/Blade কোড, Python ইঞ্জিন, ডিপেন্ডেন্সি। পুনরায় চালাতে: `scripts/security-audit.sh`।

## ১. পাওয়া ও সারানো সমস্যা

| # | সমস্যা | ঝুঁকি | সমাধান |
|---|---|---|---|
| 1 | ৩টি SECURITY DEFINER ফাংশন (`order_create`, `order_transition`, `license_seat_guard`) PUBLIC-এ execute-যোগ্য | নিম্ন (ডাটাবেসের যেকোনো রোল চালাতে পারত) | `phase28a`: PUBLIC থেকে REVOKE |
| 2 | ৫টি সাফাই-ফাংশন (`credit_expire_lots`, `credit_release_stale`, `chat_finalize_stale`, `lead_items_requeue_stale`, `channel_events_purge`) সব workspace-এ কাজ করে, অথচ অ্যাপ-রোল চালাতে পারত | মাঝারি (অ্যাপ কানেকশন দখল হলে ডিডুপ-টেবিল মোছা, স্ট্রিম জোর করে শেষ করা) | `phase28a`: শুধু `jarvis_admin`; ৫টি artisan কমান্ড অ্যাডমিন কানেকশনে সরানো |
| 3 | ২টি ভিউ (`model_current_pricing`, `social_post_latest_stats`)-তে অ্যাপ-রোলের INSERT/UPDATE/DELETE (ডিফল্ট প্রিভিলেজ থেকে; ভিউ আপডেটযোগ্য নয়, তাই নিষ্ক্রিয়) | নিম্ন (প্রতিরক্ষামূলক) | `phase28a`: REVOKE |
| 4 | `studio/compose.py`-তে আক্ষরিক bidi কন্ট্রোল অক্ষর (Trojan Source প্যাটার্ন; আসলে ফিল্টার-regex) | নিম্ন | `\u` এস্কেপে বদল (সব কোড-পয়েন্টে আগের regex-এর সমতুল্য যাচাই করা) |
| 5 | npm: `vitest`/`tinypool` (critical, শুধু ডেভ/টেস্ট) | নিম্ন (শুধু টেস্ট চালানোর সময়) | `vitest` ^3.2 → ^5.1; টেস্ট ২৬/২৬ পাস, typecheck পরিষ্কার |
| 6 | বাগ (ফেজ ২৭a): রিসেলার কোড মেলানোয় citext↔text তুলনা case-sensitive ছিল | নিম্ন | সারানো (আগের ধাপেই) |

## ২. যা ঠিক আছে বলে যাচাই হয়েছে (স্থায়ী টেস্ট)

- `tests/db/test_security_audit.py` (১৪): প্রতিটি DEFINER ফাংশনে `search_path` পিন; কোনোটি PUBLIC-এ নয়; সাফাই-ফাংশন অ্যাপ-রোলের জন্য বন্ধ; ভিউ শুধু-পড়া; রোলে superuser/bypassrls নেই; সিক্রেট কলাম অ্যাপ-রোল পড়তে পারে না; API কী শুধু হ্যাশ; **tenant-id নেয় এমন প্রতিটি ফাংশন অনুমোদন-গার্ড কল করে** (পর্যালোচিত ছাড়াও তালিকা); কোনো RLS পলিসি `true` নয়। `test_rls.py` সব `tenant_id`-টেবিলে RLS+FORCE চেক করে।
- `SecurityAuditTest.php` (১২): `{!! !!}` মাত্র ৩ জায়গায় (সবগুলোর কারণ লেখা); `eval/exec/shell_exec/unserialize` নেই; `proc_open` শুধু আর্গুমেন্ট-অ্যারে; SQL-এ ভেরিয়েবল ইন্টারপোলেশন শুধু ৩টি পর্যালোচিত জায়গায় (সব আক্ষরিক স্ট্রিং থেকে); হার্ড-কোড সিক্রেট নেই; টোকেনে `rand/mt_rand/uniqid` নেই; `md5/sha1` শুধু গেটওয়ে/ফিঙ্গারপ্রিন্টে; অ্যাডমিন DB কানেকশন শুধু অনুমোদিত তালিকায়; অ্যাডমিন কন্ট্রোলারের প্রতি অ্যাকশনে পারমিশন-চেক; পাবলিক API-র প্রতি রুটে কী লাগে; প্রতি ফাইল-আপলোড ফিল্ডে সাইজ-সীমা।
- `apps/ai-engine/tests/test_security_audit.py` (৬): ইঞ্জিনের প্রতিটি রুটে সাইন-করা রিকোয়েস্ট লাগে (শুধু `/healthz` খোলা), প্রতিটি `routes_*.py` সত্যিই মাউন্ট করা, `exec/eval/pickle` নেই, bidi নেই, সিক্রেট নেই। (এই টেস্ট লেখার সময় একটি ভুয়া-পাস ধরা পড়ে: নতুন FastAPI রাউটার র‍্যাপ করে, তাই রাউটার ভেদ করে দেখা হয়।)
- bandit: High/Medium নেই (১টি Low — ইচ্ছাকৃত best-effort `except: pass`); pip-audit: কিছু নেই।

## ৩. গৃহীত ঝুঁকি (জেনে রাখা, পরিবর্তন করা হয়নি)

1. `users`, `tenants`, `tenant_users`, `sessions`, `password_reset_tokens`-এ RLS নেই — লগইনের সময় tenant জানার আগেই পড়তে হয়। সুরক্ষা: অ্যাপ-কোডে scope, `is_platform_admin` শুধু অ্যাডমিন-কানেকশনে বদলানো যায় (DB টেস্টে প্রমাণিত), mass-assignment বন্ধ।
2. `provider_secret_for_call` ও `channel_account_for_send` অ্যাপ-রোলকে **এনক্রিপ্টেড** সিক্রেট দেয় (অ্যাপের কাজেই লাগে); ডিক্রিপ্ট-কী শুধু অ্যাপের পরিবেশে; ID অনুমান-অযোগ্য (ULID)।
3. npm: expo বিল্ড-টুলচেইনে ৮টি moderate (`minimatch`/`glob` DoS) — অ্যাপ বান্ডেলে যায় না; ফিক্স শুধু breaking expo আপগ্রেডে।
4. ~২৯টি কম-খরচের POST/PUT/DELETE রুটে আলাদা throttle নেই (মুছা/স্ট্যাটাস বদল ইত্যাদি); খরচ-সৃষ্টিকারী (AI) রুট ক্রেডিট-সীমিত।

## ৪. এখানে যাচাই করা যায়নি (আপনার মেশিনে/ডিপ্লয়ে করতে হবে)

Laravel স্তর কখনো চালানো হয়নি (Packagist অনুপলব্ধ), তাই HTTP-স্তরের এইগুলো বাকি: CSRF, সেশন কুকি (`Secure/HttpOnly/SameSite`), লগইন rate-limit, 2FA ফ্লো, ৪০৩/৪০৪ আচরণ, নিরাপত্তা হেডার (CSP/HSTS/X-Frame-Options), `APP_DEBUG=false`, TLS, ব্যাকআপ, DB রোল-পাসওয়ার্ড, ফায়ারওয়াল। ডিপ্লয়ের আগে `docs/SECURITY_CHECKLIST.md` মিলিয়ে নিন এবং একবার কোনো স্ক্যানার (যেমন OWASP ZAP baseline) স্টেজিংয়ে চালান।
