<?php

use App\Calendar\CalendarService;
use App\Calendar\HttpIcsFetcher;
use App\Calendar\Ics;
use App\Calendar\IcsException;
use App\Links\ChatHooks;
use App\Links\WebhookUrl;
use App\Workflow\Risk;
use App\Workflow\ToolContext;
use App\Workflow\ToolException;
use App\Workflow\Tools\CalendarUpcomingTool;
use App\Workflow\Tools\ChatPostTool;
use Tests\Support\FakeIcsFetcher;
use Tests\Support\FakeLinkRepo;
use Tests\Support\FakePoster;
use Tests\Support\FakeSecrets;

const CT = 'TENANT0000000000000000000A';
const CU = 'USER000000000000000000000A';
const CU2 = 'USER000000000000000000000B';

function cal(string ...$events): string
{
    return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n".implode('', array_map(fn ($e) => "BEGIN:VEVENT\r\n".trim($e)."\r\nEND:VEVENT\r\n", $events))."END:VCALENDAR\r\n";
}

function win(string $ics, string $from, string $to, string $tz = 'Asia/Dhaka'): array
{
    $z = new DateTimeZone($tz);

    return array_map(fn ($e) => $e['start']->setTimezone($z)->format('Y-m-d H:i').'|'.$e['summary'], Ics::between(Ics::parse($ics, $tz), new DateTimeImmutable($from, $z), new DateTimeImmutable($to, $z)));
}

// ---------- reading
it('reads a timed UTC event and shows it in the person\'s zone', function () {
    $ics = cal("UID:1\nSUMMARY:মিটিং\nDTSTART:20261105T040000Z\nDTEND:20261105T050000Z\nLOCATION:অফিস");
    expect(win($ics, '2026-11-05 00:00', '2026-11-06 00:00'))->toBe(['2026-11-05 10:00|মিটিং']);
    $e = Ics::between(Ics::parse($ics), new DateTimeImmutable('2026-11-05 00:00 UTC'), new DateTimeImmutable('2026-11-06 00:00 UTC'))[0];
    expect($e['location'])->toBe('অফিস');
    expect($e['all_day'])->toBeFalse();
    expect($e['end']->format('H:i'))->toBe('05:00');
});

it('reads all-day, TZID, Windows zone names and floating times', function () {
    $ics = cal("UID:a\nSUMMARY:ছুটি\nDTSTART;VALUE=DATE:20261106\nDTEND;VALUE=DATE:20261107",
        "UID:b\nSUMMARY:নিউইয়র্ক\nDTSTART;TZID=America/New_York:20261105T090000\nDTEND;TZID=America/New_York:20261105T100000",
        "UID:c\nSUMMARY:উইন্ডোজ\nDTSTART;TZID=Bangladesh Standard Time:20261105T150000\nDTEND;TZID=Bangladesh Standard Time:20261105T160000",
        "UID:d\nSUMMARY:ভাসমান\nDTSTART:20261105T180000\nDTEND:20261105T190000");
    $r = win($ics, '2026-11-05 00:00', '2026-11-08 00:00');
    expect($r)->toBe(['2026-11-05 15:00|উইন্ডোজ', '2026-11-05 18:00|ভাসমান', '2026-11-05 20:00|নিউইয়র্ক', '2026-11-06 00:00|ছুটি']);
    $all = Ics::between(Ics::parse($ics, 'Asia/Dhaka'), new DateTimeImmutable('2026-11-06 00:00 Asia/Dhaka'), new DateTimeImmutable('2026-11-07 00:00 Asia/Dhaka'));
    expect($all[0]['all_day'])->toBeTrue();
});

it('uses the calendar\'s own zone for floating times and unknown zone names fall back to it', function () {
    $ics = "BEGIN:VCALENDAR\r\nX-WR-TIMEZONE:Asia/Tokyo\r\nBEGIN:VEVENT\r\nUID:1\r\nSUMMARY:x\r\nDTSTART:20261105T090000\r\nEND:VEVENT\r\nBEGIN:VEVENT\r\nUID:2\r\nSUMMARY:y\r\nDTSTART;TZID=Nowhere/Land:20261105T100000\r\nEND:VEVENT\r\nEND:VCALENDAR";
    expect(win($ics, '2026-11-05 00:00', '2026-11-06 00:00', 'Asia/Tokyo'))->toBe(['2026-11-05 09:00|x', '2026-11-05 10:00|y']);
});

it('unfolds long lines, unescapes text and cleans control characters and length', function () {
    $ics = "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:1\r\nSUMMARY:লম্বা\r\n  শিরোনাম\\, আরও\\nনতুন\x07লাইন\r\nDTSTART:20261105T040000Z\r\nEND:VEVENT\r\nEND:VCALENDAR";
    $e = Ics::parse($ics)[0];
    expect($e['summary'])->toBe('লম্বা শিরোনাম, আরও নতুন লাইন');
    expect(mb_strlen(Ics::text(str_repeat('ক', 300), 200)))->toBe(200);
    expect(Ics::looksLikeCalendar("junk\nBEGIN:VCALENDAR"))->toBeTrue();
    expect(Ics::looksLikeCalendar('<html>'))->toBeFalse();
});

