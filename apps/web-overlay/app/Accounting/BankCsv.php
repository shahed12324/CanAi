<?php

declare(strict_types=1);

namespace App\Accounting;

/**
 * Reads a bank / bKash / Nagad statement saved as CSV. Pure.
 * Understood layouts: (a) Date + Description + separate Debit/Withdrawal and Credit/Deposit columns, or (b) Date + Description + one signed Amount column; Balance and Reference optional.
 * Dates are day-first (03/11/2026, 03-11-2026, 2026-11-03, 03-Nov-2026). Columns are found by their header names (English or Bengali) unless the person picks them.
 * Not tested against real exports of any particular bank or wallet - the preview step exists so the person can check before importing.
 */
final class BankCsv
{
    private const NAMES = [
        'date' => ['date', 'txn date', 'transaction date', 'value date', 'posting date', 'tarikh', 'তারিখ', 'completed', 'time'],
        'description' => ['description', 'narration', 'particulars', 'details', 'remarks', 'transaction details', 'type', 'বিবরণ', 'note'],
        'reference' => ['reference', 'ref', 'ref no', 'cheque no', 'chq no', 'trx id', 'trxid', 'transaction id', 'txn id', 'receipt no', 'রেফারেন্স'],
        'debit' => ['debit', 'withdrawal', 'withdrawals', 'dr', 'paid out', 'money out', 'out', 'ডেবিট', 'উত্তোলন'],
        'credit' => ['credit', 'deposit', 'deposits', 'cr', 'paid in', 'money in', 'in', 'ক্রেডিট', 'জমা'],
        'amount' => ['amount', 'txn amount', 'transaction amount', 'টাকা', 'পরিমাণ'],
        'balance' => ['balance', 'running balance', 'closing balance', 'available balance', 'ব্যালান্স'],
    ];

    /**
     * @param array<string, int|null> $pick column index per field when chosen by the person (overrides the header guess)
     * @return array{ok: bool, error: ?string, headers: list<string>, mapping: array<string, ?int>, rows: list<array{date: string, description: string, reference: ?string, amount_minor: int, balance_minor: ?int}>, errors: list<string>, skipped: int}
     */
    public static function parse(string $text, array $pick = [], bool $invert = false): array
    {
        $empty = ['ok' => false, 'error' => null, 'headers' => [], 'mapping' => [], 'rows' => [], 'errors' => [], 'skipped' => 0];
        if (strlen($text) > 2_000_000) {
            return ['error' => 'The file is over 2 MB — split it into smaller parts.'] + $empty;
        }
        if (str_starts_with($text, "\xEF\xBB\xBF")) {
            $text = substr($text, 3);
        }
        if (! mb_check_encoding($text, 'UTF-8')) {
            return ['error' => 'The file is not UTF-8 — in Excel "CSV UTF-8" save as.'] + $empty;
        }
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $first = strtok($text, "\n") ?: '';
        $delim = ',';
        foreach ([';', "\t", '|'] as $d) {
            if (substr_count($first, $d) > substr_count($first, $delim)) {
                $delim = $d;
            }
        }
        $table = [];
        foreach (explode("\n", $text) as $line) {
            if (trim($line) === '') {
                continue;
            }
            $table[] = array_map('trim', str_getcsv($line, $delim, '"', ''));
        }
        // header = first row (within the first 15) that contains a date-like and an amount-like header name
        $hi = null;
        $map = [];
        foreach (array_slice($table, 0, 15, true) as $i => $r) {
            $m = self::guess($r);
            if (isset($m['date']) && (isset($m['amount']) || isset($m['debit']) || isset($m['credit']))) {
                $hi = $i;
                $map = $m;
                break;
            }
        }
        if ($hi === null && $pick === []) {
            return ['error' => 'Column names (Date, Description, Debit/Credit or Amount) were not found — pick the columns yourself below.', 'headers' => $table[0] ?? []] + $empty;
        }
        $headers = $hi === null ? ($table[0] ?? []) : $table[$hi];
        foreach ($pick as $k => $v) {
            if ($v === null) {
                unset($map[$k]);
            } elseif ($v >= 0 && $v < count($headers)) {
                $map[$k] = $v;
            }
        }
        if (! isset($map['date']) || ! (isset($map['amount']) || isset($map['debit']) || isset($map['credit']))) {
            return ['error' => 'Pick the date and (amount or debit/credit) columns.', 'headers' => $headers, 'mapping' => $map] + $empty;
        }
        $rows = [];
        $errors = [];
        $skipped = 0;
        foreach ($table as $i => $r) {
            if ($hi !== null && $i <= $hi) {
                continue;
            }
            if ($hi === null && $i === 0) {
                continue;
            }
            $get = fn (string $k): string => isset($map[$k], $r[$map[$k]]) ? $r[$map[$k]] : '';
            $dateRaw = $get('date');
            $n = $i + 1;
            if ($dateRaw === '') {                                                    // totals, notes, blank-date carry lines: a real line always has a date
                $skipped++;
                continue;
            }
            $d = self::date($dateRaw);
            if ($d === null) {
                if (count(array_filter($r, fn ($c) => $c !== '')) <= 2) {              // "Total", "Opening balance", footer notes
                    $skipped++;

                    continue;
                }
                $errors[] = "Line $n: The date could not be understood ('$dateRaw')";

                continue;
            }
            $amt = null;
            if (isset($map['amount'])) {
                $amt = self::amount($get('amount'));
            } else {
                $dr = self::amount($get('debit'));
                $cr = self::amount($get('credit'));
                if ($dr === false || $cr === false) {
                    $amt = false;
                } elseif (($dr ?? 0) !== 0 && ($cr ?? 0) !== 0) {
                    $errors[] = "Line $n: Money in both debit and credit";

                    continue;
                } else {
                    $amt = abs($cr ?? 0) - abs($dr ?? 0);
                }
            }
            if ($amt === false) {
                $errors[] = "Line $n: The money amount could not be understood";

                continue;
            }
            if ($amt === null) {                                                      // e.g. an "Opening balance" row with only a balance
                $skipped++;

                continue;
            }
            if ($invert) {
                $amt = -$amt;
            }
            if ($amt === 0) {
                $skipped++;

                continue;
            }
            $bal = isset($map['balance']) ? self::amount($get('balance')) : null;
            $rows[] = ['date' => $d, 'description' => mb_substr($get('description'), 0, 300), 'reference' => ($ref = mb_substr($get('reference'), 0, 80)) === '' ? null : $ref,
                'amount_minor' => $amt, 'balance_minor' => is_int($bal) ? $bal : null];
        }
        if (count($rows) > 5000) {
            return ['error' => 'At most 5000 lines in one file — split it.', 'headers' => $headers, 'mapping' => $map] + $empty;
        }

        return ['ok' => $errors === [] && $rows !== [], 'error' => $rows === [] && $errors === [] ? 'No transactions were found.' : null, 'headers' => $headers, 'mapping' => $map + array_fill_keys(array_keys(self::NAMES), null), 'rows' => $rows, 'errors' => array_slice($errors, 0, 30), 'skipped' => $skipped];
    }

