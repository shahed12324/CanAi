<?php

declare(strict_types=1);

namespace App\Accounting;

interface AssetRepo
{
    /** @return list<array<string, mixed>> assets with what they have depreciated so far */
    public function assets(): array;

    /** @return array<string, mixed>|null */
    public function asset(string $id): ?array;

    /** Month-by-month plan with the months already posted marked. @return list<array{period: string, amount_minor: int, done: bool}> */
    public function schedule(string $id): array;

    /** @return string ok|bad_input|bad_account|no_system_account */
    public function register(string $tenantId, string $userId, string $name, string $assetAccountId, string $date, int $cost, int $salvage, int $lifeMonths): string;

    /** Months that still need posting up to a day. @return list<array{period: string, assets: int, total_minor: int}> */
    public function pending(string $asof): array;

    /** @return array{result: string, assets: int, total_minor: int} */
    public function run(string $tenantId, string $userId, string $month): array;

    public function dispose(string $assetId, string $userId, string $date, int $proceedsMinor, ?string $payAccountId): string;

    /** Postable asset accounts that can hold a fixed asset. @return list<array{id: string, code: string, name: string, name_bn: ?string}> */
    public function assetAccounts(): array;

    /** @return list<array{id: string, code: string, name: string, name_bn: ?string}> */
    public function cashAccounts(): array;
}
