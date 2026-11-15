<?php

use App\Office\OfficePrompt;
use App\Office\OfficeSpec;
use App\Office\OfficeText;

it('reads a document and keeps only known fields as plain text', function () {
    $s = OfficeSpec::parse('docx', "```json\n".json_encode(['title' => " রিপোর্ট\x00 ", 'extra' => 'x', 'blocks' => [
        ['t' => 'h1', 'text' => 'সারাংশ', 'html' => '<b>'], ['t' => 'p', 'text' => 'Text'], ['t' => 'ul', 'items' => ['এক', '', 7, ['x']]],
        ['t' => 'table', 'headers' => ['Product', 'Price'], 'rows' => [['কাজু', 1200], 'bad', ['x']]], ['t' => 'script', 'text' => 'no'], ['t' => 'p', 'text' => '   '], 'str', ['t' => 'ol', 'items' => []],
    ]], JSON_UNESCAPED_UNICODE)."\n```");
    expect($s['kind'])->toBe('docx');
    expect($s['title'])->toBe('রিপোর্ট');
    expect(array_column($s['blocks'], 't'))->toBe(['h1', 'p', 'ul', 'table']);
    expect($s['blocks'][2]['items'])->toBe(['এক', '7']);
    expect($s['blocks'][3]['rows'])->toBe([['কাজু', '1200'], ['x']]);
    expect(array_key_exists('html', $s['blocks'][0]))->toBeFalse();
    expect(array_key_exists('extra', $s))->toBeFalse();
});

it('finds the JSON when the model adds words around it', function () {
    $s = OfficeSpec::parse('pptx', 'Here you go: {"title":"পিচ","slides":[{"title":"Problem","bullets":["এক"],"notes":"ন"}]} Hope it helps!');
    expect($s['slides'][0])->toBe(['title' => 'Problem', 'bullets' => ['এক'], 'notes' => 'ন']);
});

it('refuses answers that are not a usable file', function () {
    foreach (['', 'sorry', '[]', '[1,2]', '{"title":"x"}', '{"title":"","blocks":[{"t":"p","text":"x"}]}', '{"title":"x","blocks":[]}', '{"title":"x","blocks":[{"t":"p","text":""}]}', '{"title":"x","blocks":[{"t":"p","text":"x"}'] as $bad) {
        expect(OfficeSpec::parse('docx', $bad))->toBeNull();
    }
    expect(OfficeSpec::parse('pdf', '{"title":"x","blocks":[{"t":"p","text":"x"}]}'))->toBeNull();
    expect(OfficeSpec::parse('xlsx', '{"title":"x","sheets":[]}'))->toBeNull();
    expect(OfficeSpec::parse('xlsx', '{"title":"x","sheets":[{"name":"a"}]}'))->toBeNull();
    expect(OfficeSpec::parse('pptx', '{"title":"x","slides":[{"title":"","bullets":[]}]}'))->toBeNull();
    expect(OfficeSpec::parse('pptx', '{"title":"x","slides":"no"}'))->toBeNull();
});

it('trims to the same limits the engine enforces instead of failing', function () {
    $long = str_repeat('ক', 6000);
    $s = OfficeSpec::parse('docx', json_encode(['title' => str_repeat('খ', 300), 'blocks' => array_merge([['t' => 'p', 'text' => $long]], array_fill(0, 400, ['t' => 'p', 'text' => 'x']))], JSON_UNESCAPED_UNICODE));
    expect(mb_strlen($s['title']))->toBe(200);
    expect(mb_strlen($s['blocks'][0]['text']))->toBe(5000);
    expect(count($s['blocks']))->toBe(300);
    $items = OfficeSpec::parse('docx', json_encode(['title' => 't', 'blocks' => [['t' => 'ul', 'items' => array_fill(0, 80, str_repeat('ক', 400))]]], JSON_UNESCAPED_UNICODE));
    expect(count($items['blocks'][0]['items']))->toBe(60);
    expect(mb_strlen($items['blocks'][0]['items'][0]))->toBe(300);
    $t = OfficeSpec::parse('docx', json_encode(['title' => 't', 'blocks' => [['t' => 'table', 'headers' => array_fill(0, 40, 'h'), 'rows' => array_fill(0, 600, array_fill(0, 40, str_repeat('c', 600)))]]]));
    expect(count($t['blocks'][0]['headers']))->toBe(30);
    expect(count($t['blocks'][0]['rows']))->toBe(500);
    expect(count($t['blocks'][0]['rows'][0]))->toBe(30);
    expect(strlen($t['blocks'][0]['rows'][0][0]))->toBe(500);
});

it('keeps numbers as numbers in sheets, text as text, and never makes formulas', function () {
    $s = OfficeSpec::parse('xlsx', json_encode(['title' => 'Budget', 'sheets' => [['name' => 'Jan', 'headers' => ['Item', 'Amount'], 'rows' => [['ভাড়া', 20000], ['=SUM(B2:B2)', 1.5], [true, null, ['x'], 'ক']]]]], JSON_UNESCAPED_UNICODE));
    $rows = $s['sheets'][0]['rows'];
    expect($rows[0])->toBe(['ভাড়া', 20000]);
    expect($rows[1])->toBe(['=SUM(B2:B2)', 1.5]);                 // stays text; the engine stores it as a string cell
    expect($rows[2])->toBe(['TRUE', null, null, 'ক']);
    expect($s['sheets'][0]['name'])->toBe('Jan');
    $d = OfficeSpec::parse('xlsx', '{"title":"t","sheets":[{"headers":["a"],"rows":[]}]}');
    expect($d['sheets'][0]['name'])->toBe('Sheet');
});