it('skips broken events and lines instead of failing', function () {
    $ics = cal("UID:1\nSUMMARY:no start", "UID:2\nSUMMARY:bad\nDTSTART:20261305T040000Z", "UID:3\nSUMMARY:bad2\nDTSTART:20261105T250000Z", "UID:4\nSUMMARY:bad3\nDTSTART:hello",
        "UID:5\nSUMMARY:ঠিক\nDTSTART:20261105T040000Z\nDTEND:20261105T030000Z\nGARBAGE", "UID:6\nSUMMARY:বাতিল\nSTATUS:CANCELLED\nDTSTART:20261105T040000Z");
    expect(win($ics, '2026-11-05 00:00', '2026-11-06 00:00'))->toBe(['2026-11-05 10:00|OK']);
    expect(Ics::parse('rubbish'))->toBe([]);
});

it('knows when an event is inside the window: end excluded, start excluded, spanning events kept', function () {
    $ics = cal("UID:1\nSUMMARY:শেষ\nDTSTART:20261105T000000Z\nDTEND:20261105T060000Z", "UID:2\nSUMMARY:শুরু\nDTSTART:20261106T000000Z\nDTEND:20261106T010000Z", "UID:3\nSUMMARY:দীর্ঘ\nDTSTART:20261104T000000Z\nDTEND:20261107T000000Z", "UID:4\nSUMMARY:সময়\nDURATION:PT2H\nDTSTART:20261105T100000Z");
    $r = array_map(fn ($x) => explode('|', $x)[1], win($ics, '2026-11-05 12:00', '2026-11-06 06:00'));
    expect($r)->toBe(['দীর্ঘ', 'Period']);
});

// ---------- repeats
it('repeats daily with interval, count, until and skipped dates', function () {
    $d = fn (string $rule, string $extra = '') => cal("UID:1\nSUMMARY:দৈনিক\nDTSTART:20261101T040000Z\nDTEND:20261101T050000Z\nRRULE:{$rule}{$extra}");
    expect(win($d('FREQ=DAILY;COUNT=3'), '2026-11-01 00:00', '2026-11-30 00:00'))->toBe(['2026-11-01 10:00|Daily', '2026-11-02 10:00|Daily', '2026-11-03 10:00|Daily']);
    expect(count(win($d('FREQ=DAILY;INTERVAL=2'), '2026-11-01 00:00', '2026-11-08 00:00')))->toBe(4);
    expect(win($d('FREQ=DAILY;UNTIL=20261103T040000Z'), '2026-11-01 00:00', '2026-11-30 00:00'))->toBe(['2026-11-01 10:00|دৈনিক' === '' ? '' : '2026-11-01 10:00|Daily', '2026-11-02 10:00|Daily', '2026-11-03 10:00|Daily']);
    $ex = cal("UID:1\nSUMMARY:দৈনিক\nDTSTART:20261101T040000Z\nDTEND:20261101T050000Z\nRRULE:FREQ=DAILY;COUNT=3\nEXDATE:20261102T040000Z");
    expect(win($ex, '2026-11-01 00:00', '2026-11-30 00:00'))->toBe(['2026-11-01 10:00|Daily', '2026-11-03 10:00|Daily']);
    expect(count(win($d('FREQ=DAILY;UNTIL=20261103'), '2026-11-01 00:00', '2026-11-30 00:00')))->toBe(3);   // a date-only UNTIL includes that day
});

it('repeats weekly on chosen days, every second week', function () {
    $w = cal("UID:1\nSUMMARY:সাপ্তাহিক\nDTSTART:20261102T040000Z\nDTEND:20261102T050000Z\nRRULE:FREQ=WEEKLY;BYDAY=MO,WE,FR");
    expect(win($w, '2026-11-01 00:00', '2026-11-15 00:00'))->toBe(['2026-11-02 10:00|সাপ্তাহিক', '2026-11-04 10:00|সাপ্তাহিক', '2026-11-06 10:00|সাপ্তাহিক', '2026-11-09 10:00|সাপ্তাহিক', '2026-11-11 10:00|সাপ্তাহিক', '2026-11-13 10:00|সাপ্তাহিক']);
    $b = cal("UID:1\nSUMMARY:দ্বি-সাপ্তাহিক\nDTSTART:20261102T040000Z\nDTEND:20261102T050000Z\nRRULE:FREQ=WEEKLY;INTERVAL=2;BYDAY=MO");
    expect(win($b, '2026-11-01 00:00', '2026-12-10 00:00'))->toBe(['2026-11-02 10:00|দ্বি-সাপ্তাহিক', '2026-11-16 10:00|দ্বি-সাপ্তাহিক', '2026-11-30 10:00|দ্বি-সাপ্তাহিক']);
    $plain = cal("UID:1\nSUMMARY:বুধ\nDTSTART:20261104T040000Z\nRRULE:FREQ=WEEKLY");     // no BYDAY: the start day
    expect(win($plain, '2026-11-01 00:00', '2026-11-20 00:00'))->toBe(['2026-11-04 10:00|Wed', '2026-11-11 10:00|Wed', '2026-11-18 10:00|Wed']);
    $c = cal("UID:1\nSUMMARY:গণনা\nDTSTART:20261102T040000Z\nRRULE:FREQ=WEEKLY;COUNT=2;BYDAY=MO,TU,WE");
    expect(count(win($c, '2026-11-01 00:00', '2026-12-30 00:00')))->toBe(2);
});

