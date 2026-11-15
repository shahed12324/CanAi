<?php

declare(strict_types=1);

namespace App\Ads;

/**
 * Turns an approved draft into the four Meta objects.  Pure.  Everything is PAUSED; the end date is start + days so the approved total holds when someone later switches it on.
 * Only what we can create correctly is planned: traffic, awareness, and sales (with a Pixel).  Anything else returns an error string instead.
 */
final class AdsLaunchPlan
{
    public const OBJECTIVE = ['traffic' => 'OUTCOME_TRAFFIC', 'awareness' => 'OUTCOME_AWARENESS', 'sales' => 'OUTCOME_SALES'];

    public const GOAL = ['traffic' => 'LINK_CLICKS', 'awareness' => 'REACH', 'sales' => 'OFFSITE_CONVERSIONS'];

    public const CTA = ['SHOP_NOW' => 'SHOP_NOW', 'LEARN_MORE' => 'LEARN_MORE', 'SIGN_UP' => 'SIGN_UP', 'CONTACT_US' => 'CONTACT_US', 'ORDER_NOW' => 'ORDER_NOW', 'GET_OFFER' => 'GET_OFFER'];

    /**
     * @param  array<string, mixed>  $c  one row of ads_launch_claim
     * @return array{error: ?string, campaign: array<string, string>, adset: array<string, string>, creative: array<string, string>, ad: array<string, string>}
     */
    public static function build(array $c, int $now): array
    {
        $err = fn (string $e) => ['error' => $e, 'campaign' => [], 'adset' => [], 'creative' => [], 'ad' => []];
        $obj = (string) $c['objective'];
        if (! isset(self::OBJECTIVE[$obj])) {
            return $err('Ads with this objective cannot be created from here yet (supported: website visits, awareness, sales).');
        }
        if (! isset(self::CTA[(string) $c['cta']])) {
            return $err('This button is not supported at this step.');
        }
        $link = (string) ($c['link_url'] ?? '');
        if (preg_match('~\Ahttps://[^\s<>]+\z~', $link) !== 1) {
            return $err('The ad link is not valid.');
        }
        if ($obj === 'sales' && preg_match('/\A[0-9]{5,30}\z/', (string) ($c['pixel_id'] ?? '')) !== 1) {
            return $err('Sales ads need the account’s Pixel ID.');
        }
        $minor = AdsMoney::minor((string) $c['daily_budget'], (string) $c['currency']);
        if ($minor === null) {
            return $err('The budget unit for this currency has not been verified ('.$c['currency'].') — so it could not be created safely.');
        }
        $today = gmdate('Y-m-d', $now);
        $startDate = (string) ($c['start_date'] ?? '');
        if (preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $startDate) !== 1) {
            return $err('A start date is needed.');
        }
        $start = strtotime(max($startDate, $today).' 00:00:00 UTC');
        $end = $start + ((int) $c['days']) * 86400;
        $name = mb_substr((string) $c['name'], 0, 120);
        $targeting = ['geo_locations' => ['countries' => [(string) $c['country']]], 'age_min' => (int) $c['age_min'], 'age_max' => (int) $c['age_max']];
        if ($c['gender'] === 'men') {
            $targeting['genders'] = [1];
        } elseif ($c['gender'] === 'women') {
            $targeting['genders'] = [2];
        }
        $adset = ['name' => $name.' – adset', 'campaign_id' => '{campaign}', 'daily_budget' => (string) $minor, 'billing_event' => 'IMPRESSIONS', 'optimization_goal' => self::GOAL[$obj], 'bid_strategy' => 'LOWEST_COST_WITHOUT_CAP',
            'targeting' => json_encode($targeting, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'start_time' => gmdate('Y-m-d\TH:i:sO', $start), 'end_time' => gmdate('Y-m-d\TH:i:sO', $end), 'status' => 'PAUSED'];
        if ($obj === 'sales') {
            $adset['promoted_object'] = json_encode(['pixel_id' => (string) $c['pixel_id'], 'custom_event_type' => 'PURCHASE'], JSON_THROW_ON_ERROR);
        }
        $link_data = ['message' => (string) $c['primary_text'], 'link' => $link, 'name' => (string) $c['headline'], 'call_to_action' => ['type' => self::CTA[(string) $c['cta']], 'value' => ['link' => $link]]];
        if (trim((string) $c['description']) !== '') {
            $link_data['description'] = (string) $c['description'];
        }

        return ['error' => null,
            'campaign' => ['name' => $name, 'objective' => self::OBJECTIVE[$obj], 'status' => 'PAUSED', 'special_ad_categories' => '[]', 'is_adset_budget_sharing_enabled' => 'false'],
            'adset' => $adset,
            'creative' => ['name' => $name.' – creative', 'object_story_spec' => json_encode(['page_id' => (string) $c['page_ext'], 'link_data' => $link_data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)],
            'ad' => ['name' => $name.' – ad', 'adset_id' => '{adset}', 'creative' => '{creative}', 'status' => 'PAUSED']];
    }
}
