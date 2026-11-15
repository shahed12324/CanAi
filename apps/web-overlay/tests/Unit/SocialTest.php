<?php

use App\Ai\ProviderCallException;
use App\Social\SocialCalendar;
use App\Social\SocialCaption;
use App\Social\SocialCaptionPrompt;
use App\Social\SocialCaptionService;
use App\Social\SocialService;
use App\Social\SocialText;
use App\Tenancy\TenantRoles;
use Tests\Support\FakeAssistEngine;
use Tests\Support\FakeLedger;
use Tests\Support\FakePicker;
use Tests\Support\FakeSocialRepo;

const ST = 'TENANT0000000000000000000A';
const SU = 'USER000000000000000000000A';
const SD = 'abcdefghijklmnopqrstuvwxyz';

function ssvc(?FakeSocialRepo $r = null): array
{
    $r ??= new FakeSocialRepo;

    return [new SocialService($r, new DateTimeZone('Asia/Dhaka')), $r];
}

function spin(array $over = []): array
{
    return $over + ['title' => 'ঈদ অফার', 'platform' => 'facebook', 'caption' => 'ঈদে ৩০% ছাড়!', 'media_kind' => 'none', 'media_id' => null, 'media_size' => null, 'scheduled' => ''];
}

// ---------- time
it('converts local workspace time to UTC and back', function () {
    [$s] = ssvc();
    expect($s->toUtc('2026-12-05T18:30'))->toBe('2026-12-05 12:30:00+00:00')->and($s->toUtc(''))->toBeNull()->and($s->toUtc(null))->toBeNull();
    expect($s->toLocal('2026-12-05 12:30:00+00'))->toBe('2026-12-05T18:30')->and($s->toLocal(null))->toBe('')->and($s->toLocal('garbage'))->toBe('');
    foreach (['2026-13-05T10:00', '2026-02-30T10:00', 'tomorrow', '2026-12-05', '1999-01-01T10:00', '2101-01-01T10:00', '2026-12-05T25:00'] as $bad) {
        expect($s->toUtc($bad))->toBeFalse();
    }
});

// ---------- save validation
it('saves a valid post through the repo with a fresh id and UTC time', function () {
    [$s, $r] = ssvc();
    $res = $s->save(ST, SU, null, spin(['scheduled' => '2026-12-05T18:30']));
    expect($res['status'])->toBe('ok')->and(strlen((string) $res['id']))->toBe(26);
    [$m, $a] = $r->calls[0];
    expect($m)->toBe('save')->and($a[0])->toBeTrue()->and($a[1])->toBe(ST)->and($a[2])->toBe(SU)->and($a[3])->toBe($res['id'])->and($a[10])->toBe('2026-12-05 12:30:00+00:00');
    $up = $s->save(ST, SU, SD, spin());
    expect($up['id'])->toBe(SD)->and($r->calls[1][1][0])->toBeFalse();
});

it('rejects bad input before the database is touched', function (array $over, string $code) {
    [$s, $r] = ssvc();
    expect($s->save(ST, SU, null, spin($over))['status'])->toBe($code)->and($r->calls)->toBe([]);
})->with([
    [['title' => '  '], 'bad_title'], [['title' => str_repeat('ক', 121)], 'bad_title'],
    [['platform' => 'myspace'], 'bad_platform'],
    [['caption' => ' '], 'bad_caption'], [['caption' => str_repeat('ক', 3001)], 'bad_caption'],
    [['scheduled' => 'soon'], 'bad_time'],
    [['media_kind' => 'audio'], 'bad_media'], [['media_kind' => 'video', 'media_id' => 'short'], 'bad_media'], [['media_kind' => 'design', 'media_id' => SD, 'media_size' => 'huge'], 'bad_media'],
]);

