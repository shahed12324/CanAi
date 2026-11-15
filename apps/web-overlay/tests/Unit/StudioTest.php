<?php

use App\Ai\ProviderCallException;
use App\Studio\ImagePricing;
use App\Studio\StudioService;
use App\Studio\StudioText;
use Tests\Support\FakeDesignRepo;
use Tests\Support\FakeImagePicker;
use Tests\Support\FakeLedger;
use Tests\Support\FakeStudioEngine;
use Tests\Support\ProviderDown;

const ST = 'TENANT0000000000000000000A';
const SU = 'USER000000000000000000000A';
const SU2 = 'USER000000000000000000000B';

function ssvc(?FakeStudioEngine $e = null, ?FakeLedger $l = null, ?FakeImagePicker $p = null, ?FakeDesignRepo $r = null, string $price = '5'): array
{
    $e ??= new FakeStudioEngine;
    $l ??= new FakeLedger('100.0000');
    $r ??= new FakeDesignRepo;

    return [new StudioService($e, $p ?? new FakeImagePicker, $l, new ImagePricing($price), $r), $e, $l, $r];
}

function sbrand(array $over = []): array
{
    return $over + ['primary' => '#7C2D12', 'accent' => '#fbbf24', 'name' => 'GoldVest', 'footer' => '০১৭০০ · goldvest.com.bd', 'logo_b64' => 'TE9HTw=='];
}

function sinput(array $over = []): array
{
    return $over + ['title' => 'ঈদ অফার', 'headline' => '30% off all cashews', 'subline' => 'Free delivery in Dhaka', 'cta' => 'Order now', 'theme' => 'dark', 'sizes' => ['post', 'story'], 'brief' => 'ঈদের অফার', 'prompt' => 'a bowl of cashew nuts'];
}

function spng(int $w = 20, int $h = 20): string
{
    ob_start();
    imagepng(imagecreatetruecolor($w, $h));

    return (string) ob_get_clean();
}

// ---------- price
it('charges a flat price per picture, scaled by the model multiplier, rounded up, never zero', function () {
    expect((new ImagePricing('5'))->image())->toBe('5.0000');
    expect((new ImagePricing('5'))->image(1, 2.0))->toBe('10.0000');
    expect((new ImagePricing('5'))->image(3))->toBe('15.0000');
    expect((new ImagePricing('5'))->image(0))->toBe('5.0000');
    expect((new ImagePricing('0'))->image())->toBe('0.0001');
    expect((new ImagePricing('0.0001'))->image(1, 0.5))->toBe('0.0001');
});

// ---------- text helpers
it('cleans a line of printable text', function () {
    expect(StudioService::line("  ঈদ \x00 অফার\n\n ৩০%  \u{202E}x ", 50))->toBe('ঈদ অফার ৩০% x');
    expect(mb_strlen(StudioService::line(str_repeat('ক', 500), 120)))->toBe(120);
    expect(StudioService::line(null, 10))->toBe('');
});

it('accepts only real pictures of a sane size as an uploaded background', function () {
    expect(StudioService::photo(spng())['status'])->toBe('ok');
    expect(StudioService::photo(spng())['base64'])->toBe(base64_encode(spng()));
    expect(StudioService::photo('')['status'])->toBe('bad_photo');
    expect(StudioService::photo('GIF89a'.str_repeat("\x00", 100))['status'])->toBe('bad_photo');
    expect(StudioService::photo("\x89PNG\r\n\x1a\n".str_repeat('x', 100))['status'])->toBe('bad_photo');      // right signature, not a picture
    expect(StudioService::photo(spng().str_repeat('x', StudioService::MAX_PHOTO_BYTES))['status'])->toBe('bad_photo');
    expect(StudioService::photo('<?php echo 1;')['status'])->toBe('bad_photo');
});

// ---------- the paid step
it('draws a background and settles exactly the reserved amount once', function () {
    [$s, $e, $l] = ssvc();
    $r = $s->background(ST, SU, 'a bowl of cashew nuts', 'post', 'rq1');
    expect($r['status'])->toBe('ok');
    expect($r['credits'])->toBe('5.0000');
    expect($r['image_b64'])->toBe(base64_encode('PICTURE'));
    expect($e->drawn[0]['size'])->toBe('1024x1024');
    expect($e->drawn[0]['modelKey'])->toBe('gpt-image-1');
    expect(count($l->settleKeys))->toBe(1);
    expect(count($l->releaseKeys))->toBe(0);
    expect($l->usages[0]['source'])->toBe('image');
    expect($l->openReservations())->toBe(0);
});

