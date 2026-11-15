<?php

use App\Chat\CreditPricing as VCP;
use App\Knowledge\KnowledgeService;
use App\Vision\VisionReader;
use Tests\Support\FakeKnowledgeRepo;
use Tests\Support\FakeLedger;
use Tests\Support\FakeVision;
use Tests\Support\FakeVisionEngine;

function ksvc(?FakeKnowledgeRepo $r = null): array
{
    $r ??= new FakeKnowledgeRepo;

    return [new KnowledgeService($r), $r];
}

function kfile(string $content, string $name = 'prices.txt'): string
{
    $p = sys_get_temp_dir().'/ksvc-'.getmypid().'-'.$name;
    file_put_contents($p, $content);

    return $p;
}

it('extracts, cuts and stores a document, titled after the file when no title is given', function () {
    [$s, $r] = ksvc();
    $res = $s->add('T', 'U', 'D1', kfile("কাজু ১২০০ টাকা\n\nবাদাম ৯০০ টাকা"), 'prices.txt', 'text/plain', 40, null);
    expect($res)->toBe(['status' => 'ok', 'chunks' => 1, 'credits' => '0.0000', 'vision' => null]);
    expect($r->saved[0])->toMatchArray(['tenantId' => 'T', 'userId' => 'U', 'id' => 'D1', 'title' => 'prices', 'filename' => 'prices.txt', 'mime' => 'text/plain', 'size' => 40]);
    expect($r->saved[0]['chunks'])->toBe(["কাজু ১২০০ টাকা\nবাদাম ৯০০ টাকা"]);
});

it('uses the given title, trimmed and cut to 200', function () {
    [$s, $r] = ksvc();
    $s->add('T', 'U', 'D', kfile('hello'), 'a.txt', 'text/plain', 5, '  '.str_repeat('ক', 300).'  ');
    expect(mb_strlen($r->saved[0]['title']))->toBe(200);
    $s->add('T', 'U', 'D', kfile('hello'), 'a.txt', 'text/plain', 5, '  নাম  ');
    expect($r->saved[1]['title'])->toBe('নাম');
});

it('passes database refusals on: duplicate and limit stay as they are, anything else is a plain failure', function () {
    foreach (['duplicate' => 'duplicate', 'limit' => 'limit', 'bad_input' => 'failed'] as $db => $want) {
        [$s, $r] = ksvc();
        $r->result = $db;
        expect($s->add('T', 'U', 'D', kfile('hello'), 'a.txt', 'text/plain', 5, null))->toMatchArray(['status' => $want, 'chunks' => 0]);
    }
});

it('refuses big files and too many passages before touching the database', function () {
    [$s, $r] = ksvc();
    expect($s->add('T', 'U', 'D', kfile('x'), 'a.txt', 'text/plain', KnowledgeService::MAX_BYTES + 1, null)['status'])->toBe('too_big');
    expect($s->add('T', 'U', 'D', kfile('x'), 'a.txt', 'text/plain', KnowledgeService::MAX_BYTES, null)['status'])->toBe('ok');
    $huge = implode("\n\n", array_fill(0, 700, str_repeat('ক', 700)));
    expect($s->add('T', 'U', 'D', kfile($huge, 'huge.txt'), 'huge.txt', 'text/plain', 1000, null)['status'])->toBe('too_big');
    expect($r->saved)->toHaveCount(1);
});

it('reports unreadable content without saving', function () {
    [$s, $r] = ksvc();
    expect($s->add('T', 'U', 'D', kfile('  '), 'e.txt', 'text/plain', 2, null))->toMatchArray(['status' => 'empty', 'chunks' => 0]);
    expect($s->add('T', 'U', 'D', kfile('MZ', 'x.exe'), 'x.exe', 'x', 2, null)['status'])->toBe('unsupported');
    expect($r->saved)->toBe([]);
});

it('searches only for a non-empty question', function () {
    [$s, $r] = ksvc();
    expect($s->search('   '))->toBe([]);
    $s->search('  দাম কত  ', 8);
    $s->search('x');
    expect($r->searches)->toBe([['দাম কত', 8], ['x', 5]]);
});

