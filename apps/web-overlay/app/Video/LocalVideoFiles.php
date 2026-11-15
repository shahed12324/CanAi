<?php

declare(strict_types=1);

namespace App\Video;

use Illuminate\Support\Facades\Storage;

/** The default private disk (storage/app/private/videos/<tenant>/).  File names are built from validated ids only, never from anything uploaded. */
final class LocalVideoFiles implements VideoFiles
{
    public const NAME = '/\A[0-9a-z]{26}(?:-[0-9]{1,2}\.img|-voice\.mp3|-clip\.vid|\.mp4)\z/';

    private static function rel(string $tenantId, string $name): string
    {
        if (preg_match('/\A[0-9a-z]{26}\z/', $tenantId) !== 1 || preg_match(self::NAME, $name) !== 1) {
            throw new \InvalidArgumentException('bad file name');
        }

        return "videos/{$tenantId}/{$name}";
    }

    public function put(string $tenantId, string $name, string $bytes): void
    {
        if (! Storage::disk('local')->put(self::rel($tenantId, $name), $bytes)) {
            throw new \RuntimeException('could not store video file');
        }
    }

    public function get(string $tenantId, string $name): ?string
    {
        $p = self::rel($tenantId, $name);

        return Storage::disk('local')->exists($p) ? Storage::disk('local')->get($p) : null;
    }

    public function path(string $tenantId, string $name): ?string
    {
        $p = self::rel($tenantId, $name);

        return Storage::disk('local')->exists($p) ? Storage::disk('local')->path($p) : null;
    }

    public function delete(string $tenantId, string $name): void
    {
        Storage::disk('local')->delete(self::rel($tenantId, $name));
    }
}
