<?php

declare(strict_types=1);

namespace App\Developer;

/**
 * Which addresses a webhook may point at.  https only, a real public host name (or public IPv4), a normal port, no passwords in the address.
 * This is the first of two checks; the second (WebhookSender / Webhooks::deliver) looks at where the name really points at send time.
 */
final class WebhookUrl
{
    private const PORTS = [443, 8443];

    /** @return ?string the address to store, or null when it is not allowed */
    public static function check(string $url): ?string
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 500 || preg_match('/[\s\x00-\x1f\x7f\\\\]/', $url) === 1) {
            return null;
        }
        $p = parse_url($url);
        if ($p === false || ($p['scheme'] ?? '') !== 'https' || isset($p['user']) || isset($p['pass']) || isset($p['fragment'])) {
            return null;
        }
        $host = strtolower((string) ($p['host'] ?? ''));
        if ($host === '' || strlen($host) > 253 || str_contains($host, '[') || str_contains($host, ':')) {
            return null;                                   // no IPv6 literals
        }
        if (isset($p['port']) && ! in_array((int) $p['port'], self::PORTS, true)) {
            return null;
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return self::isPublicIp($host) ? $url : null;
        }
        if (preg_match('/\A([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}\z/', $host) !== 1) {
            return null;                                   // not a dotted public-looking name (also refuses "localhost", "intranet")
        }
        foreach (['.local', '.localhost', '.internal', '.lan', '.home', '.corp', '.test', '.invalid', '.example'] as $bad) {
            if (str_ends_with($host, $bad)) {
                return null;
            }
        }

        return $url;
    }

    /** Ranges PHP's own filter does not cover: carrier-grade NAT, test/benchmark nets, multicast. */
    private const EXTRA_BLOCKED = ['100.64.0.0/10', '192.0.0.0/24', '192.0.2.0/24', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4'];

    public static function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        $n = ip2long($ip);
        foreach (self::EXTRA_BLOCKED as $cidr) {
            [$base, $bits] = explode('/', $cidr);
            $mask = -1 << (32 - (int) $bits);
            if (($n & $mask) === (ip2long($base) & $mask)) {
                return false;
            }
        }

        return true;
    }

    public static function host(string $url): string
    {
        return strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
    }
}
