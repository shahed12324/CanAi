<?php

declare(strict_types=1);

namespace App\Video;

/**
 * @phpstan-type Job array{id: string, user_id: string, title: string, size_key: string, scenes: list<array<string, mixed>>, narration: ?string, voice: ?string, brand: array<string, mixed>, logo_b64: ?string,
 *   audio_path: ?string, reservation_id: ?string, amount: string, charged: ?string, seconds: string, status: string, file_path: ?string, bytes: ?int, error: ?string, created_at: string, finished_at: ?string}
 */
interface VideoRepo
{
    /** @return list<Job> newest first, without the logo */
    public function list(string $userId): array;

    /** @return ?Job one of this person's jobs */
    public function find(string $userId, string $id): ?array;

    /** @return ?Job any job of the current workspace (the worker has no person) */
    public function get(string $id): ?array;

    /** Jobs of this person that are waiting or rendering. */
    public function activeCount(string $userId): int;

    /**
     * @param  list<array<string, mixed>>  $scenes
     * @param  array<string, mixed>  $brand
     * @return string ok|bad_input|limit
     */
    public function create(string $tenantId, string $userId, string $id, string $title, string $size, array $scenes, ?string $narration, ?string $voice, array $brand, ?string $logoB64, ?string $audioPath, string $reservationId, string $amount, string $seconds): string;

    /** @return string ok|not_found */
    public function cancel(string $tenantId, string $userId, string $id): string;

    /** @return string ok|not_found */
    public function delete(string $tenantId, string $userId, string $id): string;

    /** Worker only: marks the oldest queued jobs as rendering.  @return list<array{id: string, tenant_id: string}> */
    public function claim(int $limit): array;

    /** Worker only.  @return string ok|not_found */
    public function done(string $tenantId, string $id, string $filePath, int $bytes, string $charged): string;

    /** Worker only.  @return string ok|not_found */
    public function fail(string $tenantId, string $id, string $error): string;
}