it('picks a generation size from the shape of the design', function () {
    foreach (['post' => '1024x1024', 'portrait' => '1024x1536', 'story' => '1024x1536', 'cover' => '1536x1024', 'ad' => '1536x1024'] as $k => $g) {
        [$s, $e] = ssvc();
        $s->background(ST, SU, 'prompt here', $k, 'r');
        expect($e->drawn[0]['size'])->toBe($g);
    }
});

it('asks nothing of the provider and charges nothing when the request itself is wrong', function () {
    [$s, $e, $l] = ssvc();
    expect($s->background(ST, SU, 'ab', 'post', 'r')['status'])->toBe('bad_prompt');
    expect($s->background(ST, SU, "  \x00 ", 'post', 'r')['status'])->toBe('bad_prompt');
    expect($s->background(ST, SU, 'fine prompt', 'huge', 'r')['status'])->toBe('bad_size');
    expect($e->drawn)->toBe([]);
    expect($l->reserveKeys)->toBe([]);
    [$s2, $e2, $l2] = ssvc(null, null, new FakeImagePicker(null));
    expect($s2->background(ST, SU, 'fine prompt', 'post', 'r')['status'])->toBe('no_model');
    expect($l2->reserveKeys)->toBe([]);
});

it('does not call the provider when the credits are not there', function () {
    [$s, $e, $l] = ssvc(null, new FakeLedger('2.0000'));
    $r = $s->background(ST, SU, 'fine prompt', 'post', 'r');
    expect($r['status'])->toBe('no_credits');
    expect($e->drawn)->toBe([]);
    expect($r['credits'])->toBe('0.0000');
});

it('releases the hold when the provider fails, answers nothing usable, or cannot draw', function () {
    foreach ([[ProviderDown::make(), 'provider'], [['status' => 'unsupported', 'reason' => 'adapter_no_images'], 'unsupported'], [['status' => 'ok', 'image_b64' => ''], 'failed'], [new RuntimeException('x'), 'failed']] as [$answer, $code]) {
        [$s, , $l] = ssvc(new FakeStudioEngine($answer));
        $r = $s->background(ST, SU, 'fine prompt', 'post', 'r');
        expect($r['status'])->toBe($code);
        expect($r['credits'])->toBe('0.0000');
        expect(count($l->releaseKeys))->toBe(1);
        expect(count($l->settleKeys))->toBe(0);
        expect($l->openReservations())->toBe(0);
    }
});

it('still reports success when settling fails after the picture was paid for (the hold is returned by the sweeper)', function () {
    $l = new FakeLedger('100.0000');
    $l->failSettle = true;
    [$s] = ssvc(null, $l);
    $r = $s->background(ST, SU, 'fine prompt', 'post', 'r');
    expect($r['status'])->toBe('ok');
    expect($r['credits'])->toBe('5.0000');
});

// ---------- making designs
it('makes a plain brand design for free and keeps the brand snapshot', function () {
    [$s, $e, $l, $r] = ssvc();
    $res = $s->create(ST, SU, sinput(), sbrand(), 'none', null, 'rq');
    expect($res['status'])->toBe('ok');
    expect($res['credits'])->toBe('0.0000');
    expect($l->reserveKeys)->toBe([]);
    $d = $r->rows[$res['id']];
    expect($d['title'])->toBe('ঈদ অফার');
    expect($d['sizes'])->toBe(['post', 'story']);
    expect($d['base_b64'])->toBeNull();
    expect($d['brand'])->toBe(['primary' => '#7c2d12', 'accent' => '#fbbf24', 'name' => 'GoldVest', 'footer' => '০১৭০০ · goldvest.com.bd']);
    expect($d['logo_b64'])->toBe('TE9HTw==');
});

it('makes a design on the AI picture and charges for it', function () {
    [$s, $e, $l, $r] = ssvc();
    $res = $s->create(ST, SU, sinput(), sbrand(), 'ai', null, 'rq');
    expect($res['status'])->toBe('ok');
    expect($res['credits'])->toBe('5.0000');
    expect($r->rows[$res['id']]['base_b64'])->toBe(base64_encode('PICTURE'));
    expect($e->drawn[0]['prompt'])->toBe('a bowl of cashew nuts');
    expect($e->drawn[0]['size'])->toBe('1024x1024');      // the first chosen size decides the shape
});

