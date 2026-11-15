<?php

declare(strict_types=1);

namespace App\Audit;

use App\Accounting\AccText;

/** Bengali wording for the auditor's findings and checks. A finding is a question for a person, never a verdict - the wording says "check", not "wrong". */
final class AuditText
{
    public const RULES = [
        'dup_doc' => 'Possible duplicate document',
        'dup_payment' => 'Possible duplicate payment',
        'dup_entry' => 'Possible duplicate journal entry',
        'outlier' => 'Unusually large amount',
        'backdated' => 'Entry made long after the date (back-dated)',
        'round_manual' => 'Manual entry with a large round amount',
        'cash_large' => 'Large cash payment',
        'numbering_gap' => 'Gap in entry numbers',
    ];

    public const SEVERITY = ['high' => 'High', 'medium' => 'Medium', 'low' => 'Low'];

    public const STATUS = ['open' => 'Open', 'confirmed' => 'Confirmed (there is a problem)', 'dismissed' => 'Dismissed (no problem)'];

    public const KIND = ['invoice' => 'Invoices', 'bill' => 'Bills', 'expense' => 'Expenses'];

    public const HEALTH = [
        'open_high' => 'High-priority open findings',
        'books_balanced' => 'Book debits and credits match',
        'stale_bank_lines' => 'Unmatched statement lines older than 30 days',
        'overdue_bills' => 'Overdue unpaid bills',
        'tax_undeposited' => 'Tax deducted but not deposited',
        'period_locked' => 'Books for the last 2 months are closed (locked)',
    ];

    /** @param  array<string, mixed>  $detail */
    public static function explain(string $rule, int $amountMinor, array $detail): string
    {
        $m = AccText::money($amountMinor).' ৳';
        $k = self::KIND[(string) ($detail['kind'] ?? '')] ?? 'Document';

        return match ($rule) {
            'dup_doc' => sprintf('%s %s and %s — same party, same amount (%s), only %s days apart. Was the same thing entered twice?', $k, $detail['first'] ?? '?', $detail['second'] ?? '?', $m, self::n($detail['days'] ?? 0)),
            'dup_payment' => sprintf('%s On document number %s on date %s there are several payments of the same amount (%s). Was one given a second time by mistake?', $detail['doc'] ?? '?', $detail['date'] ?? '?', $m),
            'dup_entry' => sprintf('Entry #%s and #%s — Same date (%s), same description, same amount (%s). Is any of them a duplicate?', $detail['first'] ?? '?', $detail['second'] ?? '?', $detail['date'] ?? '?', $m),
            'outlier' => sprintf('%s %s-has amount %s — about %s times this party’s usual amount (%s ৳). Is the amount correct?', $k, $detail['number'] ?? '?', $m, AccText::money((int) ($detail['usual'] ?? 0)), self::n($detail['times'] ?? 0)),
            'backdated' => sprintf('Entry #%s (%s date, %s) was entered about %s days later. What was the reason for the delay?', $detail['number'] ?? '?', $detail['date'] ?? '?', $m, self::n($detail['late_days'] ?? 0)),
            'round_manual' => sprintf('Manual entry #%s (%s) — Amount %s, a perfectly round number. Is there a supporting voucher?', $detail['number'] ?? '?', $detail['date'] ?? '?', $m),
            'cash_large' => sprintf('%s %s — %s was paid in cash, which is equal to or above your limit. Could it have been paid through the bank?', $k, $detail['number'] ?? '?', $m),
            'numbering_gap' => sprintf('Entry numbers %s to %s are missing. Numbers are never deleted or reused — it needs to be looked into.', self::n($detail['from'] ?? '?'), self::n($detail['to'] ?? '?')),
            default => 'Review needed.',
        };
    }

    private static function n(mixed $v): string
    {
        if (! is_numeric($v)) {
            return (string) $v;
        }
        $f = (float) $v;
        $s = floor($f) === $f ? (string) (int) $f : number_format($f, 1, '.', '');

        return $s;
    }

    /** One line for a health row: what is wrong (or fine) and by how much. */
    public static function healthLine(string $key, bool $ok, int $count, int $amountMinor): string
    {
        if ($ok) {
            return 'OK';
        }

        return match ($key) {
            'books_balanced' => 'Difference between debit and credit '.AccText::money($amountMinor).' ৳ — look at it immediately',
            'period_locked' => 'No month is closed up to the recent period',
            default => self::n($count).' items'.($amountMinor > 0 ? ', Total '.AccText::money($amountMinor).' ৳' : ''),
        };
    }

    /** Current month in Asia/Dhaka terms is supplied by the caller; this keeps only well-formed YYYY-MM. */
    public static function period(?string $raw, string $current): string
    {
        $p = trim((string) $raw);

        return preg_match('/\A20\d\d-(0[1-9]|1[0-2])\z/', $p) === 1 && $p <= $current ? $p : $current;
    }
}
