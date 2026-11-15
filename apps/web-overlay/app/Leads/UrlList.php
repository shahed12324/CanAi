<?php

declare(strict_types=1);

namespace App\Leads;

/** Parses pasted text or a CSV into unique domains (one crawl per domain). */
final class UrlList
{
    /**
     * @return array{items: list<array{url: string, domain: string}>, rejected: list<array{input: string, reason: string}>, duplicates: int, truncated: bool}
     */
    public static function fromText(string $text, int $max): array
    {
        $tokens = preg_split('/[\s,;]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return self::build($tokens, $max);
    }

    /** First column called url/website/site/domain/link (case-insensitive), otherwise the first column. */
    public static function fromCsv(string $csv, int $max): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;
        $firstLine = strtok($csv, "\n") ?: '';
        $delim = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';
        $rows = [];
        foreach (preg_split('/\r\n|\r|\n/', $csv) ?: [] as $line) {
            if (trim($line) !== '') {
                $rows[] = str_getcsv($line, $delim, '"', '');
            }
        }
        if ($rows === []) {
            return ['items' => [], 'rejected' => [], 'duplicates' => 0, 'truncated' => false];
        }
        $header = array_map(fn ($h) => strtolower(trim((string) $h)), $rows[0]);
        $col = 0;
        $hasHeader = false;
        foreach (['url', 'website', 'site', 'domain', 'link', 'web'] as $name) {
            $i = array_search($name, $header, true);
            if ($i !== false) {
                $col = (int) $i;
                $hasHeader = true;
                break;
            }
        }
        if (! $hasHeader && DomainNormalizer::fromInput((string) ($rows[0][0] ?? '')) === null) {
            $hasHeader = true;                                        // first cell is not a domain: it is a header we do not know
        }
        $tokens = [];
        foreach (array_slice($rows, $hasHeader ? 1 : 0) as $r) {
            $v = trim((string) ($r[$col] ?? ''));
            if ($v !== '') {
                $tokens[] = $v;
            }
        }

        return self::build($tokens, $max);
    }

    /** @param list<string> $tokens */
    private static function build(array $tokens, int $max): array
    {
        $items = [];
        $rejected = [];
        $seen = [];
        $dups = 0;
        $truncated = false;
        foreach ($tokens as $t) {
            $domain = DomainNormalizer::fromInput($t);
            if ($domain === null) {
                if (count($rejected) < 50) {
                    $rejected[] = ['input' => mb_substr($t, 0, 80), 'reason' => 'Not a valid website address'];
                }

                continue;
            }
            if (isset($seen[$domain])) {
                $dups++;

                continue;
            }
            if (count($items) >= $max) {
                $truncated = true;

                continue;
            }
            $seen[$domain] = true;
            $scheme = preg_match('/\Ahttp:\/\//i', $t) === 1 ? 'http' : 'https';
            $items[] = ['url' => $scheme.'://'.$domain.'/', 'domain' => $domain];
        }

        return ['items' => $items, 'rejected' => $rejected, 'duplicates' => $dups, 'truncated' => $truncated];
    }
}
