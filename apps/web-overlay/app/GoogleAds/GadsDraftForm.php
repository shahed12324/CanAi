<?php

declare(strict_types=1);

namespace App\GoogleAds;

/** Turns what the form sent into clean typed values (the database checks everything again).  Texts are one per line; keywords: [exact match], "phrase" or plain (phrase match).  Pure. */
final class GadsDraftForm
{
    /**
     * @param  array<string, mixed>  $in
     * @return array{ok: bool, error: ?string, v: array<string, mixed>}
     */
    public static function parse(array $in): array
    {
        $raw = fn (string $k, int $max) => mb_substr(is_scalar($in[$k] ?? null) ? (string) $in[$k] : '', 0, $max);
        $s = fn (string $k, int $max) => mb_substr(trim((string) preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f<>]/u', '', $raw($k, $max))), 0, $max);
        $bad = fn (string $e) => ['ok' => false, 'error' => $e, 'v' => []];
        $name = $s('name', 120);
        if ($name === '') {
            return $bad('Give the campaign a name.');
        }
        $country = strtoupper($s('country', 2));
        if (preg_match('/\A[A-Z]{2}\z/', $country) !== 1) {
            return $bad('The country code must be 2 letters (for example BD).');
        }
        $lang = $s('language', 2);
        if (! isset(GadsText::LANGUAGES[$lang])) {
            return $bad('Pick a language.');
        }
        $daily = str_replace(',', '', $s('daily_budget', 14));
        if (preg_match('/\A[0-9]{1,9}(\.[0-9]{1,2})?\z/', $daily) !== 1 || (float) $daily <= 0) {
            return $bad('The daily budget must be a positive number.');
        }
        $days = preg_match('/\A[0-9]{1,3}\z/', $s('days', 3)) === 1 ? (int) $s('days', 3) : null;
        if ($days === null || $days < 1 || $days > 60) {
            return $bad('Days must be between 1–60.');
        }
        $url = $s('final_url', 500);
        if ($url !== '' && preg_match('~\Ahttps://[^\s<>]+\z~', $url) !== 1) {
            return $bad('The link must start with https://.');
        }
        $start = $s('start_date', 10);
        if ($start !== '' && (preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $start) !== 1 || ! checkdate((int) substr($start, 5, 2), (int) substr($start, 8, 2), (int) substr($start, 0, 4)))) {
            return $bad('The start date is not valid.');
        }
        $acc = $s('account_id', 26);
        if (preg_match('/\A[0-9a-z]{26}\z/', $acc) !== 1) {
            return $bad('Google Ads Pick an account.');
        }
        $h = GadsLines::lines($raw('headlines', 3000));
        $d = GadsLines::lines($raw('descriptions', 2000));
        $n = GadsLines::lines($raw('negatives', 3000));
        $k = GadsLines::keywordLines($raw('keywords', 4000));
        foreach ([[$h, GadsLines::HEADLINES, 30, 'Title'], [$d, GadsLines::DESCRIPTIONS, 90, 'Description'], [$n, GadsLines::NEGATIVES, 80, 'Negative keywords'], [$k, GadsLines::KEYWORDS, 80, 'Keywords']] as [$list, $max, $len, $label]) {
            if (count($list) > $max) {
                return $bad("{$label} max {$max}.");
            }
        }
        $hc = GadsLines::list($h, GadsLines::HEADLINES, 30);
        $dc = GadsLines::list($d, GadsLines::DESCRIPTIONS, 90);
        $nc = GadsLines::list($n, GadsLines::NEGATIVES, 80);
        $kc = GadsLines::keywords($k, GadsLines::KEYWORDS);
        foreach ([[$hc, 'Title', 30], [$dc, 'Description', 90], [$nc, 'Negative keywords', 80], [$kc, 'Keywords', 80]] as [$c, $label, $len]) {
            if ($c['too_long'] > 0) {
                return $bad("{$c['too_long']} items {$label} {$len} characters long — shorten it.");
            }
        }

        return ['ok' => true, 'error' => null, 'v' => ['account_id' => $acc, 'name' => $name, 'country' => $country, 'language' => $lang, 'final_url' => $url === '' ? null : $url, 'headlines' => $hc['items'], 'descriptions' => $dc['items'],
            'keywords' => $kc['items'], 'negatives' => $nc['items'], 'daily_budget' => $daily, 'days' => $days, 'start_date' => $start === '' ? null : $start]];
    }
}
