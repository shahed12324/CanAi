<?php

use App\Social\FacebookGraph;
use App\Social\Multipart;
use App\Social\SocialAccounts;
use App\Social\SocialInsights;
use App\Social\SocialPublisher;
use App\Tenancy\TenantRoles;
use Tests\Support\FakeHttpJson;
use Tests\Support\FakeSealer;
use Tests\Support\FakePostMedia;
use Tests\Support\FakeSocialPublishRepo;
use Tests\Support\FakeSocialRepo;

const PT = 'TENANT0000000000000000000A';
const PU = 'USER000000000000000000000A';
const PA = 'ACCT000000000000000000000A';
const PAGE = '123456789012345';
const TOKEN = 'EAAB_secret-token.value_0123456789';

function pvault(string $key = 'k1'): FakeSealer
{
    return new FakeSealer($key);
}

function pgraph(?FakeHttpJson $h = null): array
{
    $h ??= new FakeHttpJson;

    return [new FacebookGraph($h, 'v24.0'), $h];
}

function pclaim(array $over = []): array
{
    return $over + ['id' => 'POST000000000000000000000A', 'tenant_id' => PT, 'user_id' => PU, 'account_id' => PA, 'caption' => 'ঈদ মোবারক', 'media_kind' => 'none', 'media_id' => null, 'media_size' => null];
}

/** @return array{0: SocialPublisher, 1: FakeSocialPublishRepo, 2: FakeHttpJson, 3: FakePostMedia} */
function ppub(?FakeHttpJson $h = null, string $status = 'active'): array
{
    $secrets = pvault();
    $repo = new FakeSocialPublishRepo;
    $sealed = $secrets->seal(TOKEN);
    $repo->cred = ['tenant_id' => PT, 'external_id' => PAGE, 'cipher' => $sealed['cipher'], 'version' => $sealed['version'], 'status' => $status];
    [$g, $h] = pgraph($h);
    $m = new FakePostMedia;

    return [new SocialPublisher($repo, $secrets, $g, $m), $repo, $h, $m];
}

// ---------- multipart
it('builds a well-formed multipart body', function () {
    $m = Multipart::build(['caption' => 'হ্যালো', 'access_token' => 'T'], 'source', 'post.png', 'image/png', "\x89PNG\r\n", 'BND');
    expect($m['contentType'])->toBe('multipart/form-data; boundary=BND');
    expect($m['body'])->toBe("--BND\r\nContent-Disposition: form-data; name=\"caption\"\r\n\r\nহ্যালো\r\n--BND\r\nContent-Disposition: form-data; name=\"access_token\"\r\n\r\nT\r\n--BND\r\nContent-Disposition: form-data; name=\"source\"; filename=\"post.png\"\r\nContent-Type: image/png\r\n\r\n\x89PNG\r\n\r\n--BND--\r\n");
    $evil = Multipart::build([], 'so"urce', "a\"\r\nX-Evil: 1.png", 'image/png', 'x', 'B');
    expect(str_contains($evil['body'], "\r\nX-Evil"))->toBeFalse();
    expect(Multipart::build([], 'f', 'n', 'm', 'x')['contentType'])->toContain('boundary=jv');
});

// ---------- graph: page check
it('verifies a page and reads its name', function () {
    [$g, $h] = pgraph();
    $h->queue[] = ['status' => 200, 'json' => ['id' => PAGE, 'name' => 'আমার পেজ']];
    expect($g->page(PAGE, TOKEN))->toBe(['ok' => true, 'id' => PAGE, 'name' => 'আমার পেজ', 'token' => false, 'message' => '']);
    expect($h->calls[0]['method'])->toBe('GET')->and($h->calls[0]['url'])->toBe('https://graph.facebook.com/v24.0/'.PAGE.'?fields=id,name&access_token='.rawurlencode(TOKEN));
});

