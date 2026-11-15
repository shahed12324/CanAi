<?php

declare(strict_types=1);

namespace App\Catalog\Stores;

use App\Outreach\HttpJson;
use App\Proposals\Money;

abstract class BaseAdapter implements StoreAdapter
{
    /** @var (callable(string, string, string): array<string, string>)|null method, full url, exact body => extra headers */
    protected $signer = null;

    public function __construct(protected readonly HttpJson $http) {}

    public static function sig(array $options): string
    {
        ksort($options);

        return json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @param array<string, string> $headers @return array<string, mixed>|list<mixed> */
    protected function call(string $method, string $url, array $headers, ?array $payload = null): array
    {
        try {
            $h = $headers + ['Accept' => 'application/json'];
            if ($payload !== null) {
                $h['Content-Type'] = 'application/json';
            }
            $body = $payload === null ? null : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if ($this->signer !== null) {
                $h += ($this->signer)($method, $url, $body ?? '');
            }
            $res = $this->http->request($method, $url, $h, null, $body);
        } catch (\InvalidArgumentException $e) {
            throw new StoreException('invalid', 'The address must be https.');
        } catch (\Throwable) {
            throw new StoreException('network');
        }
        $s = $res['status'];
        if ($s === 401 || $s === 403) {
            throw new StoreException('auth_failed');
        }
        if ($s === 404) {
            throw new StoreException('not_found');
        }
        if ($s === 429) {
            throw new StoreException('rate_limited');
        }
        if ($s < 200 || $s >= 300) {
            throw new StoreException('bad_response', "HTTP $s");
        }

        return $res['json'];
    }

    /** "1,250.50" / 1250.5 / "1250.50" -> paisa; null when blank or unreadable. */
    protected static function minor(mixed $v): ?int
    {
        if ($v === null || $v === '' || $v === false) {
            return null;
        }

        return Money::parse(is_float($v) ? number_format($v, 2, '.', '') : (string) $v);
    }

    /** paisa -> "1250.50" */
    protected static function major(?int $minor): string
    {
        return $minor === null ? '' : number_format($minor / 100, 2, '.', '');
    }

    protected static function plain(mixed $html): string
    {
        $s = (string) $html;
        $s = preg_replace('#<\s*br\s*/?>|</\s*(p|div|li|h[1-6])\s*>#i', "\n", $s) ?? $s;
        $s = html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = preg_replace("/[ \t]+/", ' ', $s) ?? $s;

        return trim(preg_replace("/\n{3,}/", "\n\n", $s) ?? $s);
    }

    protected static function html(string $text): string
    {
        return nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'), false);
    }

    /** @param mixed $list @return list<string> */
    protected static function urls(mixed $list, string $key): array
    {
        $out = [];
        foreach (is_array($list) ? $list : [] as $i) {
            $u = is_array($i) ? ($i[$key] ?? null) : null;
            if (is_string($u) && preg_match('#\Ahttps?://[^\s]{4,500}\z#i', $u) === 1) {
                $out[] = $u;
            }
        }

        return array_slice($out, 0, 10);
    }
}
