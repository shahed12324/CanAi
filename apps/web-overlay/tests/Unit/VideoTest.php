<?php

use App\Ai\ProviderCallException;
use App\Video\LocalVideoFiles;
use App\Video\VideoPricing;
use App\Video\VideoService;
use App\Video\VideoText;
use Tests\Support\FakeImagePicker;
use Tests\Support\FakeLedger;
use Tests\Support\FakeNarrator;
use Tests\Support\FakeStudioEngine;
use Tests\Support\FakeVideoEngine;
use Tests\Support\FakeVideoFiles;
use Tests\Support\FakeVideoRepo;

const VT = 'TENANT0000000000000000000A';
const VU = 'USER000000000000000000000A';
const VU2 = 'USER000000000000000000000B';

function vsvc(?FakeVideoEngine $e = null, ?FakeLedger $l = null, ?FakeNarrator $n = null, ?FakeVideoRepo $r = null, ?FakeVideoFiles $f = null, string $price = '0.5', ?FakeStudioEngine $img = null, ?FakeImagePicker $picker = null, string $imgPrice = '5'): array
{
    $img ??= new FakeStudioEngine;
    $e ??= new FakeVideoEngine;
    $l ??= new FakeLedger('100.0000');
    $n ??= new FakeNarrator;
    $r ??= new FakeVideoRepo;
    $f ??= new FakeVideoFiles;

    return [new VideoService($e, $r, $f, $n, $l, new VideoPricing($price), $img, $picker ?? new FakeImagePicker, new App\Studio\ImagePricing($imgPrice)), $e, $l, $n, $r, $f, $img];
}

function vbrand(array $over = []): array
{
    return $over + ['primary' => '#7C2D12', 'accent' => '#fbbf24', 'name' => 'GoldVest', 'footer' => 'goldvest.com.bd', 'logo_b64' => 'TE9HTw=='];
}

function vscene(array $over = []): array
{
    return $over + ['headline' => '30% off all cashews', 'subline' => 'ফ্রি ডেলিভারি', 'cta' => 'Order now', 'theme' => 'dark', 'seconds' => 4, 'photo' => null];
}

function vinput(array $over = []): array
{
    return $over + ['title' => 'ঈদ রিল', 'size' => 'story', 'scenes' => [vscene(), vscene(['headline' => 'দ্বিতীয় দৃশ্য', 'seconds' => 3])], 'narration' => '', 'voice' => 'nova'];
}

function vpng(): string
{
    ob_start();
    imagepng(imagecreatetruecolor(20, 20));

    return (string) ob_get_clean();
}

function vmake(array $over = [], ?array $svc = null, string $rid = 'req'): array
{
    $svc ??= vsvc();
    $r = $svc[0]->create(VT, VU, vinput($over), vbrand(), $rid);

    return [$r, $svc];
}

// ---------- price
it('charges per second, rounded up, never zero', function () {
    expect((new VideoPricing('0.5'))->forSeconds(7.0))->toBe('3.5000');
    expect((new VideoPricing('0.5'))->forSeconds(1.0))->toBe('0.5000');
    expect((new VideoPricing('0.3333'))->forSeconds(1.0))->toBe('0.3333');
    expect((new VideoPricing('0.0001'))->forSeconds(0.1))->toBe('0.0001');
    expect((new VideoPricing('0'))->forSeconds(5.0))->toBe('0.0001');
    expect((new VideoPricing('0.3333'))->forSeconds(0.1))->toBe('0.0334');
    expect((new VideoPricing('0.5'))->forSeconds(0.04))->toBe('0.0500');
});

it('only accepts safe stored file names', function () {
    $ok = '0123456789abcdefghijklmnop';
    foreach (["$ok-0.img", "$ok-12.img", "$ok-voice.mp3", "$ok.mp4"] as $n) {
        expect(preg_match(LocalVideoFiles::NAME, $n))->toBe(1);
    }
    foreach (["$ok-123.img", "../$ok.mp4", "$ok.php", "$ok-voice.mp3\n", 'short.mp4', "$ok.mp4/x"] as $n) {
        expect(preg_match(LocalVideoFiles::NAME, $n))->toBe(0);
    }
});

// ---------- create: validation (nothing is reserved)
it('refuses bad input without touching credits', function (array $over, string $code) {
    [$r, $s] = vmake($over);
    expect($r['status'])->toBe($code)->and($r['id'])->toBeNull();
    expect($s[2]->reserveKeys)->toBe([])->and($s[4]->jobs)->toBe([]);
})->with([
    'no title' => [['title' => '  '], 'bad_title'],
    'bad size' => [['size' => 'cover'], 'bad_size'],
    'no scenes' => [['scenes' => []], 'bad_scenes'],
    '11 scenes' => [['scenes' => array_fill(0, 11, ['headline' => 'x', 'seconds' => 2])], 'bad_scenes'],
    'empty scene' => [['scenes' => [['headline' => '', 'subline' => '', 'cta' => '', 'seconds' => 3]]], 'bad_scenes'],
    'too short' => [['scenes' => [['headline' => 'x', 'seconds' => 1.9]]], 'bad_seconds'],
    'too long scene' => [['scenes' => [['headline' => 'x', 'seconds' => 8.1]]], 'bad_seconds'],
    'over a minute' => [['scenes' => array_fill(0, 9, ['headline' => 'x', 'seconds' => 7])], 'too_long'],
    'long headline' => [['scenes' => [['headline' => str_repeat('ক', 201), 'seconds' => 3]]], 'bad_text'],
    'long subline' => [['scenes' => [['subline' => str_repeat('ক', 401), 'seconds' => 3]]], 'bad_text'],
    'long cta' => [['scenes' => [['cta' => str_repeat('ক', 101), 'seconds' => 3]]], 'bad_text'],
    'bad theme' => [['scenes' => [['headline' => 'x', 'theme' => 'neon', 'seconds' => 3]]], 'bad_theme'],
    'scene not a list' => [['scenes' => ['oops']], 'bad_scenes'],
    'bad photo' => [['scenes' => [['headline' => 'x', 'seconds' => 3, 'photo' => 'not an image']]], 'bad_photo'],
    'long narration' => [['narration' => str_repeat('ক', 2001)], 'bad_narration'],
    'bad voice' => [['narration' => 'hello', 'voice' => 'robot'], 'bad_voice'],
]);

