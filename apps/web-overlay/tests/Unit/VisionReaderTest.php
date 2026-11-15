<?php

use App\Ai\ProviderCallException;
use App\Chat\CreditPricing;
use App\Vision\ImageAttachment;
use App\Vision\VisionReader;
use App\Vision\VisionText;
use Tests\Support\FakeLedger;
use Tests\Support\FakeVision;
use Tests\Support\FakeVisionEngine;

const VT = 'TENANT0000000000000000000A';
const VU = 'USER000000000000000000000A';

function vpng(): string
{
    return "\x89PNG\r\n\x1A\n".str_repeat("\0", 40);
}

function vrdr(?FakeVisionEngine $e = null, ?FakeLedger $l = null, ?FakeVision $p = null): array
{
    $e ??= new FakeVisionEngine;
    $l ??= new FakeLedger('100.0000');

    return [new VisionReader($e, $p ?? new FakeVision, $l, new CreditPricing('1', '3')), $e, $l];
}

it('reads a picture, settles the real cost once, and records it as vision usage', function () {
    [$r, $e, $l] = vrdr();
    $res = $r->read(VT, VU, vpng(), 'transcribe', 'req1');
    expect($res['status'])->toBe('ok')->and($res['text'])->toBe('দামের তালিকা')->and($res['pages'])->toBe(1);
    expect($res['credits'])->toBe(number_format((float) (new CreditPricing('1', '3'))->cost(900, 40, 1.0), 4, '.', ''));
    expect($l->log[1])->toBe('settle:'.(new CreditPricing('1', '3'))->cost(900, 40, 1.0));
    expect($l->openReservations())->toBe(0);
    expect($l->usages[0])->toMatchArray(['source' => 'vision', 'user_id' => VU, 'request_id' => 'req1', 'input_tokens' => 900, 'output_tokens' => 40]);
    expect($e->seen[0])->toMatchArray(['model' => 'gpt-vision', 'mime' => 'image/png', 'mode' => 'transcribe']);
});

it('passes the mode through and refuses an unknown one before anything happens', function () {
    [$r, $e, $l] = vrdr();
    $r->read(VT, VU, vpng(), 'describe', 'r');
    expect($e->seen[0]['mode'])->toBe('describe');
    $bad = $r->read(VT, VU, vpng(), 'translate', 'r2');
    expect($bad['status'])->toBe('rejected')->and($e->calls)->toBe(1)->and($l->log)->toHaveCount(2);
});

it('refuses files that are not pictures or PDFs, or too big, without a model call or a charge', function () {
    [$r, $e, $l] = vrdr();
    foreach (['', 'plain text', str_repeat('a', 6 * 1024 * 1024)] as $bytes) {
        $res = $r->read(VT, VU, $bytes, 'transcribe', 'r');
        expect($res['status'])->toBe('rejected');
        expect($res['reason'] !== null)->toBeTrue();
    }
    expect($e->calls)->toBe(0)->and($l->log)->toBe([]);
});

it('stops cleanly without a model or without credits', function () {
    [$r, $e, $l] = vrdr(null, null, new FakeVision(null));
    expect($r->read(VT, VU, vpng(), 'transcribe', 'r')['status'])->toBe('no_model');
    [$r2, $e2, $l2] = vrdr(null, new FakeLedger('0.0000'));
    expect($r2->read(VT, VU, vpng(), 'transcribe', 'r')['status'])->toBe('no_credits');
    expect($e->calls + $e2->calls)->toBe(0)->and($l2->openReservations())->toBe(0);
});

