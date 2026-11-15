<?php

declare(strict_types=1);

namespace App\Video;

/** Where scene photos, the voice-over and the finished video are kept.  Private: served only through authenticated, owner-checked routes. */
interface VideoFiles
{
    public function put(string $tenantId, string $name, string $bytes): void;

    public function get(string $tenantId, string $name): ?string;

    /** Absolute path of an existing file (for streaming), or null. */
    public function path(string $tenantId, string $name): ?string;

    public function delete(string $tenantId, string $name): void;
}
