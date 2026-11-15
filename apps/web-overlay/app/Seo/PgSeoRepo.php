<?php

declare(strict_types=1);

namespace App\Seo;

use Illuminate\Support\Facades\DB;

final class PgSeoRepo implements SeoRepo
{
    private const COLS = 'a.id, a.url, a.domain, a.keyword, a.status, a.score, a.pages_checked, a.client_id, c.name as client_name, a.created_at';

    public function allowed(string $tenantId): bool
    {
        return (bool) DB::selectOne('select seo_audit_allowed(?) as r', [$tenantId])->r;
    }

    public function save(string $tenantId, string $userId, string $id, ?string $clientId, string $url, string $domain, ?string $keyword, string $status, ?int $score, int $pages, array $result): string
    {
        $json = json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return (string) DB::selectOne('select seo_audit_save(?,?,?,?,?,?,?,?,?,?,?::jsonb) as r', [$id, $tenantId, $userId, $clientId, $url, $domain, $keyword, $status, $score, $pages, $json])->r;
    }

    public function delete(string $tenantId, string $userId, string $id): string
    {
        return (string) DB::selectOne('select seo_audit_delete(?,?,?) as r', [$tenantId, $userId, $id])->r;
    }

    public function recent(int $limit = 50): array
    {
        $rows = DB::select('select '.self::COLS.' from seo_audits a left join clients c on c.id = a.client_id and c.tenant_id = a.tenant_id order by a.created_at desc, a.id desc limit '.max(1, min(200, $limit)));

        return array_map(fn ($r) => self::row($r), $rows);
    }

    public function find(string $id): ?array
    {
        $r = DB::selectOne('select '.self::COLS.', a.result, (select p.score from seo_audits p where p.tenant_id = a.tenant_id and p.domain = a.domain and p.status = \'ok\' and (p.created_at, p.id) < (a.created_at, a.id) order by p.created_at desc, p.id desc limit 1) as previous_score
            from seo_audits a left join clients c on c.id = a.client_id and c.tenant_id = a.tenant_id where a.id = ?', [$id]);
        if ($r === null) {
            return null;
        }
        $decoded = json_decode((string) $r->result, true);

        return self::row($r) + ['result' => is_array($decoded) ? $decoded : [], 'previous_score' => $r->previous_score === null ? null : (int) $r->previous_score];
    }

    /** @return array{id: string, url: string, domain: string, keyword: ?string, status: string, score: ?int, pages_checked: int, client_id: ?string, client_name: ?string, created_at: string} */
    private static function row(object $r): array
    {
        return ['id' => trim((string) $r->id), 'url' => (string) $r->url, 'domain' => (string) $r->domain, 'keyword' => $r->keyword === null ? null : (string) $r->keyword, 'status' => (string) $r->status,
            'score' => $r->score === null ? null : (int) $r->score, 'pages_checked' => (int) $r->pages_checked, 'client_id' => $r->client_id === null ? null : trim((string) $r->client_id),
            'client_name' => $r->client_name === null ? null : (string) $r->client_name, 'created_at' => (string) $r->created_at];
    }
}