it('repeats monthly (skipping months without that day) and yearly (Feb 29 only in leap years)', function () {
    $m = cal("UID:1\nSUMMARY:মাসিক\nDTSTART:20261031T040000Z\nRRULE:FREQ=MONTHLY");
    expect(win($m, '2026-10-01 00:00', '2027-03-01 00:00'))->toBe(['2026-10-31 10:00|Monthly', '2026-12-31 10:00|Monthly', '2027-01-31 10:00|Monthly']);
    $y = cal("UID:1\nSUMMARY:বার্ষিক\nDTSTART:20240229T040000Z\nRRULE:FREQ=YEARLY");
    expect(win($y, '2024-01-01 00:00', '2029-01-01 00:00'))->toBe(['2024-02-29 10:00|Yearly', '2028-02-29 10:00|Yearly']);
    $iv = cal("UID:1\nSUMMARY:ত্রৈমাসিক\nDTSTART:20260115T040000Z\nRRULE:FREQ=MONTHLY;INTERVAL=3");
    expect(win($iv, '2026-01-01 00:00', '2027-01-01 00:00'))->toBe(['2026-01-15 10:00|Quarterly', '2026-04-15 10:00|Quarterly', '2026-07-15 10:00|Quarterly', '2026-10-15 10:00|Quarterly']);
});

it('finds the repeats of an event that started years ago, and keeps interval alignment', function () {
    $old = cal("UID:1\nSUMMARY:পুরনো\nDTSTART:20150101T040000Z\nRRULE:FREQ=DAILY");
    expect(win($old, '2026-11-05 00:00', '2026-11-07 00:00'))->toBe(['2026-11-05 10:00|পুরনো', '2026-11-06 10:00|পুরনো']);
    $three = cal("UID:1\nSUMMARY:তিনদিন\nDTSTART:20150101T040000Z\nRRULE:FREQ=DAILY;INTERVAL=3");
    $r = win($three, '2026-11-01 00:00', '2026-11-12 00:00');
    expect(count($r) > 2)->toBeTrue();
    $days = array_map(fn ($x) => (int) ((strtotime(substr($x, 0, 10)) - strtotime('2015-01-01')) / 86400), $r);
    expect(array_unique(array_map(fn ($d) => $d % 3, $days)))->toBe([0 => 0]);
    $w = cal("UID:1\nSUMMARY:সাপ্তাহিক-পুরনো\nDTSTART:20150105T040000Z\nRRULE:FREQ=WEEKLY");
    expect(win($w, '2026-11-01 00:00', '2026-11-08 00:00'))->toBe(['2026-11-02 10:00|সাপ্তাহিক-পুরনো']);
    $mo = cal("UID:1\nSUMMARY:মাসিক-পুরনো\nDTSTART:20150110T040000Z\nRRULE:FREQ=MONTHLY");
    expect(win($mo, '2026-11-01 00:00', '2026-12-01 00:00'))->toBe(['2026-11-10 10:00|মাসিক-পুরনো']);
    $yr = cal("UID:1\nSUMMARY:বার্ষিক-পুরনো\nDTSTART:20151111T040000Z\nRRULE:FREQ=YEARLY");
    expect(win($yr, '2026-11-01 00:00', '2026-12-01 00:00'))->toBe(['2026-11-11 10:00|বার্ষিক-পুরনো']);
});

it('keeps the clock time across daylight saving changes', function () {
    $ics = cal("UID:1\nSUMMARY:ডিএসটি\nDTSTART;TZID=America/New_York:20261031T090000\nRRULE:FREQ=DAILY;COUNT=3");
    $r = Ics::between(Ics::parse($ics), new DateTimeImmutable('2026-10-30 00:00 UTC'), new DateTimeImmutable('2026-11-05 00:00 UTC'));
    expect(array_map(fn ($e) => $e['start']->setTimezone(new DateTimeZone('America/New_York'))->format('H:i'), $r))->toBe(['09:00', '09:00', '09:00']);
});

it('lets a changed single occurrence replace the regular one', function () {
    $ics = cal("UID:s\nSUMMARY:স্ট্যান্ডআপ\nDTSTART:20261102T040000Z\nDTEND:20261102T043000Z\nRRULE:FREQ=DAILY;COUNT=3",
        "UID:s\nSUMMARY:স্ট্যান্ডআপ (দেরিতে)\nRECURRENCE-ID:20261103T040000Z\nDTSTART:20261103T080000Z\nDTEND:20261103T083000Z");
    expect(win($ics, '2026-11-02 00:00', '2026-11-05 00:00'))->toBe(['2026-11-02 10:00|স্ট্যান্ডআপ', '2026-11-03 14:00|স্ট্যান্ডআপ (দেরিতে)', '2026-11-04 10:00|স্ট্যান্ডআপ']);
});

