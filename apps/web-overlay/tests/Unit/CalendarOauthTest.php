<?php

use App\Calendar\CalendarAccounts;
use App\Calendar\CalendarApiException;
use App\Calendar\CalendarService;
use App\Calendar\GoogleCalendarApi;
use App\Calendar\GoogleCalendarOAuth;
use App\Calendar\GraphCalendarApi;
use App\Calendar\MicrosoftCalendarOAuth;
use App\Outreach\OAuthFailure;
use App\Outreach\OAuthRevoked;
use App\Workflow\Risk;
use App\Workflow\ToolContext;
use App\Workflow\ToolException;
use App\Workflow\ToolRegistry;
use App\Workflow\Tools\CalendarAddEventTool;
use App\Workflow\WorkflowDefinition;
use Tests\Support\FakeCalendarApi;
use Tests\Support\FakeCalendarOAuth;
use Tests\Support\FakeHttpJson;
use Tests\Support\FakeIcsFetcher;
use Tests\Support\FakeLinkRepo;
use Tests\Support\FakeSecrets;

const CO_T = '01HTENANT00000000000000001';
const CO_U = '01HUSER000000000000000001A';
const CO_ZONE = 'Asia/Dhaka';

function coNow(): DateTimeImmutable
{
    return new DateTimeImmutable('2026-11-09 10:00:00', new DateTimeZone(CO_ZONE));
}

/** @return array{0: CalendarAccounts, 1: FakeLinkRepo, 2: array<string, FakeCalendarOAuth>, 3: array<string, FakeCalendarApi>, 4: FakeSecrets} */
function coRig(): array
{
    $links = new FakeLinkRepo;
    $sec = new FakeSecrets;
    $p = ['google' => new FakeCalendarOAuth, 'microsoft' => new FakeCalendarOAuth];
    $a = ['google' => new FakeCalendarApi, 'microsoft' => new FakeCalendarApi];

    return [new CalendarAccounts($links, $sec, $p, $a, fn () => coNow()), $links, $p, $a, $sec];
}

function coZone(): DateTimeZone
{
    return new DateTimeZone(CO_ZONE);
}

// ---------- OAuth providers ----------

it('asks Google for calendar permission only, never for mail', function () {
    $o = new GoogleCalendarOAuth(new FakeHttpJson, 'cid', 'sec');
    $url = $o->authUrl('https://app.test/cb', 'STATE', 'CHAL');
    expect($url)->toContain('calendar.events')->toContain('access_type=offline')->toContain('code_challenge_method=S256')->toContain('state=STATE');
    expect(str_contains($url, 'gmail'))->toBeFalse();
    expect((new GoogleCalendarOAuth(new FakeHttpJson, null, 'x'))->configured())->toBeFalse();
    expect((new GoogleCalendarOAuth(new FakeHttpJson, 'a', ''))->configured())->toBeFalse();
    expect($o->configured())->toBeTrue();
});

it('Google exchange needs the calendar scope, a refresh token and a verified e-mail', function () {
    $ok = ['status' => 200, 'json' => ['access_token' => 'A', 'refresh_token' => 'R', 'scope' => 'openid email https://www.googleapis.com/auth/calendar.events']];
    $me = ['status' => 200, 'json' => ['email' => 'Me@Gmail.com', 'email_verified' => true]];
    $h = new FakeHttpJson;
    $h->queue = [$ok, $me];
    expect((new GoogleCalendarOAuth($h, 'c', 's'))->exchange('CODE', 'VER', 'https://app.test/cb'))->toBe(['refresh_token' => 'R', 'access_token' => 'A', 'email' => 'me@gmail.com']);
    expect($h->calls[0]['form']['code_verifier'])->toBe('VER');
    expect($h->calls[1]['headers']['Authorization'])->toBe('Bearer A');
    $cases = [
        'google_exchange_failed' => [['status' => 400, 'json' => []]],
        'google_no_refresh_token' => [['status' => 200, 'json' => ['access_token' => 'A', 'scope' => 'calendar.events']]],
        'google_scope_missing' => [['status' => 200, 'json' => ['access_token' => 'A', 'refresh_token' => 'R', 'scope' => 'openid email']]],
        'google_email_unverified' => [$ok, ['status' => 200, 'json' => ['email' => 'a@b.com', 'email_verified' => false]]],
    ];
    foreach ($cases as $code => $queue) {
        $h = new FakeHttpJson;
        $h->queue = $queue;
        try {
            (new GoogleCalendarOAuth($h, 'c', 's'))->exchange('C', 'V', 'u');
            $got = 'no error';
        } catch (OAuthFailure $e) {
            $got = $e->getMessage();
        }
        expect($got)->toBe($code);
    }
});