it('tells a bad token from other page-check failures', function () {
    [$g, $h] = pgraph();
    $h->queue[] = ['status' => 400, 'json' => ['error' => ['message' => "Invalid OAuth access token.\n", 'type' => 'OAuthException', 'code' => 190]]];
    $r = $g->page(PAGE, TOKEN);
    expect($r['ok'])->toBeFalse()->and($r['token'])->toBeTrue()->and($r['message'])->toBe('Facebook: Invalid OAuth access token. (Code 190)');
    $h->queue[] = ['status' => 404, 'json' => ['error' => ['message' => 'Unsupported get request', 'code' => 100]]];
    expect($g->page(PAGE, TOKEN)['token'])->toBeFalse();
    $h->queue[] = ['status' => 200, 'json' => []];
    expect($g->page(PAGE, TOKEN)['ok'])->toBeFalse();
    $thr = new class implements App\Outreach\HttpJson {
        public function request(string $method, string $url, array $headers = [], ?array $form = null, ?string $body = null): array
        {
            throw new RuntimeException('timeout');
        }
    };
    expect((new FacebookGraph($thr))->page(PAGE, TOKEN)['ok'])->toBeFalse();
});

// ---------- graph: publish
it('posts text to the Page feed with the token in the form, not the URL', function () {
    [$g, $h] = pgraph();
    $h->queue[] = ['status' => 200, 'json' => ['id' => '123_456']];
    $r = $g->publishText(PAGE, TOKEN, 'ঈদ মোবারক');
    expect($r->status)->toBe('ok')->and($r->id)->toBe('123_456')->and($r->url)->toBe('https://www.facebook.com/123_456');
    $c = $h->calls[0];
    expect($c['method'])->toBe('POST')->and($c['url'])->toBe('https://graph.facebook.com/v24.0/'.PAGE.'/feed')->and($c['form'])->toBe(['message' => 'ঈদ মোবারক', 'access_token' => TOKEN])->and(str_contains($c['url'], TOKEN))->toBeFalse();
});

it('posts a photo as multipart and prefers post_id', function () {
    [$g, $h] = pgraph();
    $h->queue[] = ['status' => 200, 'json' => ['id' => '777', 'post_id' => '123_888']];
    $r = $g->publishPhoto(PAGE, TOKEN, 'Caption', 'PNGDATA', 'image/png');
    expect($r->status)->toBe('ok')->and($r->url)->toBe('https://www.facebook.com/123_888');
    $c = $h->calls[0];
    expect($c['url'])->toBe('https://graph.facebook.com/v24.0/'.PAGE.'/photos')->and($c['form'])->toBeNull()->and($c['headers']['Content-Type'])->toStartWith('multipart/form-data; boundary=')
        ->and($c['body'])->toContain('name="caption"')->and($c['body'])->toContain('PNGDATA')->and($c['body'])->toContain('filename="post.png"');
    $h->queue[] = ['status' => 200, 'json' => ['id' => '9']];
    $g->publishPhoto(PAGE, TOKEN, 'x', 'JPG', 'image/jpeg');
    expect($h->calls[1]['body'])->toContain('filename="post.jpg"')->and($h->calls[1]['body'])->toContain('Content-Type: image/jpeg');
});

it('posts a video to the video host', function () {
    [$g, $h] = pgraph();
    $h->queue[] = ['status' => 200, 'json' => ['id' => '5551234']];
    $r = $g->publishVideo(PAGE, TOKEN, 'Description', 'MP4DATA');
    expect($r->status)->toBe('ok')->and($r->url)->toBe('https://www.facebook.com/watch/?v=5551234')->and($h->calls[0]['url'])->toBe('https://graph-video.facebook.com/v24.0/'.PAGE.'/videos')
        ->and($h->calls[0]['body'])->toContain('filename="video.mp4"')->and($h->calls[0]['body'])->toContain('MP4DATA');
});