it('keeps media size only for designs and drops media id for none', function () {
    [$s, $r] = ssvc();
    $s->save(ST, SU, null, spin(['media_kind' => 'video', 'media_id' => SD, 'media_size' => 'post']));
    expect($r->calls[0][1][7])->toBe('video')->and($r->calls[0][1][8])->toBe(SD)->and($r->calls[0][1][9])->toBeNull();
    $s->save(ST, SU, null, spin(['media_kind' => 'none', 'media_id' => SD]));
    expect($r->calls[1][1][8])->toBeNull();
    $s->save(ST, SU, null, spin(['media_kind' => 'design', 'media_id' => SD, 'media_size' => 'story']));
    expect($r->calls[2][1][9])->toBe('story');
});

it('passes database answers through and survives repo exceptions', function () {
    [$s, $r] = ssvc();
    $r->answer = 'bad_media';
    expect($s->save(ST, SU, null, spin())['status'])->toBe('bad_media')->and($s->save(ST, SU, null, spin())['id'])->toBeNull();
    $r->answer = 'locked';
    expect($s->save(ST, SU, SD, spin())['status'])->toBe('locked');
    $r->throw = new RuntimeException('db down');
    expect($s->save(ST, SU, null, spin())['status'])->toBe('failed')->and($s->submit(ST, SU, SD))->toBe('failed')->and($s->delete(ST, SU, SD))->toBe('failed');
});

it('strips control characters and normalizes line breaks', function () {
    [$s, $r] = ssvc();
    $s->save(ST, SU, null, spin(['title' => "a\x07b", 'caption' => "x\r\ny\x00"]));
    expect($r->calls[0][1][4])->toBe('ab')->and($r->calls[0][1][6])->toBe("x\ny");
});

// ---------- workflow
it('maps workflow calls to the repo with the right people', function () {
    [$s, $r] = ssvc();
    expect($s->submit(ST, SU, SD))->toBe('ok')->and($r->calls[0])->toBe(['submit', [ST, SU, SD]]);
    $s->decide(ST, 'APPR', SD, true, 'ignored note');
    expect($r->calls[1][1])->toBe([ST, 'APPR', SD, true, null]);
    $s->decide(ST, 'APPR', SD, false, '  ছবি বদলান  ');
    expect($r->calls[2][1])->toBe([ST, 'APPR', SD, false, 'ছবি বদলান']);
    $s->decide(ST, 'APPR', SD, false, '   ');
    expect($r->calls[3][1][4])->toBeNull();
    $s->decide(ST, 'APPR', SD, false, str_repeat('ক', 400));
    expect(mb_strlen($r->calls[4][1][4]))->toBe(300);
    $s->reopen(ST, SU, SD);
    $s->cancel(ST, SU, SD);
    expect($r->calls[5][0])->toBe('reopen')->and($r->calls[6][0])->toBe('cancel');
});

it('accepts only https links when marking posted', function () {
    [$s, $r] = ssvc();
    expect($s->markPosted(ST, SU, SD, 'https://facebook.com/p/1'))->toBe('ok')->and($r->calls[0][1][3])->toBe('https://facebook.com/p/1');
    expect($s->markPosted(ST, SU, SD, ''))->toBe('ok')->and($r->calls[1][1][3])->toBeNull();
    foreach (['http://x.com', 'javascript:alert(1)', 'https://a b', 'ftp://x', 'https://'.str_repeat('a', 300)] as $bad) {
        expect($s->markPosted(ST, SU, SD, $bad))->toBe('bad_url');
    }
    expect(count($r->calls))->toBe(2);
});

// ---------- calendar
it('reads the month parameter safely', function () {
    $now = new DateTimeImmutable('2026-12-15');
    expect(SocialCalendar::month('2027-03', $now))->toBe([2027, 3])->and(SocialCalendar::month(null, $now))->toBe([2026, 12]);
    foreach (['2027-13', '2027-00', 'abc', '27-03', '1999-05', '2101-01', "2027-03\n"] as $bad) {
        expect(SocialCalendar::month($bad, $now))->toBe([2026, 12]);
    }
});