it('limits sheets, rows and columns', function () {
    $s = OfficeSpec::parse('xlsx', json_encode(['title' => 't', 'sheets' => array_fill(0, 12, ['name' => 'n', 'headers' => array_fill(0, 60, 'h'), 'rows' => array_fill(0, 2100, array_fill(0, 60, 1))])]));
    expect(count($s['sheets']))->toBe(10);
    expect(count($s['sheets'][0]['headers']))->toBe(50);
    expect(count($s['sheets'][0]['rows']))->toBe(2000);
    expect(count($s['sheets'][0]['rows'][0]))->toBe(50);
});

it('limits slides and bullets and drops empty slides', function () {
    $s = OfficeSpec::parse('pptx', json_encode(['title' => 't', 'slides' => array_merge([['title' => '', 'bullets' => []]], array_fill(0, 50, ['title' => 's', 'bullets' => array_fill(0, 20, str_repeat('b', 400)), 'notes' => str_repeat('n', 3000)]))]));
    expect(count($s['slides']))->toBe(39);                          // first 40 read, the empty one dropped
    expect(count($s['slides'][0]['bullets']))->toBe(12);
    expect(strlen($s['slides'][0]['bullets'][0]))->toBe(300);
    expect(strlen($s['slides'][0]['notes']))->toBe(2000);
});

it('describes the file in a short outline', function () {
    $d = OfficeSpec::parse('docx', '{"title":"t","blocks":[{"t":"h1","text":"এক"},{"t":"h2","text":"দুই"},{"t":"p","text":"x"}]}');
    expect(OfficeSpec::outline($d))->toBe(['এক', '  দুই', '3 Block (paragraph/list/table)']);
    $x = OfficeSpec::parse('xlsx', '{"title":"t","sheets":[{"name":"A","headers":["a","b"],"rows":[[1,2,3],[4]]}]}');
    expect(OfficeSpec::outline($x))->toBe(['A — 2 সারি, 3 কলাম']);
    $p = OfficeSpec::parse('pptx', '{"title":"t","slides":[{"title":"First","bullets":[]},{"title":"দ্বিতীয়"}]}');
    expect(OfficeSpec::outline($p))->toBe(['1. First', '2. দ্বিতীয়']);
});

it('words the request so the user text cannot change the rules, and names files safely', function () {
    $m = OfficePrompt::messages('xlsx', str_repeat('ক', 3000));
    expect($m[0]['role'])->toBe('system');
    expect(str_contains($m[0]['content'], 'never formulas'))->toBeTrue();
    expect(str_contains($m[0]['content'], 'Return ONLY the JSON'))->toBeTrue();
    expect(mb_strlen($m[1]['content']))->toBe(mb_strlen("Request:\n") + 2000);
    expect(str_starts_with($m[1]['content'], 'Request:'))->toBeTrue();
    expect(OfficePrompt::fileName('মাসিক রিপোর্ট: ২০২৬/১১ "x"', 'docx'))->toBe('মাসিক-রিপোর্ট-২০২৬-১১-x.docx');
    expect(OfficePrompt::fileName('///', 'xlsx'))->toBe('jarvis-file.xlsx');
    expect(mb_strlen(OfficePrompt::fileName(str_repeat('ক', 100), 'pptx')))->toBe(60 + 5);
    expect(OfficeSpec::MIME['pptx'])->toContain('presentationml');
});

it('has a message for every status the creator can give', function () {
    foreach (['empty_request', 'bad_kind', 'no_model', 'no_credits', 'provider', 'unreadable', 'limit', 'failed'] as $s) {
        expect(OfficeText::create($s) !== '')->toBeTrue();
    }
    expect(OfficeText::create('whatever'))->toBe(OfficeText::CREATE['failed']);
});

it('is strict about kinds, block types, rows and titles', function () {
    $deck = '{"title":"x","slides":[{"title":"s","bullets":["b"]}]}';
    expect(OfficeSpec::parse('pdf', $deck))->toBeNull();
    expect(OfficeSpec::parse('pptx', $deck) !== null)->toBeTrue();
    $odd = OfficeSpec::parse('docx', '{"title":"t","blocks":[{"t":"script","headers":["a"],"text":"x"},{"t":"p","text":"ok"}]}');
    expect(array_column($odd['blocks'], 't'))->toBe(['p']);
    $rows = OfficeSpec::parse('docx', '{"title":"t","blocks":[{"t":"table","rows":[{"a":"x"},["y"]]}]}');
    expect($rows['blocks'][0]['rows'])->toBe([['y']]);
    $empty = OfficeSpec::parse('docx', '{"title":"t","blocks":[{"t":"table"},{"t":"table","headers":[],"rows":[]},{"t":"p","text":"ok"}]}');
    expect(array_column($empty['blocks'], 't'))->toBe(['p']);
    expect(OfficeSpec::parse('xlsx', '{"title":"","sheets":[{"name":"A","headers":["a"],"rows":[]}]}'))->toBeNull();
    expect(OfficeSpec::parse('pptx', '{"title":"","slides":[{"title":"s"}]}'))->toBeNull();
    expect(OfficeSpec::parse('docx', '{"title":"","blocks":[{"t":"p","text":"x"}]}'))->toBeNull();
});

it('shows at most 60 lines of outline', function () {
    $s = OfficeSpec::parse('pptx', json_encode(['title' => 't', 'slides' => array_fill(0, 40, ['title' => 's'])]));
    $big = ['kind' => 'docx', 'blocks' => array_fill(0, 100, ['t' => 'h1', 'text' => 'x'])];
    expect(count(OfficeSpec::outline($s)))->toBe(40);
    expect(count(OfficeSpec::outline($big)))->toBe(60);
});
