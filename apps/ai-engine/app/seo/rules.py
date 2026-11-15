"""SEO issue catalogue: code -> (severity, Bengali label, Bengali how-to-fix).  Deterministic; no AI call and no credits.

Severity weights decide the 0..100 score: every *kind* of problem costs its weight once, however many pages have it,
so a 50-page site is not punished 50 times for one missing template tag.
"""

from __future__ import annotations

WEIGHTS = {"high": 15, "medium": 7, "low": 3}

RULES: dict[str, tuple[str, str, str]] = {
    # site level
    "no_https": ("high", "সাইট HTTPS নয়", "SSL সার্টিফিকেট চালু করে সব পেজ HTTPS-এ রিডাইরেক্ট করুন; Google HTTPS পেজকে অগ্রাধিকার দেয়।"),
    "no_sitemap": ("medium", "sitemap.xml পাওয়া যায়নি", "একটি sitemap.xml বানান ও Google Search Console-এ জমা দিন, যাতে সব গুরুত্বপূর্ণ পেজ খুঁজে পাওয়া যায়।"),
    "no_robots": ("low", "robots.txt নেই", "একটি সাধারণ robots.txt রাখুন এবং তাতে sitemap-এর ঠিকানা দিন।"),
    # page level
    "page_error": ("high", "পেজ খোলে না (৪xx/৫xx)", "ভাঙা লিংক ঠিক করুন বা নতুন ঠিকানায় 301 রিডাইরেক্ট দিন।"),
    "no_title": ("high", "পেজ টাইটেল নেই", "প্রতিটি পেজে ৩০–৬০ অক্ষরের অনন্য <title> দিন, মূল শব্দ শুরুর দিকে রাখুন।"),
    "title_short": ("medium", "টাইটেল খুব ছোট (৩০ অক্ষরের কম)", "টাইটেলে পেজের বিষয় ও ব্র্যান্ড মিলিয়ে ৩০–৬০ অক্ষর করুন।"),
    "title_long": ("low", "টাইটেল খুব বড় (৬০ অক্ষরের বেশি)", "Google ফলাফলে কেটে যায়; ৬০ অক্ষরের মধ্যে রাখুন।"),
    "no_description": ("medium", "মেটা ডেসক্রিপশন নেই", "৭০–১৬০ অক্ষরের আকর্ষণীয় সারাংশ দিন; এটি ক্লিক বাড়ায়।"),
    "description_short": ("low", "মেটা ডেসক্রিপশন খুব ছোট (৭০ অক্ষরের কম)", "৭০–১৬০ অক্ষরে পেজ কী দেয় ও কেন ক্লিক করবেন তা লিখুন।"),
    "description_long": ("low", "মেটা ডেসক্রিপশন খুব বড় (১৬০ অক্ষরের বেশি)", "১৬০ অক্ষরের মধ্যে রাখুন, নইলে কেটে যায়।"),
    "no_h1": ("medium", "H1 হেডিং নেই", "প্রতিটি পেজে ঠিক একটি H1 দিন, যা পেজের মূল বিষয় বলে।"),
    "multi_h1": ("low", "একাধিক H1 হেডিং", "একটি H1 রাখুন; বাকিগুলো H2/H3 করুন।"),
    "no_lang": ("low", "html lang অ্যাট্রিবিউট নেই", "<html lang=\"bn\"> (বা en) দিন; ভাষা শনাক্ত ও অ্যাক্সেসিবিলিটির জন্য দরকার।"),
    "no_viewport": ("high", "মোবাইল viewport নেই", "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\"> দিন; নইলে মোবাইলে র‍্যাঙ্ক খারাপ হয়।"),
    "no_canonical": ("low", "canonical লিংক নেই", "ডুপ্লিকেট কনটেন্ট এড়াতে <link rel=\"canonical\"> দিন।"),
    "noindex": ("high", "পেজে noindex আছে (Google-এ আসবে না)", "পেজ ইনডেক্স করাতে চাইলে robots মেটা ট্যাগ থেকে noindex সরান।"),
    "img_no_alt": ("medium", "ছবিতে alt লেখা নেই", "প্রতিটি অর্থবহ ছবিতে বর্ণনামূলক alt দিন; ছবির সার্চ ও অ্যাক্সেসিবিলিটির জন্য।"),
    "thin_content": ("medium", "লেখা খুব কম (৩০০ শব্দের কম)", "পেজে গ্রাহকের প্রশ্নের উত্তরসহ আরও কার্যকর লেখা যোগ করুন।"),
    "no_og": ("low", "Open Graph ট্যাগ নেই", "og:title, og:description, og:image দিন; Facebook/WhatsApp শেয়ারে সুন্দর দেখায়।"),
    "no_schema": ("low", "স্ট্রাকচার্ড ডেটা (JSON-LD) নেই", "ব্যবসা/পণ্যের জন্য schema.org JSON-LD যোগ করুন; রিচ রেজাল্টের সুযোগ বাড়ে।"),
    "dup_title": ("medium", "একাধিক পেজে একই টাইটেল", "প্রতিটি পেজের টাইটেল আলাদা ও নির্দিষ্ট করুন।"),
    "dup_description": ("low", "একাধিক পেজে একই মেটা ডেসক্রিপশন", "প্রতিটি পেজের ডেসক্রিপশন আলাদা করুন।"),
    # keyword (only when the owner gave one)
    "kw_not_in_title": ("medium", "টার্গেট শব্দ টাইটেলে নেই", "মূল শব্দটি টাইটেলে, বিশেষত শুরুর দিকে রাখুন।"),
    "kw_not_in_h1": ("low", "টার্গেট শব্দ H1-এ নেই", "H1-এ মূল শব্দটি স্বাভাবিকভাবে রাখুন।"),
    "kw_not_in_intro": ("low", "টার্গেট শব্দ প্রথম অংশে নেই", "পেজের প্রথম ১০০ শব্দের মধ্যে মূল শব্দটি রাখুন।"),
}


def score(codes: set[str]) -> int:
    return max(0, 100 - sum(WEIGHTS[RULES[c][0]] for c in codes))