it('accepts exactly 10 scenes and exactly 60 seconds, and 2 and 8 second scenes', function () {
    [$r] = vmake(['scenes' => array_fill(0, 10, ['headline' => 'x', 'seconds' => 6])]);
    expect($r['status'])->toBe('ok');
    [$r] = vmake(['scenes' => [['headline' => 'a', 'seconds' => 2], ['headline' => 'b', 'seconds' => 8]]], null, 'r2');
    expect($r['status'])->toBe('ok');
});

it('refuses a bad brand colour', function () {
    $s = vsvc();
    expect($s[0]->create(VT, VU, vinput(), vbrand(['primary' => 'red']), 'r')['status'])->toBe('bad_brand');
    expect($s[0]->create(VT, VU, vinput(), vbrand(['accent' => '#12345']), 'r')['status'])->toBe('bad_brand');
    expect($s[2]->reserveKeys)->toBe([]);
});

// ---------- create: money
it('holds the full render price up front and queues the job', function () {
    [$r, $s] = vmake();
    expect($r['status'])->toBe('ok')->and($r['credits'])->toBe('0.0000');
    expect($s[2]->reserveKeys)->toBe(['video:req'])->and($s[2]->log)->toBe(['reserve:3.5000']);
    $job = $s[4]->jobs[$r['id']];
    expect($job['status'])->toBe('queued')->and($job['amount'])->toBe('3.5000')->and($job['seconds'])->toBe('7.0')->and($job['user_id'])->toBe(VU);
    expect($job['brand']['primary'])->toBe('#7c2d12')->and($job['audio_path'])->toBeNull();
    expect($s[2]->settleKeys)->toBe([])->and($s[2]->releaseKeys)->toBe([]);
});

it('refuses when credits are short and leaves nothing behind', function () {
    [$r, $s] = vmake([], vsvc(null, new FakeLedger('1.0000')));
    expect($r['status'])->toBe('no_credits')->and($s[4]->jobs)->toBe([])->and($s[5]->files)->toBe([]);
    expect($s[2]->openReservations())->toBe(0);
});

it('allows at most 3 active videos per person (others are not counted)', function () {
    $svc = vsvc();
    foreach (['a', 'b', 'c'] as $k) {
        expect(vmake([], $svc, $k)[0]['status'])->toBe('ok');
    }
    $r = $svc[0]->create(VT, VU, vinput(), vbrand(), 'd');
    expect($r['status'])->toBe('limit')->and($svc[2]->reserveKeys)->toBe(['video:a', 'video:b', 'video:c']);
    expect($svc[0]->create(VT, VU2, vinput(), vbrand(), 'e')['status'])->toBe('ok');
});

it('releases the hold and stores nothing when the repository refuses', function (string $result, string $code) {
    $repo = new FakeVideoRepo;
    $repo->createResult = $result;
    [$r, $s] = vmake(['scenes' => [vscene(['photo' => vpng()])], 'narration' => 'হ্যালো'], vsvc(null, null, null, $repo));
    expect($r['status'])->toBe($code)->and($s[5]->files)->toBe([]);
    expect($s[2]->releaseKeys)->toHaveCount(1)->and($s[2]->settleKeys)->toBe([])->and($s[2]->openReservations())->toBe(0);
    expect($r['credits'])->toBe('0.0300');
})->with([['limit', 'limit'], ['bad_input', 'failed']]);

it('releases the hold and cleans up when storing a file fails', function () {
    $f = new FakeVideoFiles;
    $f->failPut = true;
    [$r, $s] = vmake(['scenes' => [vscene(['photo' => vpng()])]], vsvc(null, null, null, null, $f));
    expect($r['status'])->toBe('failed')->and($s[4]->jobs)->toBe([]);
    expect($s[2]->releaseKeys)->toHaveCount(1)->and($s[2]->openReservations())->toBe(0);
});

it('rounds scene length to a tenth of a second', function () {
    [$r, $s] = vmake(['scenes' => [['headline' => 'x', 'seconds' => 2.34]]]);
    expect($s[4]->jobs[$r['id']]['scenes'][0]['seconds'])->toBe(2.3)->and($s[4]->jobs[$r['id']]['seconds'])->toBe('2.3');
});

// ---------- create: voice-over
it('adds a voice-over, stores it and reports the speech charge', function () {
    [$r, $s] = vmake(['narration' => ' স্বাগতম ', 'voice' => 'onyx']);
    expect($r['status'])->toBe('ok')->and($r['credits'])->toBe('0.0300');
    expect($s[3]->calls)->toHaveCount(1)->and($s[3]->calls[0]['voice'])->toBe('onyx')->and($s[3]->calls[0]['text'])->toBe('স্বাগতম')->and($s[3]->calls[0]['requestId'])->toBe('req-v');
    $job = $s[4]->jobs[$r['id']];
    expect($job['audio_path'])->toBe($r['id'].'-voice.mp3')->and($job['voice'])->toBe('onyx')->and($job['narration'])->toBe('স্বাগতম');
    expect($s[5]->files[VT.'/'.$r['id'].'-voice.mp3'])->toBe('ID3-VOICE');
});

it('does not call the narrator without narration', function () {
    [, $s] = vmake();
    expect($s[3]->calls)->toBe([]);
});

it('releases the render hold when the voice cannot be made', function (string $status) {
    [$r, $s] = vmake(['narration' => 'x'], vsvc(null, null, new FakeNarrator(['status' => $status, 'audio_b64' => null, 'credits' => '0.0000'])));
    expect($r['status'])->toBe($status)->and($s[4]->jobs)->toBe([])->and($s[5]->files)->toBe([]);
    expect($s[2]->releaseKeys)->toHaveCount(1)->and($s[2]->openReservations())->toBe(0);
})->with(['no_model', 'no_credits', 'provider']);

it('ignores audio that comes with a failed voice status', function () {
    [$r, $s] = vmake(['narration' => 'x'], vsvc(null, null, new FakeNarrator(['status' => 'provider', 'audio_b64' => base64_encode('ID3-VOICE'), 'credits' => '0.0000'])));
    expect($r['status'])->toBe('provider')->and($s[4]->jobs)->toBe([])->and($s[2]->openReservations())->toBe(0);
});

