<?php

declare(strict_types=1);

namespace App\Reports;

use App\Proposals\DocDate;
use App\Proposals\Money;

/**
 * Turns copied ad / social numbers into the payload the document renderer already knows (a "proposal" document: sections + one table + brand).  Pure — no AI, no network, no cost.
 * Rules that keep a report honest:
 *   - amounts of different currencies are NEVER added together (each ad account is reported in its own currency);
 *   - a comparison with the previous period is shown only when that period has a non-zero base;
 *   - an account whose data is old (or never synced) gets an explicit warning instead of silently showing small numbers;
 *   - nothing is invented: the "what we did" text is exactly what the person typed.
 */
final class ReportBuilder
{
    private const TOP = 8;

    private const T = [
        'bn' => [
            'week' => 'সাপ্তাহিক মার্কেটিং রিপোর্ট', 'month' => 'মাসিক মার্কেটিং রিপোর্ট', 'summary' => 'সারসংক্ষেপ', 'period' => 'সময়কাল', 'done' => 'এই সময়ে আমরা যা করেছি', 'top' => 'শীর্ষ ক্যাম্পেইন', 'social' => 'সোশ্যাল মিডিয়া', 'notes' => 'তথ্যসূত্র ও নোট',
            'spend' => 'খরচ', 'clicks' => 'ক্লিক', 'impressions' => 'ইম্প্রেশন', 'results' => 'ফলাফল', 'conversions' => 'কনভার্সন', 'cpr' => 'প্রতি ফলাফলে খরচ', 'cpc' => 'প্রতি ক্লিকে খরচ', 'ctr' => 'ক্লিক-হার', 'vs_week' => 'আগের সপ্তাহের তুলনায়',
            'posts' => 'প্রকাশিত পোস্ট', 'reactions' => 'রিঅ্যাকশন', 'comments' => 'কমেন্ট', 'shares' => 'শেয়ার', 'reach' => 'রিচ', 'best' => 'সবচেয়ে সাড়া পাওয়া পোস্ট', 'stale' => 'সতর্কতা: এই অ্যাকাউন্টের তথ্য শেষ হালনাগাদ হয়েছে অনেক আগে (বা কখনো হয়নি), তাই সংখ্যা অসম্পূর্ণ হতে পারে।',
            'tbl_item' => 'অ্যাকাউন্ট', 'tbl_clicks' => 'ক্লিক', 'tbl_spend' => 'খরচ', 'tbl_results' => 'ফলাফল', 'tbl_title' => 'বিজ্ঞাপনের সারাংশ', 'tbl_note' => 'প্রতিটি অ্যাকাউন্টের মুদ্রা আলাদা, তাই সব মিলিয়ে মোট দেখানো হয়নি।',
            'src' => 'বিজ্ঞাপনের সংখ্যা Meta ও Google Ads থেকে নেওয়া কপি (প্রতি আধা/এক ঘণ্টায় হালনাগাদ); সোশ্যাল সংখ্যা প্রকাশিত পোস্টের সর্বশেষ পাওয়া হিসাব। "ফলাফল" Meta-র বিজ্ঞাপনের উদ্দেশ্য অনুযায়ী, "কনভার্সন" আপনার Google Ads-এ সেট করা কনভার্সন অ্যাকশন অনুযায়ী।',
            'meta' => 'Meta (ফেসবুক/ইনস্টাগ্রাম)', 'google' => 'Google Ads', 'noact' => 'এই সময়ে কোনো কার্যক্রমের তথ্য নেই।',
        ],
        'en' => [
            'week' => 'Weekly Marketing Report', 'month' => 'Monthly Marketing Report', 'summary' => 'Summary', 'period' => 'Period', 'done' => 'What we did this period', 'top' => 'Top campaigns', 'social' => 'Social media', 'notes' => 'Sources and notes',
            'spend' => 'Spend', 'clicks' => 'Clicks', 'impressions' => 'Impressions', 'results' => 'Results', 'conversions' => 'Conversions', 'cpr' => 'Cost per result', 'cpc' => 'Cost per click', 'ctr' => 'CTR', 'vs_week' => 'vs previous week',
            'posts' => 'Posts published', 'reactions' => 'Reactions', 'comments' => 'Comments', 'shares' => 'Shares', 'reach' => 'Reach', 'best' => 'Best-performing post', 'stale' => 'Warning: this account was last synced long ago (or never), so the numbers may be incomplete.',
            'tbl_item' => 'Account', 'tbl_clicks' => 'Clicks', 'tbl_spend' => 'Spend', 'tbl_results' => 'Results', 'tbl_title' => 'Advertising summary', 'tbl_note' => 'Each account is in its own currency, so no grand total is shown.',
            'src' => 'Ad figures are copies taken from Meta and Google Ads (refreshed every 30–60 minutes); social figures are the latest counts found for published posts. "Results" follow the objective of the Meta ad; "Conversions" follow the conversion actions set up in your Google Ads account.',
            'meta' => 'Meta (Facebook/Instagram)', 'google' => 'Google Ads', 'noact' => 'No activity data for this period.',
        ],
    ];