it('Google refresh tells a revoked grant from a passing failure and passes on a rotated token', function () {
    $h = new FakeHttpJson;
    $h->queue = [['status' => 400, 'json' => ['error' => 'invalid_grant']]];
    expect(fn () => (new GoogleCalendarOAuth($h, 'c', 's'))->refresh('R'))->toThrow(OAuthRevoked::class);
    $h->queue = [['status' => 503, 'json' => []]];
    expect(fn () => (new GoogleCalendarOAuth($h, 'c', 's'))->refresh('R'))->toThrow(OAuthFailure::class);
    $h->queue = [['status' => 200, 'json' => ['access_token' => 'A2']]];
    expect((new GoogleCalendarOAuth($h, 'c', 's'))->refresh('R'))->toBe(['access_token' => 'A2', 'refresh_token' => null]);
});

it('asks Microsoft for calendar permission only and checks it came back', function () {
    $o = new MicrosoftCalendarOAuth(new FakeHttpJson, 'c', 's', 'contoso');
    $url = $o->authUrl('https://app.test/cb', 'ST', 'CH');
    expect($url)->toContain('login.microsoftonline.com/contoso/')->toContain('Calendars.ReadWrite')->toContain('offline_access');
    expect(str_contains($url, 'Mail'))->toBeFalse();
    expect(fn () => new MicrosoftCalendarOAuth(new FakeHttpJson, 'c', 's', '../evil'))->toThrow(InvalidArgumentException::class);
    $h = new FakeHttpJson;
    $h->queue = [['status' => 200, 'json' => ['access_token' => 'A', 'refresh_token' => 'R', 'scope' => 'User.Read Calendars.ReadWrite']], ['status' => 200, 'json' => ['mail' => 'Me@Work.com']]];
    expect($o = new MicrosoftCalendarOAuth($h, 'c', 's'))->not->toBeNull();
    expect($o->exchange('C', 'V', 'u')['email'])->toBe('me@work.com');
    $h->queue = [['status' => 200, 'json' => ['access_token' => 'A', 'refresh_token' => 'R', 'scope' => 'User.Read Mail.Read']]];
    expect(fn () => $o->exchange('C', 'V', 'u'))->toThrow(OAuthFailure::class, 'microsoft_scope_missing');
    $h->queue = [['status' => 400, 'json' => ['error' => 'invalid_grant']]];
    expect(fn () => $o->refresh('R'))->toThrow(OAuthRevoked::class);
});

// ---------- provider APIs ----------

it('reads Google events: timed, all-day, skips cancelled and odd ones, cleans text, asks for UTC bounds', function () {
    $h = new FakeHttpJson;
    $h->queue = [['status' => 200, 'json' => ['items' => [
        ['summary' => "মিটিং\nএকটি", 'location' => 'অফিস', 'start' => ['dateTime' => '2026-11-10T15:00:00+06:00'], 'end' => ['dateTime' => '2026-11-10T16:00:00+06:00']],
        ['summary' => 'ছুটি', 'start' => ['date' => '2026-11-11'], 'end' => ['date' => '2026-11-12']],
        ['summary' => 'Cancel', 'status' => 'cancelled', 'start' => ['dateTime' => '2026-11-10T15:00:00Z'], 'end' => ['dateTime' => '2026-11-10T16:00:00Z']],
        ['summary' => 'ভাঙা', 'start' => ['dateTime' => 'not a date'], 'end' => ['dateTime' => 'x']],
        ['summary' => 'শেষ নেই', 'start' => ['dateTime' => '2026-11-10T15:00:00Z']],
        'junk',
    ]]]];
    $ev = (new GoogleCalendarApi($h))->events('TOK', new DateTimeImmutable('2026-11-09 00:00', coZone()), new DateTimeImmutable('2026-11-16 00:00', coZone()), coZone());
    expect(count($ev))->toBe(2);
    expect($ev[0]['summary'])->toBe('মিটিং একটি');
    expect($ev[0]['all_day'])->toBeFalse();
    expect($ev[0]['start']->getTimestamp())->toBe((new DateTimeImmutable('2026-11-10T09:00:00Z'))->getTimestamp());
    expect($ev[1]['all_day'])->toBeTrue();
    expect($ev[1]['start']->format('Y-m-d H:i'))->toBe('2026-11-11 00:00');
    expect($ev[1]['start']->getTimezone()->getName())->toBe(CO_ZONE);
    $c = $h->calls[0];
    expect($c['method'])->toBe('GET');
    expect($c['headers']['Authorization'])->toBe('Bearer TOK');
    expect($c['url'])->toContain('timeMin=2026-11-08T18%3A00%3A00Z')->toContain('timeMax=2026-11-15T18%3A00%3A00Z')->toContain('singleEvents=true')->toContain('maxResults=100');
});

