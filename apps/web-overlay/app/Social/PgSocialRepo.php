<?php

declare(strict_types=1);

namespace App\Social;

use Illuminate\Support\Facades\DB;

final class PgSocialRepo implements SocialRepo
{
    private const SELECT = 'select p.id, p.user_id, u.name as author, p.title, p.platform, p.caption, p.media_kind, p.media_id, p.media_size, p.account_id, f.name as account_name, p.publish_state, p.publish_error, p.agent_note, p.yt_tags, p.yt_privacy, p.thumb_design_id, p.thumb_size, p.publish_note, p.scheduled_at, p.status, p.reject_note, p.approved_by,
        a.name as approver, p.approved_at, p.posted_at, p.post_url, p.created_at, p.updated_at from social_posts p left join users u on u.id = p.user_id left join users a on a.id = p.approved_by left join social_accounts f on f.id = p.account_id';

    private static function row(object $r): array
    {
        $n = fn ($v) => $v === null ? null : (string) $v;

        return ['id' => trim((string) $r->id), 'user_id' => trim((string) $r->user_id), 'author' => $n($r->author), 'title' => (string) $r->title, 'platform' => (string) $r->platform, 'caption' => (string) $r->caption,
            'media_kind' => (string) $r->media_kind, 'media_id' => $r->media_id === null ? null : trim((string) $r->media_id), 'media_size' => $n($r->media_size), 'account_id' => $r->account_id === null ? null : trim((string) $r->account_id), 'account_name' => $n($r->account_name),
            'publish_state' => $n($r->publish_state), 'publish_error' => $n($r->publish_error), 'note' => $n($r->agent_note), 'scheduled_at' => $n($r->scheduled_at), 'status' => (string) $r->status,
            'reject_note' => $n($r->reject_note), 'approved_by' => $r->approved_by === null ? null : trim((string) $r->approved_by), 'approver' => $n($r->approver), 'approved_at' => $n($r->approved_at), 'posted_at' => $n($r->posted_at),
            'post_url' => $n($r->post_url), 'created_at' => (string) $r->created_at, 'updated_at' => (string) $r->updated_at,
            'yt_tags' => $n($r->yt_tags), 'yt_privacy' => (string) $r->yt_privacy, 'thumb_design_id' => $r->thumb_design_id === null ? null : trim((string) $r->thumb_design_id), 'thumb_size' => $n($r->thumb_size), 'publish_note' => $n($r->publish_note)];
    }

    public function between(string $from, string $to, bool $withUnscheduled): array
    {
        $sql = self::SELECT.' where p.status <> ? and ((p.scheduled_at >= ? and p.scheduled_at < ?)'.($withUnscheduled ? ' or p.scheduled_at is null' : '').') order by p.scheduled_at nulls last, p.created_at limit 500';

        return array_map(fn ($r) => self::row($r), DB::select($sql, ['cancelled', $from, $to]));
    }

    public function recent(int $limit): array
    {
        return array_map(fn ($r) => self::row($r), DB::select(self::SELECT.' where p.status <> ? and p.scheduled_at is null order by p.updated_at desc limit ?', ['cancelled', max(1, min(100, $limit))]));
    }

    public function find(string $id): ?array
    {
        $r = DB::selectOne(self::SELECT.' where p.id = ?', [$id]);

        return $r === null ? null : self::row($r);
    }

    public function save(bool $new, string $tenantId, string $userId, string $id, string $title, string $platform, string $caption, string $kind, ?string $mediaId, ?string $size, ?string $scheduledAt): string
    {
        return (string) DB::selectOne('select social_post_save(?::boolean, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?::timestamptz) as r', [$new ? 'true' : 'false', $id, $tenantId, $userId, $title, $platform, $caption, $kind, $mediaId, $size, $scheduledAt])->r;
    }

    public function submit(string $tenantId, string $userId, string $id): string
    {
        return (string) DB::selectOne('select social_post_submit(?, ?, ?) as r', [$id, $tenantId, $userId])->r;
    }

    public function decide(string $tenantId, string $approverId, string $id, bool $approve, ?string $note): string
    {
        return (string) DB::selectOne('select social_post_decide(?, ?, ?, ?::boolean, ?) as r', [$id, $tenantId, $approverId, $approve ? 'true' : 'false', $note])->r;
    }

    public function reopen(string $tenantId, string $userId, string $id): string
    {
        return (string) DB::selectOne('select social_post_reopen(?, ?, ?) as r', [$id, $tenantId, $userId])->r;
    }

    public function markPosted(string $tenantId, string $userId, string $id, ?string $url): string
    {
        return (string) DB::selectOne('select social_post_mark_posted(?, ?, ?, ?) as r', [$id, $tenantId, $userId, $url])->r;
    }

    public function cancel(string $tenantId, string $userId, string $id): string
    {
        return (string) DB::selectOne('select social_post_cancel(?, ?, ?) as r', [$id, $tenantId, $userId])->r;
    }

