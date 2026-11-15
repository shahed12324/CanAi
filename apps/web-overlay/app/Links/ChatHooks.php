<?php

declare(strict_types=1);

namespace App\Links;

use App\Actions\Tenancy\CreateWorkspace;

/** Saved Slack / Discord webhooks of one person: add, remove, and post a message through one of them. */
final class ChatHooks
{
    public const MESSAGES = [
        'bad_url' => 'The address is not a Slack or Discord webhook address. Slack: https://hooks.slack.com/services/… · Discord: https://discord.com/api/webhooks/…',
        'wrong_kind' => 'The address does not match the chosen service (Slack/Discord).',
        'bad_input' => 'Write a name (max 60 characters).',
        'limit' => 'At most 5 connections can be kept per service.',
        'duplicate' => 'One with this name already exists.',
        'not_found' => 'This is no longer found.',
        'empty_text' => 'The message is blank.',
        'failed' => 'The message could not be sent — is the webhook still active? (If deleted or disabled, add it again.)',
        'rate' => 'The service is not taking many requests now — try shortly.',
    ];

    public function __construct(private readonly LinkRepo $repo, private readonly Secrets $vault, private readonly WebhookPoster $poster) {}

    public static function message(string $code): string
    {
        return self::MESSAGES[$code] ?? 'Could not be done.';
    }

    /** @return string ok|bad_url|wrong_kind|bad_input|limit|duplicate */
    public function add(string $tenantId, string $userId, string $kind, string $name, string $url): string
    {
        $p = WebhookUrl::parse($url);
        if ($p === null) {
            return 'bad_url';
        }
        if ($p['kind'] !== $kind) {
            return 'wrong_kind';
        }
        $enc = $this->vault->encrypt(trim($url));

        return $this->repo->add($tenantId, $userId, CreateWorkspace::newId(), $kind, $name, $enc['cipher'], $enc['version'], $p['host']);
    }

    /** @return string ok|empty_text|not_found|failed|rate */
    public function send(string $tenantId, string $userId, string $linkId, string $text): string
    {
        $link = $this->repo->find($userId, $linkId);

        return $link === null || ! in_array($link['kind'], ['slack', 'discord'], true) ? 'not_found' : $this->post($tenantId, $userId, $link, $text);
    }

    /** @return string ok|empty_text|not_found|failed|rate */
    public function sendByName(string $tenantId, string $userId, string $name, string $text): string
    {
        foreach (['slack', 'discord'] as $kind) {
            $link = $this->repo->findByName($userId, $kind, $name);
            if ($link !== null) {
                return $this->post($tenantId, $userId, $link, $text);
            }
        }

        return 'not_found';
    }

    /** @param array{id: string, kind: string, secret_ct: string, secret_ver: int} $link */
    private function post(string $tenantId, string $userId, array $link, string $text): string
    {
        $body = WebhookUrl::payload($link['kind'], $text);
        if (trim((string) ($body['text'] ?? $body['content'])) === '') {
            return 'empty_text';
        }
        try {
            $url = $this->vault->decrypt($link['secret_ct'], $link['secret_ver']);
        } catch (\Throwable $e) {
            $this->repo->touch($tenantId, $userId, $link['id'], 'decrypt');

            return 'failed';
        }
        $status = $this->poster->post($url, $body);
        $ok = $status >= 200 && $status < 300;
        $this->repo->touch($tenantId, $userId, $link['id'], $ok ? null : 'HTTP '.$status);

        return $ok ? 'ok' : ($status === 429 ? 'rate' : 'failed');
    }
}