it('refuses empty or oversized media without calling Facebook', function () {
    [$g, $h] = pgraph();
    expect($g->publishPhoto(PAGE, TOKEN, 'x', '', 'image/png')->status)->toBe('rejected')->and($g->publishPhoto(PAGE, TOKEN, 'x', str_repeat('a', FacebookGraph::MAX_PHOTO + 1), 'image/png')->status)->toBe('rejected');
    expect($g->publishVideo(PAGE, TOKEN, 'x', '')->status)->toBe('rejected')->and($g->publishVideo(PAGE, TOKEN, 'x', str_repeat('a', FacebookGraph::MAX_VIDEO + 1))->status)->toBe('rejected');
    expect($h->calls)->toBe([]);
    $h->queue[] = ['status' => 200, 'json' => ['id' => '123']];
    expect($g->publishPhoto(PAGE, TOKEN, 'x', str_repeat('a', FacebookGraph::MAX_PHOTO), 'image/png')->status)->toBe('ok')->and(count($h->calls))->toBe(1);   // exactly at the limit goes out
});

it('classifies Facebook answers: token, rejected, unknown', function () {
    [$g, $h] = pgraph();
    $h->queue[] = ['status' => 400, 'json' => ['error' => ['message' => 'Session expired', 'code' => 190]]];
    expect($g->publishText(PAGE, TOKEN, 'x')->status)->toBe('token');
    $h->queue[] = ['status' => 403, 'json' => ['error' => ['message' => '(#200) permission', 'code' => 200, 'type' => 'OAuthException']]];
    expect($g->publishText(PAGE, TOKEN, 'x')->status)->toBe('token');
    $h->queue[] = ['status' => 400, 'json' => ['error' => ['message' => 'Duplicate status', 'code' => 506]]];
    $r = $g->publishText(PAGE, TOKEN, 'x');
    expect($r->status)->toBe('rejected')->and($r->message)->toBe('Facebook: Duplicate status (Code 506)');
    foreach ([[['status' => 500, 'json' => []], 'unknown'], [['status' => 400, 'json' => []], 'unknown'], [['status' => 400, 'json' => ['error' => ['message' => 'unknown', 'code' => 1]]], 'unknown'],
        [['status' => 400, 'json' => ['error' => ['message' => 'unknown', 'code' => 2]]], 'unknown'], [['status' => 503, 'json' => ['error' => ['message' => 'x', 'code' => 506]]], 'unknown'],
        [['status' => 200, 'json' => []], 'unknown'], [['status' => 200, 'json' => ['id' => '<script>']], 'unknown'], [['status' => 200, 'json' => ['error' => ['code' => 506, 'message' => 'x']]], 'rejected']] as [$answer, $want]) {
        $h->queue[] = $answer;
        expect($g->publishText(PAGE, TOKEN, 'x')->status)->toBe($want);
    }
});

it('treats a thrown error while sending as unknown, never as failed-safe', function () {
    $thr = new class implements App\Outreach\HttpJson {
        public function request(string $method, string $url, array $headers = [], ?array $form = null, ?string $body = null): array
        {
            throw new RuntimeException('timeout');
        }
    };
    expect((new FacebookGraph($thr))->publishText(PAGE, TOKEN, 'x')->status)->toBe('unknown');
});

// ---------- secrets
it('round-trips a sealed token', function () {
    $s = pvault();
    $x = $s->seal(TOKEN);
    expect(str_contains($x['cipher'], TOKEN))->toBeFalse()->and($s->open($x['cipher'], $x['version']))->toBe(TOKEN);
    expect($s->open('garbage', 1))->toBeNull()->and($s->open($x['cipher'], 99))->toBeNull()->and(pvault('other')->open($x['cipher'], 1))->toBeNull();
});

// ---------- publisher
it('publishes text and records the post as done', function () {
    [$p, $repo, $h] = ppub();
    $h->queue[] = ['status' => 200, 'json' => ['id' => '1_2']];
    expect($p->publish(pclaim()))->toBe('posted');
    expect($repo->names())->toBe(['credentials', 'done'])->and($repo->calls[1][1])->toBe([PT, 'POST000000000000000000000A', '1_2', 'https://www.facebook.com/1_2']);
    expect($h->calls[0]['form']['access_token'])->toBe(TOKEN)->and($h->calls[0]['form']['message'])->toBe('ঈদ মোবারক');
});

