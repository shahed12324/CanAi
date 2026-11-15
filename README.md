# JARVIS ONE

> **Your Personal AI. Your Digital Employee. Your Business Automation Platform.**

JARVIS ONE একটি commercial, multi-tenant AI SaaS ecosystem। এতে একসাথে আছে Personal AI Assistant, AI Employee বিভাগ (Marketing, E-commerce, Finance), Agent ও Workflow Automation, Creative Studio, Desktop Computer Assistant এবং Mobile Control Center, সবকিছু একটা কেন্দ্রীয় Laravel Super Admin Panel থেকে নিয়ন্ত্রিত।

---

## বর্তমান অবস্থা

| বিষয় | অবস্থা |
|---|---|
| বর্তমান ফেজ | **ফেজ ০: পরিকল্পনা ও ডকুমেন্টেশন** (সম্পন্ন, অনুমোদনের অপেক্ষায়) |
| কোড | এখনো কোনো application code নেই, ফেজ ১ থেকে শুরু হবে |
| বিস্তারিত | [`DEVELOPMENT_STATUS.md`](DEVELOPMENT_STATUS.md) |

---

## Applications

| Application | প্রযুক্তি | কাজ |
|---|---|---|
| Super Admin Panel | Laravel + Blade + Livewire | পুরো SaaS নিয়ন্ত্রণ |
| Customer Dashboard | Laravel + Blade + Livewire | কাস্টমারের AI, এজেন্ট, বিভাগ, বিলিং |
| Public Website | Laravel + Blade | মার্কেটিং, প্রাইসিং, রেজিস্ট্রেশন |
| AI Execution Engine | Python + FastAPI (internal) | মডেল কল, এজেন্ট, RAG, ভয়েস, ভিশন |
| Mobile App | Expo + React Native (Android-first) | মনিটরিং, Approval Inbox, চ্যাট |
| Desktop App | Tauri 2 + React + TypeScript + Rust (Windows-first) | ভয়েস অ্যাসিস্ট্যান্ট, লোকাল AI, কম্পিউটার কন্ট্রোল |

**Laravel হলো authoritative source**: identity, tenant, permission, subscription, billing, credits। Python কখনো নিজে থেকে access বা balance বদলাতে পারে না।

---

## AI বিভাগসমূহ

1. **Personal Assistant**: চ্যাট, ভয়েস (বাংলা/ইংরেজি/বাংলিশ), মেমোরি, নলেজ বেস, Productivity Suite (ক্যালেন্ডার, টাস্ক, মিটিং), Coding Assistant, AI Character/Avatar, কম্পিউটার কন্ট্রোল ও System Monitor, অফলাইন লোকাল AI
2. **Marketing Department**: লিড এক্সট্র্যাক্টর, CRM, প্রপোজাল, ইমেইল আউটরিচ, সোশ্যাল মিডিয়া, Meta Ads, Google Ads, SEO, Creative Studio (ইমেজ, ভিডিও এডিটর)
3. **E-commerce Department**: স্টোর কানেক্টর, প্রোডাক্ট ম্যানেজার, ইউনিফাইড ইনবক্স, সাপোর্ট ও সেলস এজেন্ট, COD কনফার্মেশন, কুরিয়ার
4. **Finance Department**: Double-entry accounting, বিল স্ক্যান, ব্যাংক/bKash/Nagad, VAT (মূসক) ও ট্যাক্স, অডিট, পে-রোল

**চ্যানেল:** Web, Mobile, Desktop, Telegram, WhatsApp (Cloud API), Slack, Discord

**প্ল্যাটফর্ম:** Developer API, Plugin Marketplace, White-label ও Reseller

> **নোট:** "JARVIS ONE" কাজের নাম। লঞ্চের আগে নামের ট্রেডমার্ক আইনি যাচাই হবে (ARCHITECTURE R14); নাম কনফিগ থেকে আসে, তাই বদলানো সহজ।

---

## Documentation Map

| ডকুমেন্ট | বিষয় |
|---|---|
| [PROJECT_PLAN.md](PROJECT_PLAN.md) | ৩১ ফেজের সম্পূর্ণ পরিকল্পনা ও চেকলিস্ট |
| [ARCHITECTURE.md](ARCHITECTURE.md) | সিস্টেম আর্কিটেকচার, ডায়াগ্রাম, প্রযুক্তিগত সিদ্ধান্ত (ADR) |
| [DATABASE_SCHEMA.md](DATABASE_SCHEMA.md) | মডিউলভিত্তিক ডাটাবেস ডিজাইন ও ER ডায়াগ্রাম |
| [API_DOCUMENTATION.md](API_DOCUMENTATION.md) | `/api/v1` কনভেনশন, endpoint গ্রুপ, internal API |
| [SECURITY.md](SECURITY.md) | নিরাপত্তা মডেল, approval policy, compliance |
| [ENVIRONMENT_SETUP.md](ENVIRONMENT_SETUP.md) | লোকাল ডেভেলপমেন্ট পরিবেশ |
| [DEPLOYMENT_GUIDE.md](DEPLOYMENT_GUIDE.md) | VPS ও প্রোডাকশন ডিপ্লয়মেন্ট |
| [TESTING_GUIDE.md](TESTING_GUIDE.md) | টেস্ট কৌশল ও রিপোর্ট ফরম্যাট |
| [DEVELOPMENT_STATUS.md](DEVELOPMENT_STATUS.md) | ফেজভিত্তিক অগ্রগতি |
| [CHANGELOG.md](CHANGELOG.md) | পরিবর্তনের ইতিহাস |
| [PROJECT_HANDOVER.md](PROJECT_HANDOVER.md) | নতুন সেশনে কাজ চালিয়ে যাওয়ার নির্দেশিকা |
| [docs/diagrams/](docs/diagrams/) | সব Mermaid ডায়াগ্রামের সোর্স |

---

## Development Method

- ফেজ-বাই-ফেজ, প্রতিটা ফেজ শেষে **project owner-এর অনুমোদন** ছাড়া পরের ফেজ শুরু হয় না।
- প্রতিটা ফেজের শেষে: সম্পূর্ণ ZIP (secret ছাড়া), আসল টেস্ট রিপোর্ট, আপডেটেড ডকুমেন্টেশন।
- প্রতিটা নতুন সেশন শুরু হয় `PROJECT_HANDOVER.md` পড়ে।

## License

Proprietary. All rights reserved.
