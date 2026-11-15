<?php

declare(strict_types=1);

namespace App\Accounting;

/** What the accounting screens say, and how typed amounts become poisha. Pure. */
final class AccText
{
    /** "1,234.50" / "১২৩৪.৫" -> 123450 poisha; null when it is not a plain positive amount with at most 2 decimals. */
    public static function toMinor(string $v): ?int
    {
        $v = strtr(trim($v), ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9']);
        $v = str_replace(',', '', $v);
        if ($v === '' || preg_match('/\A\d{1,13}(?:\.\d{1,2})?\z/', $v) !== 1) {
            return null;
        }
        [$t, $p] = array_pad(explode('.', $v), 2, '0');
        $minor = (int) $t * 100 + (int) str_pad($p, 2, '0');

        return $minor > 0 ? $minor : null;
    }

    public static function money(int $minor): string
    {
        return number_format($minor / 100, $minor % 100 === 0 ? 0 : 2);
    }

    /**
     * Form rows -> journal lines. Blank rows are skipped; a row with both sides or a bad amount is an error.
     *
     * @param array<int, array{account_id?: string, debit?: string, credit?: string, memo?: string}> $rows
     * @return array{lines: list<array{account_id: string, debit_minor: int, credit_minor: int, memo: ?string}>, error: ?string}
     */
    public static function lines(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $acct = trim((string) ($r['account_id'] ?? ''));
            $d = trim((string) ($r['debit'] ?? ''));
            $c = trim((string) ($r['credit'] ?? ''));
            if ($acct === '' && $d === '' && $c === '') {
                continue;
            }
            if ($acct === '' || preg_match('/\A[0-9a-z]{26}\z/', $acct) !== 1) {
                return ['lines' => [], 'error' => 'Pick an account on every line.'];
            }
            if (($d !== '') === ($c !== '')) {
                return ['lines' => [], 'error' => 'On every line give either a debit or a credit — only one.'];
            }
            $m = self::toMinor($d !== '' ? $d : $c);
            if ($m === null) {
                return ['lines' => [], 'error' => 'The money amount is not valid (for example 1500 or 1500.50).'];
            }
            $memo = trim((string) ($r['memo'] ?? ''));
            $out[] = ['account_id' => $acct, 'debit_minor' => $d !== '' ? $m : 0, 'credit_minor' => $c !== '' ? $m : 0, 'memo' => $memo === '' ? null : mb_substr($memo, 0, 200)];
        }

        return ['lines' => $out, 'error' => null];
    }

    public static function postError(string $result): ?string
    {
        return match ($result) {
            'ok' => null,
            'bad_memo' => 'Write a description.',
            'bad_date' => 'The date is not valid (it cannot be after tomorrow or before the year 2000).',
            'locked' => 'The books for this date are closed (locked) — give a date in the open period.',
            'too_few_lines' => 'At least two lines are needed.',
            'bad_line' => 'A line amount is not valid.',
            'unbalanced' => 'Debit and credit are not equal.',
            'bad_account', 'bad_source' => 'Account not found.',
            'inactive_account' => 'An account is closed.',
            'not_postable' => 'An account is a category heading — entries cannot be posted to it directly.',
            default => 'The entry could not be made.',
        };
    }

    public static function reverseError(string $result): ?string
    {
        return match ($result) {
            'ok' => null,
            'already_reversed' => 'This has already been cancelled (reversed).',
            'is_reversal' => 'A reversal entry cannot be reversed again — make a new entry.',
            'locked' => 'The books for this date are closed (locked).',
            'bad_date' => 'The date is not valid.',
            default => 'Could not be cancelled.',
        };
    }

    /**
     * Invoice / bill / expense lines from the form (account, description, amount).
     *
     * @param array<int, array{account_id?: string, description?: string, amount?: string}> $rows
     * @return array{lines: list<array{account_id: string, description: ?string, amount_minor: int}>, error: ?string}
     */
    public static function docLines(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $acct = trim((string) ($r['account_id'] ?? ''));
            $amt = trim((string) ($r['amount'] ?? ''));
            if ($acct === '' && $amt === '' && trim((string) ($r['description'] ?? '')) === '') {
                continue;
            }
            $m = self::toMinor($amt);
            if (preg_match('/\A[0-9a-z]{26}\z/', $acct) !== 1 || $m === null) {
                return ['lines' => [], 'error' => 'On every line give an account and a valid money amount.'];
            }
            $d = trim((string) ($r['description'] ?? ''));
            $out[] = ['account_id' => $acct, 'description' => $d === '' ? null : mb_substr($d, 0, 200), 'amount_minor' => $m];
        }