it('accepts exactly 600 passages and refuses 601', function () {
    [$s, $r] = ksvc();
    $mk = fn ($n) => implode("\n\n", array_fill(0, $n, str_repeat('ক', 700)));
    expect($s->add('T', 'U', 'D', kfile($mk(600), 'a.txt'), 'a.txt', 'text/plain', 1000, null))->toMatchArray(['status' => 'ok', 'chunks' => 600]);
    expect($s->add('T', 'U', 'D', kfile($mk(601), 'b.txt'), 'b.txt', 'text/plain', 1000, null)['status'])->toBe('too_big');
});

it('cuts a very long file name and mime type to what the database accepts', function () {
    [$s, $r] = ksvc();
    $s->add('T', 'U', 'D', kfile('hello'), str_repeat('f', 250).'.txt', str_repeat('m', 150), 5, 'Name');
    expect(mb_strlen($r->saved[0]['filename']))->toBe(200);
    expect(mb_strlen($r->saved[0]['mime']))->toBe(100);
});


function kocr(?FakeVisionEngine $e = null, ?FakeLedger $l = null): array
{
    $r = new FakeKnowledgeRepo;
    $e ??= new FakeVisionEngine(['status' => 'ok', 'text' => "কাজু ১২০০ টাকা\n\nবাদাম ৯০০ টাকা", 'pages' => 1, 'source' => 'vision', 'model' => 'm', 'input_tokens' => 900, 'output_tokens' => 40]);
    $l ??= new FakeLedger('100.0000');

    return [new KnowledgeService($r, new VisionReader($e, new FakeVision, $l, new \App\Chat\CreditPricing('1', '3'))), $r, $e, $l];
}

function kpng(): string
{
    return "\x89PNG\r\n\x1A\n".str_repeat("\0", 40);
}

it('asks before spending credits: a picture or a scanned PDF needs the OCR tick', function () {
    [$s, $r, $e, $l] = kocr();
    expect($s->add('T', 'U', 'D', kfile(kpng(), 'shot.png'), 'shot.png', 'image/png', 60, null)['status'])->toBe('needs_ocr');
    expect($s->add('T', 'U', 'D', __DIR__.'/../fixtures/kb-scanned.pdf', 'scan.pdf', 'application/pdf', 900, null)['status'])->toBe('needs_ocr');
    expect($e->calls)->toBe(0)->and($l->log)->toBe([])->and($r->saved)->toBe([]);
    $noReader = new KnowledgeService(new FakeKnowledgeRepo);
    expect($noReader->add('T', 'U', 'D', kfile(kpng(), 'shot.png'), 'shot.png', 'image/png', 60, null, true)['status'])->toBe('needs_ocr');
});

it('reads a picture with the AI when the person agreed, stores the text and reports the cost', function () {
    [$s, $r, $e, $l] = kocr();
    $res = $s->add('T', 'U', 'D1', kfile(kpng(), 'shot.png'), 'shot.png', 'image/png', 60, null, true);
    expect($res['status'])->toBe('ok')->and($res['chunks'])->toBe(1)->and((float) $res['credits'] > 0)->toBeTrue();
    expect($r->saved[0]['chunks'])->toBe(["কাজু ১২০০ টাকা\nবাদাম ৯০০ টাকা"])->and($r->saved[0]['title'])->toBe('shot');
    expect($e->seen[0]['mode'])->toBe('transcribe')->and($l->usages[0]['request_id'])->toBe('D1')->and($l->usages[0]['source'])->toBe('vision');
});

it('reads a scanned PDF with the AI, but never uses the AI for a PDF that has text', function () {
    [$s, $r, $e] = kocr();
    $res = $s->add('T', 'U', 'D', __DIR__.'/../fixtures/kb-scanned.pdf', 'scan.pdf', 'application/pdf', 900, null, true);
    expect($res['status'])->toBe('ok')->and($e->calls)->toBe(1);
    if (is_executable('/usr/bin/pdftotext') || is_executable('/usr/local/bin/pdftotext')) {
        [$s2, , $e2] = kocr();
        $ok = $s2->add('T', 'U', 'D', __DIR__.'/../fixtures/kb-sample.pdf', 'kb-sample.pdf', 'application/pdf', 900, null, true);
        expect($ok['status'])->toBe('ok')->and($ok['credits'])->toBe('0.0000')->and($e2->calls)->toBe(0);
    }
});