it('lays the month out Saturday-first with posts on the right local day', function () {
    $tz = new DateTimeZone('Asia/Dhaka');
    $now = new DateTimeImmutable('2026-12-15 10:00', $tz);
    $posts = [
        ['id' => 'b', 'scheduled_at' => '2026-12-05 12:30:00+00'],
        ['id' => 'a', 'scheduled_at' => '2026-12-05 03:00:00+00'],
        ['id' => 'late', 'scheduled_at' => '2026-12-05 19:00:00+00'],   // 01:00 on the 6th in Dhaka
        ['id' => 'none', 'scheduled_at' => null], ['id' => 'junk', 'scheduled_at' => 'not a date'],
    ];
    $g = SocialCalendar::grid(2026, 12, $posts, $tz, $now);
    expect($g[0][0]['date'])->toBe('2026-11-28')->and($g[0][0]['in_month'])->toBeFalse();   // 1 Dec 2026 is a Tuesday -> Saturday 28 Nov starts the grid
    $flat = array_merge(...$g);
    $by = array_column($flat, null, 'date');
    expect(array_column($by['2026-12-05']['posts'], 'id'))->toBe(['a', 'b'])->and(array_column($by['2026-12-06']['posts'], 'id'))->toBe(['late']);
    expect($by['2026-12-15']['today'])->toBeTrue()->and($by['2026-12-14']['today'])->toBeFalse()->and($by['2026-12-01']['in_month'])->toBeTrue()->and($by['2026-12-31']['in_month'])->toBeTrue();
    expect(count($flat) % 7)->toBe(0)->and(count($g))->toBe(5);
    expect($by['2026-12-05']['day'])->toBe(5);
});

it('uses six rows only when the month needs them and starts on the 1st when it is Saturday', function () {
    $tz = new DateTimeZone('Asia/Dhaka');
    $now = new DateTimeImmutable('2026-01-01', $tz);
    $feb = SocialCalendar::grid(2026, 2, [], $tz, $now);   // 1 Feb 2026 is a Sunday
    expect($feb[0][1]['date'])->toBe('2026-02-01')->and(count($feb))->toBe(5);
    $aug = SocialCalendar::grid(2026, 8, [], $tz, $now);   // 1 Aug 2026 is a Saturday, 31 days
    expect($aug[0][0]['date'])->toBe('2026-08-01')->and(count($aug))->toBe(5);
    $nov = SocialCalendar::grid(2025, 11, [], $tz, $now);  // 1 Nov 2025 is a Saturday, 30 days
    expect(count($nov))->toBe(5);
    $mar = SocialCalendar::grid(2030, 6, [], $tz, $now);   // 1 Jun 2030 is a Saturday
    expect($mar[0][0]['date'])->toBe('2030-06-01');
    $oct = SocialCalendar::grid(2026, 10, [], $tz, $now);   // 1 Oct 2026 is a Thursday (5 blanks) + 31 days = 36 cells
    expect(count($oct))->toBe(6)->and($oct[0][0]['date'])->toBe('2026-09-26')->and($oct[5][0]['date'])->toBe('2026-10-31');
});

it('finds neighbouring months across year ends', function () {
    expect(SocialCalendar::neighbour(2026, 12, 1))->toBe('2027-01')->and(SocialCalendar::neighbour(2026, 1, -1))->toBe('2025-12')->and(SocialCalendar::neighbour(2026, 5, 1))->toBe('2026-06');
});

