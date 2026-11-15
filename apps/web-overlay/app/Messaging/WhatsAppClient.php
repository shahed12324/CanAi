<?php

declare(strict_types=1);

namespace App\Messaging;

use App\Ai\CredentialVault;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/** Sends and downloads through the WhatsApp Cloud API as the platform's number. The access token never reaches a log or a screen. */
final class WhatsAppClient implements ChannelSender
{
    public const BASE = 'https://graph.facebook.com/v21.0';

    /** @var array<string, WhatsAppCredentials> */
    private array $cache = [];

    public function __construct(private readonly CredentialVault $vault, private readonly int $timeout = 20) {}

    public function sendText(string $accountId, string $chatId, string $text): void
    {
        $c = $this->creds($accountId);
        $this->post($c, '/'.$c->phoneNumberId().'/messages', [
            'messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => $chatId, 'type' => 'text',
            'text' => ['preview_url' => false, 'body' => mb_substr($text, 0, 4000)],
        ]);
    }

    public function typing(string $accountId, string $chatId): void
    {
        // WhatsApp has no typing indicator for a bot that only replies. Cosmetic, so nothing to do.
    }

    /** The "callback id" is "<wa_id>|<message id>": there is no toast on WhatsApp, so the answer is a normal short message. */
    public function answerCallback(string $accountId, string $callbackId, string $text): void
    {
        $to = explode(WhatsAppNormalizer::CB_SEP, $callbackId, 2)[0];
        if (preg_match('/\A\d{5,20}\z/', $to) === 1) {
            $this->sendText($accountId, $to, $text);
        }
    }

    public function download(string $accountId, string $fileId, int $maxBytes): ?string
    {
        $c = $this->creds($accountId);
        $info = $this->get($c, self::BASE.'/'.rawurlencode($fileId))->json();
        $url = is_array($info) ? (string) ($info['url'] ?? '') : '';
        $size = is_array($info) ? (int) ($info['file_size'] ?? 0) : 0;
        if ($url === '' || ! str_starts_with($url, 'https://') || ($size > 0 && $size > $maxBytes)) {
            return null;
        }
        $body = $this->get($c, $url);

        return ! $body->successful() || strlen($body->body()) > $maxBytes ? null : $body->body();
    }

    private function creds(string $accountId): WhatsAppCredentials
    {
        if (isset($this->cache[$accountId])) {
            return $this->cache[$accountId];
        }
        $loaded = WhatsAppCredentials::load($this->vault, $accountId);
        if ($loaded === null || $loaded['status'] !== 'active') {
            throw new ChannelSendException('WhatsApp number is not active.');
        }

        return $this->cache[$accountId] = $loaded['creds'];
    }

    /** @param array<string, mixed> $payload */
    private function post(WhatsAppCredentials $c, string $path, array $payload): void
    {
        try {
            $res = Http::timeout($this->timeout)->withToken($c->accessToken())->asJson()->post(self::BASE.$path, $payload);
        } catch (ConnectionException|RequestException $e) {
            throw new ChannelSendException(self::scrub($e->getMessage(), $c), 0);
        }
        if (! $res->successful()) {
            $j = $res->json();
            $msg = is_array($j) ? (string) ($j['error']['message'] ?? 'error') : 'HTTP '.$res->status();
            throw new ChannelSendException(self::scrub('WhatsApp: '.$msg, $c), $res->status());
        }
    }

    private function get(WhatsAppCredentials $c, string $url): \Illuminate\Http\Client\Response
    {
        try {
            return Http::timeout($this->timeout)->withToken($c->accessToken())->get($url);
        } catch (ConnectionException|RequestException $e) {
            throw new ChannelSendException(self::scrub($e->getMessage(), $c), 0);
        }
    }

    public static function scrub(string $text, WhatsAppCredentials $c): string
    {
        foreach ([$c->accessToken(), $c->appSecret(), $c->verifyToken()] as $secret) {
            $text = str_replace($secret, '[secret]', $text);
        }

        return $text;
    }
}
