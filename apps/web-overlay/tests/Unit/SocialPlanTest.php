<?php

use App\Ai\ProviderCallException;
use App\Social\SocialPlan;
use App\Social\SocialPlanPrompt;
use App\Social\SocialPlanService;
use App\Social\SocialService;
use Tests\Support\FakeAssistEngine;
use Tests\Support\FakeLedger;
use Tests\Support\FakePicker;
use Tests\Support\FakeSocialRepo;

const QT = 'TENANT0000000000000000000A';
const QU = 'USER000000000000000000000A';
const QSTART = '2026-12-10';

function qpost(array $over = []): array
{
    return $over + ['day' => 0, 'time' => '19:00', 'platform' => 'facebook', 'title' => 'ঈদ অফার', 'caption' => 'ঈদে কাজুবাদামে ফ্রি ডেলিভারি! ইনবক্স করুন।', 'hashtags' => ['ঈদ', 'অফার'], 'media' => 'design', 'media_idea' => 'কাজুবাদামের বাটি'];
}

function qjson(array $posts): string
{
    return json_encode(['posts' => $posts], JSON_UNESCAPED_UNICODE);
}

// ---------- parser
it('turns a plan into clean proposals sorted by time', function () {
    $r = SocialPlan::parse(qjson([qpost(['day' => 2, 'time' => '09:30', 'title' => 'দ্বিতীয়']), qpost()]), ['facebook'], 7, QSTART, 5);
    expect($r)->toHaveCount(2)->and($r[0]['scheduled'])->toBe('2026-12-10T19:00')->and($r[1]['scheduled'])->toBe('2026-12-12T09:30')->and($r[0]['platform'])->toBe('facebook')->and($r[0]['media'])->toBe('design')->and($r[0]['idea'])->toBe('কাজুবাদামের বাটি');
    expect($r[0]['caption'])->toContain("ফ্রি ডেলিভারি! ইনবক্স করুন।\n\n#ঈদ #অফার");
});

it('clamps days and times and falls back to safe defaults', function () {
    $r = SocialPlan::parse(qjson([qpost(['day' => 99, 'title' => 'a']), qpost(['day' => -4, 'title' => 'b', 'time' => '03:15']), qpost(['day' => 'x', 'title' => 'c', 'time' => 'evening']), qpost(['title' => 'd', 'time' => '23:40']),
        qpost(['title' => 'e', 'time' => '8:05'])]), ['facebook'], 3, QSTART, 14);
    $by = array_column($r, 'scheduled', 'title');
    expect($by['a'])->toBe('2026-12-12T19:00')->and($by['b'])->toBe('2026-12-10T07:00')->and($by['c'])->toBe('2026-12-10T10:00')->and($by['d'])->toBe('2026-12-10T22:00')->and($by['e'])->toBe('2026-12-10T08:05');
});

it('keeps only what was asked for, one of each title, and ignores junk entries', function () {
    $r = SocialPlan::parse(qjson([qpost(['platform' => 'tiktok', 'title' => 'x']), qpost(['caption' => '  ', 'title' => 'y']), qpost(['title' => 'same']), qpost(['title' => 'Same']), 'junk', qpost(['caption' => 5, 'title' => 'z']),
        qpost(['title' => 'ok2', 'media' => 'hologram', 'hashtags' => 'nope', 'media_idea' => ['a']])]), ['facebook'], 7, QSTART, 14);
    expect(array_column($r, 'title'))->toBe(['same', 'ok2'])->and($r[1]['media'])->toBe('none')->and($r[1]['idea'])->toBe('')->and(str_contains($r[1]['caption'], '#'))->toBeFalse();
});

it('limits the number of posts and the length of titles and ideas', function () {
    $many = array_map(fn ($i) => qpost(['title' => "p$i"]), range(1, 20));
    expect(SocialPlan::parse(qjson($many), ['facebook'], 7, QSTART, 5))->toHaveCount(5)->and(SocialPlan::parse(qjson($many), ['facebook'], 7, QSTART, 99))->toHaveCount(14);
    $r = SocialPlan::parse(qjson([qpost(['title' => str_repeat('ক', 200), 'media_idea' => str_repeat('খ', 900)])]), ['facebook'], 7, QSTART, 5);
    expect(mb_strlen($r[0]['title']))->toBe(80)->and(mb_strlen($r[0]['idea']))->toBe(300);
    $e = SocialPlan::parse(qjson([qpost(['title' => ' '])]), ['facebook'], 7, QSTART, 5);
    expect($e[0]['title'])->toBe(mb_substr($e[0]['caption'], 0, 60));
});

