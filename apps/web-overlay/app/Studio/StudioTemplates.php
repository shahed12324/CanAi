<?php

declare(strict_types=1);

namespace App\Studio;

/**
 * Ready-made starting points for a design: Bengali words, a theme, the sizes and a suggestion for the AI picture.
 * They only prefill the form; the person changes everything before saving, and nothing here promises a result or invents a number.
 */
final class StudioTemplates
{
    /** @return array<string, array{name: string, headline: string, subline: string, cta: string, theme: string, sizes: list<string>, prompt: string}> */
    public static function all(): array
    {
        return [
            'offer' => ['name' => 'Offer / discount', 'headline' => 'Special offer', 'subline' => 'For a limited time — get it today', 'cta' => 'Order now', 'theme' => 'dark', 'sizes' => ['post', 'story'],
                'prompt' => 'A beautiful product photo, a bright-colored background scene, no text'],
            'new_product' => ['name' => 'New product', 'headline' => 'Just arrived', 'subline' => 'Now available with us', 'cta' => 'See details', 'theme' => 'brand', 'sizes' => ['post', 'portrait'],
                'prompt' => 'A studio photo of the product on a clean white table, soft light, no text'],
            'event' => ['name' => 'Occasion / event', 'headline' => 'You are invited', 'subline' => 'Write the date, time and place here', 'cta' => 'Confirm your seat', 'theme' => 'dark', 'sizes' => ['post', 'cover'],
                'prompt' => 'A festive atmosphere, string lights, blurred background, no text'],
            'thanks' => ['name' => 'ধন্যবাদ', 'headline' => 'Thank you', 'subline' => 'Being by your side is our strength', 'cta' => '', 'theme' => 'light', 'sizes' => ['post'],
                'prompt' => 'A soft-colored background of flowers and leaves, no text'],
            'festival' => ['name' => 'Festival greetings', 'headline' => 'Best wishes', 'subline' => 'Good wishes for you and your family', 'cta' => '', 'theme' => 'brand', 'sizes' => ['post', 'story'],
                'prompt' => 'A festive mood, warm colors, light, no text'],
            'cover' => ['name' => 'Page cover', 'headline' => 'Welcome to our page', 'subline' => 'The best quality service, at your doorstep', 'cta' => 'Message us', 'theme' => 'brand', 'sizes' => ['cover'],
                'prompt' => 'A wide panorama background, soft gradient, no text'],
        ];
    }

    /** @return ?array{name: string, headline: string, subline: string, cta: string, theme: string, sizes: list<string>, prompt: string} */
    public static function get(?string $key): ?array
    {
        return $key === null ? null : (self::all()[$key] ?? null);
    }
}
