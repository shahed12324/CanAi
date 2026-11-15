<?php

declare(strict_types=1);

namespace App\BillScan;

/** What may be uploaded as a bill: a real JPEG/PNG/WebP/PDF up to 5 MB. The type comes from the bytes, never from the name or the browser. Pure. */
final class BillFile
{
    public const MAX_BYTES = 5 * 1024 * 1024;

    public static function sniff(string $b): ?string
    {
        return match (true) {
            str_starts_with($b, "\xFF\xD8\xFF") => 'image/jpeg',
            str_starts_with($b, "\x89PNG\r\n\x1A\n") => 'image/png',
            strlen($b) >= 12 && str_starts_with($b, 'RIFF') && substr($b, 8, 4) === 'WEBP' => 'image/webp',
            str_contains(substr($b, 0, 1024), '%PDF-') => 'application/pdf',
            default => null,
        };
    }

    public static function ext(string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'pdf',
        };
    }

    /** @return array{mime: ?string, error: ?string} */
    public static function validate(string $bytes): array
    {
        if ($bytes === '') {
            return ['mime' => null, 'error' => 'The file is empty.'];
        }
        if (strlen($bytes) > self::MAX_BYTES) {
            return ['mime' => null, 'error' => 'The file is over 5 MB — make it smaller (reduce the image size) and give it again.'];
        }
        $mime = self::sniff($bytes);

        return $mime === null ? ['mime' => null, 'error' => 'Only an image (JPG, PNG, WebP) or a PDF can be given.'] : ['mime' => $mime, 'error' => null];
    }

    /** A name that is safe to store and show: no path, no control characters, at most 120 characters. */
    public static function safeName(string $name): string
    {
        $n = basename(str_replace('\\', '/', $name));
        $n = (string) preg_replace('/[\x00-\x1F\x7F<>:"|?*]+/u', '', $n);
        $n = trim($n);

        return $n === '' ? 'bill' : mb_substr($n, 0, 120);
    }
}
