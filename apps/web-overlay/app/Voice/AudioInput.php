<?php

declare(strict_types=1);

namespace App\Voice;

/** What a recording really is, decided from its first bytes (the browser's claimed type is not trusted).  Pure. */
final class AudioInput
{
    public const MAX_BYTES = 5_000_000;

    public const MIN_BYTES = 200;

    /** @return array{mime: ?string, error: ?string} error: empty|too_small|too_big|bad_type */
    public static function validate(string $bytes): array
    {
        $n = strlen($bytes);
        if ($n === 0) {
            return ['mime' => null, 'error' => 'empty'];
        }
        if ($n < self::MIN_BYTES) {
            return ['mime' => null, 'error' => 'too_small'];
        }
        if ($n > self::MAX_BYTES) {
            return ['mime' => null, 'error' => 'too_big'];
        }
        $mime = match (true) {
            str_starts_with($bytes, "\x1A\x45\xDF\xA3") => 'audio/webm',
            str_starts_with($bytes, 'OggS') => 'audio/ogg',
            str_starts_with($bytes, 'ID3'), (bool) preg_match('/\A\xFF[\xF2\xF3\xFA\xFB]/', $bytes) => 'audio/mpeg',
            str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WAVE' => 'audio/wav',
            substr($bytes, 4, 4) === 'ftyp' => 'audio/mp4',
            default => null,
        };

        return $mime === null ? ['mime' => null, 'error' => 'bad_type'] : ['mime' => $mime, 'error' => null];
    }
}