        return $out === [] ? ['lines' => [], 'error' => 'Give at least one line.'] : ['lines' => $out, 'error' => null];
    }

    public static function docError(string $result): ?string
    {
        return match ($result) {
            'ok' => null,
            'bad_party' => 'Pick a valid customer/supplier (must be active).',
            'bad_lines', 'bad_line' => 'A line amount is not valid.',
            'bad_line_account' => 'The line’s account is not right for this type of document (income on invoices, expense or asset on bills/expenses).',
            'bad_vat' => 'The VAT amount is not valid.',
            'bad_due' => 'The due date cannot be before the issue date.',
            'no_system_account' => 'The chart of accounts is incomplete (no receivable/payable/VAT accounts) — create the chart.',
            'bad_pay_account' => 'Pick a cash/bank account for the expense.',
            'locked' => 'The books for this date are closed (locked).',
            'bad_date' => 'The date is not valid.',
            'bad_memo' => 'Give a description.',
            default => 'Could not be done.',
        };
    }

    public static function payError(string $result): ?string
    {
        return match ($result) {
            'ok' => null,
            'bad_amount' => 'The money amount is not valid.',
            'bad_account' => 'Pick the cash/bank account the money will come into/go out of.',
            'not_payable' => 'A payment cannot be taken on this document (paid, cancelled or a direct expense).',
            'overpay' => 'You cannot pay more than what is due.',
            'locked' => 'The books for this date are closed (locked).',
            'bad_date' => 'The date is not valid.',
            default => 'The payment could not be made.',
        };
    }

    public static function voidError(string $result): ?string
    {
        return match ($result) {
            'ok' => null,
            'already_void' => 'Already cancelled.',
            'has_payments' => 'First cancel its payments.',
            'locked' => 'The books for this date are closed (locked) — give a date in the open period.',
            'bad_date' => 'The date is not valid.',
            default => 'Could not be cancelled.',
        };
    }

    public static function bankImportError(string $result, int $row = 0): ?string
    {
        return match ($result) {
            'ok' => null,
            'bad_account' => 'This is not an account marked as cash/bank.',
            'bad_rows' => 'The number of lines must be between 1 and 5000.',
            'bad_row' => 'Line '.$row.' is not valid (date, amount or description).',
            default => 'Could not import.',
        };
    }

    public static function bankMatchError(string $result): ?string
    {
        return match ($result) {
            'ok' => null,
            'not_open' => 'This line is no longer open.',
            'not_matched' => 'The line is not matched.',
            'not_ignored' => 'The line is not skipped.',
            'bad_journal_line' => 'This journal line’s account or amount does not match.',
            'taken' => 'This journal line is already matched with another statement line.',
            'bad_account' => 'The counter account must be active, postable and different.',
            default => self::postError($result) ?? 'Could not be done.',
        };
    }

    public static function assetError(string $result): ?string
    {
        return match ($result) {
            'ok' => null,
            'bad_input' => 'Give the name, date (not after today), cost, residual value (less than cost) and life (1–600 months) correctly.',
            'bad_account' => 'A fixed asset cannot be kept in this account — "Fixed assets" pick a group account.',
            'no_system_account' => 'In the chart "Accumulated depreciation" or "Depreciation expense" The account is not active.',
            default => 'Could not register.',
        };
    }

    public static function depreciationError(string $result): ?string
    {
        return match ($result) {
            'ok' => null,
            'nothing_to_do' => 'This month’s depreciation is already given (or no asset has depreciation).',
            'bad_month' => 'The month is not valid (no depreciation for a future month).',
            default => self::postError($result) ?? 'Depreciation could not be given.',
        };
    }

    public static function disposeError(string $result): ?string
    {
        return match ($result) {
            'ok' => null,
            'not_active' => 'The asset has already been sold/disposed.',
            'bad_date' => 'The date is not valid (not before purchase or after tomorrow).',
            'bad_proceeds' => 'The sale amount is not valid.',
            'depreciate_first' => 'First give depreciation for all earlier months.',
            'bad_pay_account' => 'Pick the cash/bank account the money came into.',
            'no_system_account' => 'For profit/loss "Other income" or "Miscellaneous expense" The account is not active.',
            default => self::postError($result) ?? 'Could not be done.',
        };
    }

    public static function costError(string $result): ?string
    {
        return match ($result) {
            'ok' => null,
            'bad_cost' => 'The cost amount is not valid.',
            'bad_date' => 'The date is not valid (cannot be after today).',
            default => 'Could not be saved.',
        };
    }

    public static function revalueError(string $result): ?string
    {
        return match ($result) {
            'ok' => null,
            'no_difference' => 'The books and stock value are already equal — nothing to do.',
            'no_system_account' => 'In the chart "Inventory" The account is not active.',
            'bad_account' => 'The counter account must be an active income/expense account (usually cost of goods sold).',
            'bad_date' => 'The date is not valid (cannot be after today).',
            default => self::postError($result) ?? 'Could not be done.',
        };
    }

    public static function autopostError(string $result): ?string
    {
        return match ($result) {
            'ok' => null,
            'not_seeded' => 'First create the chart of accounts (Accounts page → "Create chart").',
            'no_system_account' => 'In the chart one of these accounts is missing or closed: sales / cash / courier receivable / delivery income / cost of goods sold / inventory / courier cost.',
            default => 'Could not be done.',
        };
    }

    /** Why a queued order could not be posted (the code the database stored). */
    public static function autopostFailure(?string $code): string
    {
        $code = (string) $code;
        if (str_starts_with($code, 'error:')) {
            return 'Unexpected error ('.substr($code, 6).') — Try again, otherwise skip and enter by hand.';
        }

        return match ($code) {
            'locked' => 'The books for this date are closed (locked) — remove the lock, or skip and enter by hand.',
            'no_system_account' => 'A required account (sales, cash/courier receivable, delivery income, cost of goods sold, inventory) is missing or closed.',
            'already_reversed' => 'The entry has already been cancelled.',
            '' => '',
            default => self::postError($code) ?? 'Could not be posted.',
        };
    }

    /** Why a bill scan did not produce a draft (the code stored on the scan). */
    public static function billScanFailure(?string $code): string
    {
        $code = (string) $code;
        if (str_starts_with($code, 'unsupported:')) {
            return match (substr($code, 12)) {
                'encrypted_pdf' => 'PDF-password given — give a version without a password.',
                'unreadable_pdf', 'unreadable_image' => 'The file could not be opened — it may be corrupted.',
                'too_large' => 'The file is too large.',
                default => 'This file cannot be read — give an image (JPG/PNG/WebP) or a PDF.',
            };
        }
        if (str_starts_with($code, 'provider:')) {
            return 'AI The service is not responding right now — try again shortly. (Credits were not deducted)';
        }

        return match ($code) {
            'no_model' => 'No AI model that can read images is enabled in your plan — ask the admin "vision" to enable a model with that capability. (Credits were not deducted)',
            'insufficient_credits' => 'Not enough credits. Add credits.',
            'credits' => 'Problem with the credit system — try again shortly.',
            'unreadable' => 'The bill could not be read — give a clearer image. (The AI service cost was deducted)',
            'bad_draft' => 'AI-reply was not usable. (The AI service cost was deducted)',
            'timeout' => 'Time ran out — give it again.',
            '' => '',
            default => 'Could not scan — try again.',
        };
    }

    public static function billPostError(string $result): ?string
    {
        return match ($result) {
            'ok' => null,
            'not_ready' => 'This scan is no longer open for posting (already used or cancelled).',
            'bad_kind' => 'Bill or expense — pick one.',
            default => self::docError($result) ?? 'Could not be done.',
        };
    }

    public static function vatError(string $result): ?string
    {
        return match ($result) {
            'ok', 'already' => null,
            'bad_bin' => 'BIN Must be a number of 9 to 13 digits, and is required if registered.',
            'bad_period' => 'Pick monthly or quarterly (the period is not valid).',
            'bad_input' => 'Give the code (English letters/digits), name and a 0–100% rate correctly.',
            'bad_date' => 'The date is not valid (not before 2012-07-01 or too far in the future; the file date cannot be after today).',
            'overlap' => 'A rate for this code already exists on or after this date — a new rate can be given only from a date after the latest rate.',
            'not_purchase' => 'Credit can be marked only on bills/expenses.',
            'no_vat' => 'This document has no VAT.',
            'need_reason' => 'If credit is not taken, write a reason.',
            'filed' => 'The return for this period is already recorded as filed — the draft cannot be changed.',
            'not_draft' => 'This is no longer a draft.',
            'bad_ref' => 'The reference is over 60 characters.',
            default => 'Could not be done.',
        };
    }

    public static function auditError(string $result): ?string
    {
        return match ($result) {
            'ok' => null,
            'need_note' => '"No problem" write a reason to dismiss.',
            'bad_status' => 'The status is not valid.',
            'bad_input' => 'Give the numbers within limits (duplicate days 0–30, multiple 2–100, history 3–100, late 1–365, amount above 0) / give name and order (0–1000).',
            'bad_period' => 'The month is not valid (a future month cannot be ticked).',
            'bad_date' => 'The date is not valid (it cannot be tomorrow or later).',
            'already' => 'This is already done.',
            'missing' => 'The tick could not be found.',
            default => 'Could not be done.',
        };
    }

    public static function calError(string $result): ?string
    {
        return match ($result) {
            'ok' => null,
            'bad_input' => 'Give the name, type, repeat type and reminder days (0–60) correctly.',
            'bad_schedule' => 'The date details are not valid (monthly: day; quarterly: day and first month of cycle 1–3; yearly: day and month; once: date).',
            'bad_due' => 'This is not the scheduled date for this task.',
            'bad_date' => 'The completion date is not valid (it cannot be tomorrow or later).',
            'already' => 'This is already completed or the list already exists.',
            'missing' => 'The tick could not be found.',
            default => 'Could not be done.',
        };
    }

    public static function taxError(string $result): ?string
    {
        return match ($result) {
            'ok' => null,
            'bad_items' => 'There is an error in the deducted tax line (type, amount, must be less than the total, max 5).',
            'bad_amount' => 'The money amount is not valid.',
            'overpay' => 'You cannot pay more than the remaining amount.',
            'not_payable' => 'No more payments can be taken on this document (expense, paid or cancelled).',
            'bad_account' => 'Pick the cash/bank/wallet account the money is going from/coming into.',
            'no_system_account' => 'In the chart "Tax payable (TDS/AIT)" or the receivable/payable account is not active — create the chart on the Accounts page.',
            'nothing' => 'There is no deducted tax to deposit.',
            'bad_date' => 'The date is not valid (a deposit cannot be before the date of deduction or after tomorrow; the challan reference is at most 60 characters).',
            'bad_kind' => 'The type is not valid.',
            'bad_cert' => 'The certificate number is over 40 characters.',
            'bad_input' => 'Give the code (English letters/digits), name and a 0–100% rate correctly.',
            'overlap' => 'A rule of this type and code already exists on or after this date.',
            'already_void' => 'This has already been cancelled.',
            default => self::postError($result) ?? 'Could not be done.',
        };
    }

    public static function settleError(string $result): ?string
    {
        return match ($result) {
            'ok' => null,
            'bad_input' => 'Give the courier, total money received for COD (above 0) and the courier charge (not above the total) correctly.',
            'bad_date' => 'The date is not valid (cannot be after tomorrow).',
            'bad_account' => 'Pick the cash/bank/wallet account the money came into.',
            'no_system_account' => 'In the chart "receivable from the courier" or "Delivery and courier costs" The account is not active.',
            'exceeds_receivable' => 'This courier does not owe more than this — see the receivable calculation (fix the failed postings first).',
            'already_void' => 'This has already been cancelled.',
            default => self::postError($result) ?? 'Could not be done.',
        };
    }

    public static function courierBn(string $c): string
    {
        return ['steadfast' => 'Steadfast', 'pathao' => 'Pathao', 'redx' => 'RedX'][$c] ?? $c;
    }

    public static function kindBn(string $k): string
    {
        return ['invoice' => 'Invoices', 'bill' => 'Bills', 'expense' => 'Expenses'][$k] ?? $k;
    }

    public static function statusBn(string $s): string
    {
        return ['open' => 'Outstanding', 'paid' => 'Paid', 'void' => 'Cancel'][$s] ?? $s;
    }

    public static function bucketBn(string $b): string
    {
        return ['current' => 'Within term', '1-30' => '1–30 days', '31-60' => '31–60 days', '61-90' => '61–90 days', '90+' => '90+ days'][$b] ?? $b;
    }

    public static function typeBn(string $t): string
    {
        return ['asset' => 'Asset', 'liability' => 'Liabilities', 'equity' => 'Owner’s equity', 'income' => 'Income', 'expense' => 'Expense'][$t] ?? $t;
    }

    public static function accountError(string $result): ?string
    {
        return match ($result) {
            'ok' => null,
            'bad_code' => 'The code must be a number of 3–10 digits.',
            'duplicate_code' => 'This code already exists.',
            'bad_parent' => 'The parent must be of the same type.',
            default => 'Give the name and type correctly.',
        };
    }

    public static function lockError(string $result): ?string
    {
        return match ($result) {
            'ok' => null,
            'not_forward' => 'A lock can only move forward (give a date after the earlier lock).',
            'bad_date' => 'The date is not valid (cannot be after today).',
            'no_reason' => 'Write a reason for opening (at least 5 characters).',
            'not_back' => 'To open, give a date before the current lock, or open fully.',
            'not_locked' => 'There is no lock now.',
            default => 'Could not be done.',
        };
    }
}
