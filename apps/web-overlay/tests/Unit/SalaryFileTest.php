<?php

use App\Payroll\SalaryFile;

function sfLine(string $id, string $code, string $name, int $net): array
{
    return ['employee_id' => $id, 'code' => $code, 'name' => $name, 'net_minor' => $net];
}

function sfDetails(): array
{
    return [
        'e1' => ['method' => 'bank', 'bank_name' => 'ডাচ-বাংলা', 'branch' => 'গুলশান', 'routing_no' => '090261234', 'account_no' => '1234567890', 'wallet_no' => null],
        'e2' => ['method' => 'bkash', 'bank_name' => null, 'branch' => null, 'routing_no' => null, 'account_no' => null, 'wallet_no' => '01712345678'],
        'e3' => ['method' => 'nagad', 'bank_name' => null, 'branch' => null, 'routing_no' => null, 'account_no' => null, 'wallet_no' => '01812345678'],
        'e4' => ['method' => 'cash', 'bank_name' => null, 'branch' => null, 'routing_no' => null, 'account_no' => null, 'wallet_no' => null],
    ];
}

it('groups each employee by pay method and totals them', function () {
    $lines = [sfLine('e1', 'A', 'আলি', 3400000), sfLine('e2', 'B', 'বেলা', 2000050), sfLine('e3', 'C', 'চন্দন', 100), sfLine('e4', 'D', 'দিলু', 5000), sfLine('e5', 'E', 'এমা', 7000)];
    $p = SalaryFile::plan($lines, sfDetails());
    expect(count($p['rows']['bank']))->toBe(1);
    expect(count($p['rows']['bkash']))->toBe(1);
    expect(count($p['rows']['nagad']))->toBe(1);
    expect(count($p['rows']['cash']))->toBe(1);
    expect($p['totals'])->toBe(['bank' => 3400000, 'bkash' => 2000050, 'nagad' => 100, 'cash' => 5000]);
    expect(array_column($p['missing'], 'code'))->toBe(['E']);
});

it('skips people with nothing to pay and flags unknown methods as missing', function () {
    $d = sfDetails();
    $d['e6'] = ['method' => 'rocket', 'bank_name' => null, 'branch' => null, 'routing_no' => null, 'account_no' => null, 'wallet_no' => null];
    $p = SalaryFile::plan([sfLine('e1', 'A', 'আলি', 0), sfLine('e2', 'B', 'বেলা', -5), sfLine('e6', 'F', 'ফরিদ', 100)], $d);
    expect($p['rows']['bank'])->toBe([]);
    expect($p['rows']['bkash'])->toBe([]);
    expect(array_column($p['missing'], 'code'))->toBe(['F']);
});

it('writes a bank csv with taka amounts and a reference', function () {
    $p = SalaryFile::plan([sfLine('e1', 'A', 'আলি', 3400005)], sfDetails());
    $csv = SalaryFile::csv('bank', $p['rows']['bank'], '2026-11');
    expect(str_starts_with($csv, "\xEF\xBB\xBF"))->toBeTrue();
    $lines = explode("\r\n", trim($csv));
    expect(count($lines))->toBe(2);
    expect($lines[0])->toContain('Account number');
    expect($lines[1])->toBe('A,আলি,ডাচ-বাংলা,গুলশান,090261234,1234567890,34000.05,"Salary 2026-11"');
});

it('writes a wallet csv and nothing for cash or an empty channel', function () {
    $p = SalaryFile::plan([sfLine('e2', 'B', 'বেলা', 2000050), sfLine('e4', 'D', 'দিলু', 5000)], sfDetails());
    $csv = SalaryFile::csv('bkash', $p['rows']['bkash'], '2026-11');
    expect(explode("\r\n", trim($csv))[1])->toBe('B,বেলা,01712345678,20000.50,"Salary 2026-11"');
    expect(SalaryFile::csv('cash', $p['rows']['cash'], '2026-11'))->toBeNull();
    expect(SalaryFile::csv('nagad', $p['rows']['nagad'], '2026-11'))->toBeNull();
    expect(SalaryFile::csv('bank', [], '2026-11'))->toBeNull();
    expect(SalaryFile::csv('rocket', $p['rows']['bkash'], '2026-11'))->toBeNull();
});

it('keeps spreadsheet formulas out of the file', function () {
    $d = sfDetails();
    $p = SalaryFile::plan([sfLine('e2', '=1+1', '@cmd', 100)], $d);
    $csv = SalaryFile::csv('bkash', $p['rows']['bkash'], '2026-11');
    expect(str_contains($csv, "'=1+1"))->toBeTrue();
    expect(str_contains($csv, "'@cmd"))->toBeTrue();
});

it('shows only the last four characters of a number', function () {
    expect(SalaryFile::mask('01712345678'))->toBe('•••••••5678');
    expect(SalaryFile::mask('1234'))->toBe('••••');
    expect(SalaryFile::mask('12'))->toBe('••');
    expect(SalaryFile::mask(null))->toBe('');
    expect(SalaryFile::mask('12345'))->toBe('•2345');
});
