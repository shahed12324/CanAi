<?php

declare(strict_types=1);

namespace App\BillScan;

use App\Accounting\AccText;

/**
 * The review form for a scanned bill. prefill() turns what the model read into form defaults (the person can change everything);
 * parse() turns what the person confirmed into the arguments of bill_scan_post. Nothing here trusts the model: an unknown account
 * code simply leaves that line's account blank for the person to choose. Pure.
 */
final class BillDraft
{
    /**
     * @param array<string, mixed>                                        $x         the stored draft
     * @param list<array{id: string, code: string}>                       $accounts  accounts a bill/expense line may use
     * @param list<array{id: string, name: string, active: bool}>         $suppliers
     * @return array{kind: string, party_id: string, new_supplier: string, issue_date: string, due_date: string, reference: string, vat: string, rows: list<array{account_id: string, description: string, amount: string}>, total: string}
     */
    public static function prefill(array $x, array $accounts, array $suppliers, string $today): array
    {
        $byCode = [];
        foreach ($accounts as $a) {
            $byCode[$a['code']] = $a['id'];
        }
        $name = trim((string) ($x['supplier_name'] ?? ''));
        $party = '';
        foreach ($suppliers as $s) {
            if ($name !== '' && $s['active'] && mb_strtolower($s['name']) === mb_strtolower($name)) {
                $party = $s['id'];
            }
        }
        $rows = [];
        foreach ((array) ($x['lines'] ?? []) as $l) {
            if (! is_array($l) || ! isset($l['amount_minor']) || ! is_int($l['amount_minor']) || $l['amount_minor'] < 1) {
                continue;
            }
            $rows[] = ['account_id' => $byCode[(string) ($l['account_code'] ?? '')] ?? '', 'description' => mb_substr(trim((string) ($l['description'] ?? '')), 0, 200), 'amount' => self::plain($l['amount_minor'])];
        }
        $vat = isset($x['vat_minor']) && is_int($x['vat_minor']) && $x['vat_minor'] > 0 ? self::plain($x['vat_minor']) : '';
        $issue = self::date($x['issue_date'] ?? null);

        return [
            'kind' => $name !== '' ? 'bill' : (($x['kind'] ?? '') === 'bill' ? 'bill' : 'expense'),
            'party_id' => $party,
            'new_supplier' => $party === '' ? mb_substr($name, 0, 120) : '',
            'issue_date' => $issue === null || $issue > $today ? $today : $issue,
            'due_date' => self::date($x['due_date'] ?? null) ?? '',
            'reference' => mb_substr(trim((string) ($x['doc_no'] ?? '')), 0, 60),
            'vat' => $vat,
            'rows' => $rows,
            'total' => isset($x['total_minor']) && is_int($x['total_minor']) && $x['total_minor'] > 0 ? self::plain($x['total_minor']) : '',
        ];
    }

    /**
     * @param  array<string, mixed>  $in
     * @return array{error: ?string, kind?: string, party_id?: ?string, new_supplier?: string, date?: string, due?: ?string, reference?: ?string, lines?: list<array{account_id: string, description: ?string, amount_minor: int}>, vat_minor?: int, pay_account?: ?string}
     */
    public static function parse(array $in, string $today): array
    {
        $kind = (string) ($in['kind'] ?? '');
        if (! in_array($kind, ['bill', 'expense'], true)) {
            return ['error' => 'Bill or expense — pick one.'];
        }
        $date = self::date($in['issue_date'] ?? null);
        if ($date === null || $date > $today) {
            return ['error' => 'The date is not valid (a date after today is not allowed).'];
        }
        $dueRaw = trim((string) ($in['due_date'] ?? ''));
        $due = $dueRaw === '' ? null : self::date($dueRaw);
        if ($dueRaw !== '' && ($due === null || $due < $date)) {
            return ['error' => 'The end date is not valid or is before the start date.'];
        }
        $p = AccText::docLines(is_array($in['rows'] ?? null) ? array_slice($in['rows'], 0, 50) : []);
        if ($p['error'] !== null) {
            return ['error' => $p['error']];
        }
        $vatRaw = trim((string) ($in['vat'] ?? ''));
        $vat = $vatRaw === '' ? 0 : AccText::toMinor($vatRaw);
        if ($vat === null) {
            return ['error' => 'The VAT amount is not valid.'];
        }
        $party = trim((string) ($in['party_id'] ?? ''));
        $new = trim((string) ($in['new_supplier'] ?? ''));
        if ($party !== '' && preg_match('/\A[0-9a-z]{26}\z/', $party) !== 1) {
            return ['error' => 'The supplier is not valid.'];
        }
        if ($kind === 'bill' && $party === '' && $new === '') {
            return ['error' => 'Pick a supplier for the bill or give a new name.'];
        }
        $pay = trim((string) ($in['pay_account'] ?? ''));
        if ($kind === 'expense' && preg_match('/\A[0-9a-z]{26}\z/', $pay) !== 1) {
            return ['error' => 'Pick the cash/bank account the expense money went from.'];
        }
        $ref = trim((string) ($in['reference'] ?? ''));

        return ['error' => null, 'kind' => $kind, 'party_id' => $party === '' ? null : $party, 'new_supplier' => mb_substr($new, 0, 120), 'date' => $date, 'due' => $due,
            'reference' => $ref === '' ? null : mb_substr($ref, 0, 60), 'lines' => $p['lines'], 'vat_minor' => $vat, 'pay_account' => $kind === 'expense' ? $pay : null];
    }

    /**
     * Accounts a bill/expense line may use: active, postable, expense or asset, and not a cash/receivable/payable control account.
     *
     * @param  list<array<string, mixed>>  $accounts  rows of AccountingRepo::accounts()
     * @return list<array<string, mixed>>
     */
    public static function lineAccounts(array $accounts): array
    {
        return array_values(array_filter($accounts, fn ($a) => ($a['postable'] ?? false) && ($a['active'] ?? false) && in_array($a['type'], ['expense', 'asset'], true)
            && ! in_array($a['system_key'] ?? null, ['receivable', 'courier_receivable', 'payable', 'cash', 'bank', 'mobile_wallet'], true)));
    }

    /** 123450 -> "1234.50": always a plain decimal the form can read back. */
    public static function plain(int $minor): string
    {
        return intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }

    private static function date(mixed $v): ?string
    {
        if (! is_string($v) || preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $v, $m) !== 1 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return $v;
    }
}