it('maps Google error statuses and an unreadable create reply', function () {
    $api = new GoogleCalendarApi($h = new FakeHttpJson);
    $span = [new DateTimeImmutable('2026-11-09'), new DateTimeImmutable('2026-11-10'), coZone()];
    foreach ([401 => 'auth', 403 => 'auth', 429 => 'api', 500 => 'api', 302 => 'api'] as $status => $want) {
        $h->queue = [['status' => $status, 'json' => []]];
        try {
            $api->events('T', ...$span);
            $got = 'none';
        } catch (CalendarApiException $e) {
            $got = $e->getMessage();
        }
        expect([$status, $got])->toBe([$status, $want]);
    }
    $h->queue = [['status' => 200, 'json' => ['items' => []]]];
    expect($api->events('T', ...$span))->toBe([]);
    $h->queue = [['status' => 200, 'json' => []]];
    expect(fn () => $api->create('T', 'x', new DateTimeImmutable('2026-11-09'), new DateTimeImmutable('2026-11-10'), ''))->toThrow(CalendarApiException::class);
});

it('creates a Google event in UTC with no guest notifications, and leaves out an empty location', function () {
    $api = new GoogleCalendarApi($h = new FakeHttpJson);
    $h->queue = [['status' => 200, 'json' => ['id' => 'g1']], ['status' => 200, 'json' => ['id' => 'g2']]];
    $s = new DateTimeImmutable('2026-12-01 15:30', coZone());
    expect($api->create('TOK', 'বৈঠক "A"', $s, $s->modify('+45 minutes'), ''))->toBe('g1');
    $call = $h->calls[0];
    expect($call['method'])->toBe('POST');
    expect($call['url'])->toContain('/calendars/primary/events')->toContain('sendUpdates=none');
    expect($call['headers']['Content-Type'])->toBe('application/json');
    $b = json_decode($call['body'], true);
    expect($b['summary'])->toBe('বৈঠক "A"');
    expect($b['start'])->toBe(['dateTime' => '2026-12-01T09:30:00Z', 'timeZone' => 'UTC']);
    expect($b['end']['dateTime'])->toBe('2026-12-01T10:15:00Z');
    expect(array_key_exists('location', $b))->toBeFalse();
    expect(array_key_exists('attendees', $b))->toBeFalse();
    $api->create('TOK', 'x', $s, $s->modify('+1 hour'), 'Dhaka');
    expect(json_decode($h->calls[1]['body'], true)['location'])->toBe('Dhaka');
});

