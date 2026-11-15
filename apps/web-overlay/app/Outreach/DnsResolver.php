<?php

declare(strict_types=1);

namespace App\Outreach;

interface DnsResolver
{
    /** @return list<string> all TXT strings of a name (empty when none or on error) */
    public function txt(string $name): array;
}
