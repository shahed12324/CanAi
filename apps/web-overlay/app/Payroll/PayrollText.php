<?php

declare(strict_types=1);

namespace App\Payroll;

use App\Accounting\AccText;

/** Bengali wording for payroll. Amounts are always what the person entered or copied from their own structure - nothing here knows a tax slab or a minimum wage. */
final class PayrollText
{
    public const STATUS = ['draft' => 'draft', 'posted' => 'Posted as (payment pending)', 'paid' => 'Paid', 'void' => 'Cancel'];

    public const SRC = ['structure' => 'Structure', 'bonus' => 'Bonus', 'deduction' => 'One-time deduction', 'adjust' => 'Adjustment'];

    public static function month(string $period): string
    {
        $names = ['01' => 'January', '02' => 'February', '03' => 'March', '04' => 'April', '05' => 'May', '06' => 'June', '07' => 'July', '08' => 'August', '09' => 'September', '10' => 'October', '11' => 'November', '12' => 'December'];
        if (preg_match('/\A(20\d\d)-(0[1-9]|1[0-2])\z/', $period, $m) !== 1) {
            return $period;
        }

        return $names[$m[2]].' '.$m[1];
    }

    public static function error(string $result): ?string
    {
        return match ($result) {
            'ok' => null,
            'bad_input' => 'The data is not valid — give the code (English letters/digits, max 20), name, type, name/label and an amount above 0.',
            'bad_date' => 'The date is not valid (it cannot be tomorrow or later; nor before the start of the month; the payment cannot be before the posting).',
            'code_taken' => 'This code belongs to another employee.',
            'overlap' => 'A line with this label already exists on or after this date.',
            'already_ended' => 'This line has already been ended.',
            'bad_period' => 'The month is not valid (a run for a future month cannot be made).',
            'exists' => 'This month’s run already exists (it cannot be made again unless cancelled).',
            'nothing' => 'No one is due salary this month — add employees and their "Income" add lines.',
            'negative_net' => 'Deductions are exceeding earnings — check the amounts.',
            'not_draft' => 'The run is no longer a draft — it cannot be changed.',
            'no_line' => 'This employee is not in this run.',
            'no_item' => 'The item was not found (a structure line cannot be deleted here — change the structure).',
            'not_posted' => 'The run is not posted to the books or has already been paid.',
            'bad_account' => 'Pick the cash/bank/wallet you will pay from.',
            'nothing_to_pay' => 'There is no net money to pay.',
            'no_system_account' => 'In the chart "Salaries and wages" or "Salary payable" The account is missing — create the chart on the Accounts page.',
            'paid' => 'A paid run cannot be cancelled here.',
            'already_void' => 'This has already been cancelled.',
            default => AccText::postError($result) ?? 'Could not be done.',
        };
    }

    /** One item row's label: "Bonus: Eid bonus" for one-offs, the plain label for structure lines. */
    public static function itemLabel(array $i): string
    {
        $src = (string) ($i['src'] ?? 'structure');

        return $src === 'structure' ? (string) $i['label'] : (self::SRC[$src] ?? $src).': '.(string) $i['label'];
    }
}