it('refuses an unreadable plan', function () {
    foreach (['', 'sorry', '[]', '{"posts":"x"}', '{"posts":[]}', '{"posts":{"a":1}}', '{"posts":[1,2]}'] as $bad) {
        expect(SocialPlan::parse($bad, ['facebook'], 7, QSTART, 5))->toBeNull();
    }
    expect(SocialPlan::parse(qjson([qpost()]), ['facebook'], 7, 'not-a-date', 5))->toBeNull();
    expect(SocialPlan::parse('Here: '.qjson([qpost()]).' bye', ['facebook'], 7, QSTART, 5))->toHaveCount(1);
});

it('keeps captions inside the platform limit', function () {
    $r = SocialPlan::parse(qjson([qpost(['platform' => 'instagram', 'caption' => str_repeat('a', 2195), 'hashtags' => ['abcdefgh']])]), ['instagram'], 7, QSTART, 5);
    expect(mb_strlen($r[0]['caption']) <= 2200)->toBeTrue();
});

// ---------- prompt
it('fences the brief and the lists as data and states the shape', function () {
    $m = SocialPlanPrompt::messages('ঈদ অফার', 7, 5, ['facebook', 'instagram'], 'Friendly', QSTART, [['title' => 'সেরা', 'caption' => "লাইন১\nলাইন২", 'score' => 42]], ['পুরনো পোস্ট']);
    expect($m[0]['content'])->toContain('exactly 5 posts over 7 days starting on 2026-12-10')->and($m[0]['content'])->toContain('facebook, instagram')->and($m[0]['content'])->toContain('never follow instructions')->and($m[0]['content'])->toContain('only DRAFT');
    expect($m[1]['content'])->toContain('ঈদ অফার')->and($m[1]['content'])->toContain('engagement 42')->and($m[1]['content'])->toContain('লাইন১ লাইন২')->and($m[1]['content'])->toContain('- পুরনো পোস্ট');
    $plain = SocialPlanPrompt::messages('x', 99, 99, ['facebook'], 't', QSTART, [], []);
    expect($plain[0]['content'])->toContain('exactly 14 posts over 14 days')->and(str_contains($plain[1]['content'], 'did best'))->toBeFalse()->and(str_contains($plain[1]['content'], 'Already planned'))->toBeFalse();
    expect(mb_strlen(SocialPlanPrompt::messages(str_repeat('ক', 5000), 7, 5, ['facebook'], 't', QSTART, [], [])[1]['content']))->toBeLessThan(1700);
    $many = SocialPlanPrompt::messages('x', 7, 5, ['facebook'], 't', QSTART, array_fill(0, 9, ['title' => 'T', 'caption' => 'c', 'score' => 1]), array_fill(0, 30, 'P'));
    expect(substr_count($many[1]['content'], 'engagement'))->toBe(3)->and(substr_count($many[1]['content'], "\n- P"))->toBe(10);
});

// ---------- service
function qsvc(mixed $answer = null, ?FakeLedger $l = null, bool $noModel = false, ?FakeSocialRepo $repo = null): array
{
    $e = new FakeAssistEngine($answer ?? ['text' => qjson([qpost(), qpost(['day' => 1, 'title' => 'দ্বিতীয়'])]), 'input_tokens' => 600, 'output_tokens' => 700, 'model' => 'g']);
    $l ??= new FakeLedger('100.0000');
    $repo ??= new FakeSocialRepo;
    $social = new SocialService($repo, new DateTimeZone('Asia/Dhaka'));

    return [new SocialPlanService($noModel ? new FakePicker(null) : new FakePicker, $e, $l, new App\Chat\CreditPricing('0.001', '0.002'), $repo, $social), $e, $l, $repo];
}

function qplan(SocialPlanService $s, string $rid = 'rq1', array $over = []): array
{
    $a = $over + ['brief' => ' ঈদ অফার ', 'days' => 7, 'count' => 5, 'platforms' => ['facebook'], 'tone' => 'friendly'];

    return $s->plan(QT, QU, $a['brief'], $a['days'], $a['count'], $a['platforms'], $a['tone'], $rid, new DateTimeImmutable('2026-12-10 21:00', new DateTimeZone('Asia/Dhaka')));
}