    /** @param list<string> $row @return array<string, int> */
    private static function guess(array $row): array
    {
        $m = [];
        foreach ($row as $i => $cell) {
            $c = mb_strtolower(trim(preg_replace('/[\s_.]+/u', ' ', $cell) ?? ''));
            $c = trim($c, " ()\"'");
            foreach (self::NAMES as $field => $names) {
                if (! isset($m[$field]) && in_array($c, $names, true)) {
                    $m[$field] = $i;
                    break;
                }
            }
        }
        // "type" only describes the line when nothing better exists; a real description column wins
        return $m;
    }

    /** Day-first dates -> Y-m-d, or null. */
    public static function date(string $v): ?string
    {
        $v = self::latin(trim($v));
        $v = trim(preg_replace('/[T\s]+\d{1,2}:\d{2}(:\d{2})?(\s*[AaPp][Mm])?.*$/', '', $v) ?? $v);
        $months = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];
        if (preg_match('/\A(\d{4})-(\d{1,2})-(\d{1,2})\z/', $v, $m)) {
            [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/\A(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4}|\d{2})\z/', $v, $m)) {
            [$d, $mo, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]];
            $y = $y < 100 ? 2000 + $y : $y;
        } elseif (preg_match('/\A(\d{1,2})[\s\-\/]([A-Za-z]{3})[A-Za-z]*[\s\-\/,]+(\d{4}|\d{2})\z/', $v, $m) && isset($months[strtolower($m[2])])) {
            [$d, $mo, $y] = [(int) $m[1], $months[strtolower($m[2])], (int) $m[3]];
            $y = $y < 100 ? 2000 + $y : $y;
        } else {
            return null;
        }

        return $y >= 2000 && checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
    }

    /**
     * "1,234.50", "(500)", "-500", "500 Dr", "500 Cr", Bengali digits -> poisha. null = blank, false = not a number.
     */
    public static function amount(string $v): int|false|null
    {
        $v = self::latin(trim($v));
        if ($v === '' || $v === '-') {
            return null;
        }
        $neg = false;
        if (preg_match('/\A\((.*)\)\z/', $v, $m)) {
            $neg = true;
            $v = trim($m[1]);
        }
        if (preg_match('/\A(.*?)\s*(dr|cr)\.?\z/i', $v, $m)) {
            $neg = $neg || strtolower($m[2]) === 'dr';
            $v = trim($m[1]);
        }
        $v = trim(str_replace(['BDT', 'Tk.', 'Tk', '৳', ' '], '', $v));
        if (str_starts_with($v, '-') || str_starts_with($v, '−')) {
            $neg = ! $neg;
            $v = substr($v, str_starts_with($v, '-') ? 1 : strlen('−'));
        } elseif (str_starts_with($v, '+')) {
            $v = substr($v, 1);
        }
        $v = str_replace(',', '', $v);
        if (preg_match('/\A\d{1,13}(?:\.\d{1,2})?\z/', $v) !== 1) {
            return false;
        }
        [$t, $p] = array_pad(explode('.', $v), 2, '0');
        $minor = (int) $t * 100 + (int) str_pad($p, 2, '0');

        return $neg ? -$minor : $minor;
    }

    private static function latin(string $s): string
    {
        return strtr($s, ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9']);
    }
}
