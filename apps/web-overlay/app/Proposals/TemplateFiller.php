<?php

declare(strict_types=1);

namespace App\Proposals;

/** Fills {{placeholders}} in template sections in a single pass, so a value that itself contains "{{x}}" is never expanded. */
final class TemplateFiller
{
    /**
     * @param  list<array{heading?: string, body?: string}>  $sections
     * @param  array<string, string>  $vars
     * @return list<array{heading: string, body: string}>
     */
    public static function fill(array $sections, array $vars): array
    {
        $out = [];
        foreach ($sections as $s) {
            if (! is_array($s)) {
                continue;
            }
            $out[] = ['heading' => self::one((string) ($s['heading'] ?? ''), $vars), 'body' => self::one((string) ($s['body'] ?? ''), $vars)];
        }

        return $out;
    }

    /** @param array<string, string> $vars */
    public static function one(string $text, array $vars): string
    {
        return preg_replace_callback('/\{\{\s*([a-z_]{1,30})\s*\}\}/', fn (array $m) => $vars[$m[1]] ?? '', $text) ?? $text;
    }

    /**
     * The "what we found on your site" bullet list for {{gaps_list}}.
     *
     * @param  array<string, mixed>  $analysis  companies.analysis
     */
    public static function gapsList(array $analysis, string $lang): string
    {
        $en = [
            'no_https' => ['The site is not served over HTTPS', 'Website security / SSL'],
            'no_viewport' => ['The site is not mobile-friendly', 'Responsive website redesign'],
            'no_title' => ['Pages have no title', 'SEO'],
            'no_description' => ['No meta description', 'SEO'],
            'no_analytics' => ['No analytics installed', 'Tracking and analytics setup'],
            'no_fb_pixel' => ['No Facebook Pixel', 'Meta Ads tracking and advertising'],
            'no_contact_form' => ['No contact form', 'Lead-capture form'],
            'no_social' => ['No social media links', 'Social media management'],
            'no_whatsapp' => ['No WhatsApp contact', 'WhatsApp chat and automation'],
            'no_phone' => ['No phone number shown', 'Contact information improvements'],
            'old_cms' => ['Old or generic site builder', 'Custom website'],
        ];
        $lines = [];
        foreach (array_slice((array) ($analysis['gaps'] ?? []), 0, 8) as $g) {
            if (! is_array($g)) {
                continue;
            }
            $code = (string) ($g['code'] ?? '');
            [$label, $service] = $lang === 'en' && isset($en[$code]) ? $en[$code] : [(string) ($g['label'] ?? ''), (string) ($g['service'] ?? '')];
            $label = trim(preg_replace('/\s+/u', ' ', $label) ?? '');
            if ($label === '') {
                continue;
            }
            $lines[] = '- '.$label.($service !== '' ? ' → '.trim($service) : '');
        }

        return $lines !== [] ? implode("\n", $lines) : ($lang === 'en' ? '- (describe what the client needs here)' : '- (Write the client’s need here)');
    }
}