it('reads Outlook events as UTC, keeps all-day dates, skips cancelled', function () {
    $h = new FakeHttpJson;
    $h->queue = [['status' => 200, 'json' => ['value' => [
        ['subject' => 'কল', 'start' => ['dateTime' => '2026-11-10T09:00:00.0000000'], 'end' => ['dateTime' => '2026-11-10T10:00:00.0000000'], 'isAllDay' => false, 'location' => ['displayName' => 'Teams']],
        ['subject' => 'ছুটি', 'start' => ['dateTime' => '2026-11-11T00:00:00.0000000'], 'end' => ['dateTime' => '2026-11-12T00:00:00.0000000'], 'isAllDay' => true, 'location' => ['displayName' => '']],
        ['subject' => 'Cancel', 'isCancelled' => true, 'start' => ['dateTime' => '2026-11-10T09:00:00.0000000'], 'end' => ['dateTime' => '2026-11-10T10:00:00.0000000']],
        ['subject' => 'None'],
    ]]]];
    $ev = (new GraphCalendarApi($h))->events('TOK', new DateTimeImmutable('2026-11-09 00:00', coZone()), new DateTimeImmutable('2026-11-16 00:00', coZone()), coZone());
    expect(count($ev))->toBe(2);
    expect($ev[0]['start']->getTimestamp())->toBe((new DateTimeImmutable('2026-11-10T09:00:00Z'))->getTimestamp());
    expect($ev[0]['location'])->toBe('Teams');
    expect($ev[1]['all_day'])->toBeTrue();
    expect($ev[1]['start']->format('Y-m-d H:i'))->toBe('2026-11-11 00:00');
    expect($ev[1]['start']->getTimezone()->getName())->toBe(CO_ZONE);
    expect($h->calls[0]['headers']['Prefer'])->toBe('outlook.timezone="UTC"');
    expect($h->calls[0]['url'])->toContain('calendarView')->toContain('startDateTime=2026-11-08T18%3A00%3A00Z');
});

it('creates an Outlook event in UTC and maps failures', function () {
    $api = new GraphCalendarApi($h = new FakeHttpJson);
    $h->queue = [['status' => 201, 'json' => ['id' => 'm1']]];
    $s = new DateTimeImmutable('2026-12-01 15:30', coZone());
    expect($api->create('T', 'Job', $s, $s->modify('+1 hour'), 'অফিস'))->toBe('m1');
    $b = json_decode($h->calls[0]['body'], true);
    expect($b['subject'])->toBe('Job');
    expect($b['start'])->toBe(['dateTime' => '2026-12-01T09:30:00', 'timeZone' => 'UTC']);
    expect($b['location'])->toBe(['displayName' => 'অফিস']);
    expect(array_key_exists('attendees', $b))->toBeFalse();
    $h->queue = [['status' => 401, 'json' => []]];
    expect(fn () => $api->create('T', 'x', $s, $s, ''))->toThrow(CalendarApiException::class, 'auth');
    $h->queue = [['status' => 500, 'json' => []]];
    expect(fn () => $api->create('T', 'x', $s, $s, ''))->toThrow(CalendarApiException::class, 'api');
});

// ---------- the person's connected calendars ----------

it('connects a calendar once per e-mail: the refresh token is stored encrypted and a reconnect replaces it', function () {
    [$acc, $links, , , $sec] = coRig();
    expect($acc->connect(CO_T, CO_U, 'google', 'me@gmail.com', 'REFRESH-1'))->toBe('ok');
    $row = array_values($links->rows)[0];
    expect($row['kind'])->toBe('gcal');
    expect($row['host'])->toBe('google');
    expect($row['name'])->toBe('me@gmail.com');
    expect(str_contains($row['secret_ct'], 'REFRESH-1'))->toBeFalse();
    expect($sec->decrypt($row['secret_ct'], $row['secret_ver']))->toBe('REFRESH-1');
    expect($acc->connect(CO_T, CO_U, 'google', 'ME@gmail.com', 'REFRESH-2'))->toBe('ok');
    expect(count($links->rows))->toBe(1);
    $row = array_values($links->rows)[0];
    expect($sec->decrypt($row['secret_ct'], $row['secret_ver']))->toBe('REFRESH-2');
    expect($acc->connect(CO_T, CO_U, 'microsoft', 'me@work.com', 'R3'))->toBe('ok');
    expect(array_map(fn ($a) => $a['kind'], $acc->list(CO_U)))->toBe(['gcal', 'mcal']);
    expect($acc->connect(CO_T, CO_U, 'yahoo', 'a@b.com', 'R'))->toBe('bad_input');
    expect($acc->connect(CO_T, CO_U, 'google', '', 'R'))->toBe('bad_input');
    expect($acc->connect(CO_T, CO_U, 'google', 'a@b.com', ''))->toBe('bad_input');
    expect($acc->list('someone-else'))->toBe([]);
});

