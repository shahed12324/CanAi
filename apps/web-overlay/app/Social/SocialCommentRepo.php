<?php

declare(strict_types=1);

namespace App\Social;

/** What the screens and the AI draft see (app role: tenant-isolated, read-only; writes go through definer functions that check the status). */
interface SocialCommentRepo
{
    /**
     * @param  string  $filter  todo (needs attention) | replied | ignored | all
     * @return list<array{id: string, post_id: string, post_title: string, post_caption: string, author: string, message: string, commented_at: string, status: string, reply: ?string, reply_error: ?string, replied_at: ?string, approver: ?string}>
     */
    public function list(string $filter, int $limit): array;

    /** @return ?array{id: string, post_id: string, post_title: string, post_caption: string, author: string, message: string, commented_at: string, status: string, reply: ?string, reply_error: ?string, replied_at: ?string, approver: ?string} */
    public function find(string $id): ?array;

    /** @return array<string, int> number of comments per status */
    public function counts(): array;

    /** result: ok | not_found | locked | bad_input */
    public function draft(string $tenantId, string $userId, string $id, string $text): string;

    /** result: ok | not_found */
    public function approve(string $tenantId, string $userId, string $id, string $text): string;

    public function withdraw(string $tenantId, string $userId, string $id): string;

    public function ignore(string $tenantId, string $userId, string $id): string;

    public function retry(string $tenantId, string $userId, string $id): string;
}
