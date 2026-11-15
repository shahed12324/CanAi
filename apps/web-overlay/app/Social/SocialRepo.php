<?php

declare(strict_types=1);

namespace App\Social;

/**
 * @phpstan-type Post array{id: string, user_id: string, author: ?string, title: string, platform: string, caption: string, media_kind: string, media_id: ?string, media_size: ?string,
 *   account_id: ?string, account_name: ?string, publish_state: ?string, publish_error: ?string, note: ?string, scheduled_at: ?string, status: string, reject_note: ?string, approved_by: ?string, approver: ?string, approved_at: ?string, posted_at: ?string, post_url: ?string, created_at: string, updated_at: string, yt_tags: ?string, yt_privacy: string, thumb_design_id: ?string, thumb_size: ?string, publish_note: ?string}
 */
interface SocialRepo
{
    /** @return list<Post> every post of the workspace planned between the two instants (or with no time yet, when $withUnscheduled), oldest first */
    public function between(string $from, string $to, bool $withUnscheduled): array;

    /** @return list<Post> posts not in the calendar yet: no time, newest first */
    public function recent(int $limit): array;

    /** @return ?Post */
    public function find(string $id): ?array;

    /** @return string ok|bad_input|not_found|locked|bad_media|limit */
    public function save(bool $new, string $tenantId, string $userId, string $id, string $title, string $platform, string $caption, string $kind, ?string $mediaId, ?string $size, ?string $scheduledAt): string;

    /** @return string ok|not_found|no_schedule */
    public function submit(string $tenantId, string $userId, string $id): string;

    /** @return string ok|not_found */
    public function decide(string $tenantId, string $approverId, string $id, bool $approve, ?string $note): string;

    /** @return string ok|not_found */
    public function reopen(string $tenantId, string $userId, string $id): string;

    /** @return string ok|not_found|bad_url */
    public function markPosted(string $tenantId, string $userId, string $id, ?string $url): string;

    /** @return string ok|not_found */
    public function cancel(string $tenantId, string $userId, string $id): string;

    /** @return string ok|not_found */
    public function delete(string $tenantId, string $userId, string $id): string;

    /** @return list<array{id: string, platform: string, name: string, external_id: string, status: string}> the workspace's connected Pages (never the token) */
    public function accounts(): array;

    /** @return string the account id, or 'limit' */
    public function saveAccount(string $tenantId, string $id, string $pageId, string $name, string $cipher, int $version): string;

    /** @return string the account id, or 'limit' (a YouTube channel; the refresh token is already sealed) */
    public function saveChannel(string $tenantId, string $id, string $channelId, string $name, string $cipher, int $version): string;

    /** @return string ok|not_found|locked|bad_input|bad_media */
    public function setYoutube(string $tenantId, string $userId, string $id, ?string $tags, string $privacy, ?string $thumbDesignId, ?string $thumbSize): string;

    /** @return string ok|not_found|busy */
    public function deleteAccount(string $tenantId, string $id): string;

    /** @return string ok|not_found|locked|bad_account */
    public function setAccount(string $tenantId, string $userId, string $id, ?string $accountId): string;

    /** @return string ok|not_found */
    public function retry(string $tenantId, string $userId, string $id): string;

    /** @return ?array{reactions: int, comments: int, shares: ?int, reach: ?int, taken_at: string} the newest real snapshot of a post */
    public function stats(string $postId): ?array;

    /** @return list<array{id: string, title: string, caption: string, media_kind: string, platform: string, posted_at: string, post_url: ?string, author: ?string, reactions: ?int, comments: ?int, shares: ?int, reach: ?int, taken_at: ?string}> posted in the last $days days, newest first */
    public function posted(int $days): array;

    /** @return string ok|bad_input|person_cap|workspace_cap */
    public function startPlan(string $tenantId, string $userId, string $id, string $brief, int $days, array $platforms): string;

    public function finishPlan(string $tenantId, string $userId, string $id, bool $ok, int $items): string;

    /** @return string ok|not_found|locked|bad_input */
    public function setNote(string $tenantId, string $userId, string $postId, ?string $note): string;
}
