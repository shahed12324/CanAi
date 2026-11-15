<?php

declare(strict_types=1);

namespace App\Ads;

/** Turns what the form sent into clean typed values (the database checks everything again).  Pure. */
final class AdsDraftForm
{
    /**
     * @param  array<string, mixed>  $in
     * @return array{ok: bool, error: ?string, v: array<string, mixed>}
     */
    public static function parse(array $in): array
    {
        $s = fn (string $k, int $max) => mb_substr(trim((string) preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f<>]/u', '', is_scalar($in[$k] ?? null) ? (string) $in[$k] : '')), 0, $max);
        $bad = fn (string $e) => ['ok' => false, 'error' => $e, 'v' => []];
        $name = $s('name', 120);
        if ($name === '') {
            return $bad('Give the campaign a name.');
        }
        $obj = $s('objective', 20);
        if (! isset(AdsText::OBJECTIVES[$obj])) {
            return $bad('Pick an objective.');
        }
        $cta = $s('cta', 20);
        if (! isset(AdsText::CTAS[$cta])) {
            return $bad('Pick a call-to-action.');
        }
        $gender = $s('gender', 10);
        if (! isset(AdsText::GENDERS[$gender])) {
            return $bad('Pick a gender.');
        }
        $country = strtoupper($s('country', 2));
        if (preg_match('/\A[A-Z]{2}\z/', $country) !== 1) {
            return $bad('The country code must be 2 letters (for example BD).');
        }
        $int = fn (string $k) => preg_match('/\A[0-9]{1,3}\z/', $s($k, 3)) === 1 ? (int) $s($k, 3) : null;
        $ageMin = $int('age_min');
        $ageMax = $int('age_max');
        if ($ageMin === null || $ageMax === null || $ageMin < 18 || $ageMax > 65 || $ageMin > $ageMax) {
            return $bad('The age must be between 18–65, and the minimum must not exceed the maximum.');
        }
        $daily = str_replace(',', '', $s('daily_budget', 14));
        if (preg_match('/\A[0-9]{1,9}(\.[0-9]{1,2})?\z/', $daily) !== 1 || (float) $daily <= 0) {
            return $bad('The daily budget must be a positive number.');
        }
        $days = $int('days');
        if ($days === null || $days < 1 || $days > 60) {
            return $bad('Days must be between 1–60.');
        }
        $link = $s('link_url', 500);
        if ($link !== '' && preg_match('~\Ahttps://[^\s<>]+\z~', $link) !== 1) {
            return $bad('The link must start with https://.');
        }
        $start = $s('start_date', 10);
        if ($start !== '' && (preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $start) !== 1 || ! checkdate((int) substr($start, 5, 2), (int) substr($start, 8, 2), (int) substr($start, 0, 4)))) {
            return $bad('The start date is not valid.');
        }
        $acc = $s('account_id', 26);
        if (preg_match('/\A[0-9a-z]{26}\z/', $acc) !== 1) {
            return $bad('Pick an ad account.');
        }

        return ['ok' => true, 'error' => null, 'v' => ['account_id' => $acc, 'name' => $name, 'objective' => $obj, 'country' => $country, 'age_min' => $ageMin, 'age_max' => $ageMax, 'gender' => $gender,
            'interests' => $s('interests', 300) ?: null, 'primary_text' => $s('primary_text', 1000), 'headline' => $s('headline', 80), 'description' => $s('description', 200), 'cta' => $cta, 'link_url' => $link === '' ? null : $link,
            'daily_budget' => $daily, 'days' => $days, 'start_date' => $start === '' ? null : $start]];
    }
}