it('limits connected calendars to five per kind', function () {
    [$acc] = coRig();
    foreach (range(1, 5) as $i) {
        expect($acc->connect(CO_T, CO_U, 'google', "a$i@x.com", 'R'))->toBe('ok');
    }
    expect($acc->connect(CO_T, CO_U, 'google', 'a6@x.com', 'R'))->toBe('limit');
});

it('reads events through a fresh access token and tags them with the account', function () {
    [$acc, , $p, $a] = coRig();
    $acc->connect(CO_T, CO_U, 'google', 'me@gmail.com', 'RT');
    $a['google']->events = [['start' => new DateTimeImmutable('2026-11-10 10:00', coZone()), 'end' => new DateTimeImmutable('2026-11-10 11:00', coZone()), 'all_day' => false, 'summary' => 'X', 'location' => '']];
    $r = $acc->events(CO_T, CO_U, coNow(), coNow()->modify('+7 days'), coZone());
    expect($r['count'])->toBe(1);
    expect($r['errors'])->toBe([]);
    expect($r['events'][0]['calendar'])->toBe('me@gmail.com');
    expect($p['google']->refreshed)->toBe(['RT']);
    expect($a['google']->tokens)->toBe(['AT-RT']);
    expect($acc->events(CO_T, 'nobody', coNow(), coNow()->modify('+7 days'), coZone()))->toBe(['events' => [], 'errors' => [], 'count' => 0]);
});

it('one failing calendar never hides the others, and the reason is remembered only when it is the connection\'s fault', function () {
    [$acc, $links, $p, $a] = coRig();
    $acc->connect(CO_T, CO_U, 'google', 'g@x.com', 'RG');
    $acc->connect(CO_T, CO_U, 'microsoft', 'm@x.com', 'RM');
    $a['microsoft']->events = [['start' => new DateTimeImmutable('2026-11-10 10:00', coZone()), 'end' => new DateTimeImmutable('2026-11-10 11:00', coZone()), 'all_day' => false, 'summary' => 'OK', 'location' => '']];
    $span = [coNow(), coNow()->modify('+7 days'), coZone()];
    $errOf = fn (string $name) => array_values(array_filter($links->rows, fn ($r) => $r['name'] === $name))[0]['last_error'];

    $p['google']->refreshFails = 'revoked';
    $r = $acc->events(CO_T, CO_U, ...$span);
    expect(count($r['events']))->toBe(1);
    expect($r['errors'])->toBe(['g@x.com: '.CalendarAccounts::message('revoked')]);
    expect($errOf('g@x.com'))->toBe('revoked');
    expect($errOf('m@x.com'))->toBeNull();

    $p['google']->refreshFails = null;
    $a['google']->fail = 'auth';
    expect($acc->events(CO_T, CO_U, ...$span)['errors'])->toBe(['g@x.com: '.CalendarAccounts::message('auth')]);
    expect($errOf('g@x.com'))->toBe('auth');
    $a['google']->fail = 'bad_reply';
    $acc->events(CO_T, CO_U, ...$span);
    expect($errOf('g@x.com'))->toBe('api');

    $a['google']->fail = null;                        // it works again: the old mark is cleared
    expect($acc->events(CO_T, CO_U, ...$span)['errors'])->toBe([]);
    expect($errOf('g@x.com'))->toBeNull();

    $p['google']->refreshFails = 'down';              // a passing outage: reported, but nothing is remembered
    expect($acc->events(CO_T, CO_U, ...$span)['errors'])->toBe(['g@x.com: '.CalendarAccounts::message('unavailable')]);
    expect($errOf('g@x.com'))->toBeNull();
    $p['google']->refreshFails = null;
    $p['google']->configured = false;                 // server key missing: also not the person's fault
    expect($acc->events(CO_T, CO_U, ...$span)['errors'])->toBe(['g@x.com: '.CalendarAccounts::message('not_configured')]);
    expect($errOf('g@x.com'))->toBeNull();
});

