<?php

declare(strict_types=1);

namespace App\Inbox\Channels;

/**
 * Turns platform webhook payloads into plain messages. Payloads are untrusted even after the signature check, so every field is
 * type-checked and shortened; anything unrecognised is skipped, never guessed. Returns list of
 * ['contact' => id, 'name' => ?string, 'text' => string, 'mid' => string].
 */
final class InboundParser
{
    /** @return list<array{contact: string, name: ?string, text: string, mid: string}> */
    public static function meta(string $kind, array $payload, string $accountRef): array
    {
        $out = [];
        $entries = is_array($payload['entry'] ?? null) ? $payload['entry'] : [];
        foreach ($entries as $e) {
            if (! is_array($e)) {
                continue;
            }
            if ($kind === 'whatsapp') {
                foreach (is_array($e['changes'] ?? null) ? $e['changes'] : [] as $ch) {
                    $v = is_array($ch) && ($ch['field'] ?? '') === 'messages' && is_array($ch['value'] ?? null) ? $ch['value'] : null;
                    if ($v === null || (string) ($v['metadata']['phone_number_id'] ?? '') !== $accountRef) {
                        continue;                                    // another number's traffic: not ours
                    }
                    $names = [];
                    foreach (is_array($v['contacts'] ?? null) ? $v['contacts'] : [] as $c) {
                        if (is_array($c) && is_string($c['wa_id'] ?? null)) {
                            $names[$c['wa_id']] = is_string($c['profile']['name'] ?? null) ? mb_substr($c['profile']['name'], 0, 120) : null;
                        }
                    }
                    foreach (is_array($v['messages'] ?? null) ? $v['messages'] : [] as $m) {
                        if (! is_array($m) || ! is_string($m['from'] ?? null) || ! is_string($m['id'] ?? null) || preg_match('/\A\d{5,20}\z/', $m['from']) !== 1) {
                            continue;
                        }
                        $type = (string) ($m['type'] ?? '');
                        $text = $type === 'text' && is_string($m['text']['body'] ?? null) ? $m['text']['body'] : self::placeholder($type);
                        $out[] = ['contact' => $m['from'], 'name' => $names[$m['from']] ?? null, 'text' => mb_substr($text, 0, 4000), 'mid' => mb_substr($m['id'], 0, 120)];
                    }
                }

                continue;
            }
            if ((string) ($e['id'] ?? '') !== $accountRef) {
                continue;                                            // another page/account's traffic: not ours
            }
            foreach (is_array($e['messaging'] ?? null) ? $e['messaging'] : [] as $m) {
                $msg = is_array($m) && is_array($m['message'] ?? null) ? $m['message'] : null;
                $from = is_array($m) ? (string) ($m['sender']['id'] ?? '') : '';
                if ($msg === null || ($msg['is_echo'] ?? false) || $from === '' || $from === $accountRef || preg_match('/\A[\w-]{1,60}\z/', $from) !== 1 || ! is_string($msg['mid'] ?? null)) {
                    continue;                                        // echoes of our own sends, postbacks, reads...
                }
                $text = is_string($msg['text'] ?? null) && $msg['text'] !== '' ? $msg['text'] : self::placeholder((string) ($msg['attachments'][0]['type'] ?? 'other'));
                $out[] = ['contact' => $from, 'name' => null, 'text' => mb_substr($text, 0, 4000), 'mid' => mb_substr($msg['mid'], 0, 120)];
            }
        }

        return $out;
    }

    /** @return list<array{contact: string, name: ?string, text: string, mid: string}> */
    public static function telegram(array $update): array
    {
        $m = is_array($update['message'] ?? null) ? $update['message'] : null;
        if ($m === null || ($m['chat']['type'] ?? '') !== 'private' || ! is_int($m['chat']['id'] ?? null) || ! is_int($m['message_id'] ?? null) || ($m['from']['is_bot'] ?? false)) {
            return [];                                               // groups, channels, bots: not customer chats
        }
        $f = is_array($m['from'] ?? null) ? $m['from'] : [];
        $name = trim((string) ($f['first_name'] ?? '').' '.(string) ($f['last_name'] ?? ''));
        $text = is_string($m['text'] ?? null) && $m['text'] !== '' ? $m['text'] : self::placeholder(isset($m['photo']) ? 'image' : (isset($m['voice']) ? 'audio' : 'other'));

        return [['contact' => (string) $m['chat']['id'], 'name' => $name !== '' ? mb_substr($name, 0, 120) : null, 'text' => mb_substr($text, 0, 4000), 'mid' => $m['chat']['id'].':'.$m['message_id']]];
    }

    private static function placeholder(string $type): string
    {
        return match ($type) {
            'image', 'sticker' => '[sent a photo]',
            'audio', 'voice' => '[sent voice/audio]',
            'video' => '[sent a video]',
            'location' => '[sent a location]',
            'file', 'document' => '[sent a file]',
            default => '[Message without text]',
        };
    }
}
