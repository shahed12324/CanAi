<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Video\VideoFiles;

final class FakeVideoFiles implements VideoFiles
{
    /** @var array<string, string> "tenant/name" => bytes */
    public array $files = [];

    public bool $failPut = false;

    public function put(string $tenantId, string $name, string $bytes): void
    {
        if ($this->failPut) {
            throw new \RuntimeException('disk full');
        }
        $this->files["$tenantId/$name"] = $bytes;
    }

    public function get(string $tenantId, string $name): ?string
    {
        return $this->files["$tenantId/$name"] ?? null;
    }

    public function path(string $tenantId, string $name): ?string
    {
        return isset($this->files["$tenantId/$name"]) ? "/fake/$tenantId/$name" : null;
    }

    public function delete(string $tenantId, string $name): void
    {
        unset($this->files["$tenantId/$name"]);
    }
}
