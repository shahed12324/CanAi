<?php

declare(strict_types=1);

namespace App\Social;

/** The comment worker's view (admin connection).  Only this interface and SocialPublishRepo can ever read a Page token. */
interface SocialCommentWorkRepo
{
    /** @return list<array{post_id: string, tenant_id: string, account_id: string, remote_id: string}> published posts whose comments are due for a look (the look time is stamped) */
    public function due(int $limit): array;

    /** result: new | exists | not_found | bad_input | full */
    public function ingest(string $tenantId, string $postId, string $remoteId, string $author, string $message, string $at): string;

    /** @return list<array{id: string, tenant_id: string, account_id: string, remote_id: string, reply: string}> approved replies, now marked as being sent (each at most once) */
    public function claim(int $limit): array;

    public function done(string $tenantId, string $id, string $remoteId): string;

    public function fail(string $tenantId, string $id, string $error): string;

    /** @return ?array{tenant_id: string, external_id: string, cipher: string, version: int, status: string} */
    public function credentials(string $accountId): ?array;

    public function markAccount(string $accountId, string $status): void;
}