it('keeps a rotated refresh token and does nothing when the token is unchanged', function () {
    [$acc, $links, $p, , $sec] = coRig();
    $acc->connect(CO_T, CO_U, 'microsoft', 'm@x.com', 'OLD');
    $p['microsoft']->rotate = 'OLD';
    $acc->events(CO_T, CO_U, coNow(), coNow()->modify('+1 day'), coZone());
    $row = array_values($links->rows)[0];
    expect($sec->decrypt($row['secret_ct'], $row['secret_ver']))->toBe('OLD');
    expect($links->secretUpdates)->toBe(0);                                      // same token handed back: nothing is rewritten
    $p['microsoft']->rotate = 'NEW';
    $acc->events(CO_T, CO_U, coNow(), coNow()->modify('+1 day'), coZone());
    $row = array_values($links->rows)[0];
    expect($sec->decrypt($row['secret_ct'], $row['secret_ver']))->toBe('NEW');
    $acc->events(CO_T, CO_U, coNow(), coNow()->modify('+1 day'), coZone());
    expect($p['microsoft']->refreshed)->toBe(['OLD', 'OLD', 'NEW']);
});

it('adds an event: validates every bound, converts the person\'s local time, uses the first calendar by default', function () {
    [$acc, , , $a] = coRig();
    $acc->connect(CO_T, CO_U, 'google', 'g@x.com', 'RG');
    $acc->connect(CO_T, CO_U, 'microsoft', 'm@x.com', 'RM');
    $go = fn (string $title = 'বৈঠক', string $start = '2026-12-01 15:30', int $min = 60, string $loc = '', ?string $id = null) => $acc->create(CO_T, CO_U, $id, $title, $start, $min, $loc, coZone());

    $r = $go();
    expect($r)->toBe(['status' => 'ok', 'id' => 'evt1']);
    $c = $a['google']->created[0];
    expect($c['title'])->toBe('বৈঠক');
    expect($c['start']->format('Y-m-d H:i T'))->toBe('2026-12-01 15:30 +06');
    expect($c['end']->format('H:i'))->toBe('16:30');
    expect(count($a['microsoft']->created))->toBe(0);

    expect($go(start: '2026-12-01T15:30')['status'])->toBe('ok');                // from <input type="datetime-local">
    expect($go(min: 5)['status'])->toBe('ok');
    expect($go(min: 1440)['status'])->toBe('ok');
    expect($go(title: str_repeat('ক', 200))['status'])->toBe('ok');
    expect(mb_strlen($a['google']->created[count($a['google']->created) - 1]['title']))->toBe(200);
    expect($go(title: str_repeat('ক', 201))['status'])->toBe('ok');              // too long is cut, not refused
    expect(mb_strlen($a['google']->created[count($a['google']->created) - 1]['title']))->toBe(200);
    expect($go(title: "  a\x00b\n ")['status'])->toBe('ok');
    expect($a['google']->created[count($a['google']->created) - 1]['title'])->toBe('a b');

    $n = count($a['google']->created);
    foreach ([['title' => ''], ['title' => " \n "], ['min' => 4], ['min' => 1441], ['min' => 0], ['min' => -5],
        ['start' => '2026-02-30 10:00'], ['start' => '2026-12-01 25:00'], ['start' => 'tomorrow'], ['start' => ''], ['start' => '2026-12-01'], ['start' => '2026-12-01 10:00:00'],
        ['start' => '2026-11-08 09:59'],       // more than a day in the past
        ['start' => '2028-11-09 10:01'],       // more than two years ahead
    ] as $bad) {
        expect([$bad, $go(...$bad)['status']])->toBe([$bad, 'bad_input']);
    }
    expect($go(start: '2026-11-08 10:00')['status'])->toBe('ok');                  // exactly one day back is allowed
    expect($go(start: '2028-11-09 10:00')['status'])->toBe('ok');                  // exactly two years ahead is allowed
    expect(count($a['google']->created))->toBe($n + 2);
});