it('makes a design on an uploaded photo without any AI call', function () {
    [$s, $e, $l, $r] = ssvc();
    $png = spng();
    $res = $s->create(ST, SU, sinput(), sbrand(), 'upload', $png, 'rq');
    expect($res['status'])->toBe('ok');
    expect($e->drawn)->toBe([]);
    expect($l->reserveKeys)->toBe([]);
    expect($r->rows[$res['id']]['base_b64'])->toBe(base64_encode($png));
    expect($s->create(ST, SU, sinput(), sbrand(), 'upload', 'junk', 'rq')['status'])->toBe('bad_photo');
});

it('checks everything before it spends anything', function () {
    foreach ([[['title' => '  '], 'bad_title'], [['headline' => str_repeat('ক', 201)], 'bad_text'], [['subline' => str_repeat('ক', 401)], 'bad_text'], [['cta' => str_repeat('ক', 101)], 'bad_text'],
        [['sizes' => []], 'bad_sizes'], [['sizes' => ['huge']], 'bad_sizes'], [['theme' => 'neon'], 'bad_theme']] as [$patch, $code]) {
        [$s, $e, $l, $r] = ssvc();
        expect($s->create(ST, SU, sinput($patch), sbrand(), 'ai', null, 'rq')['status'])->toBe($code);
        expect($e->drawn)->toBe([]);
        expect($l->reserveKeys)->toBe([]);
        expect($r->rows)->toBe([]);
    }
    [$s, $e] = ssvc();
    expect($s->create(ST, SU, sinput(), sbrand(['primary' => 'red']), 'ai', null, 'rq')['status'])->toBe('bad_brand');
    expect($s->create(ST, SU, sinput(), sbrand(['accent' => '#12345g']), 'ai', null, 'rq')['status'])->toBe('bad_brand');
    expect($s->create(ST, SU, sinput(), sbrand(), 'magic', null, 'rq')['status'])->toBe('bad_input');
    expect($e->drawn)->toBe([]);
});

it('keeps sizes valid and unique, and accepts the exact text limits', function () {
    [$s, , , $r] = ssvc();
    $res = $s->create(ST, SU, sinput(['sizes' => ['story', 'post', 'story', 'nope', 7], 'headline' => str_repeat('ক', 200), 'subline' => str_repeat('ক', 400), 'cta' => str_repeat('ক', 100), 'title' => str_repeat('ক', 120)]), sbrand(), 'none', null, 'rq');
    expect($res['status'])->toBe('ok');
    expect($r->rows[$res['id']]['sizes'])->toBe(['story', 'post']);
});

it('refuses a 41st design before paying for a picture', function () {
    [$s, $e, $l, $r] = ssvc();
    for ($i = 0; $i < 40; $i++) {
        expect($s->create(ST, SU, sinput(['title' => "d$i"]), sbrand(), 'none', null, "r$i")['status'])->toBe('ok');
    }
    $res = $s->create(ST, SU, sinput(), sbrand(), 'ai', null, 'r41');
    expect($res['status'])->toBe('limit');
    expect($e->drawn)->toBe([]);
    expect($l->reserveKeys)->toBe([]);
    expect($s->create(ST, SU2, sinput(), sbrand(), 'none', null, 'r42')['status'])->toBe('ok');      // the limit is per person
});

it('keeps the picture charge when saving fails afterwards, and says so', function () {
    [$s, , $l, $r] = ssvc();
    $r->saveResult = 'bad_input';
    $res = $s->create(ST, SU, sinput(), sbrand(), 'ai', null, 'rq');
    expect($res['status'])->toBe('failed');
    expect($res['credits'])->toBe('5.0000');
    expect(count($l->settleKeys))->toBe(1);
});

it('does not save a design when the picture could not be made', function () {
    [$s, , $l, $r] = ssvc(new FakeStudioEngine(ProviderDown::make()));
    $res = $s->create(ST, SU, sinput(), sbrand(), 'ai', null, 'rq');
    expect($res['status'])->toBe('provider');
    expect($r->rows)->toBe([]);
    expect($l->openReservations())->toBe(0);
});

// ---------- drawing
function sdesign(FakeDesignRepo $r, StudioService $s, array $in = []): array
{
    $id = $s->create(ST, SU, sinput($in), sbrand(), 'upload', spng(), 'rq')['id'];

    return $r->find(SU, $id);
}

