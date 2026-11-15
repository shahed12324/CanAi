<?php

declare(strict_types=1);

namespace App\Messaging;

/** WhatsApp Cloud API webhook JSON -> InboundMessage list. Defensive: every field may be missing or the wrong type. */
final class WhatsAppNormalizer
{
    /** Callback ids carry the sender so the sender class can answer without a chat id: "<wa_id>|<message id>". */
    public const CB_SEP = '|';

    /** @param array<string, mixed> $payload  @return list<InboundMessage> only messages sent to $phoneNumberId */
    public static function normalize(array $payload, string $phoneNumberId): array
    {
        $out = [];
        foreach (self::arr($payload['entry'] ?? null) as $entry) {
            foreach (self::arr(is_array($entry) ? ($entry['changes'] ?? null) : null) as $change) {
                $v = is_array($change) && is_array($change['value'] ?? null) ? $change['value'] : [];
                if (($v['metadata']['phone_number_id'] ?? null) !== $phoneNumberId) {
                    continue;
                }
                $names = [];
                foreach (self::arr($v['contacts'] ?? null) as $c) {
                    if (is_array($c) && is_string($c['wa_id'] ?? null)) {
                        $names[$c['wa_id']] = is_string($c['profile']['name'] ?? null) ? mb_substr($c['profile']['name'], 0, 64) : null;
                    }
                }
                foreach (self::arr($v['messages'] ?? null) as $m) {
                    $msg = is_array($m) ? self::one($m, $names) : null;
                    if ($msg !== null) {
                        $out[] = $msg;
                    }
                }
            }
        }

        return $out;
    }

    /** A stable positive 60-bit id for the dedupe table (its column is bigint) from WhatsApp's string message id. */
    public static function numericId(string $wamid): int
    {
        return (int) hexdec(substr(hash('sha256', $wamid), 0, 15));
    }

    /** @param array<string, mixed> $m @param array<string, ?string> $names */
    private static function one(array $m, array $names): ?InboundMessage
    {
        $wamid = is_string($m['id'] ?? null) ? $m['id'] : '';
        $from = is_string($m['from'] ?? null) && preg_match('/\A\d{5,20}\z/', $m['from']) === 1 ? $m['from'] : '';
        if ($wamid === '' || $from === '') {
            return null;
        }
        $id = self::numericId($wamid);
        $base = [$from, $from, $names[$from] ?? null, false, true];
        $type = is_string($m['type'] ?? null) ? $m['type'] : '';

        switch ($type) {
            case 'text':
                return new InboundMessage($id, InboundMessage::TEXT, ...$base, text: mb_substr((string) ($m['text']['body'] ?? ''), 0, 20000));
            case 'audio':
                $a = is_array($m['audio'] ?? null) ? $m['audio'] : [];

                return new InboundMessage($id, InboundMessage::VOICE, ...$base, fileId: self::id($a['id'] ?? null), mime: is_string($a['mime_type'] ?? null) ? mb_substr($a['mime_type'], 0, 100) : null);
            case 'document':
                $d = is_array($m['document'] ?? null) ? $m['document'] : [];

                return new InboundMessage($id, InboundMessage::DOCUMENT, ...$base, text: mb_substr((string) ($d['caption'] ?? ''), 0, 2000), fileId: self::id($d['id'] ?? null),
                    fileName: is_string($d['filename'] ?? null) ? mb_substr($d['filename'], 0, 255) : null, mime: is_string($d['mime_type'] ?? null) ? mb_substr($d['mime_type'], 0, 100) : null,
                    fileSize: 1);   // WhatsApp does not tell the size up front; the download enforces the real limit
            case 'image':
            case 'sticker':
            case 'video':
                return new InboundMessage($id, InboundMessage::PHOTO, ...$base, text: mb_substr((string) ($m[$type]['caption'] ?? ''), 0, 2000));
            case 'interactive':
                $b = is_array($m['interactive']['button_reply'] ?? null) ? $m['interactive']['button_reply'] : [];
                $data = is_string($b['id'] ?? null) ? mb_substr($b['id'], 0, 64) : null;

                return $data === null ? new InboundMessage($id, InboundMessage::UNSUPPORTED, ...$base)
                    : new InboundMessage($id, InboundMessage::CALLBACK, ...$base, callbackId: $from.self::CB_SEP.$wamid, callbackData: $data);
            default:
                return new InboundMessage($id, InboundMessage::UNSUPPORTED, ...$base);
        }
    }

    /** @return array<int|string, mixed> */
    private static function arr(mixed $v): array
    {
        return is_array($v) ? $v : [];
    }

    private static function id(mixed $v): ?string
    {
        return is_string($v) && preg_match('/\A[0-9A-Za-z_.-]{1,128}\z/', $v) === 1 ? $v : null;
    }
}
