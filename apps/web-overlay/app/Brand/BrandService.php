<?php

declare(strict_types=1);

namespace App\Brand;

/** White-label: what a workspace's own people see instead of the default name, accent colour and "powered by" line. */
final class BrandService
{
    public const MESSAGES = [
        'ok' => 'The brand was saved.',
        'not_enabled' => 'White-label is not enabled in this workspace — contact the platform admin.',
        'not_owner' => 'Only the workspace owner can change the brand.',
        'bad_input' => 'Name (max 40 characters, < > without), color (#rrggbb) or email is not valid.',
        'low_contrast' => 'The color is not clear enough against white text — give a slightly darker color.',
    ];

    public function __construct(private readonly BrandRepo $repo) {}

    public static function message(string $code): string
    {
        return self::MESSAGES[$code] ?? 'The action could not be done.';
    }

    /** WCAG contrast of white text on this #rrggbb colour. */
    public static function contrastWithWhite(string $hex): float
    {
        $lin = function (int $v): float {
            $c = $v / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };
        $l = 0.2126 * $lin(hexdec(substr($hex, 1, 2))) + 0.7152 * $lin(hexdec(substr($hex, 3, 2))) + 0.0722 * $lin(hexdec(substr($hex, 5, 2)));

        return 1.05 / ($l + 0.05);
    }

    public function save(string $tenantId, string $userId, string $name, string $color, string $email, bool $hide): string
    {
        $color = strtolower(trim($color));
        if ($color !== '' && (preg_match('/\A#[0-9a-f]{6}\z/', $color) !== 1)) {
            return 'bad_input';
        }
        if ($color !== '' && self::contrastWithWhite($color) < 4.5) {
            return 'low_contrast';                         // buttons keep white text; the colour must stay readable
        }

        return $this->repo->save($tenantId, $userId, $name, $color, $email, $hide);
    }

    /** What the layout needs; null when this workspace has no brand turned on. Never throws: branding must not break a page. */
    public function forLayout(?string $tenantId): ?array
    {
        if ($tenantId === null) {
            return null;
        }
        try {
            $b = $this->repo->get($tenantId);
        } catch (\Throwable) {
            return null;
        }
        if ($b === null || ! $b['enabled']) {
            return null;
        }
        $color = $b['color'] !== null && preg_match('/\A#[0-9a-f]{6}\z/i', $b['color']) === 1 && self::contrastWithWhite($b['color']) >= 4.5 ? strtolower($b['color']) : null;

        return ['name' => $b['brand_name'], 'color' => $color, 'support_email' => $b['support_email'], 'hide_powered_by' => $b['hide_powered_by'], 'css' => self::css($color)];
    }

    /** The only CSS ever emitted from a brand: the accent colour, already checked to be #rrggbb. */
    public static function css(?string $color): string
    {
        return $color === null || preg_match('/\A#[0-9a-f]{6}\z/', $color) !== 1 ? '' : '.bg-indigo-600,.hover\\:bg-indigo-700:hover{background-color:'.$color.' !important}';
    }

    public function settings(string $tenantId): ?array
    {
        return $this->repo->get($tenantId);
    }
}
