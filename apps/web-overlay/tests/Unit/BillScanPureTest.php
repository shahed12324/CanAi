<?php

use App\BillScan\BillDraft;
use App\BillScan\BillFile;

const JPG = "\xFF\xD8\xFF\xE0".'abcdefgh';
const PNG = "\x89PNG\r\n\x1A\n".'abcdefgh';

it('recognises files by their bytes, not their name', function () {
    expect(BillFile::sniff(JPG))->toBe('image/jpeg');
    expect(BillFile::sniff(PNG))->toBe('image/png');
    expect(BillFile::sniff('RIFF'.pack('V', 4).'WEBPVP8 '))->toBe('image/webp');
    expect(BillFile::sniff("%PDF-1.7\n..."))->toBe('application/pdf');
    expect(BillFile::sniff('<?php echo 1;'))->toBeNull();
    expect(BillFile::sniff('GIF89a....'))->toBeNull();
});

it('refuses empty, oversized and unknown files in Bengali', function () {
    expect(BillFile::validate('')['error'])->toContain('Empty');
    expect(BillFile::validate(JPG.str_repeat('x', BillFile::MAX_BYTES))['error'])->toContain('৫ MB');
    expect(BillFile::validate('hello world')['error'])->toContain('ছবি');
    expect(BillFile::validate(JPG))->toMatchArray(['mime' => 'image/jpeg', 'error' => null]);
});

it('cleans uploaded file names', function () {
    expect(BillFile::safeName('../../etc/passwd'))->toBe('passwd');
    expect(BillFile::safeName('C:\\dir\\bill 1.jpg'))->toBe('bill 1.jpg');
    expect(BillFile::safeName("a\x00b<c>.png"))->toBe('abc.png');
    expect(BillFile::safeName(''))->toBe('bill');
    expect(BillFile::safeName(str_repeat('ক', 300)))->toHaveLength(120);
});

const ACCTS = [['id' => 'a1aaaaaaaaaaaaaaaaaaaaaaaa', 'code' => '5100'], ['id' => 'a2aaaaaaaaaaaaaaaaaaaaaaaa', 'code' => '5200']];
const SUPS = [['id' => 's1ssssssssssssssssssssssss', 'name' => 'Rahim Traders', 'active' => true], ['id' => 's2ssssssssssssssssssssssss', 'name' => 'Old Co', 'active' => false]];

it('pre-fills the form from the draft and leaves unknown account codes blank', function () {
    $f = BillDraft::prefill(['kind' => 'bill', 'supplier_name' => 'rahim TRADERS', 'doc_no' => 'INV-9', 'issue_date' => '2026-11-01', 'due_date' => '2026-11-20', 'vat_minor' => 1500, 'total_minor' => 11500,
        'lines' => [['description' => 'Paper', 'amount_minor' => 10000, 'account_code' => '5100'], ['description' => 'Odd', 'amount_minor' => 5, 'account_code' => '9999'], ['description' => 'bad', 'amount_minor' => 0, 'account_code' => '5100']]], ACCTS, SUPS, '2026-11-03');
    expect($f['party_id'])->toBe('s1ssssssssssssssssssssssss');
    expect($f['new_supplier'])->toBe('');
    expect($f['kind'])->toBe('bill');
    expect($f['rows'])->toHaveCount(2);
    expect($f['rows'][0])->toMatchArray(['account_id' => 'a1aaaaaaaaaaaaaaaaaaaaaaaa', 'amount' => '100.00']);
    expect($f['rows'][1])->toMatchArray(['account_id' => '', 'amount' => '0.05']);
    expect($f['vat'])->toBe('15.00');
    expect($f['total'])->toBe('115.00');
    expect($f['due_date'])->toBe('2026-11-20');
});

it('proposes a new supplier when the name is unknown or inactive, and an expense when there is none', function () {
    $f = BillDraft::prefill(['supplier_name' => 'Old Co', 'lines' => []], ACCTS, SUPS, '2026-11-03');
    expect($f['party_id'])->toBe('');
    expect($f['new_supplier'])->toBe('Old Co');
    $g = BillDraft::prefill(['kind' => 'expense', 'lines' => []], ACCTS, SUPS, '2026-11-03');
    expect($g['kind'])->toBe('expense');
    expect($g['new_supplier'])->toBe('');
});

it('never pre-fills a date in the future or a malformed one', function () {
    expect(BillDraft::prefill(['issue_date' => '2027-01-01', 'lines' => []], ACCTS, SUPS, '2026-11-03')['issue_date'])->toBe('2026-11-03');
    expect(BillDraft::prefill(['issue_date' => '2026-13-45', 'due_date' => 'tomorrow', 'lines' => []], ACCTS, SUPS, '2026-11-03'))->toMatchArray(['issue_date' => '2026-11-03', 'due_date' => '']);
});

