<?php

declare(strict_types=1);

namespace App\Social;

use Illuminate\Support\Facades\DB;

final class PgSocialPublishRepo implements SocialPublishRepo
{
    public function claim(int $limit): array
    {
        return array_map(fn ($r) => ['id' => trim((string) $r->post_id), 'tenant_id' => trim((string) $r->tenant_id), 'user_id' => trim((string) $r->user_id), 'account_id' => trim((string) $r->account_id),
            'caption' => (string) $r->caption, 'media_kind' => (string) $r->media_kind, 'media_id' => $r->media_id === null ? null : trim((string) $r->media_id), 'media_size' => $r->media_size === null ? null : (string) $r->media_size],
            DB::connection('admin')->select('select post_id, tenant_id, user_id, account_id, caption, media_kind, media_id, media_size from social_publish_claim(?)', [max(1, min(10, $limit))]));
    }

    public function claimYoutube(int $limit, int $globalCap, int $tenantCap): array
    {
        $n = fn ($v) => $v === null ? null : trim((string) $v);

        return array_map(fn ($r) => ['id' => trim((string) $r->post_id), 'tenant_id' => trim((string) $r->tenant_id), 'user_id' => trim((string) $r->user_id), 'account_id' => trim((string) $r->account_id), 'title' => (string) $r->title, 'caption' => (string) $r->caption,
            'media_id' => trim((string) $r->media_id), 'tags' => $r->yt_tags === null ? null : (string) $r->yt_tags, 'privacy' => (string) $r->yt_privacy, 'thumb_design_id' => $n($r->thumb_design_id), 'thumb_size' => $n($r->thumb_size)],
            DB::connection('admin')->select('select post_id, tenant_id, user_id, account_id, title, caption, media_id, yt_tags, yt_privacy, thumb_design_id, thumb_size from social_youtube_claim(?, ?, ?)', [max(1, min(5, $limit)), max(0, $globalCap), max(0, $tenantCap)]));
    }

    public function youtubeDone(string $tenantId, string $id, string $remoteId, ?string $url, ?string $note): string
    {
        return (string) DB::connection('admin')->selectOne('select social_youtube_done(?, ?, ?, ?, ?) as r', [$id, $tenantId, $remoteId, $url, $note])->r;
    }

    public function credentials(string $accountId): ?array
    {
        $r = DB::connection('admin')->selectOne('select tenant_id, external_id, credentials_ct, key_version, status from social_account_credentials(?)', [$accountId]);

        return $r === null || $r->credentials_ct === null ? null : ['tenant_id' => trim((string) $r->tenant_id), 'external_id' => (string) $r->external_id, 'cipher' => (string) $r->credentials_ct, 'version' => (int) $r->key_version, 'status' => (string) $r->status];
    }

    public function done(string $tenantId, string $id, string $remoteId, ?string $url): string
    {
        return (string) DB::connection('admin')->selectOne('select social_publish_done(?, ?, ?, ?) as r', [$id, $tenantId, $remoteId, $url])->r;
    }

    public function fail(string $tenantId, string $id, string $error): string
    {
        return (string) DB::connection('admin')->selectOne('select social_publish_fail(?, ?, ?) as r', [$id, $tenantId, $error])->r;
    }

    public function markAccount(string $accountId, string $status): void
    {
        DB::connection('admin')->select('select social_account_mark(?, ?)', [$accountId, $status]);
    }

    public function statsDue(int $limit): array
    {
        return array_map(fn ($r) => ['id' => trim((string) $r->post_id), 'tenant_id' => trim((string) $r->tenant_id), 'account_id' => trim((string) $r->account_id), 'remote_id' => (string) $r->remote_id, 'media_kind' => (string) $r->media_kind],
            DB::connection('admin')->select('select post_id, tenant_id, account_id, remote_id, media_kind from social_stats_due(?)', [max(1, min(20, $limit))]));
    }

    public function saveStats(string $tenantId, string $postId, ?int $reactions, ?int $comments, ?int $shares, ?int $reach): string
    {
        return (string) DB::connection('admin')->selectOne('select social_stats_save(?, ?, ?, ?, ?, ?) as r', [$postId, $tenantId, $reactions, $comments, $shares, $reach])->r;
    }
}