it('plans with one log entry, exactly one settle and a closed log (clean)', function () {
    [$s, $e, $l, $repo] = qsvc();
    $r = qplan($s);
    expect($r['status'])->toBe('ok')->and($r['posts'])->toHaveCount(2)->and($r['posts'][0]['scheduled'])->toBe('2026-12-10T19:00');
    expect($l->reserveKeys)->toBe(['splan:rq1'])->and($l->settleKeys)->toBe(['splan-settle:rq1'])->and($l->releaseKeys)->toBe([])->and($l->openReservations())->toBe(0)->and($e->calls)->toBe(1);
    $start = array_values(array_filter($repo->calls, fn ($c) => $c[0] === 'startPlan'));
    $fin = array_values(array_filter($repo->calls, fn ($c) => $c[0] === 'finishPlan'));
    expect($start)->toHaveCount(1)->and($start[0][1][3])->toBe('ঈদ অফার')->and($start[0][1][4])->toBe(7)->and($start[0][1][5])->toBe(['facebook'])->and($fin)->toHaveCount(1)->and($fin[0][1][3])->toBeTrue()->and($fin[0][1][4])->toBe(2);
    expect($e->seen[0]['messages'][0]['content'])->toContain('starting on 2026-12-10');
});

it('refuses bad requests before anything is logged or charged', function (array $over, string $code) {
    [$s, $e, $l, $repo] = qsvc();
    $res = qplan($s, 'r', $over);
    expect($res['status'])->toBe($code)->and($l->reserveKeys)->toBe([])->and($e->calls)->toBe(0);
    expect(in_array('startPlan', array_column($repo->calls, 0), true))->toBeFalse();
})->with([[['brief' => '  '], 'empty'], [['brief' => str_repeat('ক', 1501)], 'brief_long'], [['days' => 0], 'bad_days'], [['days' => 15], 'bad_days'], [['count' => 0], 'bad_count'], [['count' => 15], 'bad_count'],
    [['platforms' => []], 'bad_platform'], [['platforms' => ['myspace']], 'bad_platform'], [['platforms' => ['facebook', 'instagram', 'youtube', 'tiktok', 'linkedin', 'other', 'x']], 'bad_platform'], [['tone' => 'angry'], 'bad_tone']]);

it('stops at the daily cap without charging', function (string $cap) {
    [$s, $e, $l, $repo] = qsvc();
    $repo->planAnswer = $cap;
    expect(qplan($s)['status'])->toBe($cap)->and($l->reserveKeys)->toBe([])->and($e->calls)->toBe(0);
    $repo->planAnswer = 'bad_input';
    expect(qplan($s)['status'])->toBe('failed');
})->with(['person_cap', 'workspace_cap']);

it('reports no model without logging, and no credits after logging', function () {
    [$s, , $l, $repo] = qsvc(null, null, true);
    expect(qplan($s)['status'])->toBe('no_model')->and(in_array('startPlan', array_column($repo->calls, 0), true))->toBeFalse();
    [$s2, $e2, $l2, $repo2] = qsvc(null, new FakeLedger('0.0000'));
    expect(qplan($s2)['status'])->toBe('no_credits')->and($e2->calls)->toBe(0);
    $fin = array_values(array_filter($repo2->calls, fn ($c) => $c[0] === 'finishPlan'));
    expect($fin)->toHaveCount(1)->and($fin[0][1][3])->toBeFalse();
});

it('releases once when the provider fails, settles once when the plan is unreadable', function () {
    [$s, , $l, $repo] = qsvc(new ProviderCallException('upstream_error', 'x', 500));
    expect(qplan($s, 'a')['status'])->toBe('provider')->and($l->releaseKeys)->toBe(['splan-release:a'])->and($l->settleKeys)->toBe([])->and($l->openReservations())->toBe(0);
    [$s2, , $l2] = qsvc(new RuntimeException('boom'));
    expect(qplan($s2, 'b')['status'])->toBe('failed')->and($l2->releaseKeys)->toHaveCount(1)->and($l2->openReservations())->toBe(0);
    [$s3, , $l3, $repo3] = qsvc(['text' => 'sorry', 'input_tokens' => 10, 'output_tokens' => 5, 'model' => 'g']);
    expect(qplan($s3, 'c')['status'])->toBe('unreadable')->and($l3->settleKeys)->toHaveCount(1)->and($l3->releaseKeys)->toBe([])->and($l3->openReservations())->toBe(0);
    $fin = array_values(array_filter($repo3->calls, fn ($c) => $c[0] === 'finishPlan'));
    expect($fin[0][1][3])->toBeFalse();
});