it('rejects a voice that is not an mp3', function () {
    [$r, $s] = vmake(['narration' => 'x'], vsvc(null, null, new FakeNarrator(['status' => 'ok', 'audio_b64' => base64_encode('RIFFwave'), 'credits' => '0.0300'])));
    expect($r['status'])->toBe('failed')->and($r['credits'])->toBe('0.0300');
    expect($s[2]->releaseKeys)->toHaveCount(1)->and($s[2]->openReservations())->toBe(0);
    // a raw mpeg frame is also fine
    [$ok] = vmake(['narration' => 'x'], vsvc(null, null, new FakeNarrator(['status' => 'ok', 'audio_b64' => base64_encode("\xFF\xFBdata"), 'credits' => '0.0300'])));
    expect($ok['status'])->toBe('ok');
});

// ---------- worker
function vclaimed(array $over = [], ?array $svc = null): array
{
    [$r, $svc] = vmake($over, $svc);
    $svc[4]->claim(1);

    return [$r['id'], $svc];
}

it('renders a claimed job, settles exactly once, stores the video and removes the sources', function () {
    [$id, $s] = vclaimed(['scenes' => [vscene(['photo' => vpng()]), vscene()], 'narration' => 'x']);
    expect(count($s[5]->files))->toBe(2);
    $s[0]->work(VT, $id);
    $job = $s[4]->jobs[$id];
    expect($job['status'])->toBe('done')->and($job['file_path'])->toBe($id.'.mp4')->and($job['charged'])->toBe('4.0000')->and($job['bytes'])->toBe(strlen("\0\0\0\x18ftypmp42MOVIE"));
    expect($s[2]->settleKeys)->toBe(['video-settle:'.$id])->and($s[2]->releaseKeys)->toBe([])->and($s[2]->openReservations())->toBe(0);
    expect($s[2]->usages[0]['source'])->toBe('video')->and($s[2]->usages[0]['user_id'])->toBe(VU);
    expect(array_keys($s[5]->files))->toBe([VT.'/'.$id.'.mp4']);
    $p = $s[1]->calls[0];
    expect($p['size'])->toBe('story')->and($p['fade'])->toBeTrue()->and($p['motion'])->toBeTrue()->and($p['scenes'])->toHaveCount(2)->and($p['scenes'][0]['base_b64'])->toBe(base64_encode(vpng()))->and(array_key_exists('base_b64', $p['scenes'][1]))->toBeFalse();
    expect($p['scenes'][0]['copy']['headline'])->toBe('30% off all cashews')->and($p['scenes'][0]['seconds'])->toBe(4.0)->and($p['audio_b64'])->toBe(base64_encode('ID3-VOICE'));
    expect($p['brand']['primary'])->toBe('#7c2d12')->and($p['brand']['logo_b64'])->toBe('TE9HTw==');
});

it('sends no audio for a silent video', function () {
    [$id, $s] = vclaimed();
    $s[0]->work(VT, $id);
    expect(array_key_exists('audio_b64', $s[1]->calls[0]))->toBeFalse();
});

it('does nothing for a job that is not being rendered', function () {
    [$r, $s] = vmake();
    $s[0]->work(VT, $r['id']);       // still queued
    $s[0]->work(VT, 'nosuchjob0000000000000000');
    expect($s[1]->calls)->toBe([])->and($s[2]->settleKeys)->toBe([])->and($s[2]->releaseKeys)->toBe([])->and($s[4]->jobs[$r['id']]['status'])->toBe('queued');
});

it('releases and fails the job in every failure path, once', function (mixed $answer, string $why) {
    [$id, $s] = vclaimed(['scenes' => [vscene(['photo' => vpng()])], 'narration' => 'x'], vsvc(new FakeVideoEngine($answer)));
    $s[0]->work(VT, $id);
    $job = $s[4]->jobs[$id];
    expect($job['status'])->toBe('failed')->and($job['error'])->toBe(VideoText::message($why));
    expect($s[2]->releaseKeys)->toHaveCount(1)->and($s[2]->settleKeys)->toBe([])->and($s[2]->openReservations())->toBe(0);
    expect($s[5]->files)->toBe([]);
})->with([
    'engine down' => [new RuntimeException('conn'), 'job_engine'],
    'engine busy' => [new ProviderCallException('engine_busy', 'busy', 503), 'job_busy'],
    'engine refuses' => [['status' => 'unsupported', 'bytes' => null, 'reason' => 'bad_encoding'], 'job_refused'],
    'empty answer' => [['status' => 'ok', 'bytes' => null, 'reason' => null], 'job_refused'],
]);

it('fails and releases when the finished video cannot be stored', function () {
    [$id, $s] = vclaimed();
    $s[5]->failPut = true;
    $s[0]->work(VT, $id);
    expect($s[4]->jobs[$id]['status'])->toBe('failed')->and($s[4]->jobs[$id]['error'])->toBe(VideoText::message('job_store'));
    expect($s[2]->releaseKeys)->toHaveCount(1)->and($s[2]->settleKeys)->toBe([])->and($s[2]->openReservations())->toBe(0);
});

it('fails and releases when a source file has gone missing', function () {
    [$id, $s] = vclaimed(['scenes' => [vscene(['photo' => vpng()])]]);
    $s[5]->files = [];
    $s[0]->work(VT, $id);
    expect($s[4]->jobs[$id]['error'])->toBe(VideoText::message('job_gone'))->and($s[1]->calls)->toBe([]);
    expect($s[2]->releaseKeys)->toHaveCount(1)->and($s[2]->openReservations())->toBe(0);
});

it('still finishes the job when settling fails (the hold is returned by the stale sweep)', function () {
    [$id, $s] = vclaimed();
    $s[2]->failSettle = true;
    $s[0]->work(VT, $id);
    expect($s[4]->jobs[$id]['status'])->toBe('done')->and($s[4]->jobs[$id]['charged'])->toBe('3.5000')->and($s[2]->releaseKeys)->toBe([]);
});

