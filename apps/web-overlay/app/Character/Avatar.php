<?php

declare(strict_types=1);

namespace App\Character;

/**
 * An ORIGINAL, simple 2D avatar drawn as SVG (no existing film / comic / game character is imitated - ARCHITECTURE R14).
 * Only fixed words ever reach the drawing (kind, expression, mouth), so nothing a person typed can end up inside the SVG.
 */
final class Avatar
{
    public const EXPRESSIONS = ['neutral', 'happy', 'sad', 'surprised', 'thinking'];

    public const MOUTHS = ['closed', 'open', 'wide', 'round', 'smile'];

    private const PALETTE = [
        'male' => ['hair' => '#3b2f2a', 'shirt' => '#2f6f9f', 'skin' => '#e8b894'],
        'female' => ['hair' => '#5a3a2e', 'shirt' => '#9f4f7f', 'skin' => '#efc3a3'],
        'custom' => ['hair' => '#44505c', 'shirt' => '#4f8f6f', 'skin' => '#e9bf9f'],
    ];

    /** Unknown words fall back to the calm defaults instead of failing. */
    public static function render(string $kind, string $expression = 'neutral', string $mouth = 'closed'): string
    {
        $c = self::PALETTE[$kind] ?? self::PALETTE['custom'];
        $kind = isset(self::PALETTE[$kind]) ? $kind : 'custom';
        $e = in_array($expression, self::EXPRESSIONS, true) ? $expression : 'neutral';
        $m = in_array($mouth, self::MOUTHS, true) ? $mouth : 'closed';

        $hairBack = $kind === 'female' ? '<path d="M52 98 C40 160 52 176 70 176 L130 176 C148 176 160 160 148 98 Z" fill="'.$c['hair'].'"/>' : '';
        $hairTop = $kind === 'female'
            ? '<path d="M54 92 C50 16 150 16 146 92 C132 70 108 58 100 58 C92 58 68 70 54 92 Z" fill="'.$c['hair'].'"/>'
            : '<path d="M56 88 C50 18 150 18 144 88 C136 66 116 54 100 54 C84 54 64 66 56 88 Z" fill="'.$c['hair'].'"/>';

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 200" role="img" aria-label="Avatar">'
            .'<g>'.$hairBack
            .'<path d="M30 200 C34 160 66 150 100 150 C134 150 166 160 170 200 Z" fill="'.$c['shirt'].'"/>'
            .'<rect x="88" y="130" width="24" height="26" rx="8" fill="'.$c['skin'].'"/>'
            .'<ellipse cx="100" cy="98" rx="46" ry="52" fill="'.$c['skin'].'"/>'
            .'<ellipse cx="54" cy="102" rx="6" ry="10" fill="'.$c['skin'].'"/><ellipse cx="146" cy="102" rx="6" ry="10" fill="'.$c['skin'].'"/>'
            .$hairTop
            .self::eyes($e).self::brows($e).'<path d="M100 104 L96 118 L104 118" fill="none" stroke="#b98a6a" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>'
            .self::mouth($m, $e).'</g></svg>';
    }

    private static function eyes(string $e): string
    {
        $ink = '#2a2420';

        return match ($e) {
            'happy' => '<path d="M70 94 Q79 84 88 94" fill="none" stroke="'.$ink.'" stroke-width="4" stroke-linecap="round"/><path d="M112 94 Q121 84 130 94" fill="none" stroke="'.$ink.'" stroke-width="4" stroke-linecap="round"/>',
            'sad' => '<circle cx="79" cy="95" r="5.5" fill="'.$ink.'"/><circle cx="121" cy="95" r="5.5" fill="'.$ink.'"/><circle cx="81" cy="93" r="1.8" fill="#fff"/><circle cx="123" cy="93" r="1.8" fill="#fff"/>',
            'surprised' => '<circle cx="79" cy="93" r="9" fill="#fff" stroke="'.$ink.'" stroke-width="2"/><circle cx="121" cy="93" r="9" fill="#fff" stroke="'.$ink.'" stroke-width="2"/><circle cx="79" cy="93" r="4" fill="'.$ink.'"/><circle cx="121" cy="93" r="4" fill="'.$ink.'"/>',
            'thinking' => '<circle cx="82" cy="93" r="5.5" fill="'.$ink.'"/><circle cx="124" cy="93" r="5.5" fill="'.$ink.'"/><circle cx="84" cy="91" r="1.8" fill="#fff"/><circle cx="126" cy="91" r="1.8" fill="#fff"/>',
            default => '<circle cx="79" cy="95" r="5.5" fill="'.$ink.'"/><circle cx="121" cy="95" r="5.5" fill="'.$ink.'"/><circle cx="81" cy="93" r="1.8" fill="#fff"/><circle cx="123" cy="93" r="1.8" fill="#fff"/>',
        };
    }

    private static function brows(string $e): string
    {
        $s = 'fill="none" stroke="#3a2f29" stroke-width="3.5" stroke-linecap="round"';

        return match ($e) {
            'sad' => '<path d="M68 82 L88 77" '.$s.'/><path d="M132 82 L112 77" '.$s.'/>',
            'surprised' => '<path d="M68 74 Q79 66 90 74" '.$s.'/><path d="M110 74 Q121 66 132 74" '.$s.'/>',
            'thinking' => '<path d="M68 80 L90 80" '.$s.'/><path d="M110 72 Q122 66 134 74" '.$s.'/>',
            'happy' => '<path d="M68 80 Q79 74 90 79" '.$s.'/><path d="M110 79 Q121 74 132 80" '.$s.'/>',
            default => '<path d="M68 80 L90 79" '.$s.'/><path d="M110 79 L132 80" '.$s.'/>',
        };
    }

    private static function mouth(string $m, string $e): string
    {
        $lip = '#8a3b3b';
        $in = '#5a1f24';
        if ($m === 'closed') {                                   // the resting mouth shows the feeling
            return match ($e) {
                'happy' => '<path d="M84 128 Q100 142 116 128" fill="none" stroke="'.$lip.'" stroke-width="4" stroke-linecap="round"/>',
                'sad' => '<path d="M86 136 Q100 124 114 136" fill="none" stroke="'.$lip.'" stroke-width="4" stroke-linecap="round"/>',
                'surprised' => '<ellipse cx="100" cy="132" rx="6" ry="8" fill="'.$in.'" stroke="'.$lip.'" stroke-width="2"/>',
                'thinking' => '<path d="M90 132 L112 130" fill="none" stroke="'.$lip.'" stroke-width="4" stroke-linecap="round"/>',
                default => '<path d="M88 131 L112 131" fill="none" stroke="'.$lip.'" stroke-width="4" stroke-linecap="round"/>',
            };
        }

        return match ($m) {
            'open' => '<ellipse cx="100" cy="132" rx="11" ry="10" fill="'.$in.'" stroke="'.$lip.'" stroke-width="3"/>',
            'wide' => '<path d="M82 128 Q100 146 118 128 Q100 134 82 128 Z" fill="'.$in.'" stroke="'.$lip.'" stroke-width="3" stroke-linejoin="round"/>',
            'round' => '<ellipse cx="100" cy="132" rx="6" ry="7" fill="'.$in.'" stroke="'.$lip.'" stroke-width="3"/>',
            default => '<path d="M84 128 Q100 144 116 128 Z" fill="'.$in.'" stroke="'.$lip.'" stroke-width="3" stroke-linejoin="round"/>',   // smile
        };
    }
}