it('treats text inside the draft as plain data (no markup survives into form values by design: Blade escapes; length is capped)', function () {
    $f = BillDraft::prefill(['supplier_name' => str_repeat('x', 500), 'doc_no' => str_repeat('y', 500), 'lines' => [['description' => str_repeat('z', 500), 'amount_minor' => 100, 'account_code' => '5100']]], ACCTS, [], '2026-11-03');
    expect($f['new_supplier'])->toHaveLength(120);
    expect($f['reference'])->toHaveLength(60);
    expect($f['rows'][0]['description'])->toHaveLength(200);
});

function okInput(array $over = []): array
{
    return $over + ['kind' => 'bill', 'issue_date' => '2026-11-02', 'due_date' => '', 'party_id' => 's1ssssssssssssssssssssssss', 'new_supplier' => '', 'reference' => 'R1', 'vat' => '',
        'rows' => [['account_id' => 'a1aaaaaaaaaaaaaaaaaaaaaaaa', 'description' => 'Paper', 'amount' => '1,000.50'], ['account_id' => '', 'description' => '', 'amount' => '']], 'pay_account' => ''];
}

it('parses a confirmed form into posting arguments', function () {
    $p = BillDraft::parse(okInput(), '2026-11-03');
    expect($p['error'])->toBeNull();
    expect($p['lines'])->toBe([['account_id' => 'a1aaaaaaaaaaaaaaaaaaaaaaaa', 'description' => 'Paper', 'amount_minor' => 100050]]);
    expect($p['vat_minor'])->toBe(0);
    expect($p['party_id'])->toBe('s1ssssssssssssssssssssssss');
    expect($p['pay_account'])->toBeNull();
    $e = BillDraft::parse(okInput(['kind' => 'expense', 'party_id' => '', 'pay_account' => 'c1cccccccccccccccccccccccc', 'vat' => '১৫']), '2026-11-03');
    expect($e['error'])->toBeNull();
    expect($e['pay_account'])->toBe('c1cccccccccccccccccccccccc');
    expect($e['vat_minor'])->toBe(1500);
});

it('rejects bad confirmations with a Bengali reason', function (array $over, string $word) {
    expect(BillDraft::parse(okInput($over), '2026-11-03')['error'])->toContain($word);
})->with([
    [['kind' => 'invoice'], 'Bills No Expenses'],
    [['issue_date' => '2026-11-04'], 'Date'],
    [['issue_date' => '2026-02-30'], 'Date'],
    [['due_date' => '2026-11-01'], 'Due date'],
    [['rows' => []], 'Line'],
    [['rows' => [['account_id' => 'a1aaaaaaaaaaaaaaaaaaaaaaaa', 'description' => '', 'amount' => '-5']]], 'Lineে'],
    [['vat' => 'abc'], 'VAT'],
    [['party_id' => '', 'new_supplier' => ''], 'Supplier'],
    [['party_id' => 'nope'], 'Supplier'],
    [['kind' => 'expense', 'pay_account' => ''], 'ক্যাশ'],
]);

it('keeps only expense/asset accounts that are not control accounts', function () {
    $rows = [
        ['id' => '1', 'code' => '5100', 'type' => 'expense', 'postable' => true, 'active' => true, 'system_key' => null],
        ['id' => '2', 'code' => '1500', 'type' => 'asset', 'postable' => true, 'active' => true, 'system_key' => 'inventory'],
        ['id' => '3', 'code' => '1100', 'type' => 'asset', 'postable' => true, 'active' => true, 'system_key' => 'cash'],
        ['id' => '4', 'code' => '1200', 'type' => 'asset', 'postable' => true, 'active' => true, 'system_key' => 'courier_receivable'],
        ['id' => '5', 'code' => '4100', 'type' => 'income', 'postable' => true, 'active' => true, 'system_key' => 'sales'],
        ['id' => '6', 'code' => '5200', 'type' => 'expense', 'postable' => false, 'active' => true, 'system_key' => null],
        ['id' => '7', 'code' => '5300', 'type' => 'expense', 'postable' => true, 'active' => false, 'system_key' => null],
        ['id' => '8', 'code' => '2100', 'type' => 'liability', 'postable' => true, 'active' => true, 'system_key' => 'payable'],
    ];
    expect(array_column(BillDraft::lineAccounts($rows), 'id'))->toBe(['1', '2']);
});
