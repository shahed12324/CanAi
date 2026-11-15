<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Inbox\Channels\WebhookService;
use Illuminate\Http\Request;

/** Public webhook for Messenger / Instagram / WhatsApp / Telegram. Trust comes from the platform's signature, checked before the body is read. */
final class InboxWebhookController extends Controller
{
    public function __construct(private readonly WebhookService $hooks) {}

    public function handshake(Request $request, string $key)
    {
        [$st, $body] = $this->hooks->handshake($key, $this->q($request, 'hub_mode', 'hub.mode'), $this->q($request, 'hub_verify_token', 'hub.verify_token'), $this->q($request, 'hub_challenge', 'hub.challenge'));

        return response($body, $st)->header('Content-Type', 'text/plain');
    }

    public function receive(Request $request, string $key)
    {
        $headers = [];
        foreach (['x-hub-signature-256', 'x-telegram-bot-api-secret-token'] as $h) {
            $v = $request->headers->get($h);
            if (is_string($v)) {
                $headers[$h] = $v;
            }
        }
        [$st, $body] = $this->hooks->receive($key, $request->getContent(), $headers);

        return response($body, $st)->header('Content-Type', 'text/plain');
    }

    /** PHP rewrites dots in query names to underscores, so accept both spellings. */
    private function q(Request $r, string $a, string $b): ?string
    {
        $v = $r->query($a) ?? $r->query($b);

        return is_string($v) && strlen($v) <= 200 ? $v : null;
    }
}