it('gives the hold back when the provider fails or the engine breaks', function () {
    [$r, $e, $l] = vrdr(new FakeVisionEngine(new ProviderCallException('rate_limited', 'busy')));
    $res = $r->read(VT, VU, vpng(), 'transcribe', 'r1');
    expect($res['status'])->toBe('provider')->and($res['reason'])->toBe('rate_limited')->and($res['credits'])->toBe('0.0000');
    expect($l->openReservations())->toBe(0)->and($l->releaseKeys)->toBe(['vision-release:r1'])->and($l->usages)->toBe([]);
    [$r2, , $l2] = vrdr(new FakeVisionEngine(new RuntimeException('boom')));
    expect($r2->read(VT, VU, vpng(), 'transcribe', 'r2')['status'])->toBe('failed');
    expect($l2->openReservations())->toBe(0);
});

it('reports unsupported files from the engine without charging', function () {
    [$r, , $l] = vrdr(new FakeVisionEngine(['status' => 'unsupported', 'reason' => 'encrypted_pdf']));
    $res = $r->read(VT, VU, vpng(), 'transcribe', 'r');
    expect($res['status'])->toBe('unsupported')->and($res['reason'])->toBe('encrypted_pdf');
    expect($l->openReservations())->toBe(0)->and($l->usages)->toBe([]);
    [$r2] = vrdr(new FakeVisionEngine(['status' => 'weird']));
    expect($r2->read(VT, VU, vpng(), 'transcribe', 'r')['status'])->toBe('failed');
});

it('still charges for a picture with nothing readable, because the provider was paid', function () {
    [$r, , $l] = vrdr(new FakeVisionEngine(['status' => 'empty', 'text' => null, 'pages' => 1, 'input_tokens' => 900, 'output_tokens' => 3]));
    $res = $r->read(VT, VU, vpng(), 'transcribe', 'r');
    expect($res['status'])->toBe('empty')->and($res['text'])->toBeNull();
    expect((float) $res['credits'] > 0)->toBeTrue()->and($l->usages)->toHaveCount(1);
});

it('is free when the PDF already carried its text', function () {
    [$r, , $l] = vrdr(new FakeVisionEngine(['status' => 'ok', 'text' => 'ভেতরের লেখা', 'pages' => 2, 'source' => 'text_layer', 'model' => null, 'input_tokens' => 0, 'output_tokens' => 0]));
    $res = $r->read(VT, VU, vpng(), 'transcribe', 'r9');
    expect($res['status'])->toBe('ok')->and($res['text'])->toBe('ভেতরের লেখা')->and($res['credits'])->toBe('0.0000')->and($res['pages'])->toBe(2);
    expect($l->usages)->toBe([])->and($l->releaseKeys)->toBe(['vision-release:r9'])->and($l->openReservations())->toBe(0);
});

it('treats a blank "ok" answer as empty', function () {
    [$r] = vrdr(new FakeVisionEngine(['status' => 'ok', 'text' => "  \n", 'pages' => 1, 'input_tokens' => 10, 'output_tokens' => 1]));
    expect($r->read(VT, VU, vpng(), 'transcribe', 'r')['status'])->toBe('empty');
});

it('does not lose the answer when settling fails; the reservation is left for the sweep', function () {
    [$r, , $l] = vrdr();
    $l->failSettle = true;
    $res = $r->read(VT, VU, vpng(), 'transcribe', 'r');
    expect($res['status'])->toBe('ok')->and($res['credits'])->toBe('0.0000');
});

it('has a Bengali message for every failure and none for success', function () {
    foreach (['empty', 'rejected', 'unsupported', 'no_model', 'no_credits', 'provider', 'failed'] as $s) {
        expect(VisionText::error($s) !== null)->toBeTrue();
    }
    expect(VisionText::error('ok'))->toBeNull();
    expect(VisionText::error('rejected', 'The file is empty.'))->toBe('The file is empty.');
    expect(VisionText::error('unsupported', 'encrypted_pdf'))->toContain('Password');
    expect(VisionText::error('unsupported', 'too_large'))->toContain('বড়');
    expect(VisionText::error('unsupported', 'unreadable_pdf'))->toContain('নষ্ট');
    expect(VisionText::error('unsupported', 'x'))->toContain('শুধু ছবি');
});

