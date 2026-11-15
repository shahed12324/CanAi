<?php

declare(strict_types=1);

namespace App\Messaging;

/** Telegram Update JSON -> InboundMessage. Defensive: every field may be missing or the wrong type. */
final class TelegramNormalizer
{
    /** @param array<string, mixed> $u  @return InboundMessage|null null when there is no usable update_id */
    public static function normalize(array $u): ?InboundMessage
    {
        $id = $u['update_id'] ?? null;
        if (! is_int($id) || $id < 0) {
            return null;
        }

        if (isset($u['callback_query']) && is_array($u['callback_query'])) {
            $q = $u['callback_query'];
            $from = is_array($q['from'] ?? null) ? $q['from'] : [];
            $chat = is_array($q['message']['chat'] ?? null) ? $q['message']['chat'] : [];

            return new InboundMessage($id, InboundMessage::CALLBACK, self::id($from['id'] ?? null), self::id($chat['id'] ?? null), self::str($from['username'] ?? null, 64),
                ($from['is_bot'] ?? false) === true, ($chat['type'] ?? '') === 'private', '', null, null, null, 0,
                self::str($q['id'] ?? null, 64), self::str($q['data'] ?? null, 64));
        }

        $m = $u['message'] ?? null;
        if (! is_array($m)) {                      // edited_message, channel_post, my_chat_member ... are ignored
            return new InboundMessage($id, InboundMessage::UNSUPPORTED);
        }
        $from = is_array($m['from'] ?? null) ? $m['from'] : [];
        $chat = is_array($m['chat'] ?? null) ? $m['chat'] : [];
        $base = [self::id($from['id'] ?? null), self::id($chat['id'] ?? null), self::str($from['username'] ?? null, 64), ($from['is_bot'] ?? false) === true, ($chat['type'] ?? '') === 'private'];
        $text = (string) self::str($m['text'] ?? ($m['caption'] ?? ''), 20000);

        if (isset($m['document']) && is_array($m['document'])) {
            $d = $m['document'];

            return new InboundMessage($id, InboundMessage::DOCUMENT, ...$base, text: $text, fileId: self::str($d['file_id'] ?? null, 200),
                fileName: self::str($d['file_name'] ?? null, 255), mime: self::str($d['mime_type'] ?? null, 100), fileSize: (int) ($d['file_size'] ?? 0));
        }
        if (isset($m['photo']) && is_array($m['photo'])) {
            return new InboundMessage($id, InboundMessage::PHOTO, ...$base, text: $text);
        }
        if (isset($m['voice']) || isset($m['audio']) || isset($m['video_note'])) {
            $v = is_array($m['voice'] ?? null) ? $m['voice'] : (is_array($m['audio'] ?? null) ? $m['audio'] : []);

            return new InboundMessage($id, InboundMessage::VOICE, ...$base, fileId: self::str($v['file_id'] ?? null, 200), mime: self::str($v['mime_type'] ?? null, 100), fileSize: (int) ($v['file_size'] ?? 0));
        }
        if (isset($m['text']) && is_string($m['text'])) {
            return new InboundMessage($id, InboundMessage::TEXT, ...$base, text: $text);
        }

        return new InboundMessage($id, InboundMessage::UNSUPPORTED, ...$base);
    }

    private static function id(mixed $v): string
    {
        return (is_int($v) || (is_string($v) && preg_match('/\A-?\d{1,20}\z/', $v) === 1)) ? (string) $v : '';
    }

    private static function str(mixed $v, int $max): ?string
    {
        return is_string($v) ? mb_substr($v, 0, $max) : (is_int($v) ? (string) $v : null);
    }
}