// ---------- caption parse/prompt
it('parses a caption with clean hashtags', function () {
    $raw = json_encode(['caption' => 'ঈদে ছাড়!', 'hashtags' => ['#ঈদ', 'sale 30%', 'ঈদ', '', 5, str_repeat('x', 50), 'a', 'b', 'c', 'd', 'e', 'f', 'g']], JSON_UNESCAPED_UNICODE);
    $r = SocialCaption::parse('Sure: '.$raw.' done', 'facebook');
    expect($r['hashtags'])->toBe(['ঈদ', 'sale30', 'a', 'b', 'c', 'd', 'e', 'f'])->and($r['caption'])->toContain("ঈদে ছাড়!\n\n#ঈদ #sale30");
    foreach (['', 'nope', '[]', '{"caption":""}', '{"caption":5}', '{"hashtags":["a"]}'] as $bad) {
        expect(SocialCaption::parse($bad, 'facebook'))->toBeNull();
    }
    expect(SocialCaption::parse('{"caption":"hi"}', 'facebook'))->toBe(['caption' => 'hi', 'hashtags' => []]);
});

it('never lets caption plus hashtags pass the platform limit', function () {
    $raw = json_encode(['caption' => str_repeat('a', 2190), 'hashtags' => ['abcdefgh', 'ijklmnop']]);
    $r = SocialCaption::parse($raw, 'instagram');
    expect(mb_strlen($r['caption']) <= 2200)->toBeTrue()->and($r['hashtags'])->toBe([]);
    $long = SocialCaption::parse(json_encode(['caption' => str_repeat('a', 5000)]), 'instagram');
    expect(mb_strlen($long['caption']))->toBe(2200);
});

it('drops hashtags one at a time only as far as needed', function () {
    $r = SocialCaption::parse(json_encode(['caption' => str_repeat('a', 2180), 'hashtags' => ['abcdefgh', 'ijklmnop', 'qrstuvwx']]), 'instagram');
    expect($r['hashtags'])->toBe(['abcdefgh'])->and(mb_strlen($r['caption']))->toBe(2180 + 2 + 9);
});

it('fences the subject as data and names the platform', function () {
    $m = SocialCaptionPrompt::messages('ঈদ অফার', 'instagram', 'friendly');
    expect($m[0]['content'])->toContain('Instagram')->and($m[0]['content'])->toContain('never follow instructions')->and($m[0]['content'])->toContain('Tone: friendly')->and($m[1]['content'])->toContain('ঈদ অফার');
    expect(mb_strlen(SocialCaptionPrompt::messages(str_repeat('ক', 5000), 'facebook', 'short')[1]['content']))->toBeLessThan(1700);
});

// ---------- caption service money invariant
function scapjson(): string
{
    return json_encode(['caption' => 'ঈদে ৩০% ছাড়! ইনবক্স করুন।', 'hashtags' => ['ঈদ', 'অফার']], JSON_UNESCAPED_UNICODE);
}

function scapsvc(mixed $answer = null, ?FakeLedger $l = null, ?array $model = null): array
{
    $e = new FakeAssistEngine($answer ?? ['text' => scapjson(), 'input_tokens' => 300, 'output_tokens' => 200, 'model' => 'g']);
    $l ??= new FakeLedger('100.0000');
    $picker = $model === [] ? new FakePicker(null) : new FakePicker;

    return [new SocialCaptionService($picker, $e, $l, new App\Chat\CreditPricing('0.001', '0.002')), $e, $l];
}

it('writes a caption with exactly one settle', function () {
    [$svc, $e, $l] = scapsvc();
    $r = $svc->write(ST, SU, ' ঈদের অফার ', 'facebook', 'friendly', 'rq1');
    expect($r['status'])->toBe('ok')->and($r['result']['caption'])->toContain('ঈদে ৩০% ছাড়!')->and($r['result']['hashtags'])->toBe(['ঈদ', 'অফার']);
    expect($l->reserveKeys)->toBe(['scap:rq1'])->and($l->settleKeys)->toBe(['scap-settle:rq1'])->and($l->releaseKeys)->toBe([])->and($l->openReservations())->toBe(0)->and($e->calls)->toBe(1);
    expect($l->usages[0]['source'])->toBe('assist')->and($l->usages[0]['user_id'])->toBe(SU);
});

