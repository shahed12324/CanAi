"""The PHP MemoryGuard and the database memory_check_text() must agree on what is allowed."""
import json
import subprocess

import pytest

CASES = [
    "", "   ", "prefers short answers", "my password is hunter2", "Password: abc", "wifi passcode 12345", "the OTP was 482913", "my pin is 4821", "I like pinterest", "cvv 123", "API key abc", "api_key=zzz",
    "api-key", "private key here", "access token xyz", "we tokenize text", "client secret", "টোকেন আছে", "আমার পাসওয়ার্ড abc", "ওটিপি ৪৮২৯১৩", "পিন নম্বর ১২৩৪", "আমি পিন্টুর সাথে কাজ করি", "সিক্রেট কোড", "ব্যবসার নাম গোল্ডভেস্ট",
    "card 4111 1111 1111 1111", "card 4111-1111-1111-1111", "NID 1234567890123", "phone 01712345678", "order 123456789012 late", "কার্ড ৪১১১১১১১১১১১১১১১", "১২৩৪৫৬৭৮৯০১২৩৪", "sk-abcdefghijklmnopqrstuvwxyz123456",
    "AKIAABCDEFGHIJKLMNOP1", "ghp_abcdefghijklmnopqrstuvwxyz0123", "xoxb-abcdefghijklmnopqrstu", "a" * 32, "a" * 31, "Xk3-" + "ab9" * 12, "ক" * 500, "ক" * 501, "x" * 500, "  padded  ", "Dhaka, 1212", "২০২৬ সালে ১২ লক্ষ টাকা",
]


def php_results():
    code = '<?php require "app/Memory/MemoryGuard.php"; $in = json_decode(stream_get_contents(STDIN), true); echo json_encode(array_map(fn ($t) => App\\Memory\\MemoryGuard::check($t), $in), JSON_UNESCAPED_UNICODE);'
    r = subprocess.run(["php", "-r", code.removeprefix("<?php ")], input=json.dumps(CASES), capture_output=True, text=True, cwd="apps/web-overlay")
    assert r.returncode == 0, r.stderr
    return json.loads(r.stdout)


def test_php_and_sql_agree(app):
    php = php_results()
    sql = [app.execute("SELECT memory_check_text(%s)", (t,)).fetchone()[0] for t in CASES]
    bad = [(t[:40], p, s) for t, p, s in zip(CASES, php, sql) if p != s]
    assert bad == []


@pytest.mark.parametrize("text,expected", [("prefers short answers", "ok"), ("my password is x", "sensitive"), ("", "empty"), ("ক" * 501, "too_long"), ("১২৩৪৫৬৭৮৯০১২৩৪", "sensitive")])
def test_spot_checks_in_sql(app, text, expected):
    assert app.execute("SELECT memory_check_text(%s)", (text,)).fetchone()[0] == expected
