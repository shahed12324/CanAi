<?php

declare(strict_types=1);

namespace App\Plugins;

use App\Actions\Tenancy\CreateWorkspace;
use App\Workflow\ToolRegistry;
use App\Workflow\WorkflowRepo;

/**
 * The plugin store.  Developers submit manifests; an admin publishes them (not here); people install published ones.
 * Installing makes the person's own copies of the plugin's workflows, SWITCHED OFF until they turn them on.  Updating rewrites those copies in place
 * (so run history stays) - anything the person changed in a copy is replaced.  Removing deletes the copies.
 */
final class PluginService
{
    public const MESSAGES = [
        'bad_slug' => 'The plugin ID (a–z, 0–9, "-", 3–40 characters, starting with a letter) is not valid.',
        'bad_name' => 'The name must be 1–80 characters.',
        'bad_desc' => 'The description is over 400 characters.',
        'bad_json' => 'The manifest is not valid JSON (or over 60 thousand characters).',
        'invalid' => 'There is an error in the manifest.',
        'slug_taken' => 'This ID is used by another workspace.',
        'limit' => 'The limit is full.',
        'duplicate' => 'This has already been submitted.',
        'not_found' => 'Not found.',
        'up_to_date' => 'You have the latest version of this.',
        'manifest_invalid' => 'The plugin no longer runs under current rules (a tool changed). Ask the developer for a new version.',
        'workflow_limit' => 'At most 50 workflows can be kept. Delete some old ones and try again.',
        'failed' => 'Could not be done. Try again.',
    ];

    public function __construct(private readonly PluginRepo $repo, private readonly WorkflowRepo $workflows, private readonly ToolRegistry $tools) {}

    public static function message(string $code): string
    {
        return self::MESSAGES[$code] ?? 'Could not be done.';
    }

    /** @return array{status: string, errors: list<string>} */
    public function submit(string $tenantId, string $userId, string $slug, string $name, string $desc, string $json): array
    {
        $slug = trim($slug);
        $name = \App\Studio\StudioService::line($name, 200);
        $desc = \App\Studio\StudioService::line($desc, 1000);
        if (preg_match(PluginManifest::SLUG, $slug) !== 1) {
            return ['status' => 'bad_slug', 'errors' => []];
        }
        if ($name === '' || mb_strlen($name) > 80) {
            return ['status' => 'bad_name', 'errors' => []];
        }
        if (mb_strlen($desc) > 400) {
            return ['status' => 'bad_desc', 'errors' => []];
        }
        $m = strlen($json) <= 60000 ? json_decode($json, true) : null;
        if (! is_array($m)) {
            return ['status' => 'bad_json', 'errors' => []];
        }
        $c = PluginManifest::check($m, $this->tools);
        if ($c['errors'] !== []) {
            return ['status' => 'invalid', 'errors' => $c['errors']];
        }
        $r = $this->repo->submit($tenantId, $userId, CreateWorkspace::newId(), $slug, $name, $desc, ['workflows' => $c['workflows']], $c['tools']);

        return ['status' => in_array($r, ['ok', 'slug_taken', 'limit', 'duplicate'], true) ? $r : 'failed', 'errors' => []];
    }

    public function withdraw(string $tenantId, string $userId, string $id): string
    {
        return $this->repo->withdraw($tenantId, $userId, $id);
    }

    /** Installs the newest published version, or updates an earlier install to it.  @return string ok|not_found|up_to_date|manifest_invalid|workflow_limit|limit|failed */
    public function install(string $tenantId, string $userId, string $slug): string
    {
        $p = $this->repo->latest($slug);
        if ($p === null) {
            return 'not_found';
        }
        $have = $this->repo->installs($userId)[$slug] ?? null;
        if ($have !== null && $have['version'] >= $p['version']) {
            return 'up_to_date';
        }
        $c = PluginManifest::check($p['manifest'], $this->tools);       // checked again NOW: tools may have changed since it was published
        if ($c['errors'] !== []) {
            return 'manifest_invalid';
        }
        $ids = [];
        $created = [];
        foreach ($c['workflows'] as $i => $w) {
            $old = $have['workflow_ids'][$i] ?? null;
            $existing = $old === null ? null : $this->workflows->workflow($userId, $old);
            if ($existing !== null) {
                $r = $this->workflows->save($tenantId, $userId, $existing['id'], $w['name'], $w['description'], $w['category'], $w['definition'], $existing['enabled'], $existing['schedule_time'], $existing['max_credits'], false);
                $id = $existing['id'];
            } else {
                $id = CreateWorkspace::newId();
                $r = $this->workflows->save($tenantId, $userId, $id, $w['name'], $w['description'], $w['category'], $w['definition'], false, null, 5.0, true);
                if ($r === 'ok') {
                    $created[] = $id;
                }
            }
            if ($r !== 'ok') {
                $this->dropAll($tenantId, $userId, $created);

                return $r === 'limit' ? 'workflow_limit' : 'failed';
            }
            $ids[] = $id;
        }
        $rec = $this->repo->installRecord($tenantId, $userId, CreateWorkspace::newId(), $slug, $p['version'], $ids);
        if ($rec !== 'ok') {
            $this->dropAll($tenantId, $userId, $created);

            return match ($rec) {
                'not_newer' => 'up_to_date', 'limit' => 'limit', 'not_found' => 'not_found', default => 'failed',
            };
        }
        if ($have !== null) {
            foreach (array_slice($have['workflow_ids'], count($ids)) as $surplus) {      // the new version has fewer workflows
                $this->workflows->delete($tenantId, $userId, $surplus);
            }
        }

        return 'ok';
    }

    /** Removes the plugin and the copies of its workflows.  @return string ok|not_found */
    public function uninstall(string $tenantId, string $userId, string $slug): string
    {
        $have = $this->repo->installs($userId)[$slug] ?? null;
        if ($have === null) {
            return 'not_found';
        }
        foreach ($have['workflow_ids'] as $id) {
            $this->workflows->delete($tenantId, $userId, $id);
        }

        return $this->repo->uninstall($tenantId, $userId, $slug);
    }

    /** @param list<string> $ids */
    private function dropAll(string $tenantId, string $userId, array $ids): void
    {
        foreach ($ids as $id) {
            $this->workflows->delete($tenantId, $userId, $id);
        }
    }
}
