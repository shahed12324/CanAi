# JARVIS ONE — ডেস্কটপ অ্যাপ (Tauri)

ট্রে আইকন + নেটিভ জানালা। নিজের কোনো যুক্তি নেই: `jarvis-agent gui --no-browser --exit-with-parent` চালায়, সেটির ছাপা `http://127.0.0.1:<port>/#<token>` লিংকটি (কঠোরভাবে যাচাই করে) জানালায় খোলে।

- জানালা বন্ধ করলে ট্রেতে লুকায়; ট্রে মেনু: **খুলুন**, **বন্ধ করুন (এজেন্টসহ)**। বাম-ক্লিকে জানালা ফেরে।
- অ্যাপ হঠাৎ মরলেও (kill -9) এজেন্ট নিজে বন্ধ হয় (stdin পাইপ বন্ধ হওয়া ধরে)।
- পাতাটি কোনো Tauri API পায় না (`capabilities/default.json` খালি)।
- এজেন্ট খোঁজে: `$JARVIS_AGENT_BIN` → `jarvis-agent` → `python3 -m jarvis_agent`।

## চালানো (ডেভ)
```bash
pip install ./apps/pc-agent
# Linux: sudo apt install libwebkit2gtk-4.1-dev build-essential libxdo-dev libssl-dev libayatana-appindicator3-dev librsvg2-dev
cd apps/desktop && cargo run            # বা: cargo build --release
```
Windows-এ Microsoft C++ Build Tools ও WebView2 (Win11-এ আছে) লাগে; macOS-এ Xcode command line tools।

## যাচাই (কী হয়েছে / হয়নি)
- ✅ Linux: `cargo test` (৩), `cargo build`, Xvfb-তে আসল চালানো — নেটিভ জানালায় এজেন্টের পাতা এসেছে; kill -9-এ এজেন্ট বন্ধ।
- ❌ Windows/macOS-এ বানানো/চালানো এখানে সম্ভব হয়নি → `.github/workflows/desktop-agent.yml` তিন OS-এ চালায়। প্রথম রানের ফল আপনার GitHub-এ দেখতে হবে।
- ❌ ট্রে মেনুর ক্লিক (খুলুন/বন্ধ করুন) ও "এজেন্ট পাওয়া যায়নি" বার্তা স্বয়ংক্রিয়ভাবে পরীক্ষা হয়নি (Xvfb-তে ট্রে নেই)।
- ❌ ইনস্টলার (.msi/.dmg/.deb), কোড-সাইনিং, আইকন ডিজাইন (এখন সাদামাটা সবুজ বর্গ), অটো-আপডেট নেই।
