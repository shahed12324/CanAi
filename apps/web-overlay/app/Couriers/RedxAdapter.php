<?php

declare(strict_types=1);

namespace App\Couriers;

use App\Outreach\HttpJson;

/** RedX open API. UNVERIFIED against a live account — see TESTING_GUIDE. RedX needs a delivery-area id: matched from the address, or given by the person booking. */
final class RedxAdapter extends BaseCourier
{
    public const BASE = 'https://openapi.redx.com.bd/v1.0.0-beta';
    public const SANDBOX = 'https://sandbox.redx.com.bd/v1.0.0-alpha';

    /** @param array<string, mixed> $creds access_token  @param array<string, mixed> $settings pickup_store_id, sandbox */
    public function __construct(HttpJson $http, private readonly array $creds, private readonly array $settings = [])
    {
        parent::__construct($http, [(string) ($creds['access_token'] ?? '')]);
    }

    private function base(): string
    {
        return ($this->settings['sandbox'] ?? false) === true ? self::SANDBOX : self::BASE;
    }

    /** @return array<string, string> */
    private function auth(): array
    {
        $t = (string) ($this->creds['access_token'] ?? '');
        if ($t === '') {
            throw new CourierException('missing RedX token');
        }

        return ['API-ACCESS-TOKEN' => 'Bearer '.$t];
    }

    /** @return array{0: int, 1: string} */
    private function area(string $address, ?string $hint): array
    {
        $j = $this->send('GET', $this->base().'/areas', $this->auth(), null, true);
        $areas = is_array($j['areas'] ?? null) ? $j['areas'] : [];
        if ($hint !== null && $hint !== '') {
            foreach ($areas as $a) {
                if (is_array($a) && (string) ($a['id'] ?? '') === $hint) {
                    return [(int) $a['id'], (string) ($a['name'] ?? '')];
                }
            }
            throw new CourierException('RedX area id not found');
        }
        $addr = mb_strtolower($address);
        $best = null;
        foreach ($areas as $a) {
            $n = is_array($a) ? mb_strtolower(trim((string) ($a['name'] ?? ''))) : '';
            if ($n !== '' && is_array($a) && str_contains($addr, $n) && ($best === null || mb_strlen($n) > mb_strlen((string) $best['name']))) {
                $best = ['id' => (int) $a['id'], 'name' => $n];
            }
        }
        if ($best === null) {
            throw new CourierException('RedX delivery area not found in the address — give the RedX area id');
        }

        return [$best['id'], $best['name']];
    }

    public function book(array $p): array
    {
        [$areaId, $areaName] = $this->area($p['address'], $p['hints']['area_id'] ?? null);
        $body = [
            'customer_name' => $p['name'], 'customer_phone' => $p['phone'], 'delivery_area' => $areaName, 'delivery_area_id' => $areaId, 'customer_address' => $p['address'],
            'merchant_invoice_id' => $p['invoice'], 'cash_collection_amount' => (string) $p['cod'], 'parcel_weight' => 500, 'value' => (int) ceil((float) $p['cod']),
            'instruction' => (string) ($p['note'] ?? ''),
        ];
        $store = (string) ($this->settings['pickup_store_id'] ?? '');
        if (preg_match('/\A\d{1,12}\z/', $store) === 1) {
            $body['pickup_store_id'] = (int) $store;
        }
        $j = $this->send('POST', $this->base().'/parcel', $this->auth(), $body);
        $id = (string) ($j['tracking_id'] ?? '');
        if ($id === '') {
            throw new CourierException('unexpected RedX answer '.$this->message($j), true);
        }

        return ['consignment_id' => $id, 'tracking_code' => $id, 'raw_status' => 'pickup-pending'];
    }

    public function status(string $consignmentId, ?string $trackingCode): string
    {
        if (preg_match('/\A[A-Za-z0-9_-]{1,60}\z/', $consignmentId) !== 1) {
            throw new CourierException('bad tracking id');
        }
        $j = $this->send('GET', $this->base().'/parcel/track/'.$consignmentId, $this->auth());
        $rows = is_array($j['tracking'] ?? null) ? array_values(array_filter($j['tracking'], 'is_array')) : [];
        if ($rows === []) {
            throw new CourierException('no tracking rows in answer');
        }
        usort($rows, fn ($a, $b) => strcmp((string) ($b['time'] ?? ''), (string) ($a['time'] ?? '')));       // newest first
        $m = $rows[0]['message_en'] ?? $rows[0]['message_bn'] ?? null;
        if (! is_string($m) || $m === '') {
            throw new CourierException('no status text in answer');
        }

        return $m;
    }
}