it('ignores a repeat rule it does not know, and caps work', function () {
    $ics = cal("UID:1\nSUMMARY:অজানা\nDTSTART:20261105T040000Z\nRRULE:FREQ=SECONDLY");
    expect(win($ics, '2026-11-05 00:00', '2026-11-30 00:00'))->toBe(['2026-11-05 10:00|অজানা']);
    $many = '';
    for ($i = 0; $i < Ics::MAX_EVENTS + 50; $i++) {
        $many .= "BEGIN:VEVENT\r\nUID:{$i}\r\nSUMMARY:e\r\nDTSTART:20261105T040000Z\r\nEND:VEVENT\r\n";
    }
    expect(count(Ics::parse("BEGIN:VCALENDAR\r\n".$many.'END:VCALENDAR')))->toBe(Ics::MAX_EVENTS);
    $endless = cal("UID:1\nSUMMARY:x\nDTSTART:20261105T040000Z\nRRULE:FREQ=DAILY;INTERVAL=1000;COUNT=99999");
    expect(count(win($endless, '2026-11-01 00:00', '2030-11-01 00:00')) <= Ics::MAX_STEPS)->toBeTrue();
});

// ---------- fetcher address check
it('accepts only plain https calendar addresses', function () {
    expect(HttpIcsFetcher::check('https://Calendar.Google.com/calendar/ical/x/basic.ics'))->toBe('calendar.google.com');
    foreach (['http://example.com/a.ics', 'ftp://example.com/a', 'https://user:pw@example.com/a.ics', 'https://example.com:8443/a.ics', 'https://example.com/a.ics#x', 'javascript:alert(1)', 'file:///etc/passwd', '//example.com/a', 'example.com/a.ics', '', 'https://'.str_repeat('a', 700).'.com/'] as $bad) {
        expect([$bad, HttpIcsFetcher::check($bad)])->toBe([$bad, null]);
    }
});

// ---------- webhook addresses and messages
it('accepts only the real Slack and Discord webhook addresses', function () {
    $slack = 'https://hooks.slack'.'.com/services/T01234567/B01234567/abcdefghijklmnopqrstuvwx';
    $disc = 'https://discord.com/api/webhooks/123456789012345678/'.str_repeat('aB3_-', 14);
    expect(WebhookUrl::parse($slack))->toBe(['kind' => 'slack', 'host' => 'hooks.slack.com']);
    expect(WebhookUrl::parse($disc))->toBe(['kind' => 'discord', 'host' => 'discord.com']);
    expect(WebhookUrl::parse(str_replace('discord.com', 'discordapp.com', $disc))['kind'])->toBe('discord');
    expect(WebhookUrl::parse(str_replace('/api/', '/api/v10/', $disc))['kind'])->toBe('discord');
    foreach ([str_replace('https', 'http', $slack), $slack.'?x=1', $slack.'#f', str_replace('hooks.slack.com', 'hooks.slack.com.evil.io', $slack), str_replace('hooks.slack.com', 'evil.io', $slack), 'https://hooks.slack.com@evil.io/services/T01234567/B01234567/abcdefghijklmnopqrstuvwx',
        'https://hooks.slack.com:444/services/T01234567/B01234567/abcdefghijklmnopqrstuvwx', 'https://hooks.slack'.'.com/services/x/y/z', str_replace('discord.com', 'discord.com.evil.io', $disc), 'https://discord.com/api/webhooks/abc/def', 'https://localhost/x', 'https://127.0.0.1/api/webhooks/123456789012345678/'.str_repeat('a', 40), ' ', '', str_repeat('a', 401), "https://hooks.slack'.'.com/services/T01234567/B01234567/abcdefghijklmnopqrstuvwx\r\nHost: x"] as $bad) {
        expect([$bad, WebhookUrl::parse($bad)])->toBe([$bad, null]);
    }
    expect(WebhookUrl::parse("  {$slack}  ")['kind'])->toBe('slack');      // surrounding spaces from copy-paste are fine
});

it('defuses mass mentions and caps the message', function () {
    $s = WebhookUrl::payload('slack', "হ্যালো @everyone @here <!channel> <@U123> <#C1> <!here>\x07 শেষ");
    expect($s['link_names'])->toBeFalse();
    foreach (['@everyone', '@here', '<!channel', '<@', '<#', '<!here'] as $bad) {
        expect(str_contains($s['text'], $bad))->toBeFalse();
    }
    expect(str_contains($s['text'], "\x07"))->toBeFalse();
    expect(mb_strlen(WebhookUrl::payload('slack', str_repeat('ক', 5000))['text']))->toBe(1800);
    $d = WebhookUrl::payload('discord', '@everyone hi');
    expect($d['allowed_mentions'])->toBe(['parse' => []]);
    expect(isset($d['content']))->toBeTrue();
    expect(isset($d['text']))->toBeFalse();
});

// ---------- chat hooks
const SLACK = 'https://hooks.slack'.'.com/services/T01234567/B01234567/abcdefghijklmnopqrstuvwx';

