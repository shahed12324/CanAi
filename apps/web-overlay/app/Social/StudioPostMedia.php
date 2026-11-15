<?php

declare(strict_types=1);

namespace App\Social;

use App\Studio\DesignRepo;
use App\Studio\StudioService;
use App\Video\VideoFiles;
use App\Video\VideoRepo;

final class StudioPostMedia implements PostMedia
{
    public function __construct(private readonly DesignRepo $designs, private readonly StudioService $studio, private readonly VideoRepo $videos, private readonly VideoFiles $files) {}

    public function load(string $tenantId, string $userId, string $kind, string $mediaId, ?string $size): ?array
    {
        if ($kind === 'video') {
            $j = $this->videos->find($userId, $mediaId);
            if ($j === null || $j['status'] !== 'done' || $j['file_path'] === null) {
                return null;
            }
            $path = $this->files->path($tenantId, $j['file_path']);
            $bytes = $path === null ? false : @file_get_contents($path);

            return $bytes === false || $bytes === '' ? null : ['kind' => 'video', 'mime' => 'video/mp4', 'bytes' => $bytes];
        }
        if ($kind !== 'design' || $size === null) {
            return null;
        }
        $d = $this->designs->find($userId, $mediaId);
        if ($d === null || ! in_array($size, $d['sizes'], true)) {
            return null;
        }
        $r = $this->studio->render($tenantId, $userId, $d, [$size], 'png');

        return isset($r['items'][$size]) ? ['kind' => 'image', 'mime' => $r['items'][$size]['mime'], 'bytes' => $r['items'][$size]['bytes']] : null;
    }
}
