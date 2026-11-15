<?php

declare(strict_types=1);

namespace App\GoogleAds;

interface GadsAdviceRepo
{
    /** @return list<array{account_id: string, campaign: string, name: string, status: string, cost: string, clicks: int, conversions: string, days: int}> last 7 days per ENABLED campaign (days = days with cost) */
    public function stats(): array;

    /** @return string ok|not_active|account|exists */
    public function requestPause(string $tenantId, string $userId, string $accountId, string $campaign, string $reason): string;

    /** @return list<array{account_id: string, campaign: string, name: string, status: string, error: ?string, created_at: string}> the latest requests */
    public function requests(): array;

    /** @return list<array{req_id: string, tenant_id: string, account_id: string, customer_id: string, login_id: ?string, campaign: string, cipher: string, version: int}> claimed pause requests — admin worker */
    public function claim(int $limit): array;

    public function done(string $tenantId, string $reqId, bool $ok, string $error): string;
}
