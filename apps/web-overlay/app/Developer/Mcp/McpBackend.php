<?php

declare(strict_types=1);

namespace App\Developer\Mcp;

/** What the MCP tools can reach.  Always the key's own person and workspace; the server never passes anyone else's id. */
interface McpBackend
{
    /** @return list<array{id: string, name: string, enabled: bool, inputs: list<string>}> */
    public function workflows(): array;

    /** @param array<string, string> $inputs  @return array{status: string, run_id: ?string} */
    public function run(string $id, array $inputs, bool $dryRun): array;

    /** @return ?array{status: string, spent: mixed, max_credits: mixed} */
    public function runStatus(string $id): ?array;

    /** @return array{balance: string, reserved: string, available: string} */
    public function credits(): array;
}
