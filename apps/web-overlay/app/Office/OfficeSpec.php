<?php

declare(strict_types=1);

namespace App\Office;

/**
 * Reads what the model wrote into the strict shape the file writer accepts. The model's text is never trusted:
 * only known fields are kept, everything is turned into plain text and cut to the same limits the engine enforces
 * (so a slightly-too-long answer is trimmed, not thrown away), and anything unusable is dropped. Pure.
 */
final class OfficeSpec
{
    public const KINDS = ['docx' => 'Document (Word)', 'xlsx' => 'Spreadsheet (Excel)', 'pptx' => 'Presentation (PowerPoint)'];

    public const EXT = ['docx' => 'docx', 'xlsx' => 'xlsx', 'pptx' => 'pptx'];

    public const MIME = [
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    ];

    private const BLOCKS = ['h1', 'h2', 'h3', 'p', 'ul', 'ol', 'table'];

    public static function text(mixed $v, int $max): string
    {
        if (is_bool($v)) {
            $v = $v ? 'TRUE' : 'FALSE';
        }
        if (! is_string($v) && ! is_int($v) && ! is_float($v)) {
            return '';
        }
        $s = (string) preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', '', (string) $v);

        return mb_substr(trim($s), 0, $max);
    }

    /** @return array<string, mixed>|null a spec ready for storing and for the engine, or null when the answer was not usable */
    public static function parse(string $kind, string $answer): ?array
    {
        if (! isset(self::KINDS[$kind])) {
            return null;
        }
        $data = json_decode(trim($answer), true);
        if (! is_array($data)) {
            $a = strpos($answer, '{');
            $b = strrpos($answer, '}');
            $data = ($a !== false && $b !== false && $b > $a) ? json_decode(substr($answer, $a, $b - $a + 1), true) : null;
        }
        if (! is_array($data) || array_is_list($data)) {
            return null;
        }
        $title = self::text($data['title'] ?? '', 200);

        return match ($kind) {
            'docx' => self::doc($title, $data),
            'xlsx' => self::sheet($title, $data),
            default => self::deck($title, $data),
        };
    }

    /** @param array<string, mixed> $d */
    private static function doc(string $title, array $d): ?array
    {
        $blocks = [];
        foreach (is_array($d['blocks'] ?? null) ? $d['blocks'] : [] as $b) {
            if (! is_array($b) || ! in_array($b['t'] ?? null, self::BLOCKS, true)) {
                continue;
            }
            $t = $b['t'];
            if (in_array($t, ['h1', 'h2', 'h3', 'p'], true)) {
                $x = self::text($b['text'] ?? '', 5000);
                if ($x !== '') {
                    $blocks[] = ['t' => $t, 'text' => $x];
                }
            } elseif ($t === 'ul' || $t === 'ol') {
                $items = self::strings($b['items'] ?? null, 60, 300);
                if ($items !== []) {
                    $blocks[] = ['t' => $t, 'text' => self::text($b['text'] ?? '', 5000), 'items' => $items];
                }
            } else {
                $headers = self::strings($b['headers'] ?? null, 30, 200, true);
                $rows = [];
                foreach (is_array($b['rows'] ?? null) ? array_slice($b['rows'], 0, 500) : [] as $r) {
                    if (is_array($r) && array_is_list($r)) {
                        $rows[] = self::strings($r, 30, 500, true);
                    }
                }
                if ($headers !== [] || $rows !== []) {
                    $blocks[] = ['t' => 'table', 'headers' => $headers, 'rows' => $rows];
                }
            }
            if (count($blocks) >= 300) {
                break;
            }
        }
        if ($title === '' || $blocks === []) {
            return null;
        }

        return ['kind' => 'docx', 'title' => $title, 'blocks' => $blocks];
    }

    /** @param array<string, mixed> $d */
    private static function sheet(string $title, array $d): ?array
    {
        $sheets = [];
        foreach (is_array($d['sheets'] ?? null) ? array_slice($d['sheets'], 0, 10) : [] as $s) {
            if (! is_array($s)) {
                continue;
            }
            $rows = [];
            foreach (is_array($s['rows'] ?? null) ? array_slice($s['rows'], 0, 2000) : [] as $r) {
                if (! is_array($r) || ! array_is_list($r)) {
                    continue;
                }
                $row = [];
                foreach (array_slice($r, 0, 50) as $c) {
                    $row[] = is_int($c) || (is_float($c) && is_finite($c)) ? $c : (($c === null || is_array($c)) ? null : self::text($c, 500));
                }
                $rows[] = $row;
            }
            $headers = self::strings($s['headers'] ?? null, 50, 200, true);
            if ($headers === [] && $rows === []) {
                continue;
            }
            $sheets[] = ['name' => self::text($s['name'] ?? '', 60) ?: 'Sheet', 'headers' => $headers, 'rows' => $rows];
        }
        if ($title === '' || $sheets === []) {
            return null;
        }

        return ['kind' => 'xlsx', 'title' => $title, 'sheets' => $sheets];
    }

    /** @param array<string, mixed> $d */
    private static function deck(string $title, array $d): ?array
    {
        $slides = [];
        foreach (is_array($d['slides'] ?? null) ? array_slice($d['slides'], 0, 40) : [] as $s) {
            if (! is_array($s)) {
                continue;
            }
            $sl = ['title' => self::text($s['title'] ?? '', 200), 'bullets' => self::strings($s['bullets'] ?? null, 12, 300), 'notes' => self::text($s['notes'] ?? '', 2000)];
            if ($sl['title'] !== '' || $sl['bullets'] !== []) {
                $slides[] = $sl;
            }
        }
        if ($title === '' || $slides === []) {
            return null;
        }

        return ['kind' => 'pptx', 'title' => $title, 'slides' => $slides];
    }

    /** @return list<string> */
    private static function strings(mixed $v, int $maxCount, int $maxLen, bool $keepEmpty = false): array
    {
        if (! is_array($v)) {
            return [];
        }
        $out = [];
        foreach (array_slice(array_values($v), 0, $maxCount) as $x) {
            $s = self::text($x, $maxLen);
            if ($s !== '' || $keepEmpty) {
                $out[] = $s;
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $spec @return list<string> a short outline for the page */
    public static function outline(array $spec): array
    {
        $out = [];
        if (($spec['kind'] ?? '') === 'docx') {
            foreach ($spec['blocks'] ?? [] as $b) {
                if (in_array($b['t'], ['h1', 'h2', 'h3'], true)) {
                    $out[] = str_repeat('  ', (int) $b['t'][1] - 1).$b['text'];
                }
            }
            $out[] = count($spec['blocks'] ?? []).' Block (paragraph/list/table)';
        } elseif (($spec['kind'] ?? '') === 'xlsx') {
            foreach ($spec['sheets'] ?? [] as $s) {
                $out[] = $s['name'].' — '.count($s['rows']).' rows, '.max(count($s['headers']), ...array_map('count', $s['rows']) ?: [0]).' columns';
            }
        } else {
            foreach ($spec['slides'] ?? [] as $i => $s) {
                $out[] = ($i + 1).'. '.$s['title'];
            }
        }

        return array_slice($out, 0, 60);
    }
}
