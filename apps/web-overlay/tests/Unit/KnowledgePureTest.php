<?php

use App\Knowledge\KnowledgeText;
use App\Knowledge\TextChunker;
use App\Knowledge\TextExtractor;

function kbfile(string $name, string $content): string
{
    $dir = sys_get_temp_dir().'/kbtest-'.getmypid();
    @mkdir($dir);
    file_put_contents($dir.'/'.$name, $content);

    return $dir.'/'.$name;
}

function kbdocx(string $bodyXml): string
{
    $path = sys_get_temp_dir().'/kbtest-'.getmypid().'/x'.mt_rand().'.docx';
    @mkdir(dirname($path));
    $z = new ZipArchive;
    $z->open($path, ZipArchive::CREATE);
    $z->addFromString('word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="w"><w:body>'.$bodyXml.'</w:body></w:document>');
    $z->close();

    return $path;
}

it('cleans text before cutting it', function () {
    expect(TextChunker::normalize("\u{FEFF}a \t b\r\n\r\n\r\n\r\n c \u{00A0} d\0 "))->toBe("a b\n\nc d");
    expect(TextChunker::normalize("  \n  "))->toBe('');
});

it('treats the form feed pdftotext puts after every page as a line break, so a blank scan has no text', function () {
    expect(TextChunker::normalize("page one\fpage two\f"))->toBe("page one\npage two");
    expect(TextChunker::normalize("\f\f"))->toBe('');
    expect(TextExtractor::extract(__DIR__.'/../fixtures/kb-scanned.pdf', 'kb-scanned.pdf')['error'])->toBe(is_executable('/usr/bin/pdftotext') || is_executable('/usr/local/bin/pdftotext') ? 'empty' : 'pdf_unavailable');
});

it('keeps short paragraphs whole and packs them up to the size', function () {
    expect(TextChunker::chunks("এক\n\nদুই\n\nতিন"))->toBe(["এক\nদুই\nতিন"]);
    $a = str_repeat('ক', 500);
    $b = str_repeat('খ', 500);
    expect(TextChunker::chunks("$a\n\n$b"))->toBe([$a, $b]);
    expect(TextChunker::chunks(''))->toBe([]);
});

it('cuts long paragraphs at sentence borders, danda included, and hard-cuts what is still too long', function () {
    $s = str_repeat('আমি ভাত খাই। ', 100);
    $chunks = TextChunker::chunks($s, 200);
    expect(count($chunks) > 5)->toBeTrue();
    foreach ($chunks as $c) {
        expect(mb_strlen($c) <= 200)->toBeTrue();
        expect(str_ends_with($c, '।'))->toBeTrue();
    }
    $hard = TextChunker::chunks(str_repeat('x', 1000), 300);
    expect(array_map('mb_strlen', $hard))->toBe([300, 300, 300, 100]);
    $lines = TextChunker::chunks(str_repeat("line one two\n", 40), 100);
    expect(mb_strlen($lines[0]) <= 100)->toBeTrue();
});

it('never loses or invents text when cutting', function () {
    $text = implode("\n\n", array_map(fn ($i) => "অনুচ্ছেদ $i: ".str_repeat('শব্দ ', $i * 7).'শেষ।', range(1, 30)));
    $joined = preg_replace('/\s+/u', '', implode('', TextChunker::chunks($text, 300)));
    expect($joined)->toBe(preg_replace('/\s+/u', '', $text));
    foreach (TextChunker::chunks($text, 300) as $c) {
        expect(mb_strlen($c) <= 300)->toBeTrue();
    }
});

it('clamps the size', function () {
    expect(mb_strlen(TextChunker::chunks(str_repeat('a', 5000), 99999)[0]))->toBe(1900);
    expect(mb_strlen(TextChunker::chunks(str_repeat('a', 5000), 1)[0]))->toBe(100);
});

it('reads plain text files and refuses non-UTF-8', function () {
    expect(TextExtractor::extract(kbfile('a.txt', "\u{FEFF}আমার দোকান\r\nবসুন্ধরায়"), 'a.txt'))->toBe(['text' => "আমার দোকান\nবসুন্ধরায়", 'error' => null]);
    foreach (['md', 'csv', 'tsv', 'json', 'log', 'TXT'] as $ext) {
        expect(TextExtractor::extract(kbfile("f.$ext", 'hello'), "f.$ext")['text'])->toBe('hello');
    }
    expect(TextExtractor::extract(kbfile('bad.txt', "caf\xE9"), 'bad.txt'))->toBe(['text' => null, 'error' => 'encoding']);
});

it('reports empty, unsupported and unreadable files', function () {
    expect(TextExtractor::extract(kbfile('e.txt', "  \n "), 'e.txt')['error'])->toBe('empty');
    expect(TextExtractor::extract(kbfile('x.exe', 'MZ'), 'x.exe'))->toBe(['text' => null, 'error' => 'unsupported']);
    expect(TextExtractor::extract(kbfile('noext', 'hi'), 'noext')['error'])->toBe('unsupported');
    expect(TextExtractor::extract('/nonexistent/file.txt', 'file.txt')['error'])->toBe('unreadable');
    expect(TextExtractor::extract(kbfile('broken.docx', 'not a zip'), 'broken.docx')['error'])->toBe('unreadable');
    expect(in_array(TextExtractor::extract(kbfile('broken.pdf', 'not a pdf'), 'broken.pdf')['error'], ['unreadable', 'pdf_unavailable'], true))->toBeTrue();
});

