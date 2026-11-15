<?php

declare(strict_types=1);

namespace App\Inbox\Channels;

use App\Outreach\HttpJson;

/**
 * Messenger, Instagram DM and WhatsApp Cloud API all speak the Meta Graph API. The access token travels in the Authorization header
 * (never in the URL, so it cannot end up in logs). 4xx answers are permanent; 429 / 5xx / network errors are worth retrying.
 */
final class MetaAdapter implements ChannelAdapter
{
    private const MAX = ['messenger' => 2000, 'instagram' => 1000, 'whatsapp' => 4096];

    public function __construct(private readonly HttpJson $http, private readonly string $kind, private readonly string $version = 'v21.0')
    {
        if (! isset(self::MAX[$kind])) {
            throw new \InvalidArgumentException('not a Meta channel');
        }
    }

    public function maxLength(): int
    {
        return self::MAX[$this->kind];
    }

    /** Proves the token works with one read call (before anything is stored). */
    public function ping(array $creds, string $accountRef): void
    {
        $token = (string) ($creds['access_token'] ?? '');
        $path = $this->kind === 'whatsapp' ? $accountRef : 'me';
        if ($token === '' || preg_match('/\A(me|\d{5,25})\z/', $path) !== 1) {
            throw new ChannelException('bad credentials', true);
        }
        try {
            $r = $this->http->request('GET', "https://graph.facebook.com/{$this->version}/$path?fields=id", ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json']);
        } catch (\Throwable) {
            throw new ChannelException('network error');
        }
        if ($r['status'] < 200 || $r['status'] >= 300 || ! isset($r['json']['id'])) {
            throw new ChannelException('HTTP '.$r['status'], $r['status'] >= 400 && $r['status'] < 500 && $r['status'] !== 429);
        }
    }

    public function send(array $creds, string $accountRef, string $to, string $text): string
    {
        $token = (string) ($creds['access_token'] ?? '');
        if ($token === '' || preg_match('/\A[\w\-.]+\z/', $to) !== 1) {
            throw new ChannelException('bad credentials or recipient', true);
        }
        if ($this->kind === 'whatsapp') {
            if (preg_match('/\A\d{5,20}\z/', $accountRef) !== 1) {
                throw new ChannelException('bad phone number id', true);
            }
            $url = "https://graph.facebook.com/{$this->version}/$accountRef/messages";
            $body = ['messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => $to, 'type' => 'text', 'text' => ['preview_url' => false, 'body' => $text]];
        } else {
            $url = "https://graph.facebook.com/{$this->version}/me/messages";
            $body = ['recipient' => ['id' => $to], 'messaging_type' => 'RESPONSE', 'message' => ['text' => $text]];
        }
        try {
            $r = $this->http->request('POST', $url, ['Authorization' => 'Bearer '.$token, 'Content-Type' => 'application/json', 'Accept' => 'application/json'], null, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } catch (\Throwable) {
            throw new ChannelException('network error');
        }
        $s = $r['status'];
        if ($s === 429 || $s >= 500) {
            throw new ChannelException("HTTP $s");
        }
        if ($s < 200 || $s >= 300) {
            $msg = is_array($r['json']['error'] ?? null) ? (string) ($r['json']['error']['message'] ?? '') : '';
            throw new ChannelException(mb_substr("HTTP $s ".preg_replace('/[^\PC\s]/u', '', $msg), 0, 150), true);
        }
        $id = $this->kind === 'whatsapp' ? ($r['json']['messages'][0]['id'] ?? '') : ($r['json']['message_id'] ?? '');

        return mb_substr((string) $id, 0, 120);
    }
}