it('adds the event to the chosen calendar only, and never to someone else\'s or to a non-calendar link', function () {
    [$acc, $links, , $a] = coRig();
    $acc->connect(CO_T, CO_U, 'google', 'g@x.com', 'RG');
    $acc->connect(CO_T, CO_U, 'microsoft', 'm@x.com', 'RM');
    $acc->connect(CO_T, 'other-user', 'google', 'o@x.com', 'RO');
    $links->add(CO_T, CO_U, 'ics00000000000000000000001', 'ics', 'feed', 'c', 1, 'h');
    $ids = array_column($acc->list(CO_U), 'id', 'name');
    $other = array_column($acc->list('other-user'), 'id')[0];
    expect($acc->create(CO_T, CO_U, $ids['m@x.com'], 'x', '2026-12-01 10:00', 60, '', coZone())['status'])->toBe('ok');
    expect([count($a['google']->created), count($a['microsoft']->created)])->toBe([0, 1]);
    expect($acc->create(CO_T, CO_U, $other, 'x', '2026-12-01 10:00', 60, '', coZone())['status'])->toBe('not_found');
    expect($acc->create(CO_T, CO_U, 'ics00000000000000000000001', 'x', '2026-12-01 10:00', 60, '', coZone())['status'])->toBe('not_found');
    expect($acc->create(CO_T, CO_U, 'nope', 'x', '2026-12-01 10:00', 60, '', coZone())['status'])->toBe('not_found');
    expect($acc->create(CO_T, 'nobody', null, 'x', '2026-12-01 10:00', 60, '', coZone())['status'])->toBe('not_found');
    expect([count($a['google']->created), count($a['microsoft']->created)])->toBe([0, 1]);
});

it('reports why an event could not be added and marks only real connection problems', function () {
    [$acc, $links, $p, $a] = coRig();
    $acc->connect(CO_T, CO_U, 'google', 'g@x.com', 'RG');
    $go = fn () => $acc->create(CO_T, CO_U, null, 'x', '2026-12-01 10:00', 60, '', coZone())['status'];
    $err = fn () => array_values($links->rows)[0]['last_error'];
    $a['google']->fail = 'auth';
    expect($go())->toBe('auth');
    expect($err())->toBe('auth');
    $a['google']->fail = 'api';
    expect($go())->toBe('api');
    expect($err())->toBe('api');
    $a['google']->fail = null;
    expect($go())->toBe('ok');
    expect($err())->toBeNull();
    $p['google']->refreshFails = 'revoked';
    expect($go())->toBe('revoked');
    expect($err())->toBe('revoked');
    $p['google']->refreshFails = 'down';
    $acc->connect(CO_T, CO_U, 'google', 'g@x.com', 'RG');                       // reconnect clears the mark
    expect($err())->toBeNull();
    expect($go())->toBe('unavailable');
    expect($err())->toBeNull();
    expect(count($a['google']->created))->toBe(1);
});

it('every status the person can meet has a Bengali message', function () {
    foreach (['bad_input', 'not_found', 'revoked', 'auth', 'unavailable', 'api', 'not_configured', 'limit'] as $s) {
        expect(CalendarAccounts::message($s) !== '' && CalendarAccounts::message($s) !== CalendarAccounts::message('zzz'))->toBeTrue();
    }
});

// ---------- upcoming list and the workflow tool ----------

it('merges connected calendars into the upcoming list, shows a meeting once, and counts them as calendars', function () {
    [$acc, $links, , $a] = coRig();
    $acc->connect(CO_T, CO_U, 'google', 'g@x.com', 'RG');
    $same = ['start' => new DateTimeImmutable('2026-11-10 10:00', coZone()), 'end' => new DateTimeImmutable('2026-11-10 11:00', coZone()), 'all_day' => false, 'summary' => 'Standup', 'location' => ''];
    $a['google']->events = [$same, ['summary' => 'শুধু-OAuth'] + ['start' => new DateTimeImmutable('2026-11-09 18:00', coZone()), 'end' => new DateTimeImmutable('2026-11-09 19:00', coZone()), 'all_day' => false, 'location' => 'Dhaka']];
    $f = new FakeIcsFetcher;
    $url = 'https://calendar.google.com/calendar/ical/x/private-abc/basic.ics';
    $f->feeds[$url] = "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:1\r\nSUMMARY:standup\r\nDTSTART:20261110T040000Z\r\nDTEND:20261110T050000Z\r\nEND:VEVENT\r\nEND:VCALENDAR";
    $sec = new FakeSecrets;
    $svc = new CalendarService($links, $sec, $f, fn ($k, $t, $m) => $m(), $acc);
    expect($svc->addFeed(CO_T, CO_U, 'অফিস', $url)['status'])->toBe('ok');
    $r = $svc->upcoming(CO_T, CO_U, CO_ZONE, coNow(), 7);
    expect($r['feeds'])->toBe(2);
    expect(array_column($r['events'], 'summary'))->toBe(['শুধু-OAuth', 'Standup']);        // earliest first; the same meeting once (same time + same name ignoring case)
    expect($r['errors'])->toBe([]);
    $a['google']->fail = 'auth';
    $r2 = $svc->upcoming(CO_T, CO_U, CO_ZONE, coNow(), 7);
    expect(array_column($r2['events'], 'summary'))->toBe(['standup']);                      // the failing calendar is reported, the feed still shows
    expect(count($r2['errors']))->toBe(1);
    expect((new CalendarService($links, $sec, $f, fn ($k, $t, $m) => $m()))->upcoming(CO_T, CO_U, CO_ZONE, coNow(), 7)['feeds'])->toBe(1);   // without accounts: as before
});