it('strips tags, scripts and styles from HTML and decodes entities', function () {
    $html = '<html><head><style>p{color:red}</style><script>alert(1)</script></head><body><h1>দাম</h1><p>কাজু &amp; বাদাম</p><ul><li>এক</li><li>দুই</li></ul>one<br>two</body></html>';
    $r = TextExtractor::extract(kbfile('p.html', $html), 'p.html');
    expect($r['text'])->toContain('দাম')->toContain('কাজু & বাদাম')->toContain("এক\nদুই")->toContain("one\ntwo");
    expect(str_contains($r['text'], 'alert'))->toBeFalse();
    expect(str_contains($r['text'], 'color'))->toBeFalse();
    expect(TextExtractor::extract(kbfile('b.htm', "<p>caf\xE9</p>"), 'b.htm')['error'])->toBe('encoding');
});

it('reads paragraphs out of a Word file', function () {
    $r = TextExtractor::extract(kbdocx('<w:p><w:r><w:t>প্রথম &amp; লাইন</w:t></w:r></w:p><w:p><w:r><w:t>দ্বিতীয়</w:t><w:tab/><w:t>ট্যাব</w:t></w:r></w:p><w:p><w:r><w:t>a</w:t><w:br/><w:t>b</w:t></w:r></w:p>'), 'x.docx');
    expect($r['error'])->toBeNull();
    expect($r['text'])->toBe("প্রথম & লাইন\nদ্বিতীয় ট্যাব\na\nb");
});

it('has a Bengali message for every result the service or database can give', function () {
    foreach (['duplicate', 'limit', 'too_big', 'empty', 'unsupported', 'encoding', 'pdf_unavailable', 'unreadable', 'not_found', 'bad_input'] as $r) {
        expect(KnowledgeText::error($r) !== null && KnowledgeText::error($r) !== 'Could not be done.')->toBeTrue();
    }
    expect(KnowledgeText::error('ok'))->toBeNull();
    expect(KnowledgeText::error('failed'))->toBe('Could not be done.');
});

it('reads a PDF through pdftotext when the server has it, and says so plainly when it does not', function () {
    $r = TextExtractor::extract(__DIR__.'/../fixtures/kb-sample.pdf', 'kb-sample.pdf');
    if (is_executable('/usr/bin/pdftotext') || is_executable('/usr/local/bin/pdftotext')) {
        expect($r['error'])->toBeNull();
        expect($r['text'])->toContain('Cashew price is 1200 taka per kg')->toContain('Delivery within Dhaka');
    } else {
        expect($r)->toBe(['text' => null, 'error' => 'pdf_unavailable']);
    }
});

it('treats a line break as a border and never starts a passage with a space', function () {
    $a = str_repeat('a', 60);
    $b = str_repeat('b', 60);
    expect(TextChunker::chunks("$a\n$b", 100))->toBe([$a, $b]);
    $hard = TextChunker::chunks(str_repeat('a', 100).' '.str_repeat('b', 50), 100);
    expect($hard)->toBe([str_repeat('a', 100), str_repeat('b', 50)]);
    expect(TextChunker::chunks(str_repeat('x', 101), 100))->toBe([str_repeat('x', 100), 'x']);
});

it('packs paragraphs right up to the size, no further', function () {
    $a = str_repeat('a', 399);
    $b = str_repeat('b', 400);
    expect(TextChunker::chunks("$a\n\n$b", 800))->toHaveCount(1);
    expect(TextChunker::chunks("$a\n\n$b".'b', 800))->toHaveCount(2);
    expect(TextChunker::normalize("a\n\n\n\nb"))->toBe("a\n\nb");
    expect(TextChunker::normalize("a\n\n\nb"))->toBe("a\n\nb");
});

it('decodes quotes in HTML', function () {
    expect(TextExtractor::extract(kbfile('q.html', '<p>He said &quot;hi&quot; it&#039;s</p>'), 'q.html')['text'])->toBe('He said "hi" it\'s');
});

it('refuses a Word file whose body is absurdly large', function () {
    expect(TextExtractor::extract(kbdocx('<w:p><w:t>'.str_repeat('a', 30_000_001).'</w:t></w:p>'), 'big.docx')['error'])->toBe('unreadable');
});

it('tells the service a picture needs the AI reader and gives no text itself', function () {
    foreach (['png', 'jpg', 'jpeg', 'webp'] as $ext) {
        expect(TextExtractor::extract(kbfile("p.$ext", "\x89PNG"), "p.$ext"))->toBe(['text' => null, 'error' => 'needs_ocr']);
    }
    expect(KnowledgeText::error('needs_ocr') !== null && str_contains(KnowledgeText::error('needs_ocr'), 'টিক'))->toBeTrue();
    expect(KnowledgeText::error('no_credits'))->toBe(\App\Vision\VisionText::error('no_credits'));
    expect(KnowledgeText::error('rejected', 'Reason'))->toBe('Reason');
});
