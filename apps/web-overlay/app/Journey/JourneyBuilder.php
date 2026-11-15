<?php

declare(strict_types=1);

namespace App\Journey;

/** Pure: merges what the assistant has learned about you (memories, skills, scheduled jobs) into one dated timeline, and formats the export file. */
final class JourneyBuilder
{
    public const KINDS = ['memory' => 'Memory', 'skill' => 'Skill', 'job' => 'Scheduled job'];

    /**
     * @param  list<array{kind: string, at: string, title: string, detail: string, url: string}>  $items
     * @return list<array{kind: string, at: string, title: string, detail: string, url: string}>  newest first
     */
    public static function timeline(array $items, string $kind = '', string $q = '', int $limit = 100): array
    {
        $q = mb_strtolower(trim($q));
        $items = array_values(array_filter($items, function (array $i) use ($kind, $q): bool {
            if ($kind !== '' && $i['kind'] !== $kind) {
                return false;
            }

            return $q === '' || str_contains(mb_strtolower($i['title'].' '.$i['detail']), $q);
        }));
        usort($items, fn ($a, $b) => strcmp($b['at'], $a['at']) ?: strcmp($a['title'], $b['title']));

        return array_slice($items, 0, max(1, $limit));
    }

    /** Memories and skills as a readable Markdown file the person can keep or move elsewhere. @param list<array<string, mixed>> $memories @param list<array<string, mixed>> $skills */
    public static function markdown(array $memories, array $skills, string $when): string
    {
        $out = "# What my assistant remembers\n\nExported {$when}\n\n## Memories\n\n";
        foreach ($memories as $m) {
            $out .= '- '.str_replace("\n", ' ', (string) $m['content']).' _('.$m['category'].($m['scope'] === 'business' ? ', business' : '').($m['pinned'] ? ', important' : '').(! $m['active'] ? ', turned off' : '').")_\n";
        }
        if ($memories === []) {
            $out .= "_None._\n";
        }
        $out .= "\n## Skills\n";
        foreach ($skills as $s) {
            $out .= "\n### /{$s['slug']} — {$s['name']}\n";
            $out .= ($s['description'] !== '' ? "_{$s['description']}_\n\n" : "\n").trim((string) $s['instructions'])."\n";
        }
        if ($skills === []) {
            $out .= "\n_None._\n";
        }

        return $out;
    }

    /** @param list<array<string, mixed>> $memories @param list<array<string, mixed>> $skills */
    public static function json(array $memories, array $skills, string $when): string
    {
        $pick = fn (array $row, array $keys) => array_intersect_key($row, array_flip($keys));

        return json_encode([
            'exported_at' => $when,
            'memories' => array_map(fn ($m) => $pick($m, ['category', 'scope', 'content', 'pinned', 'active']), $memories),
            'skills' => array_map(fn ($s) => $pick($s, ['slug', 'name', 'description', 'instructions', 'version']), $skills),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