it('publishes a picture or a video when the post has media', function () {
    [$p, $repo, $h, $m] = ppub();
    $m->result = ['kind' => 'image', 'mime' => 'image/png', 'bytes' => 'IMG'];
    $h->queue[] = ['status' => 200, 'json' => ['id' => '123', 'post_id' => '1_999']];
    expect($p->publish(pclaim(['media_kind' => 'design', 'media_id' => 'D', 'media_size' => 'post'])))->toBe('posted')->and($h->calls[0]['url'])->toEndWith('/photos');
    $m->result = ['kind' => 'video', 'mime' => 'video/mp4', 'bytes' => 'VID'];
    $h->queue[] = ['status' => 200, 'json' => ['id' => '5555']];
    expect($p->publish(pclaim(['media_kind' => 'video', 'media_id' => 'V'])))->toBe('posted')->and($h->calls[1]['url'])->toContain('graph-video.facebook.com');
});

it('never posts text only when the picture is missing or cannot be drawn', function () {
    [$p, $repo, $h, $m] = ppub();
    $m->result = null;
    expect($p->publish(pclaim(['media_kind' => 'design', 'media_id' => 'D', 'media_size' => 'post'])))->toBe('failed');
    expect($h->calls)->toBe([])->and($repo->names())->toBe(['credentials', 'fail'])->and($repo->calls[1][1][2])->toContain('কিছুই Post Not done');
    $m->throw = new RuntimeException('engine');
    expect($p->publish(pclaim(['media_kind' => 'video', 'media_id' => 'V'])))->toBe('failed')->and($h->calls)->toBe([]);
    expect($p->publish(pclaim(['media_kind' => 'design', 'media_id' => null])))->toBe('failed')->and($h->calls)->toBe([]);
});

it('fails before calling Facebook when the connection is missing, foreign, off or unreadable', function () {
    [$p, $repo, $h] = ppub();
    $repo->cred = null;
    expect($p->publish(pclaim()))->toBe('failed');
    [$p2, $repo2, $h2] = ppub();
    $repo2->cred['tenant_id'] = 'OTHERTENANT00000000000000B';
    expect($p2->publish(pclaim()))->toBe('failed');
    [$p3, $repo3, $h3] = ppub(null, 'invalid');
    expect($p3->publish(pclaim()))->toBe('failed');
    [$p4, $repo4, $h4] = ppub();
    $repo4->cred['cipher'] = 'garbage';
    expect($p4->publish(pclaim()))->toBe('failed');
    foreach ([$h, $h2, $h3, $h4] as $x) {
        expect($x->calls)->toBe([]);
    }
    expect($repo->names())->toBe(['credentials', 'fail']);
});

it('marks the Page invalid when Facebook rejects the token', function () {
    [$p, $repo, $h] = ppub();
    $h->queue[] = ['status' => 400, 'json' => ['error' => ['message' => 'Expired', 'code' => 190]]];
    expect($p->publish(pclaim()))->toBe('failed');
    expect($repo->names())->toBe(['credentials', 'markAccount', 'fail'])->and($repo->calls[1][1])->toBe([PA, 'invalid'])->and($repo->calls[2][1][2])->toContain('New Page টোকেন');
});

it('says nothing was posted on a clean rejection, and asks to check the Page when unsure', function () {
    [$p, $repo, $h] = ppub();
    $h->queue[] = ['status' => 400, 'json' => ['error' => ['message' => 'Duplicate', 'code' => 506]]];
    $p->publish(pclaim());
    expect($repo->calls[1][1][2])->toContain('কিছু Post Not done')->and($repo->calls[1][1][2])->toContain('Duplicate');
    $h->queue[] = ['status' => 500, 'json' => []];
    $p->publish(pclaim());
    expect(end($repo->calls)[1][2])->toContain('পেজে দেখে নিন')->and(in_array('markAccount', $repo->names(), true))->toBeFalse();
});

it('survives the database failing after a successful post', function () {
    [$p, $repo, $h] = ppub();
    $repo->throwOnDone = true;
    $h->queue[] = ['status' => 200, 'json' => ['id' => '1_2']];
    expect($p->publish(pclaim()))->toBe('posted');
    expect(count($h->calls))->toBe(1);
});

