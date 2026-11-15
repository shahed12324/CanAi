<?php

declare(strict_types=1);

namespace App\Seo;

use App\Leads\DomainNormalizer;

/** Pure helpers: what the owner typed -> a safe start URL / keyword, the engine's result -> the small shape we store, and every message in Bengali. */
final class SeoText
{
    public const STATUSES = ['ok', 'blocked_by_robots', 'unreachable', 'unsafe_url', 'not_html', 'timeout'];

    /** "example.com", "www.example.com/x" or "http://example.com" -> "https://example.com/" (http kept only when typed); null when it cannot be a public website. The engine's SSRF guard is the real defence. */
    public static function url(string $input): ?string
    {
        $s = trim($input);
        if ($s === '' || strlen($s) > 2048 || preg_match('/[\s\x00-\x1f]/', $s) === 1) {
            return null;
        }
        $parts = parse_url(str_contains($s, '://') ? $s : 'https://'.$s);
        if ($parts === false || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return null;
        }
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        if (! in_array($scheme, ['http', 'https'], true) || DomainNormalizer::validHost($host) === null) {
            return null;
        }
        if (preg_match('/[^\x20-\x7e]/', $host) === 1) {                 // IDN: the engine wants ASCII
            $host = (string) idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
        }

        return $scheme.'://'.$host.'/';
    }

    public static function keyword(?string $input): ?string
    {
        $k = trim((string) preg_replace('/[\s\x00-\x1f]+/u', ' ', (string) $input));

        return $k === '' ? null : mb_substr($k, 0, 80);
    }

    /**
     * Keep only the fields we render, with sane sizes, whatever the engine sent.
     *
     * @param  array<string, mixed>  $r
     * @return array<string, mixed>
     */
    public static function clean(array $r): array
    {
        $s = static fn ($v, int $n): string => mb_substr(is_scalar($v) ? (string) $v : '', 0, $n);
        $out = ['status' => in_array($r['status'] ?? '', self::STATUSES, true) ? $r['status'] : 'unreachable', 'url' => $s($r['url'] ?? '', 2048), 'final_url' => $s($r['final_url'] ?? '', 2048), 'domain' => $s($r['domain'] ?? '', 253)];
        if (isset($r['error'])) {
            $out['error'] = $s($r['error'], 80);
        }
        if ($out['status'] !== 'ok') {
            return $out;
        }
        $site = is_array($r['site'] ?? null) ? $r['site'] : [];
        $out += ['score' => max(0, min(100, (int) ($r['score'] ?? 0))), 'pages_checked' => max(0, min(8, (int) ($r['pages_checked'] ?? 0))), 'keyword' => isset($r['keyword']) ? $s($r['keyword'], 80) : null,
            'site' => ['https' => (bool) ($site['https'] ?? false), 'robots_txt' => (bool) ($site['robots_txt'] ?? false), 'sitemap' => (bool) ($site['sitemap'] ?? false), 'sitemap_urls' => max(0, (int) ($site['sitemap_urls'] ?? 0))], 'issues' => [], 'pages' => []];
        foreach (array_slice(is_array($r['issues'] ?? null) ? $r['issues'] : [], 0, 40) as $i) {
            if (! is_array($i) || ! isset($i['code'])) {
                continue;
            }
            $sev = in_array($i['severity'] ?? '', ['high', 'medium', 'low'], true) ? $i['severity'] : 'low';
            $out['issues'][] = ['code' => $s($i['code'], 40), 'severity' => $sev, 'label' => $s($i['label'] ?? '', 200), 'advice' => $s($i['advice'] ?? '', 400), 'count' => max(0, (int) ($i['count'] ?? 0)),
                'pages' => array_values(array_map(fn ($u) => $s($u, 2048), array_slice(is_array($i['pages'] ?? null) ? $i['pages'] : [], 0, 5)))];
        }
        foreach (array_slice(is_array($r['pages'] ?? null) ? $r['pages'] : [], 0, 8) as $p) {
            if (is_array($p)) {
                $out['pages'][] = ['url' => $s($p['url'] ?? '', 2048), 'status' => max(0, (int) ($p['status'] ?? 0)), 'title' => $s($p['title'] ?? '', 160), 'words' => max(0, (int) ($p['words'] ?? 0)),
                    'issues' => array_values(array_map(fn ($c) => $s($c, 40), array_slice(is_array($p['issues'] ?? null) ? $p['issues'] : [], 0, 30)))];
            }
        }

        return $out;
    }

    public static function grade(int $score): string
    {
        return $score >= 85 ? 'Good' : ($score >= 60 ? 'Fair' : 'Needs improvement');
    }

    public static function severity(string $sev): string
    {
        return ['high' => 'Urgent', 'medium' => 'Medium', 'low' => 'Small'][$sev] ?? 'Small';
    }

    /** Message for the outcome of a run (service outcome) or of the engine's own status. */
    public static function message(string $code): string
    {
        return [
            'ok' => 'The audit is complete.',
            'bad_url' => 'The website address is not valid. For example: example.com',
            'limit' => 'Today’s audit limit (30 in 24 hours) is used up. Try again after a while.',
            'client' => 'The client was not found or is archived.',
            'too_large' => 'The result became too large to save.',
            'engine_down' => 'An audit cannot be run right now. Try again shortly.',
            'missing' => 'The audit was not found.',
            'error' => 'Something went wrong. Try again.',
            'unsafe_url' => 'This address cannot be audited (only ordinary public websites).',
            'blocked_by_robots' => 'The site’s robots.txt forbids robots from entering, so we did not audit.',
            'unreachable' => 'The site could not be opened.',
            'not_html' => 'The address is not a web page.',
            'timeout' => 'The site did not respond in time.',
        ][$code] ?? 'Something went wrong.';
    }
}
