<?php

declare(strict_types=1);

namespace App\Vision;

use App\BillScan\BillFile;

/** A picture attached to a chat message becomes text first: the AI's description plus any writing in it. The chat model then reads that text. */
final class ImageAttachment
{
    public const MAX_IMAGES = 2;

    public const LABEL = "[Information the AI read from the image — a description and text of the image; may have errors]\n";

    public function __construct(private readonly VisionReader $reader) {}

    public static function isImage(string $bytes): bool
    {
        return in_array(BillFile::sniff($bytes), ['image/jpeg', 'image/png', 'image/webp'], true);
    }

    /** @return array{attachment: ?array{filename: string, mime: string, size: int, text: string}, error: ?string, credits: string} */
    public function build(string $tenantId, string $userId, string $name, string $bytes, string $requestId): array
    {
        $r = $this->reader->read($tenantId, $userId, $bytes, 'describe', $requestId);
        $err = VisionText::error($r['status'], $r['reason']);
        if ($err !== null || $r['text'] === null) {
            return ['attachment' => null, 'error' => $err ?? VisionText::error('failed'), 'credits' => $r['credits']];
        }

        return ['attachment' => ['filename' => BillFile::safeName($name), 'mime' => 'text/plain', 'size' => strlen($bytes), 'text' => mb_substr(self::LABEL.$r['text'], 0, 190000)], 'error' => null, 'credits' => $r['credits']];
    }
}
