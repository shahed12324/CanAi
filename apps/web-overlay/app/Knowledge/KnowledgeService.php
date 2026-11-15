<?php

declare(strict_types=1);

namespace App\Knowledge;

use App\Vision\VisionReader;

/** Turns an uploaded file into searchable passages. No AI is involved here, so nothing is charged. */
final class KnowledgeService
{
    public const MAX_BYTES = 5_000_000;

    public function __construct(private readonly KnowledgeRepo $repo, private readonly ?VisionReader $vision = null) {}

    /**
     * @param  bool  $ocr  the person agreed to let the AI read a picture / scanned page (costs credits)
     * @return array{status: string, chunks: int, credits: string, vision: ?string}  status: ok|duplicate|limit|too_big|empty|unsupported|encoding|pdf_unavailable|unreadable|needs_ocr|failed, or a VisionReader failure (no_model|no_credits|provider|rejected)
     */
    public function add(string $tenantId, string $userId, string $id, string $path, string $filename, string $mime, int $size, ?string $title, bool $ocr = false): array
    {
        if ($size > self::MAX_BYTES) {
            return self::res('too_big');
        }
        $credits = '0.0000';
        $x = TextExtractor::extract($path, $filename);
        $isPdf = strtolower(pathinfo($filename, PATHINFO_EXTENSION)) === 'pdf';
        $readByAi = in_array($x['error'], ['needs_ocr', 'empty', 'pdf_unavailable'], true) && ($x['error'] === 'needs_ocr' || $isPdf);
        if ($readByAi) {
            if (! $ocr || $this->vision === null) {
                return self::res('needs_ocr');
            }
            $bytes = (string) @file_get_contents($path);
            $r = $this->vision->read($tenantId, $userId, $bytes, 'transcribe', $id);
            $credits = $r['credits'];
            if ($r['status'] !== 'ok') {
                return self::res($r['status'] === 'unsupported' ? 'unreadable' : $r['status'], $credits, $r['reason']);
            }
            $x = ['text' => TextChunker::normalize((string) $r['text']), 'error' => null];
            if ($x['text'] === '') {
                return self::res('empty', $credits);
            }
        }
        if ($x['text'] === null) {
            return self::res((string) $x['error']);
        }
        $chunks = TextChunker::chunks($x['text']);
        if (count($chunks) > TextChunker::MAX_CHUNKS) {
            return self::res('too_big', $credits);
        }
        $title = trim((string) $title);
        if ($title === '') {
            $title = pathinfo($filename, PATHINFO_FILENAME) ?: $filename;
        }
        $r = $this->repo->save($tenantId, $userId, $id, mb_substr($title, 0, 200), mb_substr($filename, 0, 200), mb_substr($mime, 0, 100), $size, $chunks);

        return ['status' => $r === 'ok' ? 'ok' : ($r === 'duplicate' || $r === 'limit' ? $r : 'failed'), 'chunks' => $r === 'ok' ? count($chunks) : 0, 'credits' => $credits, 'vision' => null];
    }

    /** @return array{status: string, chunks: int, credits: string, vision: ?string} */
    private static function res(string $status, string $credits = '0.0000', ?string $reason = null): array
    {
        return ['status' => $status, 'chunks' => 0, 'credits' => $credits, 'vision' => $reason];
    }

    /** @return list<array{document_id: string, title: string, seq: int, content: string, rank: float}> */
    public function search(string $query, int $k = 5): array
    {
        $query = trim($query);

        return $query === '' ? [] : $this->repo->search($query, $k);
    }
}
