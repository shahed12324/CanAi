<?php

declare(strict_types=1);

namespace App\Vision;

final class VisionText
{
    /** Bengali message for a failed VisionReader result; null when it worked. */
    public static function error(string $status, ?string $reason = null): ?string
    {
        return match ($status) {
            'ok' => null,
            'empty' => 'No readable text was found in the image.',
            'rejected' => $reason !== null && $reason !== 'bad_mode' ? $reason : 'The file could not be accepted.',
            'unsupported' => match ($reason) {
                'too_large' => 'The file is very large — make it smaller and give it again.',
                'encrypted_pdf' => 'A password-protected PDF cannot be read.',
                'unreadable_image', 'unreadable_pdf' => 'The image or PDF could not be opened — it may be corrupted.',
                default => 'Only an image (JPG, PNG, WebP) or a PDF can be read.',
            },
            'no_model' => 'No AI model that can read images is enabled in your plan.',
            'no_credits' => 'Not enough credits to read the image.',
            'provider' => 'AI The provider cannot read the image now. Try again shortly.',
            default => 'The image could not be read. Try again.',
        };
    }
}
