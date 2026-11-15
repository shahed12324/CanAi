<?php

declare(strict_types=1);

namespace App\GoogleAds;

/**
 * @phpstan-type Draft array{id: string, user_id: string, author: ?string, account_id: string, account_name: ?string, currency: ?string, name: string, country: string, language: string, final_url: ?string,
 *   headlines: list<string>, descriptions: list<string>, keywords: list<array{t: string, m: string}>, negatives: list<string>, daily_budget: string, days: int, start_date: ?string, status: string,
 *   reject_note: ?string, approver: ?string, approved_at: ?string, updated_at: string,
 *   launch_state: ?string, launch_error: ?string, launch_unknown: bool, launched_at: ?string, remote_budget: ?string, remote_campaign: ?string, remote_adgroup: ?string, remote_ad: ?string}
 */
interface GadsDraftRepo
{
    /** @return list<Draft> newest first */
    public function drafts(): array;

    /** @return ?Draft */
    public function find(string $id): ?array;

    /** @return ?array{max_daily: string, max_total: string} */
    public function caps(): ?array;

    /** @return string ok|bad_input */
    public function saveCaps(string $tenantId, string $userId, string $daily, string $total): string;

    /**
     * @param  array<string, mixed>  $v  values from GadsDraftForm::parse
     * @return string ok|not_found|locked|bad_input|bad_account|limit
     */
    public function save(bool $new, string $tenantId, string $userId, string $id, array $v): string;

    /** @return string ok|not_found|incomplete|unsupported|no_cap|over_cap */
    public function submit(string $tenantId, string $userId, string $id): string;

    /** @return string ok|not_found|incomplete|unsupported|no_cap|over_cap|emergency */
    public function decide(string $tenantId, string $approverId, string $id, bool $approve, ?string $note): string;

    /** @return string ok|not_found|launched */
    public function reopen(string $tenantId, string $userId, string $id): string;

    /** @return string ok|not_found|confirm */
    public function retryLaunch(string $tenantId, string $userId, string $id, bool $confirm): string;

    /** @return string ok|not_found */
    public function delete(string $tenantId, string $userId, string $id): string;
}
