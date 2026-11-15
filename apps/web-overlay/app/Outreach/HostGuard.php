<?php

declare(strict_types=1);

namespace App\Outreach;

/** The SMTP host is typed by a user and our server connects to it, so it must be a public address (no SSRF into the private network). */
final class HostGuard
{
    public static function publicIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false
            && ! str_starts_with(strtolower($ip), '::ffff:')            // IPv4-mapped IPv6 can smuggle a private v4
            && ! str_starts_with(strtolower($ip), 'fe80:')
            && ! str_starts_with(strtolower($ip), 'fc')
            && ! str_starts_with(strtolower($ip), 'fd');
    }

    /** @return list<string> */
    public static function resolve(string $host): array
    {
        $v4 = @gethostbynamel($host) ?: [];
        $v6 = array_column(@dns_get_record($host, DNS_AAAA) ?: [], 'ipv6');

        return array_values(array_merge($v4, $v6));
    }

    /** @param callable(string): list<string> $resolve host => IPs */
    public static function allowed(string $host, callable $resolve): bool
    {
        $host = strtolower(trim($host));
        if ($host === '' || strlen($host) > 253 || preg_match('/\s/', $host)) {
            return false;
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return self::publicIp($host);
        }
        if ($host === 'localhost' || preg_match('/\.(local|localhost|internal|lan|home|corp|test|invalid|example)$/', $host) || ! str_contains($host, '.')) {
            return false;
        }
        $ips = $resolve($host);
        if ($ips === []) {
            return false;
        }
        foreach ($ips as $ip) {
            if (! self::publicIp($ip)) {
                return false;
            }
        }

        return true;
    }
}
