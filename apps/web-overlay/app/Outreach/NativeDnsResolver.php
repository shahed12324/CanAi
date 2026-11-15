<?php

declare(strict_types=1);

namespace App\Outreach;

final class NativeDnsResolver implements DnsResolver
{
    public function txt(string $name): array
    {
        $rows = @dns_get_record($name, DNS_TXT);
        if (! is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $out[] = isset($r['entries']) && is_array($r['entries']) ? implode('', $r['entries']) : (string) ($r['txt'] ?? '');
        }

        return $out;
    }
}