// ---------- connecting a page
function pacc(?FakeHttpJson $h = null): array
{
    $repo = new FakeSocialRepo;
    [$g, $h] = pgraph($h);

    return [new SocialAccounts($repo, pvault(), $g), $repo, $h];
}

it('connects a verified page and stores only the sealed token', function () {
    [$a, $repo, $h] = pacc();
    $h->queue[] = ['status' => 200, 'json' => ['id' => PAGE, 'name' => 'আমার পেজ']];
    expect($a->connect(PT, ' '.PAGE.' ', ' '.TOKEN.' '))->toBe('ok');
    [$m, $args] = $repo->calls[0];
    expect($m)->toBe('saveAccount')->and($args[0])->toBe(PT)->and(strlen($args[1]))->toBe(26)->and($args[2])->toBe(PAGE)->and($args[3])->toBe('আমার পেজ')->and(str_contains($args[4], TOKEN))->toBeFalse()->and($args[5])->toBe(1);
    expect(pvault('other')->open($args[4], 1))->toBeNull()->and(pvault()->open($args[4], 1))->toBe(TOKEN);
});

it('refuses bad ids and tokens before calling Facebook', function (string $page, string $token, string $code) {
    [$a, $repo, $h] = pacc();
    expect($a->connect(PT, $page, $token))->toBe($code)->and($h->calls)->toBe([])->and($repo->calls)->toBe([]);
})->with([['abc', TOKEN, 'bad_page'], ['1234', TOKEN, 'bad_page'], [PAGE, 'short', 'bad_token'], [PAGE, 'has space in the token value here', 'bad_token'], [PAGE, str_repeat('a', 601), 'bad_token']]);

it('reports mismatch, bad token, rejection and the page limit', function () {
    [$a, $repo, $h] = pacc();
    $h->queue[] = ['status' => 200, 'json' => ['id' => '999999999', 'name' => 'অন্য পেজ']];
    expect($a->connect(PT, PAGE, TOKEN))->toBe('mismatch');
    $h->queue[] = ['status' => 400, 'json' => ['error' => ['message' => 'bad', 'code' => 190]]];
    $msg = null;
    expect($a->connect(PT, PAGE, TOKEN, $msg))->toBe('token')->and($msg)->toContain('190');
    $h->queue[] = ['status' => 404, 'json' => ['error' => ['message' => 'nope', 'code' => 100]]];
    expect($a->connect(PT, PAGE, TOKEN))->toBe('rejected');
    expect($repo->calls)->toBe([]);
    $h->queue[] = ['status' => 200, 'json' => ['id' => PAGE, 'name' => 'P']];
    $repo->answer = 'limit';
    expect($a->connect(PT, PAGE, TOKEN))->toBe('limit');
    $h->queue[] = ['status' => 200, 'json' => ['id' => PAGE, 'name' => 'P']];
    $repo->answer = 'ok';
    $repo->throw = new RuntimeException('db');
    expect($a->connect(PT, PAGE, TOKEN))->toBe('failed');
});

it('disconnects a page and reports busy or failure', function () {
    [$a, $repo] = pacc();
    expect($a->disconnect(PT, PA))->toBe('ok')->and($repo->calls[0])->toBe(['deleteAccount', [PT, PA]]);
    $repo->answer = 'busy';
    expect($a->disconnect(PT, PA))->toBe('busy');
    $repo->throw = new RuntimeException('x');
    expect($a->disconnect(PT, PA))->toBe('failed');
});

