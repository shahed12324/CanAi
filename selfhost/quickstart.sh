#!/usr/bin/env bash
# JARVIS ONE - one-command setup for a FRESH Ubuntu/Debian server (run as root, from the unzipped folder: jarvis/selfhost).
#   sudo ./quickstart.sh                                   # asks two questions
#   sudo ./quickstart.sh --domain app.example.com --email you@example.com
set -Eeuo pipefail
trap 'echo "ERROR at line $LINENO: $BASH_COMMAND" >&2' ERR
cd "$(dirname "${BASH_SOURCE[0]}")"
DOMAIN=""; EMAIL=""
while [ $# -gt 0 ]; do
  case "$1" in
    --domain) DOMAIN="${2:?}"; shift 2 ;;
    --email) EMAIL="${2:?}"; shift 2 ;;
    -h|--help) sed -n 2,5p "$0"; exit 0 ;;
    *) echo "unknown option: $1" >&2; exit 2 ;;
  esac
done
step() { printf '\n==> %s\n' "$*"; }
[ "$(id -u)" -eq 0 ] || { echo "Run as root:  sudo ./quickstart.sh" >&2; exit 1; }
command -v apt-get >/dev/null || { echo "This script is for Ubuntu/Debian (apt). On another system see README.md." >&2; exit 1; }

if [ -z "$DOMAIN" ] && [ -t 0 ]; then
  read -r -p "ডোমেইন আছে? (যেমন app.example.com; না থাকলে শুধু Enter — IP দিয়ে চলবে): " DOMAIN || true
fi
if [ -n "$DOMAIN" ] && [ -z "$EMAIL" ] && [ -t 0 ]; then
  read -r -p "ইমেইল (HTTPS সার্টিফিকেটের জন্য): " EMAIL || true
fi
if [ -n "$DOMAIN" ] && [ -z "$EMAIL" ]; then echo "--email is required with --domain" >&2; exit 2; fi

MEM_MB=$(awk '/MemTotal/ {print int($2/1024)}' /proc/meminfo)
if [ "${MEM_MB:-0}" -lt 3500 ]; then echo "⚠ RAM ${MEM_MB} MB: কমপক্ষে ৪ GB দরকার; কম হলে বিল্ড ব্যর্থ হতে পারে।"; fi

step "প্রয়োজনীয় প্যাকেজ"
apt-get update -y
DEBIAN_FRONTEND=noninteractive apt-get install -y curl unzip openssl ca-certificates ufw

step "Docker"
if ! command -v docker >/dev/null || ! docker compose version >/dev/null 2>&1; then
  curl -fsSL https://get.docker.com | sh
fi
docker compose version

step "ফায়ারওয়াল (SSH, 80, 443 ছাড়া সব বন্ধ)"
ufw allow OpenSSH >/dev/null
ufw allow 80/tcp >/dev/null
ufw allow 443/tcp >/dev/null
ufw --force enable >/dev/null

step "JARVIS ONE ইনস্টল (প্রথমবার ১০–২০ মিনিট)"
chmod +x ./*.sh
if [ -n "$DOMAIN" ]; then ./install.sh --domain "$DOMAIN" --email "$EMAIL"; else ./install.sh; fi

step "স্বাস্থ্য পরীক্ষা"
./healthcheck.sh || echo "⚠ কিছু অংশ এখনো ওঠেনি — ১–২ মিনিট পরে ./healthcheck.sh আবার চালান।"

step "প্রতিদিনের স্বয়ংক্রিয় ব্যাকআপ (রাত ২:১৫)"
CRON="15 2 * * * cd $(pwd) && ./backup.sh"
( crontab -l 2>/dev/null | grep -vF "./backup.sh" || true; echo "$CRON" ) | crontab -

URL=$([ -n "$DOMAIN" ] && echo "https://$DOMAIN" || echo "http://$(hostname -I | awk '{print $1}')")
cat <<DONE

✅ চালু হয়েছে:  $URL

এখন:
 1. ব্রাউজারে $URL খুলে রেজিস্টার করুন।
 2. এখানে চালান (আপনার ইমেইল দিয়ে):
      ./artisan.sh user:verify you@example.com
      ./artisan.sh admin:promote you@example.com
 3. জরুরি — এই দুই লাইন নিজের কম্পিউটারে/পাসওয়ার্ড ম্যানেজারে আলাদা করে রাখুন (হারালে ডেটা উদ্ধার হয় না):
      grep -E "^(APP_KEY|AI_VAULT_KEY_1)=" $(pwd)/.env
DONE
