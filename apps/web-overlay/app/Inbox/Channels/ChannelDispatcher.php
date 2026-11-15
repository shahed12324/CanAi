<?php

declare(strict_types=1);

namespace App\Inbox\Channels;

use App\Inbox\InboxRepo;
use App\Messaging\TextSplitter;
use Closure;

/**
 * Delivers queued outgoing messages. The rules are re-checked at SEND time (a message can wait a while in the queue):
 * emergency stop holds it, a closed 24h window or a paused channel fails it, transient errors retry (max 5), permanent ones fail at once.
 */
final class ChannelDispatcher
{
    /**
     * @param  Closure(string): ChannelAdapter  $adapterFor  kind -> adapter
     * @param  Closure(string): ?array<string, mixed>  $creds  channel id -> decrypted secrets
     */
    public function __construct(private readonly InboxRepo $repo, private readonly Closure $adapterFor, private readonly Closure $creds) {}

    /** @return 'sent'|'failed'|'retry'|'held'|'skipped' */
    public function dispatch(int $seq): string
    {
        $x = $this->repo->dispatchContext($seq);
        if ($x === null || $x['status'] !== 'queued') {
            return 'skipped';
        }
        if ($x['emergency']) {
            return 'held';                                           // stays queued; nothing leaves while the emergency stop is on
        }
        if (! $x['channel_active']) {
            return $this->fail($seq, 'channel paused');
        }
        if (! $x['window_ok']) {
            return $this->fail($seq, 'window_closed');
        }
        $creds = ($this->creds)($x['channel_id']);
        if ($creds === null) {
            return $this->fail($seq, 'credentials unreadable');
        }
        $adapter = ($this->adapterFor)($x['kind']);
        $sent = 0;
        try {
            foreach (TextSplitter::split((string) $x['body'], $adapter->maxLength()) as $part) {
                $adapter->send($creds, (string) $x['external_ref'], (string) $x['to_external'], $part);
                $sent++;
            }
        } catch (ChannelException $e) {
            if ($sent > 0) {
                return $this->fail($seq, 'partial: '.$e->getMessage());     // part of it is out already: never resend it
            }
            if ($e->permanent) {
                return $this->fail($seq, $e->getMessage());
            }

            return $this->repo->messageRetry($seq, $e->getMessage()) === 'failed' ? 'failed' : 'retry';
        }
        $this->repo->messageStatus($seq, 'sent', null);

        return 'sent';
    }

    private function fail(int $seq, string $why): string
    {
        $this->repo->messageStatus($seq, 'failed', $why);

        return 'failed';
    }
}