    public function delete(string $tenantId, string $userId, string $id): string
    {
        return (string) DB::selectOne('select social_post_delete(?, ?, ?) as r', [$id, $tenantId, $userId])->r;
    }

    public function accounts(): array
    {
        return array_map(fn ($r) => ['id' => trim((string) $r->id), 'platform' => (string) $r->platform, 'name' => (string) $r->name, 'external_id' => (string) $r->external_id, 'status' => (string) $r->status],
            DB::select('select id, platform, name, external_id, status from social_accounts order by name'));
    }

    public function saveAccount(string $tenantId, string $id, string $pageId, string $name, string $cipher, int $version): string
    {
        return trim((string) DB::selectOne("select social_account_save(?, ?, 'facebook', ?, ?, ?, ?::smallint) as r", [$id, $tenantId, $name, $pageId, $cipher, $version])->r);
    }

    public function saveChannel(string $tenantId, string $id, string $channelId, string $name, string $cipher, int $version): string
    {
        return trim((string) DB::selectOne('select social_channel_save(?, ?, ?, ?, ?, ?::smallint) as r', [$id, $tenantId, $name, $channelId, $cipher, $version])->r);
    }

    public function setYoutube(string $tenantId, string $userId, string $id, ?string $tags, string $privacy, ?string $thumbDesignId, ?string $thumbSize): string
    {
        return (string) DB::selectOne('select social_post_set_youtube(?, ?, ?, ?, ?, ?, ?) as r', [$id, $tenantId, $userId, $tags, $privacy, $thumbDesignId, $thumbSize])->r;
    }

    public function deleteAccount(string $tenantId, string $id): string
    {
        return (string) DB::selectOne('select social_account_delete(?, ?) as r', [$id, $tenantId])->r;
    }

    public function setAccount(string $tenantId, string $userId, string $id, ?string $accountId): string
    {
        return (string) DB::selectOne('select social_post_set_account(?, ?, ?, ?) as r', [$id, $tenantId, $userId, $accountId])->r;
    }

    public function retry(string $tenantId, string $userId, string $id): string
    {
        return (string) DB::selectOne('select social_post_retry(?, ?, ?) as r', [$id, $tenantId, $userId])->r;
    }

    public function stats(string $postId): ?array
    {
        $r = DB::selectOne('select reactions, comments, shares, reach, taken_at from social_post_latest_stats where post_id = ?', [$postId]);

        return $r === null ? null : ['reactions' => (int) $r->reactions, 'comments' => (int) $r->comments, 'shares' => $r->shares === null ? null : (int) $r->shares, 'reach' => $r->reach === null ? null : (int) $r->reach, 'taken_at' => (string) $r->taken_at];
    }

    public function posted(int $days): array
    {
        $n = fn ($v) => $v === null ? null : (int) $v;

        return array_map(fn ($r) => ['id' => trim((string) $r->id), 'title' => (string) $r->title, 'platform' => (string) $r->platform, 'posted_at' => (string) $r->posted_at, 'post_url' => $r->post_url === null ? null : (string) $r->post_url,
            'author' => $r->author === null ? null : (string) $r->author, 'reactions' => $n($r->reactions), 'comments' => $n($r->comments), 'shares' => $n($r->shares), 'reach' => $n($r->reach), 'taken_at' => $r->taken_at === null ? null : (string) $r->taken_at],
            DB::select('select p.id, p.title, p.caption, p.media_kind, p.platform, p.posted_at, p.post_url, u.name as author, s.reactions, s.comments, s.shares, s.reach, s.taken_at from social_posts p left join users u on u.id = p.user_id
                left join social_post_latest_stats s on s.post_id = p.id where p.status = ? and p.posted_at >= now() - make_interval(days => ?) order by p.posted_at desc limit 200', ['posted', max(1, min(365, $days))]));
    }

    public function startPlan(string $tenantId, string $userId, string $id, string $brief, int $days, array $platforms): string
    {
        return (string) DB::selectOne('select social_plan_start(?, ?, ?, ?, ?, ?::text[]) as r', [$id, $tenantId, $userId, $brief, $days, '{'.implode(',', array_map(fn ($x) => '"'.str_replace(['\\', '"'], '', $x).'"', $platforms)).'}'])->r;
    }

    public function finishPlan(string $tenantId, string $userId, string $id, bool $ok, int $items): string
    {
        return (string) DB::selectOne('select social_plan_finish(?, ?, ?, ?::boolean, ?) as r', [$id, $tenantId, $userId, $ok ? 'true' : 'false', $items])->r;
    }

    public function setNote(string $tenantId, string $userId, string $postId, ?string $note): string
    {
        return (string) DB::selectOne('select social_post_set_note(?, ?, ?, ?) as r', [$postId, $tenantId, $userId, $note])->r;
    }
}