it('does not send a Word or text file to the AI just because the tick is on', function () {
    [$s, , $e] = kocr();
    expect($s->add('T', 'U', 'D', kfile("  \n "), 'e.txt', 'text/plain', 3, null, true)['status'])->toBe('empty');
    expect($s->add('T', 'U', 'D', kfile('MZ', 'x.exe'), 'x.exe', 'x', 2, null, true)['status'])->toBe('unsupported');
    expect($s->add('T', 'U', 'D', kfile('hello'), 'ok.txt', 'text/plain', 5, null, true)['status'])->toBe('ok');
    expect($e->calls)->toBe(0);
});

it('passes picture-reading failures on with the Bengali reason, and charges only what the provider cost', function () {
    [$s, $r, , $l] = kocr(null, new FakeLedger('0.0000'));
    $res = $s->add('T', 'U', 'D', kfile(kpng(), 'a.png'), 'a.png', 'image/png', 60, null, true);
    expect($res['status'])->toBe('no_credits');
    [$s2] = kocr(new FakeVisionEngine(['status' => 'unsupported', 'reason' => 'encrypted_pdf']));
    expect($s2->add('T', 'U', 'D', kfile(kpng(), 'a.png'), 'a.png', 'image/png', 60, null, true))->toMatchArray(['status' => 'unreadable', 'vision' => 'encrypted_pdf']);
    [$s3, $r3, , $l3] = kocr(new FakeVisionEngine(['status' => 'empty', 'text' => null, 'pages' => 1, 'input_tokens' => 900, 'output_tokens' => 2]));
    $e3 = $s3->add('T', 'U', 'D', kfile(kpng(), 'a.png'), 'a.png', 'image/png', 60, null, true);
    expect($e3['status'])->toBe('empty')->and((float) $e3['credits'] > 0)->toBeTrue()->and($r3->saved)->toBe([]);
});

it('refuses AI text that is too long to store, after the cost was paid', function () {
    $huge = implode("\n\n", array_fill(0, 700, str_repeat('ক', 700)));
    [$s, $r] = kocr(new FakeVisionEngine(['status' => 'ok', 'text' => $huge, 'pages' => 1, 'input_tokens' => 900, 'output_tokens' => 500]));
    $res = $s->add('T', 'U', 'D', kfile(kpng(), 'a.png'), 'a.png', 'image/png', 60, null, true);
    expect($res['status'])->toBe('too_big')->and((float) $res['credits'] > 0)->toBeTrue()->and($r->saved)->toBe([]);
});

it('does not call the AI for a file over the size limit', function () {
    [$s, , $e] = kocr();
    expect($s->add('T', 'U', 'D', kfile(kpng(), 'a.png'), 'a.png', 'image/png', KnowledgeService::MAX_BYTES + 1, null, true)['status'])->toBe('too_big');
    expect($e->calls)->toBe(0);
});

it('falls back to the AI for a PDF when the server has no pdftotext, and says so when the tick is off', function () {
    \App\Knowledge\TextExtractor::$pdftotext = '/nonexistent/pdftotext';
    try {
        [$s, , $e] = kocr();
        expect($s->add('T', 'U', 'D', __DIR__.'/../fixtures/kb-sample.pdf', 'kb-sample.pdf', 'application/pdf', 900, null)['status'])->toBe('needs_ocr');
        expect($e->calls)->toBe(0);
        expect($s->add('T', 'U', 'D', __DIR__.'/../fixtures/kb-sample.pdf', 'kb-sample.pdf', 'application/pdf', 900, null, true)['status'])->toBe('ok');
        expect($e->calls)->toBe(1);
    } finally {
        \App\Knowledge\TextExtractor::$pdftotext = null;
    }
});

it('treats AI text that is only page breaks as nothing', function () {
    [$s, $r, , $l] = kocr(new FakeVisionEngine(['status' => 'ok', 'text' => "\f\f", 'pages' => 1, 'input_tokens' => 900, 'output_tokens' => 2]));
    $res = $s->add('T', 'U', 'D', kfile(kpng(), 'a.png'), 'a.png', 'image/png', 60, null, true);
    expect($res['status'])->toBe('empty')->and($r->saved)->toBe([])->and((float) $res['credits'] > 0)->toBeTrue();
});
