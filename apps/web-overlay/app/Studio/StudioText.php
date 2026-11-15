<?php

declare(strict_types=1);

namespace App\Studio;

/** Bengali messages for every outcome of the studio. */
final class StudioText
{
    public const MESSAGES = [
        'no_model' => 'No model is enabled for making images. The admin should enable the "image_gen" capability on a model (OpenAI or a compatible provider).',
        'no_credits' => 'Credits ran out.',
        'provider' => 'The image service is not responding now. Try again shortly.',
        'unsupported' => 'This provider cannot make images (an OpenAI or compatible provider is needed).',
        'failed' => 'Could not be done. Try again.',
        'bad_input' => 'The details are not valid.',
        'bad_title' => 'Give the design a name (max 120 characters).',
        'bad_text' => 'The text is too long (headline 200, subtitle 400, button 100 characters).',
        'bad_sizes' => 'Pick at least one size.',
        'bad_theme' => 'The theme is not valid.',
        'bad_photo' => 'The image must be PNG, JPEG or WEBP (max 4 MB).',
        'bad_prompt' => 'Write a description of the image (3–1500 characters).',
        'limit' => 'At most 40 designs can be kept. Delete some old ones.',
        'not_found' => 'Not found.',
        'engine' => 'The design could not be drawn. Check that the engine is running.',
        'no_shaping' => 'The server lacks the component to draw Bengali text (libfribidi) — the admin should on the server `apt install libfribidi0` and restart the engine.',
        'bad_brand' => 'The brand kit’s colors or logo are not right — see the brand kit page.',
        'review_limit' => '10 links of this design are still open. Close a few.',
        'bad_size' => 'This size is not in the design.',
        'unchanged' => 'Nothing was changed.',
        'saved' => 'The design was changed. The earlier version "Earlier versions" was kept in the list.',
        'restored' => 'The earlier version was restored. The version before the change was also kept in the list.',
    ];

    public const SIZES = [
        'post' => ['Post', '১০৮০×১০৮০ — Facebook / Instagram'],
        'portrait' => ['Portrait post', '1080×1350 — more space in the feed'],
        'story' => ['Story / reel', '১০৮০×১৯২০'],
        'cover' => ['Page cover', '1640×624 — Facebook cover'],
        'ad' => ['Ad / link', '১২০০×৬২৮'],
    ];

    public const THEMES = ['dark' => 'Dark', 'light' => 'Light', 'brand' => 'Brand color'];

    public const REVIEW_MESSAGES = [
        'ok' => 'Thank you, your reply has been recorded.',
        'not_found' => 'This link is not valid.',
        'expired' => 'This link has expired.',
        'decided' => 'You have already replied on this design.',
        'revoked' => 'This link has been disabled.',
        'bad_input' => 'Write what to change (max 1000 characters).',
    ];

    public static function message(string $code): string
    {
        return self::MESSAGES[$code] ?? 'Could not be done.';
    }

    public static function answer(string $code): string
    {
        return self::REVIEW_MESSAGES[$code] ?? 'Could not be done.';
    }
}