function hooks(?FakePoster $p = null, ?FakeLinkRepo $r = null, ?FakeSecrets $s = null): array
{
    $r ??= new FakeLinkRepo;
    $p ??= new FakePoster;
    $s ??= new FakeSecrets;

    return [new ChatHooks($r, $s, $p), $r, $p, $s];
}

it('saves a webhook address encrypted and never in plain', function () {
    [$h, $r] = hooks();
    expect($h->add(CT, CU, 'slack', 'টিম', SLACK))->toBe('ok');
    $row = array_values($r->rows)[0];
    expect($row['secret_ct'] !== SLACK)->toBeTrue();
    expect(str_contains(json_encode($row), 'abcdefghijklmnopqrstuvwx'))->toBeFalse();
    expect($row['host'])->toBe('hooks.slack.com');
    expect($h->add(CT, CU, 'slack', 'টিম', SLACK))->toBe('duplicate');
    expect($h->add(CT, CU, 'discord', 'টিম', SLACK))->toBe('wrong_kind');
    expect($h->add(CT, CU, 'slack', 'x', 'https://evil.io/x'))->toBe('bad_url');
    expect($h->add(CT, CU, 'slack', '  ', SLACK))->toBe('bad_input');
    expect(count($r->rows))->toBe(1);
    expect(strlen(ChatHooks::message('failed')) > 10)->toBeTrue();
    expect(ChatHooks::message('zzz'))->toBe('Could not be done.');
});

it('posts through a saved webhook and records the result', function () {
    [$h, $r, $p] = hooks();
    $h->add(CT, CU, 'slack', 'টিম', SLACK);
    $id = array_key_first($r->rows);
    expect($h->send(CT, CU, $id, 'নমস্কার @everyone'))->toBe('ok');
    expect($p->posts[0]['url'])->toBe(SLACK);
    expect(str_contains($p->posts[0]['json']['text'], '@everyone'))->toBeFalse();
    expect($r->rows[$id]['last_error'])->toBeNull();
    $p->status = 404;
    expect($h->send(CT, CU, $id, 'x'))->toBe('failed');
    expect($r->rows[$id]['last_error'])->toBe('HTTP 404');
    $p->status = 429;
    expect($h->send(CT, CU, $id, 'x'))->toBe('rate');
    $p->status = 0;
    expect($h->send(CT, CU, $id, 'x'))->toBe('failed');
    $p->status = 200;
    expect($h->send(CT, CU, $id, 'x'))->toBe('ok');
    expect($r->rows[$id]['last_error'])->toBeNull();
});

it('never posts for somebody else, to a missing link, an empty message, or when the address cannot be decrypted', function () {
    [$h, $r, $p, $s] = hooks();
    $h->add(CT, CU, 'slack', 'টিম', SLACK);
    $id = array_key_first($r->rows);
    expect($h->send(CT, CU2, $id, 'hi'))->toBe('not_found');
    expect($h->sendByName(CT, CU2, 'টিম', 'hi'))->toBe('not_found');
    expect($h->send(CT, CU, 'nope', 'hi'))->toBe('not_found');
    expect($h->send(CT, CU, $id, "  \x07 "))->toBe('empty_text');
    $r->rows['cal'] = ['id' => 'cal', 'user' => CU, 'kind' => 'ics', 'name' => 'c', 'secret_ct' => 'x', 'secret_ver' => 1, 'host' => 'h', 'last_used_at' => null, 'last_error' => null];
    expect($h->send(CT, CU, 'cal', 'hi'))->toBe('not_found');       // a calendar link is not a chat link
    $s->failDecrypt = true;
    expect($h->send(CT, CU, $id, 'hi'))->toBe('failed');
    expect($p->posts)->toBe([]);
});

it('finds a webhook by its name for either service, ignoring case', function () {
    [$h, $r, $p] = hooks();
    $disc = 'https://discord.com/api/webhooks/123456789012345678/'.str_repeat('aB3_-', 14);
    $h->add(CT, CU, 'discord', 'Dev', $disc);
    expect($h->sendByName(CT, CU, ' dev ', 'hi'))->toBe('ok');
    expect($p->posts[0]['json']['content'])->toBe('hi');
    expect($h->sendByName(CT, CU, 'other', 'hi'))->toBe('not_found');
});

// ---------- calendar service
const FEED = 'https://calendar.google.com/calendar/ical/x/private-abc/basic.ics';

function csvc(array $feeds = [], ?FakeLinkRepo $r = null): array
{
    $r ??= new FakeLinkRepo;
    $f = new FakeIcsFetcher($feeds);
    $s = new FakeSecrets;
    $hits = new ArrayObject;
    $store = [];
    $cache = function (string $k, int $ttl, Closure $make) use (&$store, $hits): string {
        $hits[] = [$k, $ttl];

        return $store[$k] ??= $make();
    };

    return [new CalendarService($r, $s, $f, $cache), $r, $f, $s, $hits];
}

