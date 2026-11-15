<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Plugins\PluginRepo;

final class FakePluginRepo implements PluginRepo
{
    /** @var list<array<string, mixed>> */
    public array $versions = [];

    /** @var array<string, array{version: int, workflow_ids: list<string>}> by "user|slug" */
    public array $inst = [];

    public ?string $submitResult = null;

    public ?string $recordResult = null;

    public function submit(string $tenantId, string $userId, string $id, string $slug, string $name, string $desc, array $manifest, array $tools): string
    {
        if ($this->submitResult !== null) {
            return $this->submitResult;
        }
        $n = count(array_filter($this->versions, fn ($v) => $v['slug'] === $slug)) + 1;
        $this->versions[] = ['id' => $id, 'slug' => $slug, 'version' => $n, 'name' => $name, 'description' => $desc, 'manifest' => $manifest, 'tools' => $tools, 'status' => 'pending', 'tenant' => $tenantId, 'review_note' => null, 'created_at' => 'now'];

        return 'ok';
    }

    public function withdraw(string $tenantId, string $userId, string $id): string
    {
        return 'ok';
    }

    public function publish(string $slug, int $version): void
    {
        foreach ($this->versions as &$v) {
            if ($v['slug'] === $slug && $v['version'] === $version) {
                $v['status'] = 'published';
            }
        }
    }

    public function published(): array
    {
        $out = [];
        foreach ($this->versions as $v) {
            if ($v['status'] === 'published' && (! isset($out[$v['slug']]) || $out[$v['slug']]['version'] < $v['version'])) {
                $out[$v['slug']] = $v;
            }
        }

        return array_values($out);
    }

    public function latest(string $slug): ?array
    {
        foreach ($this->published() as $v) {
            if ($v['slug'] === $slug) {
                return $v;
            }
        }

        return null;
    }

    public function mine(): array
    {
        return [];
    }

    public function installs(string $userId): array
    {
        $out = [];
        foreach ($this->inst as $k => $v) {
            if (str_starts_with($k, $userId.'|')) {
                $out[substr($k, strlen($userId) + 1)] = $v;
            }
        }

        return $out;
    }

    public function installRecord(string $tenantId, string $userId, string $id, string $slug, int $version, array $workflowIds): string
    {
        if ($this->recordResult !== null) {
            return $this->recordResult;
        }
        $cur = $this->inst[$userId.'|'.$slug]['version'] ?? 0;
        if ($version <= $cur) {
            return 'not_newer';
        }
        $this->inst[$userId.'|'.$slug] = ['version' => $version, 'workflow_ids' => $workflowIds];

        return 'ok';
    }

    public function uninstall(string $tenantId, string $userId, string $slug): string
    {
        if (! isset($this->inst[$userId.'|'.$slug])) {
            return 'not_found';
        }
        unset($this->inst[$userId.'|'.$slug]);

        return 'ok';
    }
}