it('removes the stored video if recording the result fails', function () {
    [$id, $s] = vclaimed();
    $s[4]->failDone = true;
    $s[0]->work(VT, $id);
    expect($s[5]->files)->toBe([]);
});

// ---------- cancel / delete
it('cancels a waiting job: hold released once, sources removed; refuses once rendering or for another person', function () {
    [$r, $s] = vmake(['scenes' => [vscene(['photo' => vpng()])], 'narration' => 'x']);
    expect($s[0]->cancel(VT, VU2, $r['id']))->toBe('not_found');
    expect($s[0]->cancel(VT, VU, $r['id']))->toBe('ok');
    expect($s[4]->jobs[$r['id']]['status'])->toBe('cancelled')->and($s[2]->releaseKeys)->toHaveCount(1)->and($s[2]->openReservations())->toBe(0)->and($s[5]->files)->toBe([]);
    expect($s[0]->cancel(VT, VU, $r['id']))->toBe('not_found')->and($s[2]->releaseKeys)->toHaveCount(1);
    [$id, $s2] = vclaimed();
    expect($s2[0]->cancel(VT, VU, $id))->toBe('not_found')->and($s2[2]->releaseKeys)->toBe([]);
});

it('deletes a finished video and its file, but not a running job or someone elses', function () {
    [$id, $s] = vclaimed();
    expect($s[0]->delete(VT, VU, $id))->toBe('not_found');
    $s[0]->work(VT, $id);
    expect($s[0]->delete(VT, VU2, $id))->toBe('not_found')->and(count($s[5]->files))->toBe(1);
    expect($s[0]->delete(VT, VU, $id))->toBe('ok')->and($s[4]->jobs)->toBe([])->and($s[5]->files)->toBe([]);
});

it('has a message for every outcome and falls back safely', function () {
    expect(VideoText::message('limit'))->toContain('৩')->and(VideoText::message('nonsense'))->toBe(VideoText::MESSAGES['failed']);
    foreach (['bad_title', 'bad_size', 'bad_scenes', 'bad_seconds', 'too_long', 'bad_text', 'bad_theme', 'bad_photo', 'bad_brand', 'bad_voice', 'bad_narration', 'limit', 'no_credits', 'no_model', 'provider', 'unsupported', 'failed', 'not_found'] as $c) {
        expect(VideoText::MESSAGES)->toHaveKey($c);
    }
});

// ---------- subtitles
use App\Video\VideoCaptions;
use App\Video\VideoScript;
use App\Video\VideoScriptPrompt;
use App\Video\VideoScriptService;
use Tests\Support\FakeAssistEngine;
use Tests\Support\FakePicker;

it('cuts a script into subtitle lines at sentence ends and merges short ones', function () {
    expect(VideoCaptions::chunks(''))->toBe([])->and(VideoCaptions::chunks("  \n "))->toBe([]);
    expect(VideoCaptions::chunks('স্বাগতম।'))->toBe(['স্বাগতম।']);
    $long = 'প্রথম বাক্যটি একটু লম্বা করে লিখলাম যাতে আলাদা থাকে। দ্বিতীয় বাক্যটিও একটু লম্বা করে লিখলাম যাতে আলাদা থাকে।';
    $c = VideoCaptions::chunks($long);
    expect($c)->toHaveCount(2)->and($c[0])->toEndWith('stays.');
    expect(VideoCaptions::chunks('এক। দুই। তিন।'))->toBe(['এক। দুই। তিন।']);
});

it('keeps every subtitle line within 120 characters and at most 40 lines', function () {
    $words = implode(' ', array_fill(0, 400, 'Words'));
    $c = VideoCaptions::chunks($words);
    expect(count($c) <= 40)->toBeTrue()->and(max(array_map('mb_strlen', $c)) <= 120)->toBeTrue();
    expect(implode(' ', $c))->toBe($words);
    $many = implode(' ', array_fill(0, 1000, 'ক।'));
    expect(count(VideoCaptions::chunks($many)) <= 40)->toBeTrue();
    $huge = str_repeat('ক', 300);
    $h = VideoCaptions::chunks($huge);
    expect(max(array_map('mb_strlen', $h)) <= 120)->toBeTrue()->and(implode('', $h))->toBe($huge);
    expect(count(VideoCaptions::chunks(str_repeat('ক। ', 2000))) <= 40)->toBeTrue();
});

it('widens subtitle lines instead of dropping text when there are too many', function () {
    $words = implode(' ', array_fill(0, 700, 'Words'));
    $c = VideoCaptions::chunks($words);
    expect(count($c) <= 40)->toBeTrue()->and(implode(' ', $c))->toBe($words);
});

it('needs a voice-over script to make subtitles', function () {
    [$r, $s] = vmake(['captions' => true, 'narration' => '']);
    expect($r['status'])->toBe('bad_captions')->and($s[2]->reserveKeys)->toBe([]);
    [$ok, $s2] = vmake(['captions' => true, 'narration' => 'স্বাগতম। আমাদের অফার দেখুন।']);
    expect($ok['status'])->toBe('ok')->and($s2[4]->jobs[$ok['id']]['brand']['captions'])->toBeTrue();
});

it('sends subtitle lines to the engine only when asked', function () {
    [$id, $s] = vclaimed(['captions' => true, 'narration' => 'স্বাগতম। আমাদের অফার দেখুন।']);
    $s[0]->work(VT, $id);
    expect($s[1]->calls[0]['captions'])->toBe(['স্বাগতম। আমাদের অফার দেখুন।']);
    [$id2, $s2] = vclaimed(['narration' => 'স্বাগতম।']);
    $s2[0]->work(VT, $id2);
    expect(array_key_exists('captions', $s2[1]->calls[0]))->toBeFalse();
});

// ---------- AI script
function vscriptjson(array $over = []): string
{
    return json_encode($over + ['title' => 'ঈদ অফার', 'scenes' => [
        ['headline' => 'ঈদ অফার', 'subline' => '৩০% ছাড়', 'cta' => '', 'seconds' => 4],
        ['headline' => 'ফ্রি ডেলিভারি', 'subline' => 'ঢাকায়', 'cta' => 'ইনবক্স করুন', 'seconds' => 5],
    ], 'narration' => 'ঈদ উপলক্ষে বিশেষ অফার।'], JSON_UNESCAPED_UNICODE);
}

