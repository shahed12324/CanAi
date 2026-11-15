<?php

declare(strict_types=1);

namespace App\Social;

/** The worker's view (admin connection).  Only this interface can ever read a Page token. */
interface SocialPublishRepo
{
    /** @return list<array{id: string, tenant_id: string, user_id: string, account_id: string, caption: string, media_kind: string, media_id: ?string, media_size: ?string}> */
    public function claim(int $limit): array;

    /** @return ?array{tenant_id: string, external_id: string, cipher: string, version: int, status: string} */
    public function credentials(string $accountId): ?array;

    /** @return list<array{id: string, tenant_id: string, user_id: string, account_id: string, title: string, caption: string, media_id: string, tags: ?string, privacy: string, thumb_design_id: ?string, thumb_size: ?string}> YouTube posts now marked as being uploaded (each at most once; daily caps applied) */
    public function claimYoutube(int $limit, int $globalCap, int $tenantCap): array;

    public function youtubeDone(string $tenantId, string $id, string $remoteId, ?string $url, ?string $note): string;

    public function done(string $tenantId, string $id, string $remoteId, ?string $url): string;

    public function fail(string $tenantId, string $id, string $error): string;

    public function markAccount(string $accountId, string $status): void;

    /** @return list<array{id: string, tenant_id: string, account_id: string, remote_id: string, media_kind: string}> published posts due for a look at their numbers */
    public function statsDue(int $limit): array;

    /** A snapshot; all numbers null = a look that found nothing (spaces out the next try). */
    public function saveStats(string $tenantId, string $postId, ?int $reactions, ?int $comments, ?int $shares, ?int $reach): string;
}
