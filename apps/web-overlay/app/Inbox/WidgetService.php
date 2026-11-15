<?php

declare(strict_types=1);

namespace App\Inbox;

use Closure;

/**
 * The public side of the website chat widget. Nobody here is logged in, so every call proves itself: the channel's public key, the page's
 * Origin (must be one the shop owner listed), and — for returning visitors — a secret token that only a hash of is stored.
 * Returns [http status, json body, origin-to-allow-or-null]; the controller turns that into a response with CORS headers.
 */
final class WidgetService
{
    public const MAX_BODY = 2000;

    /** @param Closure(string, Closure): mixed $inTenant runs the closure with the channel's workspace selected
     * @param Closure(string, string, int): void|null $afterReceive called (tenant, conversation, seq) after a NEW customer message is stored */
    public function __construct(private readonly InboxRepo $repo, private readonly Closure $inTenant, private readonly ?Closure $afterReceive = null) {}

    /** @return array{0: int, 1: array<string, mixed>, 2: ?string} */
    public function start(string $key, ?string $origin, ?string $visitor, ?string $token): array
    {
        [$ch, $err] = $this->channel($key, $origin);
        if ($ch === null) {
            return $err;
        }

        return ($this->inTenant)($ch['tenant_id'], function () use ($ch, $origin, $visitor, $token) {
            if (self::validVisitor($visitor) && self::validToken($token)) {
                $conv = $this->repo->contactVerify($ch['id'], $visitor, self::hash($token));
                if ($conv !== null) {
                    return [200, ['visitor' => $visitor, 'token' => $token, 'greeting' => $ch['greeting'], 'name' => $ch['name'], 'messages' => $this->view($conv, 0)], $origin];
                }
            }
            $visitor = 'v'.bin2hex(random_bytes(12));
            $token = bin2hex(random_bytes(16));
            $r = $this->repo->contactEnsure($ch['id'], $visitor, null, self::hash($token));
            if ($r['result'] !== 'ok') {
                return $this->fail($r['result'], $origin);
            }

            return [200, ['visitor' => $visitor, 'token' => $token, 'greeting' => $ch['greeting'], 'name' => $ch['name'], 'messages' => []], $origin];
        });
    }

    /** @return array{0: int, 1: array<string, mixed>, 2: ?string} */
    public function post(string $key, ?string $origin, ?string $visitor, ?string $token, string $body, ?string $clientId): array
    {
        [$ch, $err] = $this->channel($key, $origin);
        if ($ch === null) {
            return $err;
        }
        if (mb_strlen(trim($body)) > self::MAX_BODY) {
            return [422, ['error' => 'too_long'], $origin];
        }
        if ($clientId !== null && preg_match('/\A[A-Za-z0-9_-]{1,64}\z/', $clientId) !== 1) {
            return [422, ['error' => 'bad_client_id'], $origin];
        }

        return ($this->inTenant)($ch['tenant_id'], function () use ($ch, $origin, $visitor, $token, $body, $clientId) {
            $conv = $this->owned($ch['id'], $visitor, $token);
            if ($conv === null) {
                return [401, ['error' => 'unknown_visitor'], $origin];
            }
            $r = $this->repo->receive($conv, $body, $clientId);
            if (! in_array($r['result'], ['received', 'duplicate'], true)) {
                return $this->fail($r['result'], $origin);
            }

            if ($r['result'] === 'received' && $r['seq'] !== null && $this->afterReceive !== null) {
                try {
                    ($this->afterReceive)($ch['tenant_id'], $conv, (int) $r['seq']);
                } catch (\Throwable) {
                    // the customer's message is saved; a failed AI hook must never fail the visitor's request
                }
            }

            return [200, ['ok' => true, 'duplicate' => $r['result'] === 'duplicate'], $origin];
        });
    }

    /** @return array{0: int, 1: array<string, mixed>, 2: ?string} */
    public function poll(string $key, ?string $origin, ?string $visitor, ?string $token, int $after): array
    {
        [$ch, $err] = $this->channel($key, $origin);
        if ($ch === null) {
            return $err;
        }

        return ($this->inTenant)($ch['tenant_id'], function () use ($ch, $origin, $visitor, $token, $after) {
            $conv = $this->owned($ch['id'], $visitor, $token);
            if ($conv === null) {
                return [401, ['error' => 'unknown_visitor'], $origin];
            }

            return [200, ['messages' => $this->view($conv, max(0, $after))], $origin];
        });
    }

    /** What the visitor may see: staff / agent / system replies only. */
    private function view(string $conv, int $after): array
    {
        return array_map(fn ($m) => ['seq' => (int) $m['seq'], 'from' => $m['sender'] === 'agent' ? 'bot' : 'staff', 'text' => (string) $m['body'], 'at' => (string) $m['created_at']],
            array_values(array_filter($this->repo->messages($conv, $after, 50, true), fn ($m) => $m['status'] !== 'failed')));
    }

    private function owned(string $channelId, ?string $visitor, ?string $token): ?string
    {
        if (! self::validVisitor($visitor) || ! self::validToken($token)) {
            return null;
        }

        return $this->repo->contactVerify($channelId, $visitor, self::hash($token));
    }

    /** @return array{0: ?array<string, mixed>, 1: array{0: int, 1: array<string, mixed>, 2: ?string}} */
    private function channel(string $key, ?string $origin): array
    {
        if (preg_match('/\A[0-9a-f]{32}\z/', $key) !== 1) {
            return [null, [404, ['error' => 'not_found'], null]];
        }
        $ch = $this->repo->channelByKey($key);
        if ($ch === null || $ch['kind'] !== 'web_widget') {
            return [null, [404, ['error' => 'not_found'], null]];
        }
        // The page's origin must be one the owner listed (exact match). No Origin header, or an unlisted one, is refused: no CORS grant is given.
        if ($origin === null || ! in_array($origin, $ch['allowed_origins'], true)) {
            return [null, [403, ['error' => 'origin_not_allowed'], null]];
        }
        if ($ch['status'] !== 'active') {
            return [null, [503, ['error' => 'paused'], $origin]];
        }

        return [$ch, [0, [], null]];
    }

    /** @return array{0: int, 1: array<string, mixed>, 2: ?string} */
    private function fail(string $result, ?string $origin): array
    {
        return match ($result) {
            'rate_limited' => [429, ['error' => 'slow_down'], $origin],
            'empty', 'too_long' => [422, ['error' => $result], $origin],
            default => [503, ['error' => 'unavailable'], $origin],
        };
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function validVisitor(?string $v): bool
    {
        return $v !== null && preg_match('/\Av[0-9a-f]{24}\z/', $v) === 1;
    }

    public static function validToken(?string $t): bool
    {
        return $t !== null && preg_match('/\A[0-9a-f]{32}\z/', $t) === 1;
    }
}