it('draws the requested sizes with the words, brand and picture of the design', function () {
    [$s, $e, , $r] = ssvc();
    $d = sdesign($r, $s);
    $out = $s->render(ST, SU, $d, ['post', 'story', 'cover']);       // cover is not one of this design's sizes
    expect($out['status'])->toBe('ok');
    expect(array_keys($out['items']))->toBe(['post', 'story']);
    expect($out['items']['post']['bytes'])->toBe('IMG-post');
    expect($out['items']['post']['mime'])->toBe('image/png');
    $p = $e->composed[0];
    expect($p['copy'])->toBe(['headline' => '30% off all cashews', 'subline' => 'Free delivery in Dhaka', 'cta' => 'Order now', 'theme' => 'dark']);
    expect($p['brand']['primary'])->toBe('#7c2d12');
    expect($p['brand']['logo_b64'])->toBe('TE9HTw==');
    expect($p['base_b64'])->toBe(base64_encode(spng()));
    expect($p['fmt'])->toBe('png');
});

it('can draw jpeg, and says what went wrong instead of throwing', function () {
    [$s, $e, , $r] = ssvc();
    $d = sdesign($r, $s);
    expect($s->render(ST, SU, $d, ['post'], 'jpeg')['items']['post']['mime'])->toBe('image/jpeg');
    expect($s->render(ST, SU, $d, ['cover'])['status'])->toBe('bad_size');
    expect($s->render(ST, SU, $d, [])['status'])->toBe('bad_size');
    foreach ([[ProviderDown::make(), 'engine'], [new RuntimeException('x'), 'engine'], [['status' => 'unsupported', 'reason' => 'bad_logo'], 'bad_brand'], [['status' => 'unsupported', 'reason' => 'bad_color'], 'bad_brand'], [['status' => 'unsupported', 'reason' => 'no_raqm'], 'no_shaping'],
        [['status' => 'unsupported', 'reason' => 'bad_base'], 'engine'], [['status' => 'ok', 'items' => [['size' => 'post', 'image_b64' => '!!!', 'mime' => 'image/png', 'width' => 1, 'height' => 1]]], 'engine'], [['status' => 'ok'], 'engine']] as [$ans, $code]) {
        [$s2, , , $r2] = ssvc(new FakeStudioEngine(null, $ans));
        $d2 = sdesign($r2, $s2);
        expect($s2->render(ST, SU, $d2, ['post'])['status'])->toBe($code);
    }
});

// ---------- client approval
it('freezes the shown picture into a link and stores only the hash of the token', function () {
    [$s, $e, , $r] = ssvc();
    $d = sdesign($r, $s);
    $res = $s->share(ST, SU, $d['id'], 'post', '  রহিম  স্টোর ', 7);
    expect($res['status'])->toBe('ok');
    expect(preg_match('/\A[0-9a-f]{64}\z/', $res['token']))->toBe(1);
    $rev = array_values($r->revs)[0];
    expect($rev['hash'])->toBe(hash('sha256', $res['token']));
    expect(str_contains(json_encode($r->revs), $res['token']))->toBeFalse();
    expect($rev['client_name'])->toBe('রহিম স্টোর');
    expect($rev['preview_b64'])->toBe(base64_encode('IMG-post'));
    expect(end($e->composed)['fmt'])->toBe('jpeg');
    expect($rev['expires_at'])->toBe('+7');
});

it('makes every link different and keeps the validity between 1 and 30 days', function () {
    [$s, , , $r] = ssvc();
    $d = sdesign($r, $s);
    $a = $s->share(ST, SU, $d['id'], 'post', '', 0)['token'];
    $b = $s->share(ST, SU, $d['id'], 'post', '', 99)['token'];
    expect($a === $b)->toBeFalse();
    expect(array_column($r->revs, 'expires_at'))->toBe(['+1', '+30']);
});

it('shares only my own design and only a size it has', function () {
    [$s, , , $r] = ssvc();
    $d = sdesign($r, $s);
    expect($s->share(ST, SU2, $d['id'], 'post', '', 7)['status'])->toBe('not_found');
    expect($s->share(ST, SU, 'nope', 'post', '', 7)['status'])->toBe('not_found');
    expect($s->share(ST, SU, $d['id'], 'cover', '', 7)['status'])->toBe('bad_size');
    expect($r->revs)->toBe([]);
});

