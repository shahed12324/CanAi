<?php

declare(strict_types=1);

namespace App\Couriers;

use App\Outreach\HttpJson;

/** Steadfast Courier merchant API. UNVERIFIED against a live account — see TESTING_GUIDE. */
final class SteadfastAdapter extends BaseCourier
{
    public const BASE = 'https://portal.packzy.com/api/v1';

    /** @param array<string, mixed> $creds api_key, secret_key */
    public function __construct(HttpJson $http, private readonly array $creds)
    {
        parent::__construct($http, [(string) ($creds['api_key'] ?? ''), (string) ($creds['secret_key'] ?? '')]);
    }

    /** @return array<string, string> */
    private function auth(): array
    {
        $k = (string) ($this->creds['api_key'] ?? '');
        $s = (string) ($this->creds['secret_key'] ?? '');
        if ($k === '' || $s === '') {
            throw new CourierException('missing Steadfast keys');
        }

        return ['Api-Key' => $k, 'Secret-Key' => $s];
    }

    public function book(array $p): array
    {
        $j = $this->send('POST', self::BASE.'/create_order', $this->auth(), [
            'invoice' => $p['invoice'], 'recipient_name' => $p['name'], 'recipient_phone' => $p['phone'], 'recipient_address' => $p['address'],
            'cod_amount' => $p['cod'], 'note' => (string) ($p['note'] ?? ''),
        ]);
        $c = $j['consignment'] ?? null;
        $id = is_array($c) ? (string) ($c['consignment_id'] ?? '') : '';
        if (($j['status'] ?? null) !== 200 || $id === '') {
            // The HTTP call was accepted but the body does not say "created": we cannot tell, so a person must check.
            throw new CourierException('unexpected Steadfast answer '.$this->message($j), true);
        }

        return ['consignment_id' => $id, 'tracking_code' => (string) ($c['tracking_code'] ?? ''), 'raw_status' => (string) ($c['status'] ?? 'in_review')];
    }

    public function status(string $consignmentId, ?string $trackingCode): string
    {
        if (preg_match('/\A[A-Za-z0-9_-]{1,60}\z/', $consignmentId) !== 1) {
            throw new CourierException('bad consignment id');
        }
        $j = $this->send('GET', self::BASE.'/status_by_cid/'.$consignmentId, $this->auth());
        $s = $j['delivery_status'] ?? null;
        if (! is_string($s) || $s === '') {
            throw new CourierException('no delivery_status in answer');
        }

        return $s;
    }
}
