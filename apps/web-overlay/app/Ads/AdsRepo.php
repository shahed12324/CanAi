<?php

declare(strict_types=1);

namespace App\Ads;

interface AdsRepo
{
    /** @return list<array{id: string, name: string, external_id: string, currency: string, pixel_id: ?string, status: string, last_synced_at: ?string, sync_error: ?string}> the workspace's ad accounts (never the token) */
    public function accounts(): array;

    /** @return string the account id, or 'limit' */
    public function saveAccount(string $tenantId, string $id, string $name, string $externalId, string $currency, string $cipher, int $version): string;

    /** @return string ok|not_found|bad_input  (empty clears) */
    public function setPixel(string $tenantId, string $id, string $pixel): string;

    /** @return string ok|not_found|busy */
    public function deleteAccount(string $tenantId, string $id): string;

    /**
     * Totals per campaign over the last $days days (copied from Meta), biggest spend first.
     *
     * @return list<array{account_id: string, campaign: string, name: string, status: string, objective: ?string, spend: string, impressions: int, clicks: int, results: int}>
     */
    public function summary(int $days): array;

    /** @return list<array{account_id: string, tenant_id: string, external_id: string, cipher: string, version: int}> accounts due for a sync (marks them as just synced) — admin worker */
    public function syncDue(int $limit, int $minutes): array;

    /**
     * @param  list<array<string, mixed>>  $campaigns
     * @param  list<array<string, mixed>>  $insights
     * @return string ok|not_found|bad_input
     */
    public function store(string $tenantId, string $accountId, array $campaigns, array $insights): string;

    public function fail(string $tenantId, string $accountId, string $note, bool $invalid): string;
}
