<?php

declare(strict_types=1);

namespace App\Ads;

/**
 * @phpstan-type Draft array{id: string, user_id: string, author: ?string, account_id: string, account_name: ?string, currency: ?string, name: string, objective: string, country: string, age_min: int, age_max: int, gender: string,
 *   interests: ?string, primary_text: string, headline: string, description: string, cta: string, link_url: ?string, daily_budget: string, days: int, start_date: ?string, status: string, reject_note: ?string,
 *   approver: ?string, approved_at: ?string, updated_at: string, page_account_id: ?string, page_name: ?string, launch_state: ?string, launch_error: ?string, launch_unknown: bool, launched_at: ?string,
 *   remote_campaign: ?string, remote_adset: ?string, remote_creative: ?string, remote_ad: ?string}
 */
interface AdsDraftRepo
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
     * @param  array<string, mixed>  $v  values from AdsDraftForm::parse
     * @return string ok|not_found|locked|bad_input|bad_account|limit
     */
    public function save(bool $new, string $tenantId, string $userId, string $id, array $v): string;

    /** @return string ok|not_found|locked|bad_page */
    public function setPage(string $tenantId, string $userId, string $id, ?string $pageAccountId): string;

    /** @return string ok|not_found|confirm  (confirm = the last attempt's outcome was unknown; the person must say they checked Ads Manager) */
    public function retryLaunch(string $tenantId, string $userId, string $id, bool $confirm): string;

    /** @return string ok|not_found|incomplete|unsupported|no_page|no_pixel|no_cap|over_cap */
    public function submit(string $tenantId, string $userId, string $id): string;

    /** @return string ok|not_found|incomplete|unsupported|no_page|no_pixel|no_cap|over_cap|emergency */
    public function decide(string $tenantId, string $approverId, string $id, bool $approve, ?string $note): string;

    /** @return string ok|not_found|launched */
    public function reopen(string $tenantId, string $userId, string $id): string;

    /** @return string ok|not_found */
    public function delete(string $tenantId, string $userId, string $id): string;
}
