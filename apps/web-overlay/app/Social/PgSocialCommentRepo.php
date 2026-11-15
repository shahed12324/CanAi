<?php

declare(strict_types=1);

namespace App\Social;

use Illuminate\Support\Facades\DB;

final class PgSocialCommentRepo implements SocialCommentRepo
{
    private const SELECT = 'select c.id, c.post_id, p.title as post_title, p.caption as post_caption, c.author_name, c.message, c.commented_at, c.status, c.reply_text, c.reply_error, c.replied_at, u.name as approver
        from social_comments c join social_posts p on p.id = c.post_id and p.tenant_id = c.tenant_id left join users u on u.id = c.approved_by';

    private static function row(object $r): array
    {
        $n = fn ($v) => $v === null ? null : (string) $v;

        return ['id' => trim((string) $r->id), 'post_id' => trim((string) $r->post_id), 'post_title' => (string) $r->post_title, 'post_caption' => (string) $r->post_caption, 'author' => (string) $r->author_name, 'message' => (string) $r->message,
            'commented_at' => (string) $r->commented_at, 'status' => (string) $r->status, 'reply' => $n($r->reply_text), 'reply_error' => $n($r->reply_error), 'replied_at' => $n($r->replied_at), 'approver' => $n($r->approver)];
    }

    public function list(string $filter, int $limit): array
    {
        $where = match ($filter) {
            'replied' => "c.status = 'replied'", 'ignored' => "c.status = 'ignored'", 'all' => 'true',
            default => "c.status in ('new', 'drafted', 'approved', 'replying', 'failed')",
        };

        return array_map(fn ($r) => self::row($r), DB::select(self::SELECT." where {$where} order by c.commented_at desc limit ?", [max(1, min(200, $limit))]));
    }

    public function find(string $id): ?array
    {
        $r = DB::selectOne(self::SELECT.' where c.id = ?', [$id]);

        return $r === null ? null : self::row($r);
    }

    public function counts(): array
    {
        $out = [];
        foreach (DB::select('select status, count(*) as n from social_comments group by status') as $r) {
            $out[(string) $r->status] = (int) $r->n;
        }

        return $out;
    }

    public function draft(string $tenantId, string $userId, string $id, string $text): string
    {
        return (string) DB::selectOne('select social_comment_draft(?, ?, ?, ?) as r', [$id, $tenantId, $userId, $text])->r;
    }

    public function approve(string $tenantId, string $userId, string $id, string $text): string
    {
        return (string) DB::selectOne('select social_comment_approve(?, ?, ?, ?) as r', [$id, $tenantId, $userId, $text])->r;
    }

    public function withdraw(string $tenantId, string $userId, string $id): string
    {
        return (string) DB::selectOne('select social_comment_withdraw(?, ?, ?) as r', [$id, $tenantId, $userId])->r;
    }

    public function ignore(string $tenantId, string $userId, string $id): string
    {
        return (string) DB::selectOne('select social_comment_ignore(?, ?, ?) as r', [$id, $tenantId, $userId])->r;
    }

    public function retry(string $tenantId, string $userId, string $id): string
    {
        return (string) DB::selectOne('select social_comment_retry(?, ?, ?) as r', [$id, $tenantId, $userId])->r;
    }
}
