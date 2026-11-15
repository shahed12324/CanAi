<?php

declare(strict_types=1);

namespace App\Links;

/**
 * A Slack or Discord "incoming webhook" address.  Only the real services' own addresses are accepted, so a typed address can never make our server
 * call somewhere else.  The address is a secret (whoever has it can post in the channel).  Pure.
 */
final class WebhookUrl
{
    /** @return array{kind: string, host: string}|null kind slack|discord, or null when it is not one of those */
    public static function parse(string $url): ?array
    {
        $url = trim($url);
        if (strlen($url) > 400 || preg_match('/[\s\x00-\x1f]/', $url) === 1) {
            return null;
        }
        $p = parse_url($url);
        if ($p === false || ($p['scheme'] ?? '') !== 'https' || isset($p['user']) || isset($p['pass']) || isset($p['port']) || isset($p['query']) || isset($p['fragment'])) {
            return null;
        }
        $host = strtolower($p['host'] ?? '');
        $path = $p['path'] ?? '';
        if ($host === 'hooks.slack.com' && preg_match('#\A/services/T[A-Z0-9]{6,}/B[A-Z0-9]{6,}/[A-Za-z0-9]{16,}\z#', $path) === 1) {
            return ['kind' => 'slack', 'host' => $host];
        }
        if (in_array($host, ['discord.com', 'discordapp.com', 'canary.discord.com', 'ptb.discord.com'], true) && preg_match('#\A/api/(v\d+/)?webhooks/\d{15,25}/[A-Za-z0-9_\-]{30,100}\z#', $path) === 1) {
            return ['kind' => 'discord', 'host' => $host];
        }

        return null;
    }

    /** What a message looks like to the channel: mentions that would ping a whole channel or a person are defused; length is capped. */
    public static function payload(string $kind, string $text): array
    {
        $text = (string) preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', '', $text);
        $text = str_replace(['@everyone', '@here', '<!channel', '<!here', '<!everyone', '<@', '<#', '<!subteam'], ["@\u{200B}everyone", "@\u{200B}here", "<\u{200B}!channel", "<\u{200B}!here", "<\u{200B}!everyone", "<\u{200B}@", "<\u{200B}#", "<\u{200B}!subteam"], $text);
        $text = mb_substr(trim($text), 0, 1800);

        return $kind === 'discord' ? ['content' => $text, 'allowed_mentions' => ['parse' => []]] : ['text' => $text, 'link_names' => false];
    }
}
