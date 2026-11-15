<?php

declare(strict_types=1);

namespace App\Social;

/** Loads the picture or video bytes of a post (inside the post's workspace). */
interface PostMedia
{
    /** @return ?array{kind: string, mime: string, bytes: string} kind: image|video; null when the design / video is gone or cannot be drawn */
    public function load(string $tenantId, string $userId, string $kind, string $mediaId, ?string $size): ?array;
}