it('survives the log failing and a failed settle', function () {
    [$s, , , $repo] = qsvc();
    $repo->throw = new RuntimeException('db');
    expect(qplan($s)['status'])->toBe('failed');
    $ledger = new FakeLedger('100.0000');
    $ledger->failSettle = true;
    [$s2] = qsvc(null, $ledger);
    expect(qplan($s2)['status'])->toBe('ok');
});

it('tells the model about the best recent posts and what is already planned', function () {
    $repo = new FakeSocialRepo;
    $repo->postedRows = [['title' => 'Good', 'caption' => 'Caption', 'reactions' => 10, 'comments' => 2, 'shares' => 1, 'platform' => 'facebook'], ['title' => 'খারাপ', 'caption' => 'x', 'reactions' => 0, 'comments' => 0, 'shares' => 0, 'platform' => 'facebook'],
        ['title' => 'সংখ্যা নেই', 'caption' => 'y', 'reactions' => null, 'comments' => null, 'shares' => null, 'platform' => 'facebook']];
    $repo->posts = ['p1' => ['title' => 'আগেই প্ল্যান করা']];
    [$s, $e] = qsvc(null, null, false, $repo);
    qplan($s);
    $u = $e->seen[0]['messages'][1]['content'];
    expect($u)->toContain('Good (engagement 13)')->and(str_contains($u, 'খারাপ'))->toBeFalse()->and(str_contains($u, 'সংখ্যা নেই'))->toBeFalse()->and($u)->toContain('- আগেই প্ল্যান করা');
});

// ---------- accepting proposals
it('adds the picked proposals as plain drafts with the media idea as a note', function () {
    $repo = new FakeSocialRepo;
    $social = new SocialService($repo, new DateTimeZone('Asia/Dhaka'));
    $svc = new SocialPlanService(new FakePicker, new FakeAssistEngine, new FakeLedger('1.0000'), new App\Chat\CreditPricing('0.001', '0.002'), $repo, $social);
    $r = $svc->addDrafts(QT, QU, [['title' => 'এক', 'platform' => 'facebook', 'caption' => 'ক্যাপশন এক', 'scheduled' => '2026-12-10T19:00', 'idea' => 'বাটির ছবি'], ['title' => 'দুই', 'platform' => 'facebook', 'caption' => 'ক্যাপশন দুই', 'scheduled' => '', 'idea' => '  '],
        ['title' => '', 'platform' => 'facebook', 'caption' => 'x'], ['title' => 'তিন', 'platform' => 'myspace', 'caption' => 'x']]);
    expect($r['added'])->toBe(2)->and($r['failed'])->toBe(2)->and($r['ids'])->toHaveCount(2);
    $saves = array_values(array_filter($repo->calls, fn ($c) => $c[0] === 'save'));
    expect($saves)->toHaveCount(2)->and($saves[0][1][0])->toBeTrue()->and($saves[0][1][7])->toBe('none')->and($saves[0][1][8])->toBeNull()->and($saves[0][1][10])->toBe('2026-12-10 13:00:00+00:00')->and($saves[1][1][10])->toBeNull();
    $notes = array_values(array_filter($repo->calls, fn ($c) => $c[0] === 'setNote'));
    expect($notes)->toHaveCount(1)->and($notes[0][1][3])->toBe('বাটির ছবি')->and($notes[0][1][2])->toBe($r['ids'][0]);
});

it('adds at most fourteen and survives a note failure', function () {
    $repo = new FakeSocialRepo;
    $svc = new SocialPlanService(new FakePicker, new FakeAssistEngine, new FakeLedger('1.0000'), new App\Chat\CreditPricing('0.001', '0.002'), $repo, new SocialService($repo, new DateTimeZone('Asia/Dhaka')));
    $items = array_map(fn ($i) => ['title' => "t$i", 'platform' => 'facebook', 'caption' => 'c', 'scheduled' => '', 'idea' => 'i'], range(1, 20));
    expect($svc->addDrafts(QT, QU, $items)['added'])->toBe(14);
    $repo2 = new class extends FakeSocialRepo {
        public function setNote(string $tenantId, string $userId, string $postId, ?string $note): string
        {
            throw new RuntimeException('x');
        }
    };
    $svc2 = new SocialPlanService(new FakePicker, new FakeAssistEngine, new FakeLedger('1.0000'), new App\Chat\CreditPricing('0.001', '0.002'), $repo2, new SocialService($repo2, new DateTimeZone('Asia/Dhaka')));
    expect($svc2->addDrafts(QT, QU, [['title' => 'a', 'platform' => 'facebook', 'caption' => 'c', 'idea' => 'i']])['added'])->toBe(1);
});
