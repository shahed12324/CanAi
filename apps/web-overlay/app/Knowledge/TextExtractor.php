<?php

declare(strict_types=1);

namespace App\Knowledge;

/** Reads the text out of an uploaded file. Never executes anything from the file; PDF goes to the poppler `pdftotext` tool when the server has it. */
final class TextExtractor
{
    public const TEXT = ['txt', 'md', 'csv', 'tsv', 'json', 'log'];

    /** For tests (and odd servers): the full path of pdftotext, or null to look in the usual places. */
    public static ?string $pdftotext = null;

    public const IMAGES = ['png', 'jpg', 'jpeg', 'webp'];

    /** @return array{text: ?string, error: ?string}  error: unsupported | encoding | pdf_unavailable | unreadable | empty | needs_ocr (a picture: only the vision reader can read it) */
    public static function extract(string $path, string $filename): array
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $raw = is_file($path) ? @file_get_contents($path) : false;
        if ($raw === false) {
            return ['text' => null, 'error' => 'unreadable'];
        }
        $text = match (true) {
            in_array($ext, self::TEXT, true) => self::plain($raw),
            in_array($ext, ['html', 'htm'], true) => self::html($raw),
            $ext === 'docx' => self::docx($path),
            $ext === 'pdf' => self::pdf($path),
            in_array($ext, self::IMAGES, true) => ['needs_ocr'],
            default => ['unsupported'],
        };
        if (is_array($text)) {
            return ['text' => null, 'error' => $text[0]];
        }
        $text = TextChunker::normalize($text);

        return $text === '' ? ['text' => null, 'error' => 'empty'] : ['text' => $text, 'error' => null];
    }

    /** @return string|array{0: string} */
    private static function plain(string $raw): string|array
    {
        if (str_starts_with($raw, "\u{FEFF}")) {
            $raw = substr($raw, 3);
        }

        return mb_check_encoding($raw, 'UTF-8') ? $raw : ['encoding'];
    }

    /** @return string|array{0: string} */
    private static function html(string $raw): string|array
    {
        if (! mb_check_encoding($raw, 'UTF-8')) {
            return ['encoding'];
        }
        $raw = (string) preg_replace('~<(script|style)\b[^>]*>.*?</\1>~is', ' ', $raw);
        $raw = (string) preg_replace('~</(p|div|li|tr|h[1-6]|br)>|<br\s*/?>~i', "\n", $raw);

        return html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** @return string|array{0: string} */
    private static function docx(string $path): string|array
    {
        if (! class_exists(\ZipArchive::class)) {
            return ['unsupported'];
        }
        $zip = new \ZipArchive;
        if ($zip->open($path) !== true) {
            return ['unreadable'];
        }
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        if ($xml === false || strlen($xml) > 30_000_000) {
            return ['unreadable'];
        }
        $xml = (string) preg_replace(['~</w:p>~', '~<w:tab\s*/>~', '~<w:br\s*/>~'], ["\n", "\t", "\n"], $xml);

        return html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /** @return string|array{0: string} */
    private static function pdf(string $path): string|array
    {
        if (! function_exists('proc_open') || ! is_executable(self::binary())) {
            return ['pdf_unavailable'];
        }
        $p = @proc_open([self::binary(), '-enc', 'UTF-8', '-q', $path, '-'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($p)) {
            return ['pdf_unavailable'];
        }
        $out = (string) stream_get_contents($pipes[1], 20_000_000);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($p);

        return $code === 0 ? $out : ['unreadable'];
    }

    private static function binary(): string
    {
        if (self::$pdftotext !== null) {
            return self::$pdftotext;
        }
        foreach (['/usr/bin/pdftotext', '/usr/local/bin/pdftotext'] as $b) {
            if (is_executable($b)) {
                return $b;
            }
        }

        return '/usr/bin/pdftotext';
    }
}