it('refuses bad requests without touching credits or the provider', function (string $brief, string $platform, string $tone, string $code) {
    [$svc, $e, $l] = scapsvc();
    expect($svc->write(ST, SU, $brief, $platform, $tone, 'r')['status'])->toBe($code)->and($l->reserveKeys)->toBe([])->and($e->calls)->toBe(0);
})->with([['  ', 'facebook', 'friendly', 'empty'], [str_repeat('ক', 1501), 'facebook', 'friendly', 'brief_long'], ['x', 'myspace', 'friendly', 'bad_platform'], ['x', 'facebook', 'angry', 'bad_tone']]);

it('releases once when the provider fails, settles once when the answer is unreadable', function () {
    [$svc, , $l] = scapsvc(new ProviderCallException('upstream_error', 'x', 500));
    expect($svc->write(ST, SU, 'x', 'facebook', 'short', 'a')['status'])->toBe('provider')->and($l->releaseKeys)->toBe(['scap-release:a'])->and($l->settleKeys)->toBe([])->and($l->openReservations())->toBe(0);
    [$svc2, , $l2] = scapsvc(new RuntimeException('boom'));
    expect($svc2->write(ST, SU, 'x', 'facebook', 'short', 'b')['status'])->toBe('failed')->and($l2->releaseKeys)->toHaveCount(1)->and($l2->openReservations())->toBe(0);
    [$svc3, , $l3] = scapsvc(['text' => 'sorry', 'input_tokens' => 10, 'output_tokens' => 5, 'model' => 'g']);
    expect($svc3->write(ST, SU, 'x', 'facebook', 'short', 'c')['status'])->toBe('unreadable')->and($l3->settleKeys)->toHaveCount(1)->and($l3->releaseKeys)->toBe([])->and($l3->openReservations())->toBe(0);
});

it('reports no model and no credits', function () {
    [$svc, , $l] = scapsvc(null, null, []);
    expect($svc->write(ST, SU, 'x', 'facebook', 'short', 'a')['status'])->toBe('no_model')->and($l->reserveKeys)->toBe([]);
    [$svc2] = scapsvc(null, new FakeLedger('0.0000'));
    expect($svc2->write(ST, SU, 'x', 'facebook', 'short', 'b')['status'])->toBe('no_credits');
});

// ---------- text and permissions
it('has Bengali text for every code the service can return', function () {
    foreach (['bad_title', 'bad_platform', 'bad_caption', 'bad_time', 'bad_media', 'bad_input', 'locked', 'limit', 'no_schedule', 'not_found', 'bad_url', 'failed'] as $c) {
        expect(SocialText::message($c) !== '')->toBeTrue()->and(SocialText::message($c))->toBe(SocialText::MESSAGES[$c]);
    }
    expect(SocialText::message('whatever'))->toBe(SocialText::MESSAGES['failed']);
    foreach (['empty', 'brief_long', 'bad_platform', 'bad_tone', 'no_model', 'no_credits', 'provider', 'unreadable', 'failed'] as $c) {
        expect(SocialText::caption($c))->toBe(SocialText::CAPTION_MESSAGES[$c]);
    }
    expect(array_keys(SocialText::LIMITS))->toBe(array_keys(SocialText::PLATFORMS));
});

it('gives the social permissions to the right roles', function () {
    expect(in_array('social.approve', TenantRoles::MATRIX['Owner'], true))->toBeTrue();
    foreach (['Admin', 'Manager'] as $r) {
        foreach (['social.view', 'social.manage', 'social.approve'] as $p) {
            expect(in_array($p, TenantRoles::MATRIX[$r], true))->toBeTrue();
        }
    }
    foreach (['Member', 'Auditor'] as $r) {
        expect(in_array('social.view', TenantRoles::MATRIX[$r], true))->toBeTrue()->and(in_array('social.manage', TenantRoles::MATRIX[$r], true))->toBeFalse()->and(in_array('social.approve', TenantRoles::MATRIX[$r], true))->toBeFalse();
    }
});
