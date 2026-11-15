<?php

declare(strict_types=1);

namespace App\Social;

use App\Actions\Tenancy\CreateWorkspace;
use Illuminate\Support\Facades\DB;

final class PgSocialCommentWorkRepo implements SocialCommentWorkRepo
{
    public function due(int $limit): array
    {
        return array_map(fn ($r) => ['post_id' => trim((string) $r->post_id), 'tenant_id' => trim((string) $r->tenant_id), 'account_id' => trim((string) $r->account_id), 'remote_id' => (string) $r->remote_id],
            DB::connection('admin')->select('select post_id, tenant_id, account_id, remote_id from social_comments_due(?)', [max(1, min(20, $limit))]));
    }

    public function ingest(string $tenantId, string $postId, string $remoteId, string $author, string $message, string $at): string
    {
        return (string) DB::connection('admin')->selectOne('select social_comment_ingest(?, ?, ?, ?, ?, ?, ?::timestamptz) as r', [CreateWorkspace::newId(), $tenantId, $postId, $remoteId, $author, $message, $at])->r;
    }

    public function claim(int $limit): array
    {
        return array_map(fn ($r) => ['id' => trim((string) $r->comment_id), 'tenant_id' => trim((string) $r->tenant_id), 'account_id' => trim((string) $r->account_id), 'remote_id' => (string) $r->remote_id, 'reply' => (string) $r->reply_text],
            DB::connection('admin')->select('select comment_id, tenant_id, account_id, remote_id, reply_text from social_comment_reply_claim(?)', [max(1, min(10, $limit))]));
    }

    public function done(string $tenantId, string $id, string $remoteId): string
    {
        return (string) DB::connection('admin')->selectOne('select social_comment_reply_done(?, ?, ?) as r', [$id, $tenantId, $remoteId])->r;
    }

    public function fail(string $tenantId, string $id, string $error): string
    {
        return (string) DB::connection('admin')->selectOne('select social_comment_reply_fail(?, ?, ?) as r', [$id, $tenantId, $error])->r;
    }

    public function credentials(string $accountId): ?array
    {
        $r = DB::connection('admin')->selectOne('select tenant_id, external_id, credentials_ct, key_version, status from social_account_credentials(?)', [$accountId]);

        return $r === null || $r->credentials_ct === null ? null : ['tenant_id' => trim((string) $r->tenant_id), 'external_id' => (string) $r->external_id, 'cipher' => (string) $r->credentials_ct, 'version' => (int) $r->key_version, 'status' => (string) $r->status];
    }

    public function markAccount(string $accountId, string $status): void
    {
        DB::connection('admin')->select('select social_account_mark(?, ?)', [$accountId, $status]);
    }
}
