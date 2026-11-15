<?php

declare(strict_types=1);

namespace App\Studio;

use Illuminate\Support\Facades\DB;

final class PgDesignRepo implements DesignRepo
{
    private static function sizes(string $pg): array
    {
        $inner = trim($pg, '{}');

        return $inner === '' ? [] : explode(',', $inner);
    }

    public function list(string $userId): array
    {
        return array_map(fn ($r) => ['id' => trim((string) $r->id), 'title' => (string) $r->title, 'headline' => (string) $r->headline, 'sizes' => self::sizes((string) $r->sizes),
            'has_base' => (bool) $r->has_base, 'created_at' => (string) $r->created_at],
            DB::select('select id, title, headline, sizes::text as sizes, (base_b64 is not null) as has_base, created_at from designs where user_id = ? order by created_at desc, id desc', [$userId]));
    }

    public function count(string $userId): int
    {
        return (int) DB::scalar('select count(*) from designs where user_id = ?', [$userId]);
    }

    public function find(string $userId, string $id): ?array
    {
        $r = DB::selectOne('select id, title, brief, headline, subline, cta, theme, sizes::text as sizes, brand::text as brand, logo_b64, base_b64, created_at from designs where user_id = ? and id = ?', [$userId, $id]);
        if ($r === null) {
            return null;
        }

        return ['id' => trim((string) $r->id), 'title' => (string) $r->title, 'brief' => (string) $r->brief, 'headline' => (string) $r->headline, 'subline' => (string) $r->subline, 'cta' => (string) $r->cta,
            'theme' => (string) $r->theme, 'sizes' => self::sizes((string) $r->sizes), 'brand' => (array) json_decode((string) $r->brand, true), 'logo_b64' => $r->logo_b64 === null ? null : (string) $r->logo_b64,
            'base_b64' => $r->base_b64 === null ? null : (string) $r->base_b64, 'created_at' => (string) $r->created_at];
    }

    public function save(string $tenantId, string $userId, string $id, string $title, string $brief, string $headline, string $subline, string $cta, string $theme, array $sizes, array $brand, ?string $logoB64, ?string $baseB64): string
    {
        return (string) DB::selectOne('select design_save(?, ?, ?, ?, ?, ?, ?, ?, ?, ?::text[], ?::jsonb, ?, ?) as r',
            [$id, $tenantId, $userId, $title, $brief, $headline, $subline, $cta, $theme, '{'.implode(',', $sizes).'}', json_encode($brand, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $logoB64, $baseB64])->r;
    }

    public function update(string $tenantId, string $userId, string $id, string $title, string $headline, string $subline, string $cta, string $theme, array $sizes): string
    {
        return (string) DB::selectOne('select design_update(?, ?, ?, ?, ?, ?, ?, ?, ?::text[]) as r', [$id, $tenantId, $userId, $title, $headline, $subline, $cta, $theme, '{'.implode(',', $sizes).'}'])->r;
    }

    public function versions(string $userId, string $designId): array
    {
        return array_map(fn ($r) => ['n' => (int) $r->n, 'title' => (string) $r->title, 'headline' => (string) $r->headline, 'subline' => (string) $r->subline, 'cta' => (string) $r->cta,
            'theme' => (string) $r->theme, 'sizes' => self::sizes((string) $r->sizes), 'created_at' => (string) $r->created_at],
            DB::select('select n, title, headline, subline, cta, theme, sizes::text as sizes, created_at from design_versions where user_id = ? and design_id = ? order by n desc limit 10', [$userId, $designId]));
    }

    public function restore(string $tenantId, string $userId, string $id, int $n): string
    {
        return (string) DB::selectOne('select design_restore(?, ?, ?, ?) as r', [$id, $tenantId, $userId, $n])->r;
    }

    public function delete(string $tenantId, string $userId, string $id): string
    {
        return (string) DB::selectOne('select design_delete(?, ?, ?) as r', [$id, $tenantId, $userId])->r;
    }

    public function reviews(string $userId, string $designId): array
    {
        return array_map(fn ($r) => ['id' => trim((string) $r->id), 'client_name' => (string) $r->client_name, 'size_key' => (string) $r->size_key, 'status' => (string) $r->status,
            'comment' => $r->comment === null ? null : (string) $r->comment, 'expires_at' => (string) $r->expires_at, 'decided_at' => $r->decided_at === null ? null : (string) $r->decided_at, 'created_at' => (string) $r->created_at],
            DB::select("select id, client_name, size_key, case when status = 'pending' and expires_at <= now() then 'expired' else status end as status, comment, expires_at, decided_at, created_at
                from design_reviews where user_id = ? and design_id = ? order by created_at desc, id desc", [$userId, $designId]));
    }

    public function createReview(string $tenantId, string $userId, string $id, string $designId, string $tokenHash, string $client, string $size, string $previewB64, int $days): string
    {
        return (string) DB::selectOne('select review_create(?, ?, ?, ?, ?, ?, ?, ?, ?) as r', [$id, $tenantId, $userId, $designId, $tokenHash, $client, $size, $previewB64, $days])->r;
    }

    public function revokeReview(string $tenantId, string $userId, string $id): string
    {
        return (string) DB::selectOne('select review_revoke(?, ?, ?) as r', [$id, $tenantId, $userId])->r;
    }

    public function publicView(string $tokenHash): ?array
    {
        $j = DB::selectOne('select review_public_view(?) as r', [$tokenHash])->r;

        return $j === null ? null : (array) json_decode((string) $j, true);
    }

    public function publicDecide(string $tokenHash, string $decision, string $comment): string
    {
        return (string) DB::selectOne('select review_public_decide(?, ?, ?) as r', [$tokenHash, $decision, $comment])->r;
    }
}
