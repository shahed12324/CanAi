<?php

declare(strict_types=1);

namespace App\Plugins;

use Illuminate\Support\Facades\DB;

final class PgPluginRepo implements PluginRepo
{
    /** @return list<string> */
    private static function arr(string $pg): array
    {
        $inner = trim($pg, '{}');

        return $inner === '' ? [] : array_map(fn ($v) => trim($v, '"'), explode(',', $inner));
    }

    public function submit(string $tenantId, string $userId, string $id, string $slug, string $name, string $desc, array $manifest, array $tools): string
    {
        return (string) DB::selectOne('select plugin_submit(?, ?, ?, ?, ?, ?, ?::jsonb, ?::text[]) as r',
            [$id, $tenantId, $userId, $slug, $name, $desc, json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), '{'.implode(',', $tools).'}'])->r;
    }

    public function withdraw(string $tenantId, string $userId, string $id): string
    {
        return (string) DB::selectOne('select plugin_withdraw(?, ?, ?) as r', [$id, $tenantId, $userId])->r;
    }

    public function published(): array
    {
        return array_map(fn ($r) => ['id' => trim((string) $r->id), 'slug' => (string) $r->slug, 'version' => (int) $r->version, 'name' => (string) $r->name, 'description' => (string) $r->description, 'tools' => self::arr((string) $r->tools)],
            DB::select("select distinct on (slug) id, slug, version, name, description, tools::text as tools from plugin_versions where status = 'published' order by slug, version desc"));
    }

    public function latest(string $slug): ?array
    {
        $r = DB::selectOne("select id, slug, version, name, description, manifest::text as manifest, tools::text as tools from plugin_versions where slug = ? and status = 'published' order by version desc limit 1", [$slug]);

        return $r === null ? null : ['id' => trim((string) $r->id), 'slug' => (string) $r->slug, 'version' => (int) $r->version, 'name' => (string) $r->name, 'description' => (string) $r->description,
            'manifest' => (array) json_decode((string) $r->manifest, true), 'tools' => self::arr((string) $r->tools)];
    }

    public function mine(): array
    {
        return array_map(fn ($r) => ['id' => trim((string) $r->id), 'slug' => (string) $r->slug, 'version' => (int) $r->version, 'name' => (string) $r->name, 'status' => (string) $r->status,
            'review_note' => $r->review_note === null ? null : (string) $r->review_note, 'created_at' => (string) $r->created_at],
            DB::select('select id, slug, version, name, status, review_note, created_at from plugin_versions where tenant_id = app_tenant_id() order by created_at desc, id desc limit 50'));
    }

    public function installs(string $userId): array
    {
        $out = [];
        foreach (DB::select('select slug, version, workflow_ids::text as ids from plugin_installs where user_id = ?', [$userId]) as $r) {
            $out[(string) $r->slug] = ['version' => (int) $r->version, 'workflow_ids' => self::arr((string) $r->ids)];
        }

        return $out;
    }

    public function installRecord(string $tenantId, string $userId, string $id, string $slug, int $version, array $workflowIds): string
    {
        return (string) DB::selectOne('select plugin_install_record(?, ?, ?, ?, ?, ?::text[]) as r', [$id, $tenantId, $userId, $slug, $version, '{'.implode(',', $workflowIds).'}'])->r;
    }

    public function uninstall(string $tenantId, string $userId, string $slug): string
    {
        return (string) DB::selectOne('select plugin_uninstall(?, ?, ?) as r', [$tenantId, $userId, $slug])->r;
    }
}