function vscriptsvc(mixed $answer = null, ?FakeLedger $l = null, ?array $model = null): array
{
    $e = new FakeAssistEngine($answer ?? ['text' => vscriptjson(), 'input_tokens' => 400, 'output_tokens' => 300, 'model' => 'g']);
    $l ??= new FakeLedger('100.0000');
    $picker = $model === [] ? new FakePicker(null) : new FakePicker;

    return [new VideoScriptService($picker, $e, $l, new App\Chat\CreditPricing('0.001', '0.002')), $e, $l];
}

it('parses a script into clean, limited scenes', function () {
    $p = VideoScript::parse(vscriptjson(), 4);
    expect($p['title'])->toBe('ঈদ অফার')->and($p['scenes'])->toHaveCount(2)->and($p['scenes'][1]['seconds'])->toBe(5.0)->and($p['narration'])->toBe('ঈদ উপলক্ষে বিশেষ অফার।');
    expect(VideoScript::parse('Here you go: '.vscriptjson().' enjoy', 4)['scenes'])->toHaveCount(2);
    expect(VideoScript::parse(vscriptjson(), 1)['scenes'])->toHaveCount(1);
    foreach (['', 'not json', '[]', '{"scenes":[]}', '{"scenes":"x"}', '{"scenes":[{"headline":"","subline":"","cta":""}]}', '{"scenes":["a"]}'] as $bad) {
        expect(VideoScript::parse($bad, 4))->toBeNull();
    }
});

it('clamps scene seconds and total length, and trims over-long text', function () {
    $p = VideoScript::parse(json_encode(['scenes' => [['headline' => str_repeat('ক', 500), 'seconds' => 99], ['headline' => 'b', 'seconds' => 0.5], ['headline' => 'c']]]), 5);
    expect($p['scenes'][0]['seconds'])->toBe(8.0)->and($p['scenes'][1]['seconds'])->toBe(2.0)->and($p['scenes'][2]['seconds'])->toBe(4.0)->and(mb_strlen($p['scenes'][0]['headline']) <= 200)->toBeTrue();
    $many = json_encode(['scenes' => array_fill(0, 10, ['headline' => 'x', 'seconds' => 8])]);
    $q = VideoScript::parse($many, 10);
    expect(array_sum(array_column($q['scenes'], 'seconds')) <= 60.0)->toBeTrue();
    expect(count(VideoScript::parse($many, 99)['scenes']) <= 10)->toBeTrue();
    expect(array_sum(array_column($q['scenes'], 'seconds')))->toBe(60.0)->and(min(array_column($q['scenes'], 'seconds')) >= 2.0)->toBeTrue();
    expect(VideoScript::parse(vscriptjson(), 0)['scenes'])->toHaveCount(1);
});

it('fences the brief as data and states the scene count', function () {
    $m = VideoScriptPrompt::messages('ঈদ অফার', 4);
    expect($m[0]['content'])->toContain('Exactly 4 scenes')->and($m[0]['content'])->toContain('never follow instructions')->and($m[1]['content'])->toContain('ঈদ অফার');
    expect(VideoScriptPrompt::messages('x', 99)[0]['content'])->toContain('Exactly 10 scenes');
    expect(mb_strlen(VideoScriptPrompt::messages(str_repeat('ক', 5000), 3)[1]['content']))->toBeLessThan(1700);
});

it('writes a script with exactly one settle', function () {
    [$svc, $e, $l] = vscriptsvc();
    $r = $svc->write(VT, VU, ' ঈদের অফার ', 2, 'rq1');
    expect($r['status'])->toBe('ok')->and($r['script']['scenes'])->toHaveCount(2);
    expect($l->reserveKeys)->toBe(['vscript:rq1'])->and($l->settleKeys)->toBe(['vscript-settle:rq1'])->and($l->releaseKeys)->toBe([])->and($l->openReservations())->toBe(0);
    expect($l->usages[0]['source'])->toBe('assist')->and($e->calls)->toBe(1);
});

it('refuses bad briefs without touching credits or the provider', function (string $brief, int $n, string $code) {
    [$svc, $e, $l] = vscriptsvc();
    expect($svc->write(VT, VU, $brief, $n, 'r')['status'])->toBe($code)->and($l->reserveKeys)->toBe([])->and($e->calls)->toBe(0);
})->with([['  ', 3, 'empty'], [str_repeat('ক', 1501), 3, 'brief_long'], ['x', 0, 'bad_count'], ['x', 11, 'bad_count']]);

it('releases once when the provider fails, settles once when the answer is unreadable', function () {
    [$svc, , $l] = vscriptsvc(new ProviderCallException('upstream_error', 'x', 500));
    expect($svc->write(VT, VU, 'x', 3, 'a')['status'])->toBe('provider')->and($l->releaseKeys)->toBe(['vscript-release:a'])->and($l->settleKeys)->toBe([])->and($l->openReservations())->toBe(0);
    [$svc2, , $l2] = vscriptsvc(new RuntimeException('boom'));
    expect($svc2->write(VT, VU, 'x', 3, 'b')['status'])->toBe('failed')->and($l2->releaseKeys)->toHaveCount(1)->and($l2->openReservations())->toBe(0);
    [$svc3, , $l3] = vscriptsvc(['text' => 'sorry', 'input_tokens' => 10, 'output_tokens' => 5, 'model' => 'g']);
    expect($svc3->write(VT, VU, 'x', 3, 'c')['status'])->toBe('unreadable')->and($l3->settleKeys)->toHaveCount(1)->and($l3->releaseKeys)->toBe([])->and($l3->openReservations())->toBe(0);
});

it('reports no model and no credits', function () {
    [$svc, , $l] = vscriptsvc(null, null, []);
    expect($svc->write(VT, VU, 'x', 3, 'a')['status'])->toBe('no_model')->and($l->reserveKeys)->toBe([]);
    [$svc2] = vscriptsvc(null, new FakeLedger('0.0000'));
    expect($svc2->write(VT, VU, 'x', 3, 'b')['status'])->toBe('no_credits');
});