    private const PLATFORMS = ['facebook' => 'Facebook', 'instagram' => 'Instagram', 'youtube' => 'YouTube', 'tiktok' => 'TikTok', 'linkedin' => 'LinkedIn', 'other' => 'Other'];

    /**
     * @param  array{language: string, kind: string, range: array{from: string, to: string, prev_from: ?string, prev_to: ?string, days: int}, today: \DateTimeInterface, client_name?: string, notes?: string, brand?: ?array<string, mixed>, brand_fallback_name?: string,
     *   ads: array{meta: list<array<string, mixed>>, google: list<array<string, mixed>>}, prev?: array{meta?: ?list<array<string, mixed>>, google?: ?list<array<string, mixed>>}, social?: ?array<string, mixed>}  $in
     * @return array{error: ?string, doc: array<string, mixed>}
     */
    public static function build(array $in): array
    {
        $lang = $in['language'] === 'en' ? 'en' : 'bn';
        $L = self::T[$lang];
        $kind = $in['kind'] === 'month' ? 'month' : 'week';
        $groups = self::groups($in['ads']['meta'], 'meta') + self::groups($in['ads']['google'], 'google');
        $prevGroups = [];
        foreach (['meta', 'google'] as $p) {
            if (($in['prev'][$p] ?? null) !== null) {
                $prevGroups += self::groups($in['prev'][$p], $p);
            }
        }
        $hasPrev = isset($in['prev']) && $in['range']['prev_from'] !== null;
        $social = $in['social'] ?? null;
        $socialActive = $social !== null && ($social['posts'] !== [] || $social['reactions'] > 0 || $social['reach'] > 0);
        if ($groups === [] && ! $socialActive) {
            return ['error' => 'no_data', 'doc' => []];
        }
        $now = \DateTimeImmutable::createFromInterface($in['today']);
        $num = fn (int|float $n, int $d = 0) => self::digits(number_format($n, $d), $lang);
        $money = fn (float $v, string $cur) => $lang === 'bn' ? self::digits(number_format($v, 2), $lang).' '.$cur : $cur.' '.number_format($v, 2);
        $pct = function (float $cur, float $prev) use ($lang): ?string {
            if ($prev <= 0) {
                return null;
            }
            $p = (int) round(($cur - $prev) / $prev * 100);

            return self::digits(($p > 0 ? '+' : ($p < 0 ? '−' : '')).abs($p).'%', $lang);
        };
        $period = self::digits(self::d($in['range']['from'], $lang).' – '.self::d($in['range']['to'], $lang), $lang);

        $sections = [];
        $lines = [];
        $rows = [];
        $currencies = [];
        foreach ($groups as $key => $g) {
            $t = self::totals($g['rows']);
            $label = $L[$g['platform']].' — '.$g['account'];
            $resLabel = $g['platform'] === 'google' ? $L['conversions'] : $L['results'];
            $parts = ["{$L['spend']} ".$money($t['spend'], $g['currency']), "{$L['clicks']} ".$num($t['clicks']), "{$resLabel} ".self::res($t['results'], $lang)];
            if ($t['results'] > 0) {
                $parts[] = "{$L['cpr']} ".$money($t['spend'] / $t['results'], $g['currency']);
            }
            if ($t['clicks'] > 0 && $t['spend'] > 0) {
                $parts[] = "{$L['cpc']} ".$money($t['spend'] / $t['clicks'], $g['currency']);
            }
            if ($t['impressions'] > 0) {
                $parts[] = "{$L['ctr']} ".self::digits(number_format($t['clicks'] / $t['impressions'] * 100, 2), $lang).'%';
            }
            $line = '- '.self::clean($label, 120).': '.implode(' · ', $parts);
            if ($hasPrev && isset($prevGroups[$key])) {
                $pt = self::totals($prevGroups[$key]['rows']);
                $ch = array_filter([$pct($t['spend'], $pt['spend']) !== null ? "{$L['spend']} ".$pct($t['spend'], $pt['spend']) : null, $pct((float) $t['clicks'], (float) $pt['clicks']) !== null ? "{$L['clicks']} ".$pct((float) $t['clicks'], (float) $pt['clicks']) : null,
                    $pct($t['results'], $pt['results']) !== null ? "{$resLabel} ".$pct($t['results'], $pt['results']) : null]);
                if ($ch !== []) {
                    $line .= ' ('.$L['vs_week'].': '.implode(', ', $ch).')';
                }
            }
            $lines[] = $line;
            $currencies[$g['currency']] = true;
            $rows[] = ['name' => self::clean($L[$g['platform']].' · '.$g['account'], 200), 'qty' => $num($t['clicks']), 'unit_price' => $money($t['spend'], $g['currency']), 'total' => self::res($t['results'], $lang)];

            $body = [];
            foreach (array_slice($g['rows'], 0, self::TOP) as $r) {
                $body[] = '- '.self::clean($r['name'], 80).' — '.$L['spend'].' '.$money((float) $r['spend'], $g['currency']).', '.$L['clicks'].' '.$num($r['clicks']).', '.$resLabel.' '.self::res((float) $r['results'], $lang);
            }
            if (self::stale($g['synced_at'], $now)) {
                array_unshift($body, $L['stale']."\n");
            }
            $sections[] = ['heading' => $L['top'].': '.self::clean($label, 100), 'body' => implode("\n", $body)];
        }

        $summary = ["{$L['period']}: {$period}"];
        $summary = array_merge($summary, $lines);
        if ($socialActive) {
            $n = array_sum(array_column($social['posts'], 'count'));
            $summary[] = '- '.$L['social'].': '.$L['posts'].' '.$num($n).' · '.$L['reactions'].' '.$num($social['reactions']).' · '.$L['reach'].' '.$num($social['reach']);
        }
        array_unshift($sections, ['heading' => $L['summary'], 'body' => implode("\n", array_map(fn ($l) => $l, self::bulletize($summary)))]);

        $notes = self::notes((string) ($in['notes'] ?? ''));
        if ($notes !== '') {
            array_splice($sections, 1, 0, [['heading' => $L['done'], 'body' => $notes]]);
        }
        if ($socialActive) {
            $b = [];
            foreach ($social['posts'] as $p) {
                $b[] = '- '.(self::PLATFORMS[$p['platform']] ?? 'Other').': '.$num($p['count']);
            }
            $b[] = '- '.$L['reactions'].' '.$num($social['reactions']).' · '.$L['comments'].' '.$num($social['comments']).' · '.$L['shares'].' '.$num($social['shares']).' · '.$L['reach'].' '.$num($social['reach']);
            if ($social['top'] !== null) {
                $b[] = '- '.$L['best'].': '.self::clean($social['top']['title'], 80).' ('.(self::PLATFORMS[$social['top']['platform']] ?? 'Other').', '.$L['reactions'].' '.$num($social['top']['reactions']).')';
            }
            $sections[] = ['heading' => $L['social'], 'body' => implode("\n", $b)];
        }
        $sections[] = ['heading' => $L['notes'], 'body' => $L['src']];

        $brand = $in['brand'] ?? null;
        $doc = [
            'language' => $lang,
            'title' => $L[$kind],
            'client_name' => self::clean((string) ($in['client_name'] ?? ''), 200),
            'date_text' => DocDate::format($in['today'], $lang),
            'sections' => array_slice($sections, 0, 30),
            'brand' => ['name' => (string) ($brand['name'] ?? $in['brand_fallback_name'] ?? ''), 'primary' => (string) ($brand['primary_color'] ?? '#1f3a5f'), 'accent' => (string) ($brand['accent_color'] ?? '#e07a1f'), 'logo_b64' => ($brand['logo_b64'] ?? null) ?: null,
                'email' => (string) ($brand['email'] ?? ''), 'phone' => (string) ($brand['phone'] ?? ''), 'website' => (string) ($brand['website'] ?? ''), 'address' => (string) ($brand['address'] ?? '')],
            'labels' => ['prepared_for' => $lang === 'bn' ? 'প্রস্তুত করা হয়েছে' : 'Prepared for', 'date' => $lang === 'bn' ? 'তারিখ' : 'Date', 'item' => $L['tbl_item'], 'qty' => $L['tbl_clicks'], 'unit_price' => $L['tbl_spend'], 'amount' => $L['tbl_results'], 'pricing' => $L['tbl_title'],
                'portfolio' => 'Portfolio', 'contact' => $lang === 'bn' ? 'Contact' : 'Contact'],
            'portfolio' => [],
        ];
        if ($rows !== []) {
            $doc['pricing'] = ['rows' => array_slice($rows, 0, 60), 'totals' => [], 'note' => count($rows) > 1 || count($currencies) > 1 ? $L['tbl_note'] : ''];
        }

        return ['error' => null, 'doc' => $doc];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, array{platform: string, account: string, currency: string, synced_at: ?string, rows: list<array<string, mixed>>}>
     */
    private static function groups(array $rows, string $platform): array
    {
        $out = [];
        foreach ($rows as $r) {
            $k = $platform.':'.$r['account_id'];
            $out[$k] ??= ['platform' => $platform, 'account' => $r['account'], 'currency' => $r['currency'], 'synced_at' => $r['synced_at'], 'rows' => []];
            $out[$k]['rows'][] = $r;
        }

        return $out;
    }

    /** @param  list<array<string, mixed>>  $rows @return array{spend: float, impressions: int, clicks: int, results: float} */
    private static function totals(array $rows): array
    {
        $t = ['spend' => 0.0, 'impressions' => 0, 'clicks' => 0, 'results' => 0.0];
        foreach ($rows as $r) {
            $t['spend'] += (float) $r['spend'];
            $t['impressions'] += (int) $r['impressions'];
            $t['clicks'] += (int) $r['clicks'];
            $t['results'] += (float) $r['results'];
        }

        return $t;
    }

    private static function res(float $v, string $lang): string
    {
        return self::digits(floor($v) === $v ? number_format($v, 0) : number_format($v, 1), $lang);
    }

    private static function digits(string $s, string $lang): string
    {
        return $lang === 'bn' ? Money::toBnDigits($s) : $s;
    }

    private static function d(string $iso, string $lang): string
    {
        return DocDate::format(new \DateTimeImmutable($iso), $lang);
    }

    private static function stale(?string $syncedAt, \DateTimeImmutable $now): bool
    {
        $ts = $syncedAt === null ? false : strtotime($syncedAt);

        return $ts === false || $ts < $now->getTimestamp() - 2 * 86400;
    }

    private static function clean(string $s, int $max): string
    {
        return trim(mb_substr((string) preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $s), 0, $max));
    }

    /** What the person typed, kept as typed (line breaks and "- " bullets), minus control characters. */
    private static function notes(string $s): string
    {
        $s = (string) preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', '', str_replace("\r\n", "\n", $s));

        return trim(mb_substr($s, 0, 3000));
    }

    /** @param  list<string>  $lines @return list<string> the first line stays plain text, the rest are bullets (already prefixed) */
    private static function bulletize(array $lines): array
    {
        return array_values(array_map(fn ($l, $i) => $i === 0 ? $l."\n" : $l, $lines, array_keys($lines)));
    }
}
