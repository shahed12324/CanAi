<?php

declare(strict_types=1);

namespace App\Crm;

use App\Leads\DomainNormalizer;

/** CSV in and out for leads. Input is untrusted; output is safe to open in a spreadsheet (no formula injection). */
final class LeadCsv
{
    public const STAGE_LABELS_BN = ['New' => 'new', 'Contacted' => 'contacted', 'Qualified' => 'qualified', 'Proposals' => 'proposal', 'Won' => 'won', 'Lost' => 'lost'];

    private const ALIASES = [
        'website' => ['website', 'url', 'site', 'domain', 'web', 'link', 'ওয়েবসাইট'],
        'name' => ['name', 'company', 'company name', 'business', 'নাম'],
        'email' => ['email', 'emails', 'e-mail', 'mail', 'ইমেইল'],
        'phone' => ['phone', 'phones', 'mobile', 'tel', 'whatsapp', 'ফোন', 'মোবাইল'],
        'tags' => ['tags', 'tag', 'ট্যাগ'],
        'stage' => ['stage', 'status', 'ধাপ'],
        'note' => ['note', 'notes', 'comment', 'নোট'],
    ];

    /**
     * @return array{rows: list<array{line: int, domain: string, name: string, emails: list<string>, phones: list<string>, tags: list<string>, stage: ?string, note: string}>, rejected: list<array{line: int, reason: string}>, duplicates: int, truncated: bool, error: ?string}
     */
    public static function parse(string $csv, int $max): array
    {
        $out = ['rows' => [], 'rejected' => [], 'duplicates' => 0, 'truncated' => false, 'error' => null];
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;
        $first = strtok($csv, "\n") ?: '';
        $delim = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';

        $h = fopen('php://temp', 'r+');
        fwrite($h, $csv);
        rewind($h);
        $header = null;
        $col = [];
        $line = 0;
        $seen = [];
        while (($r = fgetcsv($h, 20000, $delim, '"', '')) !== false) {
            $line++;
            if ($r === [null] || $r === []) {
                continue;
            }
            if ($header === null) {
                $header = array_map(fn ($x) => mb_strtolower(trim((string) $x)), $r);
                foreach (self::ALIASES as $key => $names) {
                    foreach ($names as $n) {
                        $i = array_search($n, $header, true);
                        if ($i !== false) {
                            $col[$key] = (int) $i;

                            break;
                        }
                    }
                }
                if (! isset($col['website'])) {
                    $out['error'] = 'no_website_column';

                    break;
                }

                continue;
            }
            $cell = fn (string $k): string => isset($col[$k]) ? trim((string) ($r[$col[$k]] ?? '')) : '';
            $domain = DomainNormalizer::fromInput($cell('website'));
            if ($domain === null) {
                self::reject($out, $line, 'The website address is not valid');

                continue;
            }
            if (isset($seen[$domain])) {
                $out['duplicates']++;

                continue;
            }
            if (count($out['rows']) >= $max) {
                $out['truncated'] = true;

                continue;
            }
            $seen[$domain] = true;

            $emails = [];
            foreach (preg_split('/[\s,;|]+/', $cell('email'), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $e) {
                $e = strtolower($e);
                if (strlen($e) <= 120 && filter_var($e, FILTER_VALIDATE_EMAIL) !== false && ! in_array($e, $emails, true)) {
                    $emails[] = $e;
                }
            }
            $phones = [];
            foreach (preg_split('/[;,|\/]+/', $cell('phone'), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $p) {
                $n = BdPhone::normalize($p);
                if ($n !== null && ! in_array($n, $phones, true)) {
                    $phones[] = $n;
                }
            }
            $stage = mb_strtolower($cell('stage'));
            $stage = self::STAGE_LABELS_BN[$cell('stage')] ?? (in_array($stage, ['new', 'contacted', 'qualified', 'proposal', 'won', 'lost'], true) ? $stage : null);

            $out['rows'][] = [
                'line' => $line,
                'domain' => $domain,
                'name' => mb_substr($cell('name'), 0, 200),
                'emails' => array_slice($emails, 0, 5),
                'phones' => array_slice($phones, 0, 5),
                'tags' => TagNormalizer::parse($cell('tags'))['tags'],
                'stage' => $stage,
                'note' => mb_substr($cell('note'), 0, 1000),
            ];
        }
        fclose($h);
        if ($header === null && $out['error'] === null) {
            $out['error'] = 'empty';
        }

        return $out;
    }

    /** @param array<string, mixed> $out */
    private static function reject(array &$out, int $line, string $reason): void
    {
        if (count($out['rejected']) < 50) {
            $out['rejected'][] = ['line' => $line, 'reason' => $reason];
        }
    }

    /** Spreadsheets run cells that start with = + - @ (or tab/CR) as formulas. A plain +8801… phone number is the one safe exception. */
    public static function safeCell(string $v): string
    {
        if ($v !== '' && preg_match('/\A[=+\-@\t\r]/', $v) === 1 && preg_match('/\A\+\d{8,15}\z/', $v) !== 1) {
            return "'".$v;
        }

        return $v;
    }

    /** @param list<list<string>> $rows  First row is the header. UTF-8 BOM + CRLF so Excel reads Bangla correctly. */
    public static function toCsv(array $rows): string
    {
        $h = fopen('php://temp', 'r+');
        foreach ($rows as $row) {
            fputcsv($h, array_map(fn ($v) => self::safeCell((string) $v), $row), ',', '"', '', "\r\n");
        }
        rewind($h);
        $s = (string) stream_get_contents($h);
        fclose($h);

        return "\xEF\xBB\xBF".$s;
    }
}
