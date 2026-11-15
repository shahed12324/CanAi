<?php

declare(strict_types=1);

namespace App\Studio;

/**
 * Designs are personal (a person sees only their own); the database enforces the tenant, the repo filters by person.
 *
 * @phpstan-type Design array{id: string, title: string, brief: string, headline: string, subline: string, cta: string, theme: string, sizes: list<string>, brand: array<string, mixed>, logo_b64: ?string, base_b64: ?string, created_at: string}
 */
interface DesignRepo
{
    /** @return list<array{id: string, title: string, headline: string, sizes: list<string>, has_base: bool, created_at: string}> newest first, without the pictures */
    public function list(string $userId): array;

    public function count(string $userId): int;

    /** @return ?Design */
    public function find(string $userId, string $id): ?array;

    /**
     * @param  list<string>  $sizes
     * @param  array<string, mixed>  $brand
     * @return string ok|bad_input|limit
     */
    public function save(string $tenantId, string $userId, string $id, string $title, string $brief, string $headline, string $subline, string $cta, string $theme, array $sizes, array $brand, ?string $logoB64, ?string $baseB64): string;

    /**
     * Edits the words, name, theme and sizes (never the pictures); the previous state is kept as a version.
     *
     * @param  list<string>  $sizes
     * @return string ok|bad_input|not_found|unchanged
     */
    public function update(string $tenantId, string $userId, string $id, string $title, string $headline, string $subline, string $cta, string $theme, array $sizes): string;

    /** @return list<array{n: int, title: string, headline: string, subline: string, cta: string, theme: string, sizes: list<string>, created_at: string}> newest first, at most 10 */
    public function versions(string $userId, string $designId): array;

    /** @return string ok|not_found */
    public function restore(string $tenantId, string $userId, string $id, int $n): string;

    /** @return string ok|not_found */
    public function delete(string $tenantId, string $userId, string $id): string;

    /** @return list<array{id: string, client_name: string, size_key: string, status: string, comment: ?string, expires_at: string, decided_at: ?string, created_at: string}> newest first; "expired" is shown for a pending link past its date */
    public function reviews(string $userId, string $designId): array;

    /** @return string ok|bad_input|not_found|limit|duplicate */
    public function createReview(string $tenantId, string $userId, string $id, string $designId, string $tokenHash, string $client, string $size, string $previewB64, int $days): string;

    /** @return string ok|not_found */
    public function revokeReview(string $tenantId, string $userId, string $id): string;

    /** @return ?array{title: string, brand_name: string, client_name: string, size: string, preview_b64: string, status: string, comment: ?string, decided_at: ?string, expires_at: string} */
    public function publicView(string $tokenHash): ?array;

    /** @return string ok|not_found|expired|decided|revoked|bad_input */
    public function publicDecide(string $tokenHash, string $decision, string $comment): string;
}