it('adds a calendar only after reading it once, encrypted, and turns webcal:// into https://', function () {
    [$c, $r, $f] = csvc([FEED => cal("UID:1\nSUMMARY:x\nDTSTART:20261105T040000Z")]);
    expect($c->addFeed(CT, CU, 'অফিস', ' webcal://calendar.google.com/calendar/ical/x/private-abc/basic.ics '))->toBe(['status' => 'ok', 'message' => null]);
    expect($f->urls)->toBe([FEED]);
    $row = array_values($r->rows)[0];
    expect($row['kind'])->toBe('ics');
    expect(str_contains($row['secret_ct'], 'private-abc'))->toBeFalse();
    expect($row['host'])->toBe('calendar.google.com');
    expect($c->addFeed(CT, CU, 'অফিস', FEED)['status'])->toBe('duplicate');
    expect($c->addFeed(CT, CU, 'x', 'http://calendar.google.com/a.ics')['status'])->toBe('bad_url');
    $bad = $c->addFeed(CT, CU, 'y', 'https://calendar.google.com/other.ics');
    expect($bad['status'])->toBe('fetch');
    expect($bad['message'])->toBe('পাওয়া যায়নি');
    expect(count($r->rows))->toBe(1);
});

it('lists what is coming up from all calendars, in the person\'s zone, earliest first', function () {
    $a = cal("UID:1\nSUMMARY:পরে\nDTSTART:20261106T100000Z\nDTEND:20261106T110000Z", "UID:2\nSUMMARY:আজ-সকাল\nDTSTART:20261105T010000Z\nDTEND:20261105T020000Z");
    $b = cal("UID:3\nSUMMARY:ছুটি\nDTSTART;VALUE=DATE:20261105\nDTEND;VALUE=DATE:20261106", "UID:4\nSUMMARY:অনেক দূর\nDTSTART:20261201T040000Z");
    [$c, $r] = csvc(['https://a.example.com/a.ics' => $a, 'https://b.example.com/b.ics' => $b]);
    $c->addFeed(CT, CU, 'At', 'https://a.example.com/a.ics');
    $c->addFeed(CT, CU, 'বি', 'https://b.example.com/b.ics');
    $up = $c->upcoming(CT, CU, 'Asia/Dhaka', new DateTimeImmutable('2026-11-05 15:30 Asia/Dhaka'), 7);
    expect($up['feeds'])->toBe(2);
    expect($up['errors'])->toBe([]);
    expect(array_map(fn ($e) => $e['start'].'|'.$e['summary'].'|'.$e['calendar'], $up['events']))->toBe(['2026-11-05T00:00|ছুটি|বি', '2026-11-05T07:00|আজ-সকাল|At', '2026-11-06T16:00|Later|At']);
    expect($up['events'][0]['all_day'])->toBeTrue();
    $one = $c->upcoming(CT, CU, 'Asia/Dhaka', new DateTimeImmutable('2026-11-05 15:30 Asia/Dhaka'), 1);
    expect(count($one['events']))->toBe(2);
    expect(count($c->upcoming(CT, CU, 'Asia/Dhaka', new DateTimeImmutable('2026-11-05 15:30 Asia/Dhaka'), 999)['events']))->toBe(4);   // 30 days reach Dec 1
    expect(count($c->upcoming(CT, CU, 'Asia/Dhaka', new DateTimeImmutable('2026-10-25 15:30 Asia/Dhaka'), 999)['events']))->toBe(3);   // but not more: 30 days from Oct 25 end on Nov 24, before Dec 1
    expect(count($c->upcoming(CT, CU, 'Asia/Dhaka', new DateTimeImmutable('2026-11-30 15:30 Asia/Dhaka'), 0)['events']))->toBe(0);
    expect($c->upcoming(CT, CU2, 'Asia/Dhaka', new DateTimeImmutable('2026-11-05 15:30 Asia/Dhaka'), 7))->toBe(['events' => [], 'errors' => [], 'feeds' => 0]);
});

it('reads each feed from the network at most once per cache period, and a broken feed does not hide the others', function () {
    $good = cal("UID:1\nSUMMARY:ঠিক\nDTSTART:20261105T040000Z");
    [$c, $r, $f, , $hits] = csvc(['https://a.example.com/a.ics' => $good]);
    $c->addFeed(CT, CU, 'At', 'https://a.example.com/a.ics');
    $now = new DateTimeImmutable('2026-11-05 08:00 Asia/Dhaka');
    $c->upcoming(CT, CU, 'Asia/Dhaka', $now);
    $c->upcoming(CT, CU, 'Asia/Dhaka', $now);
    expect(count($f->urls))->toBe(2);        // 1 test-read when added + 1 real read (the second call came from the cache)
    expect($hits[0][1])->toBe(CalendarService::CACHE_SECONDS);
    // a second feed that is broken
    $r->rows['bad'] = ['id' => 'bad', 'user' => CU, 'kind' => 'ics', 'name' => 'ভাঙা', 'secret_ct' => 'ENC('.strrev('https://gone.example.com/x.ics').')', 'secret_ver' => 1, 'host' => 'gone.example.com', 'last_used_at' => null, 'last_error' => null];
    $up = $c->upcoming(CT, CU, 'Asia/Dhaka', $now);
    expect(count($up['events']))->toBe(1);
    expect($up['errors'])->toBe(['ভাঙা: পাওয়া যায়নি']);
    expect($r->rows['bad']['last_error'])->toBe('পাওয়া যায়নি');
    // it heals: the next good read clears the warning
    $f->feeds['https://gone.example.com/x.ics'] = $good;
    $up = $c->upcoming(CT, CU, 'Asia/Dhaka', $now);
    expect($up['errors'])->toBe([]);
    expect($r->rows['bad']['last_error'])->toBeNull();
});