it('does not make a link when the picture cannot be drawn, and reports a full link list', function () {
    [$s, , , $r] = ssvc(new FakeStudioEngine(null, ProviderDown::make()));
    $d = sdesign($r, $s);
    expect($s->share(ST, SU, $d['id'], 'post', '', 7)['status'])->toBe('engine');
    expect($r->revs)->toBe([]);
});

it('lets a client look and answer exactly once, with a token that must be well formed', function () {
    [$s, , , $r] = ssvc();
    $d = sdesign($r, $s);
    $t = $s->share(ST, SU, $d['id'], 'post', 'রহিম', 7)['token'];
    $v = $s->publicView($t);
    expect($v['title'])->toBe('ঈদ অফার');
    expect($v['status'])->toBe('pending');
    expect($s->publicView(str_repeat('a', 64)))->toBeNull();
    expect($s->publicView('short'))->toBeNull();
    expect($s->publicView(strtoupper($t)))->toBeNull();
    expect($s->decide('short', 'approved', ''))->toBe('not_found');
    expect($s->decide(str_repeat('a', 64), 'approved', ''))->toBe('not_found');
    expect($s->decide($t, 'changes', "  Logo\n বড় করুন \x00 "))->toBe('ok');
    expect(array_values($r->revs)[0]['comment'])->toBe('লোগো বড় করুন');
    expect($s->decide($t, 'approved', ''))->toBe('decided');
    expect($s->publicView($t)['status'])->toBe('changes');
});

it('cuts a client comment at 1000 characters', function () {
    [$s, , , $r] = ssvc();
    $d = sdesign($r, $s);
    $t = $s->share(ST, SU, $d['id'], 'post', '', 7)['token'];
    $s->decide($t, 'approved', str_repeat('ক', 1500));
    expect(mb_strlen(array_values($r->revs)[0]['comment']))->toBe(1000);
});

it('has a Bengali message for every outcome', function () {
    foreach (['no_model', 'no_credits', 'provider', 'unsupported', 'failed', 'bad_input', 'bad_title', 'bad_text', 'bad_sizes', 'bad_theme', 'bad_photo', 'bad_prompt', 'limit', 'not_found', 'engine', 'bad_brand', 'review_limit', 'bad_size', 'no_shaping'] as $c) {
        expect(StudioText::message($c) === 'Could not be done.')->toBeFalse();
    }
    expect(StudioText::message('zzz'))->toBe('Could not be done.');
    foreach (['ok', 'not_found', 'expired', 'decided', 'revoked', 'bad_input'] as $c) {
        expect(StudioText::answer($c) === 'Could not be done.')->toBeFalse();
    }
    expect(array_keys(StudioText::SIZES))->toBe(StudioService::SIZES);
    expect(array_keys(StudioText::THEMES))->toBe(StudioService::THEMES);
});

it('rounds a fractional price up, not down', function () {
    expect((new ImagePricing('0.0003'))->image(1, 0.5))->toBe('0.0002');
});

it('accepts JPEG and WEBP backgrounds too', function () {
    ob_start();
    imagejpeg(imagecreatetruecolor(8, 8));
    $jpg = (string) ob_get_clean();
    expect(StudioService::photo($jpg)['status'])->toBe('ok');
    if (function_exists('imagewebp')) {
        ob_start();
        imagewebp(imagecreatetruecolor(8, 8));
        $webp = (string) ob_get_clean();
        expect(StudioService::photo($webp)['status'])->toBe('ok');
    }
    expect(StudioService::photo('RIFF'."\x00\x00\x00\x00".'WAVE'.str_repeat("\x00", 50))['status'])->toBe('bad_photo');
});

it('reports a full database limit and a full link list as their own outcomes', function () {
    [$s, , , $r] = ssvc();
    $r->saveResult = 'limit';
    expect($s->create(ST, SU, sinput(), sbrand(), 'none', null, 'rq')['status'])->toBe('limit');
    [$s2, , , $r2] = ssvc();
    $d = sdesign($r2, $s2);
    $r2->reviewResult = 'limit';
    expect($s2->share(ST, SU, $d['id'], 'post', '', 7)['status'])->toBe('review_limit');
    $r2->reviewResult = 'bad_input';
    expect($s2->share(ST, SU, $d['id'], 'post', '', 7)['status'])->toBe('failed');
    $r2->reviewResult = 'not_found';
    expect($s2->share(ST, SU, $d['id'], 'post', '', 7)['status'])->toBe('not_found');
});
