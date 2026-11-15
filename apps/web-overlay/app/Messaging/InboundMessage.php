<?php

declare(strict_types=1);

namespace App\Messaging;

/** A channel-neutral inbound event (today: Telegram). Never carries secrets. */
final class InboundMessage
{
    public const TEXT = 'text';
    public const DOCUMENT = 'document';
    public const PHOTO = 'photo';
    public const VOICE = 'voice';
    public const CALLBACK = 'callback';
    public const UNSUPPORTED = 'unsupported';

    public function __construct(
        public readonly int $updateId,
        public readonly string $kind,
        public readonly string $fromId = '',
        public readonly string $chatId = '',
        public readonly ?string $username = null,
        public readonly bool $fromBot = false,
        public readonly bool $privateChat = false,
        public readonly string $text = '',          // message text, or the caption of a file
        public readonly ?string $fileId = null,
        public readonly ?string $fileName = null,
        public readonly ?string $mime = null,
        public readonly int $fileSize = 0,
        public readonly ?string $callbackId = null,
        public readonly ?string $callbackData = null,
    ) {}

    /** @return array<string, mixed> for queue serialisation */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /** @param array<string, mixed> $a */
    public static function fromArray(array $a): self
    {
        return new self(
            (int) $a['updateId'], (string) $a['kind'], (string) ($a['fromId'] ?? ''), (string) ($a['chatId'] ?? ''), $a['username'] ?? null,
            (bool) ($a['fromBot'] ?? false), (bool) ($a['privateChat'] ?? false), (string) ($a['text'] ?? ''), $a['fileId'] ?? null,
            $a['fileName'] ?? null, $a['mime'] ?? null, (int) ($a['fileSize'] ?? 0), $a['callbackId'] ?? null, $a['callbackData'] ?? null,
        );
    }
}
