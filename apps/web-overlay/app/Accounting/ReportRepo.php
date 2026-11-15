<?php

declare(strict_types=1);

namespace App\Accounting;

interface ReportRepo
{
    /** @return list<array{account_id: string, code: string, name: string, name_bn: ?string, type: string, system_key: ?string, amount_minor: int}> */
    public function profitLoss(string $from, string $to): array;

    /** @return list<array{section: string, account_id: ?string, code: ?string, name: string, name_bn: ?string, amount_minor: int}> */
    public function balanceSheet(string $asof): array;

    /** @return list<array{section: string, account_id: ?string, code: ?string, name: string, name_bn: ?string, amount_minor: int}> */
    public function cashFlow(string $from, string $to): array;
}
