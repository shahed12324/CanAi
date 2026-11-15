<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Social\PostMedia;

final class FakePostMedia implements PostMedia
{
    /** @var ?array{kind: string, mime: string, bytes: string} */
    public ?array $result = null;

    public ?\Throwable $throw = null;

    public int $calls = 0;

    public function load(string $tenantId, string $userId, string $kind, string $mediaId, ?string $size): ?array
    {
        $this->calls++;
        if ($this->throw !== null) {
            throw $this->throw;
        }

        return $this->result;
    }
}
