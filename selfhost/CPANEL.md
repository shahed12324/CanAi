# cPanel/WHM সার্ভারে চালানো

> **শর্ত:** আপনার সার্ভার **VPS বা ডেডিকেটেড** (root/SSH পাওয়া যায়) হতে হবে। সাধারণ শেয়ার্ড হোস্টিংয়ে এটা চলে না (Docker, PostgreSQL, Redis লাগে)।
> **ধারণা:** প্রজেক্ট চলে Docker-এ (`127.0.0.1:8080`-এ), cPanel-এর Apache শুধু ডোমেইনটি সেখানে পাঠায় (reverse proxy) আর AutoSSL দেয়।
> **সততার কথা:** `install.sh --behind-proxy` এর `.env` তৈরি আমি নকল docker দিয়ে পরীক্ষা করেছি। Apache-প্রক্সি, AutoSSL ও আসল cPanel-এ চালানো **আমি যাচাই করতে পারিনি** — ধাপগুলো নথি অনুযায়ী লেখা, প্রথমবার আপনার সার্ভারে মিলিয়ে দেখুন।

## ০. দরকার
- AlmaLinux/Rocky 8-9 বা Ubuntu 22.04/24.04 (cPanel যেগুলো সমর্থন করে), ৪ GB+ RAM, ২ vCPU+, ২০ GB+ ডিস্ক।
- একটি আলাদা সাবডোমেইন (যেমন `app.yourdomain.com`) — মূল সাইটের সঙ্গে মেশাবেন না।

## ১. Docker বসান (root হিসেবে)
**AlmaLinux/Rocky:**
```bash
dnf -y install dnf-plugins-core
dnf config-manager --add-repo https://download.docker.com/linux/rhel/docker-ce.repo
dnf -y install docker-ce docker-ce-cli containerd.io docker-compose-plugin openssl unzip
systemctl enable --now docker
```
**Ubuntu:** https://docs.docker.com/engine/install/ubuntu/ অনুসরণ করুন, সঙ্গে `apt install -y openssl unzip`।
পরীক্ষা: `docker compose version`

> ⚠️ `quickstart.sh` **চালাবেন না** — ওটা `ufw` চালু করে ৮০/৪৪৩ নিজের জন্য নেয়, cPanel-এর সঙ্গে সংঘর্ষ হবে। নিচের ধাপ ব্যবহার করুন।

## ২. সাবডোমেইন বানান
cPanel → **Domains → Create A New Domain** (বা Subdomains): `app.yourdomain.com`। DNS-এ A রেকর্ড এই সার্ভারের IP-তে।

## ৩. প্রজেক্ট আপলোড ও ইনস্টল
`/home/<user>`-এর ভেতরে নয়, আলাদা ফোল্ডারে:
```bash
mkdir -p /opt/jarvis && cd /opt/jarvis
# jarvis-one-full.zip এখানে তুলুন (scp/SFTP), তারপর:
unzip -q jarvis-one-full.zip && cd selfhost
./install.sh --domain app.yourdomain.com --behind-proxy 8080
```
`--behind-proxy 8080` মানে: Caddy চালু হবে না, অ্যাপ শুধু `127.0.0.1:8080`-এ শোনে (বাইরে থেকে পৌঁছানো যায় না), `APP_URL=https://app.yourdomain.com`।
প্রথমবার বিল্ড কয়েক মিনিট নেয়। `.env`-এর `AI_VAULT_KEY_1` ও `APP_KEY` অফলাইনে ব্যাকআপ রাখুন।

## ৪. Apache-কে প্রক্সি করতে বলুন (WHM)
১) WHM → **EasyApache 4** → Currently Installed Packages → Apache Modules-এ `mod_proxy` ও `mod_proxy_http` আছে কিনা দেখুন (না থাকলে যোগ করে Provision)।
২) ডোমেইনের cPanel ইউজারনেম জানুন (`whmapi1 domainuserdata domain=app.yourdomain.com` বা WHM → List Accounts), ধরুন `myuser`।
৩) দুটি ফাইল বানান (HTTP ও HTTPS, দুটোই):
```bash
for D in std ssl; do
  mkdir -p /etc/apache2/conf.d/userdata/$D/2_4/myuser/app.yourdomain.com
  cat > /etc/apache2/conf.d/userdata/$D/2_4/myuser/app.yourdomain.com/jarvis.conf <<'CONF'
ProxyPreserveHost On
RequestHeader set X-Forwarded-Proto "https"
ProxyPass / http://127.0.0.1:8080/ timeout=300
ProxyPassReverse / http://127.0.0.1:8080/
CONF
done
/scripts/verify_vhost_includes
/scripts/rebuildhttpconf && systemctl restart httpd    # Ubuntu-তে: systemctl restart apache2
```
`verify_vhost_includes` ভুল দেখালে আগে ঠিক করুন, তারপর রিস্টার্ট।

## ৫. HTTPS (AutoSSL)
WHM → **Manage AutoSSL** চালু থাকলে সাবডোমেইনের সার্টিফিকেট নিজে আসে (ডোমেইন এই সার্ভারে পয়েন্ট করা থাকতে হবে; Cloudflare-এ প্রক্সি চালু থাকলে আগে বন্ধ রাখুন)। না এলে WHM → **Run AutoSSL For All Users** বা cPanel → SSL/TLS Status → Run AutoSSL।

## ৬. শেষ ধাপ
ব্রাউজারে `https://app.yourdomain.com` → register, তারপর `selfhost/README.md`-এর "পরের ধাপ" (`./artisan.sh user:verify …`, 2FA, `admin:promote`)।

## ফায়ারওয়াল
cPanel সাধারণত **CSF** বা firewalld চালায়। ৮০৮০ পোর্ট খুলবেন **না** (অ্যাপ শুধু 127.0.0.1-এ)। PostgreSQL/Redis কোথাও প্রকাশিত নয়।

## সমস্যা হলে
| লক্ষণ | সম্ভাব্য কারণ |
|---|---|
| 503/502 | `cd /opt/jarvis/selfhost && ./healthcheck.sh`; `docker compose ps`; ৮০৮০ শুনছে কিনা: `curl -I http://127.0.0.1:8080` |
| লগইনের পর আবার লগইন পাতা / http লিংক | প্রক্সি হেডার (`X-Forwarded-Proto`) ঠিক আছে কিনা; এই রিলিজে অ্যাপ নিজেও `X-Forwarded-*` বিশ্বাস করে (`scripts/bootstrap-web.sh`-এ প্যাচ — আসল বিল্ডে যাচাই হয়নি) |
| চ্যাটের উত্তর একসঙ্গে আসে, ধাপে ধাপে নয় | প্রক্সি বাফার করছে; `ProxyPass`-এ `flushpackets=on` যোগ করে দেখুন (আমি যাচাই করিনি) |
| লাইভ/রিয়েলটাইম (Reverb/WebSocket) কাজ করে না | এই গাইডে WebSocket প্রক্সি নেই; `mod_proxy_wstunnel` লাগবে — আলাদা করে সাজাতে হবে, আমি পরীক্ষা করিনি |
| cPanel আপডেটের পর প্রক্সি গেছে | `/etc/apache2/conf.d/userdata/…` ফাইল দুটি আছে কিনা দেখুন, `/scripts/rebuildhttpconf` আবার চালান |

আপডেট/ব্যাকআপ: `./update.sh`, `./backup.sh` (আগের মতো)।
