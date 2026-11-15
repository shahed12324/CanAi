<?php

declare(strict_types=1);

namespace App\Couriers;

use App\Outreach\HttpJson;

/** Pathao Courier merchant API (OAuth password grant). UNVERIFIED against a live account — see TESTING_GUIDE. */
final class PathaoAdapter extends BaseCourier
{
    public const BASE = 'https://api-hermes.pathao.com';
    public const SANDBOX = 'https://courier-api-sandbox.pathao.com';

    private ?string $token = null;

    /** @param array<string, mixed> $creds client_id, client_secret, username, password  @param array<string, mixed> $settings store_id, sandbox */
    public function __construct(HttpJson $http, private readonly array $creds, private readonly array $settings = [])
    {
        parent::__construct($http, [(string) ($creds['client_secret'] ?? ''), (string) ($creds['password'] ?? ''), (string) ($creds['client_id'] ?? '')]);
    }

    private function base(): string
    {
        return ($this->settings['sandbox'] ?? false) === true ? self::SANDBOX : self::BASE;
    }

    /** @return array<string, string> */
    private function auth(): array
    {
        if ($this->token === null) {
            foreach (['client_id', 'client_secret', 'username', 'password'] as $k) {
                if (($this->creds[$k] ?? '') === '') {
                    throw new CourierException("missing Pathao $k");
                }
            }
            $j = $this->send('POST', $this->base().'/aladdin/api/v1/issue-token', [], [
                'client_id' => $this->creds['client_id'], 'client_secret' => $this->creds['client_secret'],
                'username' => $this->creds['username'], 'password' => $this->creds['password'], 'grant_type' => 'password',
            ], true);
            $t = $j['access_token'] ?? null;
            if (! is_string($t) || $t === '') {
                throw new CourierException('Pathao gave no access token');
            }
            $this->token = $t;
        }

        return ['Authorization' => 'Bearer '.$this->token];
    }

    public function book(array $p): array
    {
        $store = (string) ($this->settings['store_id'] ?? '');
        if (preg_match('/\A\d{1,12}\z/', $store) !== 1) {
            throw new CourierException('Pathao store id is not set');
        }
        $h = $this->auth();
        $j = $this->send('POST', $this->base().'/aladdin/api/v1/orders', $h, [
            'store_id' => (int) $store, 'merchant_order_id' => $p['invoice'], 'recipient_name' => $p['name'], 'recipient_phone' => $p['phone'], 'recipient_address' => $p['address'],
            'delivery_type' => 48, 'item_type' => 2, 'special_instruction' => (string) ($p['note'] ?? ''), 'item_quantity' => $p['qty'], 'item_weight' => 0.5, 'amount_to_collect' => $p['cod'],
        ]);
        $d = $j['data'] ?? null;
        $id = is_array($d) ? (string) ($d['consignment_id'] ?? '') : '';
        if ($id === '') {
            throw new CourierException('unexpected Pathao answer '.$this->message($j), true);
        }

        return ['consignment_id' => $id, 'tracking_code' => $id, 'raw_status' => (string) ($d['order_status'] ?? 'Pending')];
    }

    public function status(string $consignmentId, ?string $trackingCode): string
    {
        if (preg_match('/\A[A-Za-z0-9_-]{1,60}\z/', $consignmentId) !== 1) {
            throw new CourierException('bad consignment id');
        }
        $j = $this->send('GET', $this->base().'/aladdin/api/v1/orders/'.$consignmentId.'/info', $this->auth());
        $s = is_array($j['data'] ?? null) ? ($j['data']['order_status'] ?? null) : null;
        if (! is_string($s) || $s === '') {
            throw new CourierException('no order_status in answer');
        }

        return $s;
    }
}
