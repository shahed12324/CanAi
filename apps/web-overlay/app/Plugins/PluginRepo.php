<?php

declare(strict_types=1);

namespace App\Plugins;

interface PluginRepo
{
    /** @param array<string, mixed> $manifest @param list<string> $tools @return string ok|bad_input|slug_taken|limit|duplicate */
    public function submit(string $tenantId, string $userId, string $id, string $slug, string $name, string $desc, array $manifest, array $tools): string;

    /** @return string ok|not_found */
    public function withdraw(string $tenantId, string $userId, string $id): string;

    /** @return list<array{id: string, slug: string, version: int, name: string, description: string, tools: list<string>}> the newest PUBLISHED version of every plugin */
    public function published(): array;

    /** @return ?array{id: string, slug: string, version: int, name: string, description: string, manifest: array<string, mixed>, tools: list<string>} */
    public function latest(string $slug): ?array;

    /** @return list<array{id: string, slug: string, version: int, name: string, status: string, review_note: ?string, created_at: string}> the workspace's own submissions, newest first */
    public function mine(): array;

    /** @return array<string, array{version: int, workflow_ids: list<string>}> by slug */
    public function installs(string $userId): array;

    /** @param list<string> $workflowIds @return string ok|bad_input|not_found|not_newer|limit */
    public function installRecord(string $tenantId, string $userId, string $id, string $slug, int $version, array $workflowIds): string;

    /** @return string ok|not_found */
    public function uninstall(string $tenantId, string $userId, string $slug): string;
}
