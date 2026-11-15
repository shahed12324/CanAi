"""Rule-based site analysis: service gaps + lead score. Deterministic and explainable (no AI call, no credits)."""

from __future__ import annotations

from app.leads.extract import PageFacts

# code -> (Bengali label, weight in score, service we could offer)
GAPS: dict[str, tuple[str, int, str]] = {
    "no_https": ("সাইট HTTPS নয়", 8, "ওয়েবসাইট সিকিউরিটি/SSL"),
    "no_viewport": ("মোবাইল-বান্ধব নয়", 8, "রেসপনসিভ ওয়েবসাইট রিডিজাইন"),
    "no_title": ("পেজ টাইটেল নেই", 4, "SEO"),
    "no_description": ("মেটা ডেসক্রিপশন নেই", 4, "SEO"),
    "no_analytics": ("অ্যানালিটিক্স নেই", 6, "ট্র্যাকিং ও অ্যানালিটিক্স সেটআপ"),
    "no_fb_pixel": ("Facebook Pixel নেই", 6, "Meta Ads ট্র্যাকিং/বিজ্ঞাপন"),
    "no_contact_form": ("যোগাযোগ ফর্ম নেই", 4, "লিড ক্যাপচার ফর্ম"),
    "no_social": ("সোশ্যাল মিডিয়া লিংক নেই", 4, "সোশ্যাল মিডিয়া ম্যানেজমেন্ট"),
    "no_whatsapp": ("WhatsApp যোগাযোগ নেই", 3, "WhatsApp চ্যাট/অটোমেশন"),
    "no_phone": ("ফোন নম্বর দেওয়া নেই", 3, "যোগাযোগ তথ্য উন্নয়ন"),
    "old_cms": ("পুরনো/সাধারণ বিল্ডার (Wix/Joomla)", 3, "কাস্টম ওয়েবসাইট"),
}
GAP_CAP = 40


def find_gaps(pages: list[PageFacts], final_url: str, has_phone: bool) -> list[dict[str, object]]:
    home = pages[0]
    track = {
        k: any(p.tracking.get(k) for p in pages) for k in ("ga", "gtm", "fb_pixel", "tiktok_pixel")
    }
    codes: list[str] = []
    if not final_url.lower().startswith("https://"):
        codes.append("no_https")
    if not home.has_viewport:
        codes.append("no_viewport")
    if not home.title:
        codes.append("no_title")
    if not home.description:
        codes.append("no_description")
    if not (track["ga"] or track["gtm"]):
        codes.append("no_analytics")
    if not track["fb_pixel"]:
        codes.append("no_fb_pixel")
    if not any(p.has_form for p in pages):
        codes.append("no_contact_form")
    if not any(p.social for p in pages):
        codes.append("no_social")
    if not any(p.has_whatsapp for p in pages):
        codes.append("no_whatsapp")
    if not has_phone:
        codes.append("no_phone")
    if any(t in ("wix", "joomla") for p in pages for t in p.tech):
        codes.append("old_cms")
    return [
        {"code": c, "label": GAPS[c][0], "weight": GAPS[c][1], "service": GAPS[c][2]} for c in codes
    ]


def lead_score(
    *,
    phones: list[tuple[str, str]],
    emails: list[dict[str, object]],
    has_social: bool,
    has_form: bool,
    gaps: list[dict[str, object]],
) -> int:
    """0..100 = contactability (max 50) + opportunity from gaps (max 40) + reachable site (10)."""
    s = 10
    if any(k == "mobile" and e164.startswith("+880") for e164, k in phones):
        s += 20
    elif phones:
        s += 10
    good = [e for e in emails if e.get("status") == "mx_ok"]
    if good:
        s += 20 + (5 if any(e.get("same_domain") for e in good) else 0)
    elif emails:
        s += 8
    s += 3 if has_social else 0
    s += 2 if has_form else 0
    s += min(GAP_CAP, sum(int(g["weight"]) for g in gaps))  # type: ignore[call-overload]
    return max(0, min(100, s))
