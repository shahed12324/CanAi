<?php

declare(strict_types=1);

namespace App\Studio;

use Illuminate\Support\Facades\DB;

/** The workspace's default brand kit, shaped for the design and video studios (colours, name, footer line, logo). */
final class BrandSource
{
    /** @return array{primary: string, accent: string, name: string, footer: string, logo_b64: ?string, has_kit: bool} */
    public function current(): array
    {
        $k = DB::selectOne('select name, primary_color, accent_color, email, phone, website, address, logo_b64 from brand_kits where is_default limit 1');
        if ($k === null) {
            return ['primary' => '#1f3a8a', 'accent' => '#f59e0b', 'name' => '', 'footer' => '', 'logo_b64' => null, 'has_kit' => false];
        }
        $foot = array_filter([(string) $k->phone, (string) $k->website, (string) $k->address], fn ($x) => trim($x) !== '');

        return ['primary' => (string) $k->primary_color, 'accent' => (string) $k->accent_color, 'name' => (string) $k->name, 'footer' => implode(' · ', $foot),
            'logo_b64' => $k->logo_b64 === null ? null : (string) $k->logo_b64, 'has_kit' => true];
    }
}
