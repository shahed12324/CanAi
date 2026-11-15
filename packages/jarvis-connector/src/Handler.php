<?php

declare(strict_types=1);

namespace Jarvis\Connector;

/**
 * The whole API as one plain class (no framework): verify the signature, route, call the ProductStore.
 * The Laravel controller only hands it the raw request, so everything here is testable without Laravel.
 */
final class Handler
{
    public const VERSION = '1.0.0';

    public const MAX_BODY = 1_048_576;

    /** @param callable(): int $now */
    public function __construct(
        private readonly ProductStore $store,
        private readonly string $secret,
        private readonly NonceStore $nonces,
        private $now,
        private readonly string $shopName = 'Store',
        private readonly string $currency = 'BDT',
    ) {}

    /**
     * @param  array<string, string>  $headers  lower-case names
     * @return array{0: int, 1: array<string, mixed>}
     */
    public function handle(string $method, string $uri, string $body, array $headers): array
    {
        $method = strtoupper($method);
        if ($this->secret === '' || strlen($this->secret) < 32) {
            return [503, ['error' => 'not_configured']];
        }
        if (strlen($body) > self::MAX_BODY) {
            return [413, ['error' => 'too_large']];
        }
        $ts = $headers['x-jarvis-timestamp'] ?? '';
        $nonce = $headers['x-jarvis-nonce'] ?? '';
        $sig = $headers['x-jarvis-signature'] ?? '';
        if (! Signature::fresh($ts, ($this->now)()) || ! Signature::nonceOk($nonce)) {
            return [401, ['error' => 'stale_or_malformed']];
        }
        if (! Signature::valid($this->secret, $sig, $ts, $nonce, $method, $uri, $body)) {
            return [401, ['error' => 'bad_signature']];
        }
        if (! $this->nonces->remember($nonce, Signature::MAX_SKEW * 2)) {     // after the signature, so strangers cannot fill the nonce cache
            return [401, ['error' => 'replay']];
        }

        $path = (string) parse_url($uri, PHP_URL_PATH);
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
        $pos = strpos($path, '/jarvis-connector/v1');
        if ($pos === false) {
            return [404, ['error' => 'not_found']];
        }
        $route = trim(substr($path, $pos + strlen('/jarvis-connector/v1')), '/');
        $data = [];
        if ($body !== '') {
            $data = json_decode($body, true);
            if (! is_array($data)) {
                return [400, ['error' => 'bad_json']];
            }
        }

        try {
            if ($method === 'GET' && $route === 'ping') {
                return [200, ['ok' => true, 'name' => $this->shopName, 'version' => self::VERSION, 'currency' => $this->currency, 'capabilities' => ['products', 'variants', 'stock']]];
            }
            if ($method === 'GET' && $route === 'products') {
                $limit = max(1, min(100, (int) ($query['limit'] ?? 50)));
                $cursor = isset($query['cursor']) && is_string($query['cursor']) && $query['cursor'] !== '' ? substr($query['cursor'], 0, 40) : null;

                return [200, $this->store->page($cursor, $limit)];
            }
            if (preg_match('#\Aproducts/([^/]{1,60})\z#', $route, $m) === 1) {
                $id = rawurldecode($m[1]);
                if ($method === 'GET') {
                    $p = $this->store->find($id);

                    return $p === null ? [404, ['error' => 'not_found']] : [200, $p];
                }
                if ($method === 'PUT') {
                    return [200, ['external_id' => $this->store->upsert($id, Canonical::clean($data))]];
                }
            }
            if ($method === 'POST' && $route === 'products') {
                return [201, ['external_id' => $this->store->upsert(null, Canonical::clean($data))]];
            }
            if ($method === 'POST' && $route === 'stock') {
                $v = $data['variant_external_id'] ?? null;
                $q = $data['qty'] ?? null;
                if (! is_string($v) || $v === '' || strlen($v) > 120 || ! is_int($q) || $q < 0 || $q > 100_000_000) {
                    return [422, ['error' => 'invalid', 'message' => 'variant_external_id and a whole qty >= 0 are required']];
                }
                $this->store->setStock($v, $q);

                return [200, ['ok' => true]];
            }
        } catch (\InvalidArgumentException $e) {
            return [422, ['error' => 'invalid', 'message' => $e->getMessage()]];
        } catch (\OutOfBoundsException) {
            return [404, ['error' => 'not_found']];
        } catch (\Throwable $e) {
            if (function_exists('report')) {
                report($e);
            }

            return [500, ['error' => 'store_error']];
        }

        return [404, ['error' => 'not_found']];
    }
}
