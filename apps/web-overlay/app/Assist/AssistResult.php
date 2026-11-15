<?php

declare(strict_types=1);

namespace App\Assist;

use App\Office\OfficeSpec;

/** Reads the model's answer into plain, size-limited fields. Nothing the model writes is trusted as markup. Pure. */
final class AssistResult
{
    /** @return array<string, mixed>|null */
    public static function parse(string $mode, string $answer): ?array
    {
        $data = json_decode(trim($answer), true);
        if (! is_array($data)) {
            $a = strpos($answer, '{');
            $b = strrpos($answer, '}');
            $data = ($a !== false && $b !== false && $b > $a) ? json_decode(substr($answer, $a, $b - $a + 1), true) : null;
        }
        if (! is_array($data) || array_is_list($data)) {
            return null;
        }
        if ($mode === 'draft') {
            $body = OfficeSpec::text($data['body'] ?? '', 5000);

            return $body === '' ? null : ['subject' => OfficeSpec::text($data['subject'] ?? '', 200), 'body' => $body];
        }
        $summary = OfficeSpec::text($data['summary'] ?? '', 3000);
        if ($mode === 'summary') {
            return $summary === '' ? null : ['summary' => $summary, 'points' => self::list($data['points'] ?? null, 10, 300), 'needs_reply' => ($data['needs_reply'] ?? false) === true,
                'deadlines' => self::list($data['deadlines'] ?? null, 10, 200)];
        }
        if ($mode !== 'meeting') {
            return null;
        }
        $actions = [];
        foreach (is_array($data['actions'] ?? null) ? array_slice($data['actions'], 0, 20) : [] as $x) {
            if (! is_array($x)) {
                continue;
            }
            $task = OfficeSpec::text($x['task'] ?? '', 200);
            if ($task !== '') {
                $actions[] = ['task' => $task, 'owner' => OfficeSpec::text($x['owner'] ?? '', 80), 'due' => self::date($x['due'] ?? null)];
            }
        }
        $decisions = self::list($data['decisions'] ?? null, 20, 300);

        return ($summary === '' && $actions === [] && $decisions === []) ? null : ['summary' => $summary, 'decisions' => $decisions, 'actions' => $actions];
    }

    /** @return list<string> */
    private static function list(mixed $v, int $count, int $len): array
    {
        $out = [];
        foreach (is_array($v) ? array_slice(array_values($v), 0, $count) : [] as $x) {
            $s = OfficeSpec::text($x, $len);
            if ($s !== '') {
                $out[] = $s;
            }
        }

        return $out;
    }

    private static function date(mixed $v): ?string
    {
        if (! is_string($v)) {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($v));

        return ($d !== false && $d->format('Y-m-d') === trim($v)) ? trim($v) : null;
    }
}