it('survives an address that cannot be decrypted and unreadable calendar text', function () {
    [$c, $r, , $s] = csvc(['https://a.example.com/a.ics' => cal("UID:1\nSUMMARY:x\nDTSTART:20261105T040000Z")]);
    $c->addFeed(CT, CU, 'At', 'https://a.example.com/a.ics');
    $s->failDecrypt = true;
    $up = $c->upcoming(CT, CU, 'Asia/Dhaka', new DateTimeImmutable('2026-11-05 08:00 Asia/Dhaka'));
    expect($up['events'])->toBe([]);
    expect($up['errors'])->toBe(['এ: ক্যালেন্ডার পড়া যায়নি।']);
    expect(array_values($r->rows)[0]['last_error'])->toBe('read');
});

it('removes a calendar only for its owner and prints event lines', function () {
    [$c, $r] = csvc(['https://a.example.com/a.ics' => cal("UID:1\nSUMMARY:x\nDTSTART:20261105T040000Z")]);
    $c->addFeed(CT, CU, 'At', 'https://a.example.com/a.ics');
    $id = array_key_first($r->rows);
    expect($c->removeFeed(CT, CU2, $id))->toBe('not_found');
    expect($c->removeFeed(CT, CU, $id))->toBe('ok');
    expect(CalendarService::lines([]))->toContain('None');
    expect(CalendarService::lines([['start' => '2026-11-05T10:00', 'all_day' => false, 'summary' => 'মিটিং', 'location' => 'অফিস', 'calendar' => 'At'], ['start' => '2026-11-06T00:00', 'all_day' => true, 'summary' => 'ছুটি', 'location' => '', 'calendar' => 'At']]))
        ->toBe("- 2026-11-05 10:00 মিটিং @ অফিস\n- 2026-11-06 (সারাদিন) ছুটি");
});

// ---------- workflow tools
it('has a read-only calendar tool and an external chat tool', function () {
    [$c, , , ] = csvc();
    $cal = new CalendarUpcomingTool($c);
    expect($cal->risk())->toBe(Risk::READ);
    expect($cal->run(new ToolContext(CT, CU), []))->toBe('(No calendar has been added)');
    [$h, $r, $p] = hooks();
    $post = new ChatPostTool($h);
    expect($post->risk())->toBe(Risk::EXTERNAL);
    $h->add(CT, CU, 'slack', 'টিম', SLACK);
    expect($post->run(new ToolContext(CT, CU), ['to' => ' টিম ', 'text' => 'রিপোর্ট']))->toContain('টিম');
    expect($p->posts[0]['json']['text'])->toBe('রিপোর্ট');
    expect(fn () => $post->run(new ToolContext(CT, CU), ['to' => 'অজানা', 'text' => 'x']))->toThrow(ToolException::class);
    expect(fn () => $post->run(new ToolContext(CT, CU2), ['to' => 'টিম', 'text' => 'x']))->toThrow(ToolException::class);
    $p->status = 500;
    expect(fn () => $post->run(new ToolContext(CT, CU), ['to' => 'টিম', 'text' => 'x']))->toThrow(ToolException::class);
});

// ---------- extra edges
it('rejects webhook addresses with a login in front, short ids or tokens, or a foreign host', function () {
    $tok = str_repeat('aB3_-', 14);
    foreach (['https://x@hooks.slack.com/services/T01234567/B01234567/abcdefghijklmnopqrstuvwx', 'https://hooks.slack'.'.com/services/T1/B1/abcdefghijklmnopqrstuvwx', 'https://hooks.slack'.'.com/services/T01234567/B01234567/abcdefghijklmno',
        'https://discord.com/api/webhooks/12345678901234/'.$tok, 'https://discord.com/api/webhooks/123456789012345678/'.substr($tok, 0, 29), 'https://evil.io/api/webhooks/123456789012345678/'.$tok, 'https://x:y@discord.com/api/webhooks/123456789012345678/'.$tok] as $bad) {
        expect([$bad, WebhookUrl::parse($bad)])->toBe([$bad, null]);
    }
    expect(WebhookUrl::parse('https://hooks.slack'.'.com/services/T0123456/B0123456/abcdefghijklmnop')['kind'])->toBe('slack');      // exactly the minimum lengths
    expect(WebhookUrl::parse('https://discord.com/api/webhooks/123456789012345/'.substr($tok, 0, 30))['kind'])->toBe('discord');
});

it('stores the trimmed webhook address and notes a decryption failure', function () {
    [$h, $r, $p, $s] = hooks();
    $h->add(CT, CU, 'slack', 'টিম', "  ".SLACK." \n");
    $id = array_key_first($r->rows);
    $h->send(CT, CU, $id, 'hi');
    expect($p->posts[0]['url'])->toBe(SLACK);
    $s->failDecrypt = true;
    $h->send(CT, CU, $id, 'hi');
    expect($r->rows[$id]['last_error'])->toBe('decrypt');
});