it('turns a chat picture into a labelled text attachment', function () {
    [$r, $e, $l] = vrdr(new FakeVisionEngine(['status' => 'ok', 'text' => "একটি দোকান।\nText:\nOPEN", 'pages' => 1, 'input_tokens' => 800, 'output_tokens' => 30]));
    $a = (new ImageAttachment($r))->build(VT, VU, '../my shop?.png', vpng(), 'c1');
    expect($a['error'])->toBeNull();
    expect($a['attachment']['filename'])->toBe('my shop.png')->and($a['attachment']['mime'])->toBe('text/plain')->and($a['attachment']['size'])->toBe(strlen(vpng()));
    expect($a['attachment']['text'])->toBe(ImageAttachment::LABEL."একটি দোকান।\nText:\nOPEN");
    expect($e->seen[0]['mode'])->toBe('describe')->and((float) $a['credits'] > 0)->toBeTrue();
});

it('returns the reason instead of an attachment when the picture cannot be read', function () {
    [$r] = vrdr(null, new FakeLedger('0.0000'));
    $a = (new ImageAttachment($r))->build(VT, VU, 'a.png', vpng(), 'c2');
    expect($a['attachment'])->toBeNull()->and($a['error'])->toBe(VisionText::error('no_credits'));
    [$r2] = vrdr(new FakeVisionEngine(['status' => 'empty', 'text' => null, 'pages' => 1, 'input_tokens' => 5, 'output_tokens' => 1]));
    $b = (new ImageAttachment($r2))->build(VT, VU, 'a.png', vpng(), 'c3');
    expect($b['attachment'])->toBeNull()->and($b['error'])->toBe(VisionText::error('empty'));
});

it('recognises pictures by their bytes, not their names', function () {
    expect(ImageAttachment::isImage(vpng()))->toBeTrue();
    expect(ImageAttachment::isImage("\xFF\xD8\xFFrest"))->toBeTrue();
    expect(ImageAttachment::isImage('RIFF....WEBPdata'))->toBeTrue();
    expect(ImageAttachment::isImage('%PDF-1.4'))->toBeFalse();
    expect(ImageAttachment::isImage('hello'))->toBeFalse();
    expect(ImageAttachment::MAX_IMAGES)->toBe(2);
});

it('keeps provider reasons short and never trusts negative token counts', function () {
    [$r] = vrdr(new FakeVisionEngine(new ProviderCallException(str_repeat('x', 100), 'busy')));
    expect(mb_strlen((string) $r->read(VT, VU, vpng(), 'transcribe', 'r')['reason']))->toBe(40);
    [$r2] = vrdr(new FakeVisionEngine(['status' => 'unsupported', 'reason' => str_repeat('y', 100)]));
    expect(mb_strlen((string) $r2->read(VT, VU, vpng(), 'transcribe', 'r')['reason']))->toBe(30);
    [$r3, , $l3] = vrdr(new FakeVisionEngine(['status' => 'ok', 'text' => 'x', 'pages' => 1, 'input_tokens' => -500, 'output_tokens' => 0]));
    expect($r3->read(VT, VU, vpng(), 'transcribe', 'r')['credits'])->toBe('0.0000')->and($l3->usages)->toBe([]);
});

it('uses one stable idempotency key per step so a retry cannot charge twice', function () {
    [$r, , $l] = vrdr();
    $r->read(VT, VU, vpng(), 'transcribe', 'req7');
    expect($l->reserveKeys)->toBe(['vision:req7'])->and($l->settleKeys)->toBe(['vision-settle:req7']);
});

it('never hands back text from a picture the engine called empty', function () {
    [$r] = vrdr(new FakeVisionEngine(['status' => 'empty', 'text' => 'leaked', 'pages' => 1, 'input_tokens' => 10, 'output_tokens' => 1]));
    $res = $r->read(VT, VU, vpng(), 'transcribe', 'r');
    expect($res['status'])->toBe('empty')->and($res['text'])->toBeNull();
});
