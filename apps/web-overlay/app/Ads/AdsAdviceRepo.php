<?php

declare(strict_types=1);

namespace App\Ads;

interface AdsAdviceRepo
{
    /** @return list<array{account_id: string, campaign: string, name: string, status: string, spend: string, clicks: int, results: int, days: int}> last 7 days per campaign (days = days with spend) */
    public function stats(): array;

    /** @return string ok|not_active|account|exists */
    public function requestPause(string $tenantId, string $userId, string $accountId, string $campaign, string $reason): string;

    /** @return list<array{account_id: string, campaign: string, name: string, status: string, error: ?string, created_at: string}> the latest requests */
    public function requests(): array;

    /** @return list<array{req_id: string, tenant_id: string, account_id: string, account_ext: string, campaign: string, cipher: string, version: int}> claimed pause requests — admin worker */
    public function claim(int $limit): array;

    public function done(string $tenantId, string $reqId, bool $ok, string $error): string;
}