function coTool(): array
{
    [$acc, $links, $p, $a] = coRig();
    $acc->connect(CO_T, CO_U, 'google', 'g@x.com', 'RG');
    $acc->connect(CO_T, CO_U, 'microsoft', 'm@x.com', 'RM');

    return [new CalendarAddEventTool($acc), $a, $acc];
}

it('the add-event tool is an outside-world tool: a workflow must ask for approval before it', function () {
    [$t] = coTool();
    expect($t->key())->toBe('calendar.add_event');
    expect($t->risk())->toBe(Risk::EXTERNAL);
    expect(array_keys($t->args()))->toBe(['title', 'start', 'minutes?', 'location?', 'calendar?']);
    $reg = new ToolRegistry([$t]);
    $no = ['start' => 'a', 'nodes' => ['a' => ['type' => 'tool', 'tool' => 'calendar.add_event', 'args' => ['title' => 'x', 'start' => '2026-12-01 10:00'], 'next' => 'o'], 'o' => ['type' => 'output', 'text' => 'ok']]];
    expect(count(WorkflowDefinition::validate($no, $reg)) > 0)->toBeTrue();
    $yes = ['start' => 'q', 'nodes' => ['q' => ['type' => 'approval', 'message' => 'যোগ করব?', 'next' => 'a'], 'a' => $no['nodes']['a'], 'o' => $no['nodes']['o']]];
    expect(WorkflowDefinition::validate($yes, $reg))->toBe([]);
});

it('the add-event tool adds to the right calendar and explains every refusal', function () {
    [$t, $a] = coTool();
    $c = new ToolContext(CO_T, CO_U, CO_ZONE);
    $r = $t->run($c, ['title' => 'বৈঠক', 'start' => '2026-12-01 15:30']);
    expect($r)->toContain('বৈঠক')->toContain('2026-12-01 15:30');
    expect($a['google']->created[0]['end']->format('H:i'))->toBe('16:30');          // default 60 minutes, person's zone (+06)
    $t->run($c, ['title' => 'x', 'start' => '2026-12-01 10:00', 'minutes' => '30', 'location' => 'অফিস', 'calendar' => 'M@X.com']);
    expect(count($a['microsoft']->created))->toBe(1);
    expect($a['microsoft']->created[0]['location'])->toBe('অফিস');
    expect($a['microsoft']->created[0]['end']->format('H:i'))->toBe('10:30');
    foreach ([['calendar' => 'nobody@x.com'], ['minutes' => 'abc'], ['minutes' => '-5'], ['minutes' => '2'], ['title' => ''], ['start' => 'soon'], ['start' => '']] as $bad) {
        $threw = false;
        try {
            $t->run($c, $bad + ['title' => 'x', 'start' => '2026-12-01 10:00']);
        } catch (ToolException $e) {
            $threw = $e->getMessage() !== '';
        }
        expect([$bad, $threw])->toBe([$bad, true]);
    }
    expect(count($a['google']->created) + count($a['microsoft']->created))->toBe(2);
    expect(fn () => $t->run(new ToolContext(CO_T, 'nobody', CO_ZONE), ['title' => 'x', 'start' => '2026-12-01 10:00']))->toThrow(ToolException::class);
});
