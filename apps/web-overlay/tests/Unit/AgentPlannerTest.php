<?php

declare(strict_types=1);

use App\DocTools\AgentPlanner as P;
use App\DocTools\Catalog;

function plan(string $text, array $files = []): array
{
    return P::plan($text, $files);
}

it('plans passport photos and sheets with sizes and colors', function () {
    $a = plan('passport size photo with white background', ['me.jpg']);
    expect($a['tool'])->toBe('passport_photo')->and($a['params'])->toMatchArray(['size' => '35x45', 'bg' => '#ffffff']);
    $b = plan('2x2 photo blue background 4R sheet 6 copies', ['me.jpg']);
    expect($b['tool'])->toBe('photo_sheet')->and($b['params'])->toMatchArray(['size' => '2x2in', 'paper' => '4r', 'copies' => 6, 'bg' => '#2a6fd6']);
    $c = plan('পাসপোর্ট সাইজ ছবি বানাও নীল ব্যাকগ্রাউন্ড', ['me.jpg']);
    expect($c['tool'])->toBe('passport_photo')->and($c['params']['bg'])->toBe('#2a6fd6');
    expect(plan('photo 40x50 mm', ['a.jpg'])['params'])->toMatchArray(['size' => 'custom', 'width_mm' => 40.0, 'height_mm' => 50.0]);
});

it('plans background tools', function () {
    expect(plan('remove background', ['a.png'])['tool'])->toBe('bg_remove');
    expect(plan('ব্যাকগ্রাউন্ড রিমুভ করো', ['a.png'])['tool'])->toBe('bg_remove');
    $r = plan('change background color to red', ['a.png']);
    expect($r['tool'])->toBe('bg_color')->and($r['params']['color'])->toBe('#d32f2f');
});

it('plans ID scans', function () {
    expect(plan('NID front and back combine', ['f.jpg', 'b.jpg'])['tool'])->toBe('nid_combine');
    expect(plan('এনআইডি এক পাতায়', ['f.jpg', 'b.jpg'])['tool'])->toBe('nid_combine');
    expect(plan('driving license scan', ['a.jpg'])['tool'])->toBe('licence_scan');
    expect(plan('passport scan', ['a.jpg'])['tool'])->toBe('passport_scan');
    expect(plan('birth certificate scan', ['a.jpg'])['tool'])->toBe('birth_scan');
});

it('plans PDF work from the words and the file types', function () {
    expect(plan('merge these', ['a.pdf', 'b.pdf'])['tool'])->toBe('pdf_merge');
    expect(plan('এই pdf গুলো মার্জ করো', ['a.pdf', 'b.pdf'])['tool'])->toBe('pdf_merge');
    expect(plan('compress the pdf', ['a.pdf'])['tool'])->toBe('pdf_compress');
    expect(plan('split pages 1-3,5', ['a.pdf'])['params']['pages'])->toBe('1-3,5');
    expect(plan('rotate 180', ['a.pdf'])['params']['degrees'])->toBe('180');
    expect(plan('pdf to word', ['a.pdf'])['tool'])->toBe('pdf_to_word');
    expect(plan('convert to excel', ['a.pdf'])['tool'])->toBe('pdf_to_excel');
    expect(plan('convert to pdf', ['a.jpg', 'b.jpg'])['tool'])->toBe('image_to_pdf');
    expect(plan('convert to pdf', ['report.docx'])['tool'])->toBe('doc_to_pdf');
});

it('plans OCR, handwriting and translation', function () {
    expect(plan('extract text from this', ['a.jpg'])['tool'])->toBe('ocr_text');
    expect(plan('bangla ocr', ['a.jpg'])['tool'])->toBe('ocr_bangla');
    expect(plan('read the handwriting', ['a.jpg'])['tool'])->toBe('handwriting');
    expect(plan('হাতের লেখা টেক্সট করো', ['a.jpg'])['tool'])->toBe('handwriting');
    expect(plan('translate to english', ['a.pdf'])['params']['target'])->toBe('English');
    expect(plan('এটা বাংলায় অনুবাদ করো', ['a.pdf'])['params']['target'])->toBe('Bengali');
});

it('plans generators with the text you give', function () {
    expect(plan('QR code "https://canai.example/pay"')['params']['text'])->toBe('https://canai.example/pay');
    expect(plan('make a qr code for https://x.test/a')['params']['text'])->toBe('https://x.test/a');
    expect(plan('barcode 5901234123457')['params'])->toMatchArray(['text' => '5901234123457', 'type' => 'ean13']);
    expect(plan('watermark: DRAFT only', ['a.jpg'])['params']['text'])->toBe('DRAFT only');
    expect(plan('signature maker', ['s.jpg'])['tool'])->toBe('signature');
    expect(plan('make id card for "Rahim"')['params']['name'])->toBe('Rahim');
    expect(plan('visiting card')['tool'])->toBe('business_card');
    expect(plan('qr code')['error'])->toContain('What should the QR code say');
});

it('plans picture fixes', function () {
    expect(plan('enhance this blurry photo', ['a.jpg'])['tool'])->toBe('enhance');
    expect(plan('shadow সরিয়ে scan করো', ['a.jpg'])['tool'])->toBe('doc_cleanup');
    expect(plan('resize to 100kb', ['a.jpg'])['params']['max_kb'])->toBe(100);
    expect(plan('resize 600x800 px', ['a.jpg'])['params'])->toMatchArray(['width' => 600, 'height' => 800]);
});

it('asks when it cannot tell, and every plan survives the catalog', function () {
    expect(plan('hello there')['error'])->toContain('could not tell');
    expect(plan('   ')['error'])->toContain('Tell me');
    foreach (['passport photo blue', 'merge', 'qr "x"', 'resize to 50kb', 'bangla ocr', 'translate to hindi', 'watermark: A'] as $s) {
        $r = plan($s, ['a.pdf', 'b.pdf']);
        if (isset($r['tool'])) {
            expect(Catalog::get($r['tool']))->not->toBeNull();
            foreach (array_keys(Catalog::params(Catalog::get($r['tool']), $r['params'])) as $k) {
                expect(is_string($k))->toBeTrue();
            }
        }
    }
});