it('survives a failed settle', function () {
    $l = new FakeLedger('100.0000');
    $l->failSettle = true;
    [$svc] = vscriptsvc(null, $l);
    expect($svc->write(VT, VU, 'x', 2, 'a')['status'])->toBe('ok');
});

it('has Bengali messages for every script outcome', function () {
    foreach (['empty', 'brief_long', 'bad_count', 'no_model', 'no_credits', 'provider', 'unreadable', 'failed'] as $c) {
        expect(VideoText::SCRIPT_MESSAGES)->toHaveKey($c);
    }
    expect(VideoText::script('zzz'))->toBe(VideoText::SCRIPT_MESSAGES['failed'])->and(VideoText::MESSAGES)->toHaveKey('bad_captions');
});

// ---------- clip editor
function vclipmake(array $over = [], ?array $svc = null, string $bytes = 'FAKEVIDEO', string $rid = 'clip'): array
{
    $svc ??= vsvc();
    $r = $svc[0]->createClip(VT, VU, $over + ['title' => 'আমার ক্লিপ', 'size' => 'story', 'trim_start' => 2, 'trim_end' => 12, 'remove_silence' => true], $bytes, $rid);

    return [$r, $svc];
}

it('queues a clip: probes it, holds the price of the trimmed window, stores the source', function () {
    [$r, $s] = vclipmake();
    expect($r['status'])->toBe('ok');
    expect($s[1]->probes)->toBe([base64_encode('FAKEVIDEO')]);
    expect($s[2]->log)->toBe(['reserve:5.0000'])->and($s[2]->reserveKeys)->toBe(['video:clip']);
    $job = $s[4]->jobs[$r['id']];
    expect($job['status'])->toBe('queued')->and($job['seconds'])->toBe('10.0')->and($job['size_key'])->toBe('story')->and($job['scenes'][0]['clip'])->toBeTrue()->and($job['scenes'][0]['remove_silence'])->toBeTrue();
    expect(array_keys($s[5]->files))->toBe([VT.'/'.$r['id'].'-clip.vid']);
});

it('prices to the end of the clip when no end is given, and floors to a tenth', function () {
    [$r, $s] = vclipmake(['trim_end' => null, 'trim_start' => 0], vsvc(new FakeVideoEngine(null, null, ['status' => 'ok', 'duration' => 12.37, 'has_audio' => false, 'reason' => null])));
    expect($s[4]->jobs[$r['id']]['seconds'])->toBe('12.3');
    expect($s[4]->jobs[$r['id']]['scenes'][0]['remove_silence'])->toBeFalse();   // no audio, so nothing to cut
});

it('refuses bad clip requests before any money moves', function (array $over, string $bytes, string $code) {
    [$r, $s] = vclipmake($over, null, $bytes);
    expect($r['status'])->toBe($code)->and($s[2]->reserveKeys)->toBe([])->and($s[4]->jobs)->toBe([])->and($s[5]->files)->toBe([]);
})->with([
    'no title' => [['title' => ' '], 'x', 'bad_title'],
    'bad size' => [['size' => 'cover'], 'x', 'bad_size'],
    'empty file' => [[], '', 'bad_video'],
    'huge file' => [[], str_repeat('x', 25_000_001), 'bad_video'],
    'end before start' => [['trim_start' => 5, 'trim_end' => 5], 'x', 'bad_trim'],
    'under 2 seconds' => [['trim_start' => 1, 'trim_end' => 2.5], 'x', 'bad_trim'],
    'start past the end' => [['trim_start' => 25, 'trim_end' => null], 'x', 'bad_trim'],
]);

it('refuses a trimmed window over a minute, but a long file trimmed under a minute is fine', function () {
    $long = new FakeVideoEngine(null, null, ['status' => 'ok', 'duration' => 100.0, 'has_audio' => true, 'reason' => null]);
    [$r, $s] = vclipmake(['trim_start' => 0, 'trim_end' => null], vsvc($long));
    expect($r['status'])->toBe('too_long')->and($s[2]->reserveKeys)->toBe([]);
    [$ok] = vclipmake(['trim_start' => 10, 'trim_end' => 70], vsvc($long));
    expect($ok['status'])->toBe('ok');
    [$edge] = vclipmake(['trim_start' => 10, 'trim_end' => 70.1], vsvc($long), 'x', 'e2');
    expect($edge['status'])->toBe('too_long');
});

it('maps probe refusals and engine trouble to clear messages', function (mixed $probe, string $code) {
    [$r, $s] = vclipmake([], vsvc(new FakeVideoEngine(null, null, $probe)));
    expect($r['status'])->toBe($code)->and($s[2]->reserveKeys)->toBe([]);
})->with([
    'not a video' => [['status' => 'unsupported', 'duration' => 0.0, 'has_audio' => false, 'reason' => 'bad_video'], 'bad_video'],
    'bad encoding' => [['status' => 'unsupported', 'duration' => 0.0, 'has_audio' => false, 'reason' => 'bad_encoding'], 'bad_video'],
    'longer than 2 min' => [['status' => 'unsupported', 'duration' => 0.0, 'has_audio' => false, 'reason' => 'too_long'], 'clip_long'],
    'no ffmpeg' => [['status' => 'unsupported', 'duration' => 0.0, 'has_audio' => false, 'reason' => 'no_ffmpeg'], 'engine'],
    'other' => [['status' => 'unsupported', 'duration' => 0.0, 'has_audio' => false, 'reason' => 'zzz'], 'failed'],
    'engine down' => [new RuntimeException('conn'), 'engine'],
    'engine error' => [new ProviderCallException('engine_busy', 'busy', 503), 'engine'],
]);

it('limits clips to 3 active jobs and reports short credits', function () {
    $svc = vsvc();
    foreach (['a', 'b', 'c'] as $k) {
        expect(vclipmake([], $svc, 'x', $k)[0]['status'])->toBe('ok');
    }
    expect(vclipmake([], $svc, 'x', 'd')[0]['status'])->toBe('limit')->and($svc[1]->probes)->toHaveCount(3);
    expect(vclipmake([], vsvc(null, new FakeLedger('1.0000')))[0]['status'])->toBe('no_credits');
});

