<?php

declare(strict_types=1);

namespace App\GoogleAds;

/**
 * Turns an approved Google Ads draft into the requests that create it.  Pure.  The campaign is created PAUSED (see GoogleAdsLaunchApi — it cannot send anything else);
 * the end date is start + days − 1 (inclusive) so the approved total holds if someone later enables it.  Anything we cannot create correctly returns an error string instead, before any call.
 */
final class GadsLaunchPlan
{
    /** Google geo-target constant ids (countries). */
    public const GEO = ['BD' => 2050, 'IN' => 2356, 'PK' => 2586, 'US' => 2840, 'GB' => 2826, 'AE' => 2784, 'SA' => 2682, 'MY' => 2458, 'ID' => 2360, 'SG' => 2702, 'CA' => 2124, 'AU' => 2036];

    /** Google language constant ids. */
    public const LANG = ['bn' => 1056, 'en' => 1000, 'hi' => 1023, 'ar' => 1019, 'ur' => 1041, 'ms' => 1102, 'id' => 1025];

    /** Characters Google refuses in keywords. */
    private const BAD_KEYWORD = '/[!@%^()={}\\\;~`<>?|\[\]"]/u';

    /** "200.00" → "200000000" (micros) with integer math, or null. */
    public static function micros(string $amount): ?string
    {
        if (preg_match('/\A([0-9]{1,9})(?:\.([0-9]{1,2}))?\z/', $amount, $m) !== 1) {
            return null;
        }
        $n = ((int) $m[1]) * 1000000 + ((int) str_pad($m[2] ?? '0', 2, '0')) * 10000;

        return $n > 0 ? (string) $n : null;
    }

    /**
     * @param  array<string, mixed>  $c  one row of gads_launch_claim
     * @return array{error: ?string, budget: array<string, mixed>, campaign: array<string, mixed>, adgroup: array<string, mixed>, ad: array<string, mixed>, keywords: list<array<string, mixed>>, targeting: list<array<string, mixed>>}
     */
    public static function build(array $c, int $now): array
    {
        $err = fn (string $e) => ['error' => $e, 'budget' => [], 'campaign' => [], 'adgroup' => [], 'ad' => [], 'keywords' => [], 'targeting' => []];
        $cust = (string) $c['customer_id'];
        if (preg_match('/\A[0-9]{10}\z/', $cust) !== 1) {
            return $err('Google Ads The account ID is not valid.');
        }
        $url = (string) ($c['final_url'] ?? '');
        if (preg_match('~\Ahttps://[^\s<>]+\z~', $url) !== 1) {
            return $err('The ad link is not valid (https is needed).');
        }
        $geo = self::GEO[(string) $c['country']] ?? null;
        $lang = self::LANG[(string) $c['language']] ?? null;
        if ($geo === null || $lang === null) {
            return $err('This country or language is not supported from here yet.');
        }
        $micros = self::micros((string) $c['daily_budget']);
        if ($micros === null) {
            return $err('The budget amount is not valid.');
        }
        $heads = self::texts($c['headlines'] ?? null, 3, 15, 30);
        $descs = self::texts($c['descriptions'] ?? null, 2, 4, 90);
        $negs = self::texts($c['negatives'] ?? null, 0, 30, 80);
        if ($heads === null || $descs === null || $negs === null) {
            return $err('Headlines (3–15, ≤30 characters), descriptions (2–4, ≤90 characters) or the negative keyword list is not valid.');
        }
        $kw = [];
        $kwIn = $c['keywords'] ?? null;
        if (! is_array($kwIn) || $kwIn === [] || count($kwIn) > 30) {
            return $err('The keyword list is not valid (1–30).');
        }
        foreach ($kwIn as $k) {
            $t = is_array($k) ? trim((string) ($k['t'] ?? '')) : '';
            $m = is_array($k) ? (string) ($k['m'] ?? '') : '';
            $t = trim((string) preg_replace('/\A\[|\]\z/u', '', $t));
            if ($t === '' || mb_strlen($t) > 80 || ! in_array($m, ['phrase', 'exact'], true) || preg_match(self::BAD_KEYWORD, $t) === 1) {
                return $err('A keyword is not valid ("'.mb_substr($t, 0, 30).'") — match can be only phrase/exact, and ! signs and the like are not allowed. @ % ^ ( ) = etc. are not allowed.');
            }
            $kw[] = ['text' => $t, 'matchType' => $m === 'exact' ? 'EXACT' : 'PHRASE'];
        }
        foreach ($negs as $n) {
            if (preg_match(self::BAD_KEYWORD, $n) === 1) {
                return $err('A negative keyword has a disallowed character ("'.mb_substr($n, 0, 30).'").');
            }
        }
        $today = gmdate('Y-m-d', $now);
        $startDate = (string) ($c['start_date'] ?? '');
        if (preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $startDate) !== 1) {
            return $err('A start date is needed.');
        }
        $days = (int) $c['days'];
        if ($days < 1 || $days > 60) {
            return $err('The number of days is not valid.');
        }
        $start = max($startDate, $today);
        $end = gmdate('Y-m-d', strtotime($start.' 00:00:00 UTC') + ($days - 1) * 86400);
        $tag = ' [J1-'.substr((string) $c['id'], -6).']';
        $name = mb_substr(trim((string) preg_replace('/[\x00-\x1f\x7f]/u', ' ', (string) $c['name'])), 0, 100).$tag;

        $targeting = [['location' => ['geoTargetConstant' => 'geoTargetConstants/'.$geo]], ['language' => ['languageConstant' => 'languageConstants/'.$lang]]];
        foreach ($negs as $n) {
            $targeting[] = ['negative' => true, 'keyword' => ['text' => $n, 'matchType' => 'PHRASE']];
        }

        return ['error' => null,
            'budget' => ['name' => 'Budget '.$name, 'amountMicros' => $micros, 'deliveryMethod' => 'STANDARD', 'explicitlyShared' => false],
            'campaign' => ['name' => $name, 'advertisingChannelType' => 'SEARCH', 'targetSpend' => new \stdClass,
                'networkSettings' => ['targetGoogleSearch' => true, 'targetSearchNetwork' => false, 'targetContentNetwork' => false],
                'startDate' => $start, 'endDate' => $end, 'containsEuPoliticalAdvertising' => 'DOES_NOT_CONTAIN_EU_POLITICAL_ADVERTISING'],
            'adgroup' => ['name' => 'Ad group 1', 'type' => 'SEARCH_STANDARD'],
            'ad' => ['finalUrls' => [$url], 'responsiveSearchAd' => ['headlines' => array_map(fn ($t) => ['text' => $t], $heads), 'descriptions' => array_map(fn ($t) => ['text' => $t], $descs)]],
            'keywords' => $kw,
            'targeting' => $targeting];
    }

    /** @return list<string>|null */
    private static function texts(mixed $v, int $min, int $max, int $len): ?array
    {
        if (! is_array($v) || count($v) < $min || count($v) > $max) {
            return null;
        }
        $out = [];
        foreach ($v as $t) {
            if (! is_string($t) || trim($t) === '' || mb_strlen($t) > $len || preg_match('/[<>\x00-\x1f\x7f]/u', $t) === 1) {
                return null;
            }
            $out[] = trim($t);
        }

        return $out;
    }
}