it('shows events from earlier today, caps the list, names nameless events', function () {
    $many = '';
    for ($i = 0; $i < 105; $i++) {
        $many .= "BEGIN:VEVENT\r\nUID:{$i}\r\nSUMMARY:\r\nDTSTART:20261105T".sprintf('%02d', intdiv($i, 5)).sprintf('%02d', ($i % 5) * 10)."00Z\r\nEND:VEVENT\r\n";
    }
    $ics = "BEGIN:VCALENDAR\r\n".$many.'END:VCALENDAR';
    [$c] = csvc(['https://a.example.com/a.ics' => $ics]);
    $c->addFeed(CT, CU, 'At', 'https://a.example.com/a.ics');
    $up = $c->upcoming(CT, CU, 'UTC', new DateTimeImmutable('2026-11-05 15:00 UTC'), 1);
    expect(count($up['events']))->toBe(CalendarService::MAX_EVENTS);
    expect($up['events'][0]['summary'])->toBe('(Untitled)');
    expect($up['events'][0]['start'])->toBe('2026-11-05T00:00');     // before "now" but still today
});

it('does not invent weekly repeats before the start, and honours UNTIL on weekly rules', function () {
    $w = cal("UID:1\nSUMMARY:বুধ-সোম\nDTSTART:20261104T040000Z\nRRULE:FREQ=WEEKLY;BYDAY=MO,WE");
    expect(win($w, '2026-11-01 00:00', '2026-11-10 00:00'))->toBe(['2026-11-04 10:00|বুধ-সোম', '2026-11-09 10:00|বুধ-সোম']);
    $u = cal("UID:1\nSUMMARY:সীমিত\nDTSTART:20261102T040000Z\nRRULE:FREQ=WEEKLY;UNTIL=20261110T000000Z");
    expect(win($u, '2026-11-01 00:00', '2026-12-30 00:00'))->toBe(['2026-11-02 10:00|সীমিত', '2026-11-09 10:00|সীমিত']);
});

it('uses the Windows zone map and counts repeats from the real start', function () {
    $ics = cal("UID:1\nSUMMARY:ঢাকা\nDTSTART;TZID=Bangladesh Standard Time:20261105T090000\nDTEND;TZID=Bangladesh Standard Time:20261105T100000");
    expect(win($ics, '2026-11-04 00:00', '2026-11-05 00:00', 'America/New_York'))->toBe(['2026-11-04 22:00|Dhaka']);
    $c = cal("UID:1\nSUMMARY:সীমিত-গণনা\nDTSTART:20150101T040000Z\nRRULE:FREQ=DAILY;COUNT=3");
    expect(win($c, '2026-11-01 00:00', '2026-11-30 00:00'))->toBe([]);
    expect(count(win($c, '2015-01-01 00:00', '2015-02-01 00:00')))->toBe(3);
});

it('finds a long repeating event that began before the window', function () {
    $d = cal("UID:1\nSUMMARY:তিনদিনের\nDTSTART:20150101T040000Z\nDTEND:20150104T040000Z\nRRULE:FREQ=DAILY;INTERVAL=7");
    $r = win($d, '2026-11-02 00:00', '2026-11-03 00:00');
    // started on a 7-day beat from 2015-01-01; the beat nearest to Nov 2026 that is still running on Nov 2 must show
    $beats = [];
    for ($t = strtotime('2026-10-25'); $t < strtotime('2026-11-04'); $t += 86400) {
        $days = (int) (($t - strtotime('2015-01-01')) / 86400);
        if ($days % 7 === 0) {
            $beats[] = $t;
        }
    }
    $expected = array_values(array_filter($beats, fn ($t) => $t + 3 * 86400 > strtotime('2026-11-02') - 6 * 3600 && $t < strtotime('2026-11-03')));
    expect(count($r))->toBe(count($expected));
    $m = cal("UID:1\nSUMMARY:দীর্ঘ-মাসিক\nDTSTART:20150110T040000Z\nDTEND:20150115T040000Z\nRRULE:FREQ=MONTHLY");
    expect(win($m, '2026-11-12 00:00', '2026-11-13 00:00'))->toBe(['2026-11-10 10:00|দীর্ঘ-মাসিক']);
    $y = cal("UID:1\nSUMMARY:দীর্ঘ-বার্ষিক\nDTSTART:20151230T040000Z\nDTEND:20160110T040000Z\nRRULE:FREQ=YEARLY");
    expect(win($y, '2026-01-02 00:00', '2026-01-03 00:00'))->toBe(['2025-12-30 10:00|দীর্ঘ-বার্ষিক']);
    expect(count(win($cal = cal("UID:2\nSUMMARY:Period\nDTSTART:20261105T090000Z\nRRULE:FREQ=DAILY;COUNT=2"), '2026-11-05 15:00', '2026-11-05 15:01')))->toBe(1);
});

it('rejects an impossible minute', function () {
    expect(win(cal("UID:1\nSUMMARY:ভুল\nDTSTART:20261105T047500Z"), '2026-11-05 00:00', '2026-11-06 00:00'))->toBe([]);
});
