<?php

declare(strict_types=1);

namespace App\Inbox\Channels;

use App\Inbox\InboxRepo;
use Closure;

/**
 * Receives platform webhooks. Order matters: find the channel by its public key (no workspace yet) -> enter that workspace ->
 * check the platform's signature with the channel's own secret -> only then read the payload.
 * Returns [http status, body text].
 */
final class WebhookService
{
    private const META = ['messenger', 'instagram', 'whatsapp'];

    /**
     * @param  Closure(string): ?array<string, mixed>  $creds  channel id -> decrypted secrets (null when unreadable)
     * @param  Closure(string, Closure): mixed  $inTenant
     * @param  Closure(string, string, int): void|null  $afterReceive  (tenant, conversation, seq) after a NEW customer message is stored
     */
    public function __construct(private readonly InboxRepo $repo, private readonly Closure $creds, private readonly Closure $inTenant, private readonly ?Closure $afterReceive = null) {}

    /** Meta's GET handshake. @return array{0: int, 1: string} */
    public function handshake(string $key, ?string $mode, ?string $token, ?string $challenge): array
    {
        $ch = $this->channel($key);
        if ($ch === null || ! in_array($ch['kind'], self::META, true)) {
            return [404, ''];
        }
        $c = WebhookVerifier::challenge($mode, $token, $challenge, $key);

        return $c === null ? [403, ''] : [200, $c];
    }

    /**
     * @param  array<string, string>  $headers  lower-case names
     * @return array{0: int, 1: string}
     */
    public function receive(string $key, string $raw, array $headers): array
    {
        $ch = $this->channel($key);
        if ($ch === null || $ch['kind'] === 'web_widget' || strlen($raw) > 1_000_000) {
            return [$ch === null || $ch['kind'] === 'web_widget' ? 404 : 413, ''];
        }

        return ($this->inTenant)($ch['tenant_id'], function () use ($ch, $raw, $headers) {
            $c = ($this->creds)($ch['id']);
            if ($c === null) {
                return [403, ''];
            }
            $meta = in_array($ch['kind'], self::META, true);
            $ok = $meta ? WebhookVerifier::meta($raw, $headers['x-hub-signature-256'] ?? null, (string) ($c['app_secret'] ?? ''))
                : WebhookVerifier::telegram($headers['x-telegram-bot-api-secret-token'] ?? null, (string) ($c['webhook_secret'] ?? ''));
            if (! $ok) {
                return [403, ''];
            }
            if ($ch['status'] !== 'active') {
                return [200, 'paused'];                              // acknowledge so the platform does not disable the webhook
            }
            try {
                $payload = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                return [400, ''];
            }
            if (! is_array($payload)) {
                return [400, ''];
            }
            $msgs = $meta ? InboundParser::meta($ch['kind'], $payload, (string) $ch['external_ref']) : InboundParser::telegram($payload);
            foreach (array_slice($msgs, 0, 100) as $m) {
                $e = $this->repo->contactEnsure($ch['id'], $m['contact'], $m['name'], null);
                if ($e['result'] !== 'ok' || $e['conversation_id'] === null) {
                    continue;
                }
                $r = $this->repo->receive($e['conversation_id'], $m['text'], $m['mid']);
                if ($r['result'] === 'received' && $r['seq'] !== null && $this->afterReceive !== null) {
                    try {
                        ($this->afterReceive)($ch['tenant_id'], $e['conversation_id'], (int) $r['seq']);
                    } catch (\Throwable) {
                        // saved already; the platform must still get its 200
                    }
                }
            }

            return [200, 'ok'];
        });
    }

    /** @return array<string, mixed>|null */
    private function channel(string $key): ?array
    {
        return preg_match('/\A[0-9a-f]{32}\z/', $key) === 1 ? $this->repo->channelByKey($key) : null;
    }
}
