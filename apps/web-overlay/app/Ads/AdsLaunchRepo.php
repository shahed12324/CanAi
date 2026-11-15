<?php

declare(strict_types=1);

namespace App\Ads;

interface AdsLaunchRepo
{
    /**
     * Claims approved drafts (once per attempt) — admin worker.
     *
     * @return list<array<string, mixed>>
     */
    public function claim(int $limit, int $hourlyCap): array;

    /** @param  string  $field  campaign|adset|creative|ad @return string ok|not_found|bad_input */
    public function step(string $tenantId, string $draftId, string $field, string $remoteId): string;

    /** @return string ok|not_found */
    public function done(string $tenantId, string $draftId): string;

    /** @return string ok|not_found */
    public function fail(string $tenantId, string $draftId, string $error, bool $unknown): string;
}