// ---------- service wiring + permissions
it('attaches and retries through the service', function () {
    $repo = new FakeSocialRepo;
    $s = new App\Social\SocialService($repo, new DateTimeZone('Asia/Dhaka'));
    expect($s->attach(PT, PU, 'POST000000000000000000000A', 'acct000000000000000000000a'))->toBe('ok')->and($repo->calls[0])->toBe(['setAccount', [PT, PU, 'POST000000000000000000000A', 'acct000000000000000000000a']]);
    $s->attach(PT, PU, 'POST000000000000000000000A', '  ');
    expect($repo->calls[1][1][3])->toBeNull()->and($s->attach(PT, PU, 'POST000000000000000000000A', 'not an id'))->toBe('bad_account')->and(count($repo->calls))->toBe(2);
    expect($s->retry(PT, PU, 'POST000000000000000000000A'))->toBe('ok')->and($repo->calls[2][0])->toBe('retry');
    $repo->throw = new RuntimeException('x');
    expect($s->retry(PT, PU, 'P'))->toBe('failed')->and($s->attach(PT, PU, 'P', 'acct000000000000000000000a'))->toBe('failed');
});

it('lets only Owner and Admin connect pages', function () {
    expect(in_array('social.connect', TenantRoles::MATRIX['Owner'], true))->toBeTrue()->and(in_array('social.connect', TenantRoles::MATRIX['Admin'], true))->toBeTrue();
    foreach (['Manager', 'Member', 'Auditor'] as $r) {
        expect(in_array('social.connect', TenantRoles::MATRIX[$r], true))->toBeFalse();
    }
});

// ---------- insights
function pstatsok(array $over = []): array
{
    return ['status' => 200, 'json' => $over + ['reactions' => ['summary' => ['total_count' => 12]], 'comments' => ['summary' => ['total_count' => 3]], 'shares' => ['count' => 2]]];
}

it('reads reactions, comments, shares and a reach number', function () {
    [$g, $h] = pgraph();
    $h->queue[] = pstatsok();
    $h->queue[] = ['status' => 200, 'json' => ['data' => [['values' => [['value' => 480]]]]]];
    expect($g->stats('123_456', TOKEN, 'design'))->toBe(['status' => 'ok', 'reactions' => 12, 'comments' => 3, 'shares' => 2, 'reach' => 480]);
    expect($h->calls[0]['url'])->toBe('https://graph.facebook.com/v24.0/123_456?fields='.rawurlencode('reactions.summary(true).limit(0),comments.summary(true).limit(0),shares').'&access_token='.rawurlencode(TOKEN))
        ->and($h->calls[1]['url'])->toContain('/123_456/insights?metric=post_media_view&');
});

it('falls back to the older reach metric and survives having no reach at all', function () {
    [$g, $h] = pgraph();
    $h->queue[] = pstatsok();
    $h->queue[] = ['status' => 400, 'json' => ['error' => ['code' => 100, 'message' => 'bad metric']]];
    $h->queue[] = ['status' => 200, 'json' => ['data' => [['values' => [['value' => '77']]]]]];
    expect($g->stats('123_456', TOKEN, 'design')['reach'])->toBe(77)->and($h->calls[2]['url'])->toContain('metric=post_impressions_unique');
    $h->queue[] = pstatsok();
    $h->queue[] = ['status' => 400, 'json' => []];
    $h->queue[] = ['status' => 400, 'json' => []];
    $r = $g->stats('123_456', TOKEN, 'none');
    expect($r['status'])->toBe('ok')->and($r['reach'])->toBeNull()->and($r['reactions'])->toBe(12);
});

it('asks videos for no shares and no reach, and treats missing counts as zero', function () {
    [$g, $h] = pgraph();
    $h->queue[] = pstatsok(['comments' => []]);
    $r = $g->stats('5555', TOKEN, 'video');
    expect($r)->toBe(['status' => 'ok', 'reactions' => 12, 'comments' => 0, 'shares' => null, 'reach' => null]);
    expect(count($h->calls))->toBe(1)->and(str_contains($h->calls[0]['url'], 'shares'))->toBeFalse();
    $h->queue[] = pstatsok(['shares' => []]);
    $h->queue[] = ['status' => 400, 'json' => []];
    $h->queue[] = ['status' => 400, 'json' => []];
    expect($g->stats('123_9', TOKEN, 'design')['shares'])->toBe(0);
});

