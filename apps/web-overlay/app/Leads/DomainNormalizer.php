<?php

declare(strict_types=1);

namespace App\Leads;

/**
 * Turns whatever a user typed into a bare lowercase ASCII domain, or null when it cannot be a public website.
 * First line of defence only; the AI engine's SSRF guard is the real one.
 */
final class DomainNormalizer
{
    public static function fromInput(string $input): ?string
    {
        $s = trim($input);
        if ($s === '' || strlen($s) > 2048 || preg_match('/[\s\x00-\x1f]/', $s) === 1) {
            return null;
        }
        $url = str_contains($s, '://') ? $s : 'https://'.$s;
        $parts = parse_url($url);
        if ($parts === false || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));

        return self::validHost($host);
    }

    public static function validHost(string $host): ?string
    {
        if ($host === '' || strlen($host) > 253) {
            return null;
        }
        if (preg_match('/[^\x20-\x7e]/', $host) === 1) {                 // non-ASCII: convert (IDN) when the extension exists
            if (! function_exists('idn_to_ascii')) {
                return null;
            }
            $host = (string) idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if ($host === '') {
                return null;
            }
        }
        $host = preg_replace('/^www\./', '', $host) ?? $host;
        if (filter_var($host, FILTER_VALIDATE_IP) !== false || str_starts_with($host, '[')) {
            return null;
        }
        if (preg_match('/\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+(?:[a-z]{2,63}|xn--[a-z0-9-]{2,59})\z/', $host) !== 1) {
            return null;
        }
        foreach (['.local', '.internal', '.localhost', '.lan', '.home', '.corp', '.test', '.invalid', '.example'] as $bad) {
            if (str_ends_with($host, $bad)) {
                return null;
            }
        }

        return $host;
    }
}