it('undoes a clip when storing or recording it fails', function () {
    $f = new FakeVideoFiles;
    $f->failPut = true;
    [$r, $s] = vclipmake([], vsvc(null, null, null, null, $f));
    expect($r['status'])->toBe('failed')->and($s[2]->releaseKeys)->toHaveCount(1)->and($s[2]->openReservations())->toBe(0);
    $repo = new FakeVideoRepo;
    $repo->createResult = 'limit';
    [$r2, $s2] = vclipmake([], vsvc(null, null, null, $repo));
    expect($r2['status'])->toBe('limit')->and($s2[5]->files)->toBe([])->and($s2[2]->releaseKeys)->toHaveCount(1)->and($s2[2]->openReservations())->toBe(0);
});

it('edits a claimed clip, settles once and removes the source', function () {
    [$r, $s] = vclipmake();
    $s[4]->claim(1);
    $s[0]->work(VT, $r['id']);
    $job = $s[4]->jobs[$r['id']];
    expect($job['status'])->toBe('done')->and($job['file_path'])->toBe($r['id'].'.mp4')->and($job['charged'])->toBe('5.0000');
    expect($s[1]->calls)->toBe([])->and($s[1]->edits)->toHaveCount(1);
    $p = $s[1]->edits[0];
    expect($p['video_b64'])->toBe(base64_encode('FAKEVIDEO'))->and($p['trim_start'])->toBe(2.0)->and($p['trim_end'])->toBe(12.0)->and($p['remove_silence'])->toBeTrue()->and($p['size'])->toBe('story');
    expect($s[2]->settleKeys)->toBe(['video-settle:'.$r['id']])->and($s[2]->releaseKeys)->toBe([])->and($s[2]->openReservations())->toBe(0);
    expect(array_keys($s[5]->files))->toBe([VT.'/'.$r['id'].'.mp4']);
});

it('sends a null end when none was given', function () {
    [$r, $s] = vclipmake(['trim_end' => null]);
    $s[4]->claim(1);
    $s[0]->work(VT, $r['id']);
    expect($s[1]->edits[0]['trim_end'])->toBeNull();
});

it('fails a clip with a specific reason, releasing once and removing the source', function (array $answer, string $why) {
    [$r, $s] = vclipmake([], vsvc(new FakeVideoEngine(null, ['status' => 'unsupported', 'bytes' => null] + $answer)));
    $s[4]->claim(1);
    $s[0]->work(VT, $r['id']);
    expect($s[4]->jobs[$r['id']]['status'])->toBe('failed')->and($s[4]->jobs[$r['id']]['error'])->toBe(VideoText::message($why));
    expect($s[2]->releaseKeys)->toHaveCount(1)->and($s[2]->settleKeys)->toBe([])->and($s[2]->openReservations())->toBe(0)->and($s[5]->files)->toBe([]);
})->with([
    'all silent' => [['reason' => 'all_silent'], 'job_silent'],
    'bad trim' => [['reason' => 'bad_trim'], 'job_trim'],
    'bad video' => [['reason' => 'bad_video'], 'job_badvideo'],
    'other' => [['reason' => 'zzz'], 'job_refused'],
]);

it('fails a clip whose source has gone, and cancels / deletes clips cleanly', function () {
    [$r, $s] = vclipmake();
    $s[4]->claim(1);
    $s[5]->files = [];
    $s[0]->work(VT, $r['id']);
    expect($s[4]->jobs[$r['id']]['error'])->toBe(VideoText::message('job_gone'))->and($s[1]->edits)->toBe([])->and($s[2]->openReservations())->toBe(0);
    [$r2, $s2] = vclipmake();
    expect($s2[0]->cancel(VT, VU, $r2['id']))->toBe('ok')->and($s2[5]->files)->toBe([])->and($s2[2]->openReservations())->toBe(0);
});

it('accepts the clip source name and has clip messages', function () {
    expect(preg_match(LocalVideoFiles::NAME, '0123456789abcdefghijklmnop-clip.vid'))->toBe(1);
    expect(preg_match(LocalVideoFiles::NAME, '0123456789abcdefghijklmnop-clip.exe'))->toBe(0);
    foreach (['bad_video', 'bad_trim', 'clip_long', 'engine', 'job_silent', 'job_trim', 'job_badvideo'] as $c) {
        expect(VideoText::MESSAGES)->toHaveKey($c);
    }
});

it('rounds the trim start to a tenth before measuring the window', function () {
    [$r, $s] = vclipmake(['trim_start' => 2.04, 'trim_end' => 12]);
    expect($s[4]->jobs[$r['id']]['seconds'])->toBe('10.0')->and($s[4]->jobs[$r['id']]['scenes'][0]['trim_start'])->toBe(2.0);
});

// ---------- music
it('lists the music presets the engine knows', function () {
    expect(array_keys(VideoService::MUSIC))->toBe(['calm', 'warm', 'upbeat', 'cinematic']);
});

it('refuses an unknown music preset before any money moves, and accepts each known one', function () {
    [$r, $s] = vmake(['music' => 'disco']);
    expect($r['status'])->toBe('bad_music')->and($s[2]->reserveKeys)->toBe([]);
    foreach (array_keys(VideoService::MUSIC) as $i => $m) {
        expect(vmake(['music' => $m], null, 'm'.$i)[0]['status'])->toBe('ok');
    }
});

it('passes the chosen music to the engine, and nothing when none was chosen', function () {
    [$id, $s] = vclaimed(['music' => 'cinematic']);
    $s[0]->work(VT, $id);
    expect($s[1]->calls[0]['music'])->toBe('cinematic');
    [$id2, $s2] = vclaimed([]);
    $s2[0]->work(VT, $id2);
    expect(array_key_exists('music', $s2[1]->calls[0]))->toBeFalse();
    expect(VideoText::MESSAGES)->toHaveKey('bad_music');
});


// ---------- AI pictures in scenes
function vai(array $scenes, ?array $svc = null, string $rid = 'ai'): array
{
    $svc ??= vsvc();
    $r = $svc[0]->create(VT, VU, vinput(['scenes' => $scenes]), vbrand(), $rid);

    return [$r, $svc];
}