it('reports a bad token or an unreadable answer from the stats call', function () {
    [$g, $h] = pgraph();
    $h->queue[] = ['status' => 400, 'json' => ['error' => ['code' => 190, 'message' => 'x']]];
    expect($g->stats('123_456', TOKEN, 'design')['status'])->toBe('token');
    $h->queue[] = ['status' => 404, 'json' => ['error' => ['code' => 100, 'message' => 'gone']]];
    expect($g->stats('123_456', TOKEN, 'design')['status'])->toBe('error');
    $h->queue[] = ['status' => 200, 'json' => []];
    expect($g->stats('123_456', TOKEN, 'design')['status'])->toBe('error');
    $h->queue[] = ['status' => 200, 'json' => ['reactions' => ['summary' => ['total_count' => -4]]]];
    expect($g->stats('123_456', TOKEN, 'design')['reactions'])->toBe(0);
    $n = count($h->calls);
    expect($g->stats('../etc', TOKEN, 'design')['status'])->toBe('error')->and(count($h->calls))->toBe($n);
});

function pins(string $status = 'active'): array
{
    [$p, $repo, $h] = ppub(null, $status);
    $due = ['id' => 'POST000000000000000000000A', 'tenant_id' => PT, 'account_id' => PA, 'remote_id' => '123_456', 'media_kind' => 'design'];
    $repo->due = [$due];

    return [new SocialInsights($repo, pvault(), new FacebookGraph($h, 'v24.0')), $repo, $h, $due];
}

it('stores a snapshot for each due post', function () {
    [$i, $repo, $h] = pins();
    $h->queue[] = pstatsok();
    $h->queue[] = ['status' => 200, 'json' => ['data' => [['values' => [['value' => 50]]]]]];
    expect($i->run(10))->toBe(['looked' => 1, 'saved' => 1]);
    expect($repo->calls[0])->toBe(['statsDue', [10]])->and(end($repo->calls))->toBe(['saveStats', [PT, 'POST000000000000000000000A', 12, 3, 2, 50]]);
});

it('stores an empty snapshot when the look fails, so it is not repeated at once', function () {
    [$i, $repo, $h] = pins();
    $h->queue[] = ['status' => 404, 'json' => ['error' => ['code' => 100, 'message' => 'gone']]];
    expect($i->run(10))->toBe(['looked' => 1, 'saved' => 0])->and(end($repo->calls))->toBe(['saveStats', [PT, 'POST000000000000000000000A', null, null, null, null]]);
});

it('marks the Page invalid on a bad token and does not call Facebook for a foreign or inactive connection', function () {
    [$i, $repo, $h] = pins();
    $h->queue[] = ['status' => 400, 'json' => ['error' => ['code' => 190, 'message' => 'x']]];
    $i->run(10);
    $mark = array_values(array_filter($repo->calls, fn ($c) => $c[0] === 'markAccount'));
    expect($mark)->toBe([['markAccount', [PA, 'invalid']]]);
    [$i2, $repo2, $h2] = pins('invalid');
    $i2->run(10);
    [$i3, $repo3, $h3] = pins();
    $repo3->cred['tenant_id'] = 'OTHERTENANT00000000000000B';
    $i3->run(10);
    [$i4, $repo4, $h4] = pins();
    $repo4->cred = null;
    $i4->run(10);
    foreach ([$h2, $h3, $h4] as $x) {
        expect($x->calls)->toBe([]);
    }
    foreach ([$repo2, $repo3, $repo4] as $r) {
        expect(end($r->calls)[1][2])->toBeNull();
    }
});

it('survives the database failing while looking up or saving', function () {
    [$i, $repo, $h] = pins();
    $repo->throwOnStats = true;
    $h->queue[] = pstatsok();
    expect($i->run(10))->toBe(['looked' => 1, 'saved' => 0]);
    expect((new SocialInsights(new class extends FakeSocialPublishRepo {
        public function statsDue(int $limit): array
        {
            throw new RuntimeException('down');
        }
    }, pvault(), new FacebookGraph(new FakeHttpJson)))->run(5))->toBe(['looked' => 0, 'saved' => 0]);
});
