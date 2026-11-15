# JARVIS Connector (Laravel প্যাকেজ)

আপনার নিজের Laravel ই-কমার্স শপকে JARVIS ONE-এর **ক্যাটালগ** সিস্টেমের সাথে যুক্ত করে। JARVIS শপ থেকে প্রোডাক্ট পড়ে, আপনি অনুমোদন করলে আপডেট ফেরত পাঠায়, আর স্টক মিলিয়ে রাখে।

> ⚠️ এই প্যাকেজ এখনো কোনো আসল Laravel শপে চালিয়ে দেখা হয়নি। প্রথমে **টেস্ট/স্টেজিং** শপে বসান।

## নিরাপত্তা (সংক্ষেপে)
- প্রতিটি অনুরোধ HMAC-SHA256 দিয়ে সই করা (সময় + একবার-ব্যবহারযোগ্য nonce + পাথ + বডি)। ৫ মিনিটের বেশি পুরনো বা দ্বিতীয়বার আসা অনুরোধ বাতিল।
- সিক্রেট ৩২ অক্ষরের কম হলে প্যাকেজ কিছুই করে না (৫০৩)।
- অনুরোধ থেকে কখনো SQL/কলামের নাম নেওয়া হয় না; কলাম-ম্যাপ কেবল আপনার কনফিগে।
- ছবির ঠিকানা শপে লেখা হয় না (ডিফল্ট), ডিলিট করা হয় না, ভ্যারিয়েন্ট মোছা হয় না।
- সবসময় **https** ব্যবহার করুন।

## ইনস্টল
```bash
# প্যাকেজ ফোল্ডারটি শপের packages/jarvis-connector এ রাখুন, তারপর শপের composer.json এ:
#   "repositories": [{ "type": "path", "url": "packages/jarvis-connector" }]
composer require jarvis/connector:@dev
php artisan vendor:publish --tag=jarvis-connector-config   # config/jarvis-connector.php
php artisan jarvis-connector:secret                        # একটি ৪৮-অক্ষরের সিক্রেট দেখাবে
```
সিক্রেটটি `.env` এ রাখুন: `JARVIS_CONNECTOR_SECRET=...`

## কলাম-ম্যাপ সাজান
`config/jarvis-connector.php` খুলে আপনার টেবিল/কলামের নাম লিখুন (উদাহরণ ফাইলেই আছে): `table`, `columns` (title, description, price, sale_price, sku, stock, images), `price_unit` (major = টাকা, minor = পয়সা), `where`, `soft_delete`, `insert_defaults` (যেমন `status => 1`), এবং ভ্যারিয়েন্ট টেবিল থাকলে `variants`।

তারপর যাচাই করুন:
```bash
php artisan jarvis-connector:check
```
এটি কলামগুলো আসল স্কিমায় আছে কি না দেখে এবং একটি পড়ার টেস্ট চালায়।

## JARVIS এ সংযুক্ত করুন
JARVIS → ক্যাটালগ → দোকান → প্ল্যাটফর্ম **JARVIS Connector** → শপের ঠিকানা (`https://yourshop.com`) ও সিক্রেট দিন → "যাচাই করে সংযুক্ত করুন"।

## এন্ডপয়েন্ট (`/jarvis-connector/v1`)
`GET ping` · `GET products?cursor&limit` · `GET products/{id}` · `PUT products/{id}` · `POST products` · `POST stock`

## ডেভেলপমেন্ট
`tests/` এ Handler ও SQL-স্টোরের টেস্ট আছে (sqlite)। Handler ফ্রেমওয়ার্ক-মুক্ত, তাই ফ্রেমওয়ার্ক ছাড়াও টেস্ট চলে।