it('holds the render price plus one picture price per AI scene', function () {
    [$r, $s] = vai([vscene(['ai_prompt' => 'a bowl of cashew nuts']), vscene(), vscene(['ai_prompt' => 'dates on a table'])]);
    expect($r['status'])->toBe('ok')->and($s[2]->log)->toBe(['reserve:16.0000']);        // 12 s x 0.5 + 2 x 5
    $job = $s[4]->jobs[$r['id']];
    expect($job['amount'])->toBe('16.0000')->and($job['scenes'][0]['ai_prompt'])->toBe('a bowl of cashew nuts')->and($job['scenes'][1]['ai_prompt'])->toBe('');
    expect($s[6]->drawn)->toBe([]);   // nothing is drawn while the job is only being queued
});

it('applies the image model multiplier to the picture price', function () {
    $picker = new FakeImagePicker(['id' => 'I1', 'provider_id' => 'p1', 'model_key' => 'm', 'name' => 'n', 'credit_multiplier' => 2.0]);
    [, $s] = vai([vscene(['ai_prompt' => 'abc'])], vsvc(null, null, null, null, null, '0.5', null, $picker));
    expect($s[2]->log)->toBe(['reserve:12.0000']);   // 4 s x 0.5 + 10
});

it('an uploaded photo wins over an AI prompt and costs nothing extra', function () {
    [$r, $s] = vai([vscene(['photo' => vpng(), 'ai_prompt' => 'abc'])]);
    expect($r['status'])->toBe('ok')->and($s[2]->log)->toBe(['reserve:2.0000'])->and($s[4]->jobs[$r['id']]['scenes'][0]['ai_prompt'])->toBe('');
});

it('refuses AI scenes without a model, with a tiny prompt, or more than five', function () {
    [$r, $s] = vai([vscene(['ai_prompt' => 'abc'])], vsvc(null, null, null, null, null, '0.5', null, new FakeImagePicker(null)));
    expect($r['status'])->toBe('no_image_model')->and($s[2]->reserveKeys)->toBe([]);
    [$r2, $s2] = vai([vscene(['ai_prompt' => 'ab'])]);
    expect($r2['status'])->toBe('bad_prompt')->and($s2[2]->reserveKeys)->toBe([]);
    [$r3, $s3] = vai(array_fill(0, 6, vscene(['ai_prompt' => 'abc'])));
    expect($r3['status'])->toBe('too_many_ai')->and($s3[2]->reserveKeys)->toBe([]);
    [$r4] = vai(array_fill(0, 5, vscene(['ai_prompt' => 'abc'])));
    expect($r4['status'])->toBe('ok');
});

it('draws the AI pictures when rendering and charges only for the ones that arrived', function () {
    [$r, $s] = vai([vscene(['ai_prompt' => 'one']), vscene(['ai_prompt' => 'two']), vscene()]);
    $s[4]->claim(1);
    $s[0]->work(VT, $r['id']);
    expect($s[6]->drawn)->toHaveCount(2)->and($s[6]->drawn[0]['prompt'])->toBe('one')->and($s[6]->drawn[0]['size'])->toBe('1024x1536')->and($s[6]->drawn[0]['modelKey'])->toBe('gpt-image-1');
    $p = $s[1]->calls[0];
    expect($p['scenes'][0]['base_b64'])->toBe(base64_encode('PICTURE'))->and($p['scenes'][1]['base_b64'])->toBe(base64_encode('PICTURE'))->and(array_key_exists('base_b64', $p['scenes'][2]))->toBeFalse();
    expect($s[4]->jobs[$r['id']]['status'])->toBe('done')->and($s[4]->jobs[$r['id']]['charged'])->toBe('16.0000');   // 12 s x 0.5 + 2 pictures x 5
});

it('does not charge for a picture that did not arrive, and still makes the video', function (mixed $answer) {
    $img = new FakeStudioEngine($answer);
    [$r, $s] = vai([vscene(['ai_prompt' => 'one']), vscene()], vsvc(null, null, null, null, null, '0.5', $img));
    $s[4]->claim(1);
    $s[0]->work(VT, $r['id']);
    expect($s[4]->jobs[$r['id']]['status'])->toBe('done')->and($s[4]->jobs[$r['id']]['charged'])->toBe('4.0000');   // render only: 8 s x 0.5
    expect(array_key_exists('base_b64', $s[1]->calls[0]['scenes'][0]))->toBeFalse()->and($s[2]->settleKeys)->toHaveCount(1)->and($s[2]->openReservations())->toBe(0);
})->with([
    'provider error' => [new ProviderCallException('upstream_error', 'x', 500)],
    'engine down' => [new RuntimeException('conn')],
    'unsupported' => [['status' => 'unsupported', 'reason' => 'adapter_no_images']],
    'empty picture' => [['status' => 'ok', 'image_b64' => '']],
    'refused with a picture attached' => [['status' => 'unsupported', 'image_b64' => 'QUJD']],
]);

it('charges the full amount when every picture arrives and the render fails releases everything', function () {
    [$r, $s] = vai([vscene(['ai_prompt' => 'one'])], vsvc(new FakeVideoEngine(new RuntimeException('down'))));
    $s[4]->claim(1);
    $s[0]->work(VT, $r['id']);
    expect($s[4]->jobs[$r['id']]['status'])->toBe('failed')->and($s[2]->releaseKeys)->toHaveCount(1)->and($s[2]->settleKeys)->toBe([])->and($s[2]->openReservations())->toBe(0);
});

it('has messages for the AI picture refusals', function () {
    foreach (['bad_prompt', 'too_many_ai', 'no_image_model'] as $c) {
        expect(VideoText::MESSAGES)->toHaveKey($c);
    }
});

it('records the partial amount even when settling fails', function () {
    $l = new FakeLedger('100.0000');
    $l->failSettle = true;
    [$r, $s] = vai([vscene(['ai_prompt' => 'one']), vscene()], vsvc(null, $l, null, null, null, '0.5', new FakeStudioEngine(['status' => 'unsupported'])));
    $s[4]->claim(1);
    $s[0]->work(VT, $r['id']);
    expect($s[4]->jobs[$r['id']]['charged'])->toBe('4.0000');
});
