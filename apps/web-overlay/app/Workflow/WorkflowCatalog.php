<?php

declare(strict_types=1);

namespace App\Workflow;

/**
 * Browsing helpers for the workflow screen: which of the six groups a workflow or template belongs to, what status to show, and search / filter.  Pure (no database).
 * A workflow keeps the category it was stored with (old rows are never rewritten); the group is worked out from it here.
 */
final class WorkflowCatalog
{
    /** stored category => group shown to people (old categories map onto the new six). */
    public const GROUP_OF_CATEGORY = [
        'productivity' => 'productivity', 'personal' => 'productivity', 'finance' => 'productivity',
        'marketing' => 'marketing', 'ecommerce' => 'marketing',
        'communication' => 'communication', 'sales' => 'communication',
        'knowledge' => 'knowledge',
        'desktop' => 'desktop',
        'development' => 'development', 'developer' => 'development',
    ];

    public const STATUSES = ['all' => 'All', 'active' => 'Active', 'paused' => 'Paused', 'disabled' => 'Off', 'scheduled' => 'Scheduled', 'running' => 'Running', 'attention' => 'Needs attention'];

    /** A workflow that uses the paired computer is always shown in Desktop AI, whatever category it was saved with. @param array<string, mixed> $w */
    public static function groupOfWorkflow(array $w): string
    {
        if (self::usesDesktop((array) ($w['definition'] ?? []))) {
            return 'desktop';
        }

        return self::GROUP_OF_CATEGORY[(string) ($w['category'] ?? '')] ?? 'productivity';
    }

    /** @param array<string, mixed> $def */
    public static function usesDesktop(array $def): bool
    {
        foreach ((array) ($def['nodes'] ?? []) as $n) {
            if (is_array($n) && ($n['type'] ?? null) === 'tool' && is_string($n['tool'] ?? null) && str_starts_with($n['tool'], 'pc.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * One word for the dot on the card.  `$last` = the status of the newest real (non-test) run, if any.
     *
     * @param  array<string, mixed>  $w
     * @return 'disabled'|'paused'|'running'|'attention'|'scheduled'|'active'
     */
    public static function status(array $w, ?string $last): string
    {
        if (! ($w['enabled'] ?? true)) {
            return 'disabled';
        }
        if (in_array($last, ['queued', 'running'], true)) {
            return 'running';
        }
        if ($last === 'waiting_approval') {
            return 'attention';
        }
        if ($w['paused'] ?? false) {
            return 'paused';
        }
        if ($last === 'failed') {
            return 'attention';
        }

        return ($w['schedule_time'] ?? null) !== null ? 'scheduled' : 'active';
    }

    /**
     * @param  list<array<string, mixed>>  $workflows  each with a 'status' and 'group' already worked out
     * @return list<array<string, mixed>>
     */
    public static function filter(array $workflows, string $q, string $group, string $status): array
    {
        $q = mb_strtolower(trim($q));
        $out = [];
        foreach ($workflows as $w) {
            if ($group !== '' && $group !== 'all' && ($w['group'] ?? '') !== $group) {
                continue;
            }
            if ($status !== '' && $status !== 'all' && ! self::statusMatches((string) ($w['status'] ?? ''), $status, $w)) {
                continue;
            }
            if ($q !== '' && mb_stripos(mb_strtolower((string) ($w['name'] ?? '').' '.(string) ($w['description'] ?? '')), $q) === false) {
                continue;
            }
            $out[] = $w;
        }

        return $out;
    }

    /** "active" means "will run" (active, scheduled, running); "scheduled" also holds when a paused/attention card has a time; the rest match exactly. @param array<string, mixed> $w */
    private static function statusMatches(string $actual, string $wanted, array $w): bool
    {
        return match ($wanted) {
            'active' => in_array($actual, ['active', 'scheduled', 'running', 'attention'], true) && ($w['enabled'] ?? true) && ! ($w['paused'] ?? false),
            'scheduled' => ($w['schedule_time'] ?? null) !== null && ($w['enabled'] ?? true),
            default => $actual === $wanted,
        };
    }

    /** @param array<string, array<string, mixed>> $templates @return array<string, array<string, mixed>> */
    public static function filterTemplates(array $templates, string $q, string $group): array
    {
        $q = mb_strtolower(trim($q));
        $out = [];
        foreach ($templates as $k => $t) {
            if ($group !== '' && $group !== 'all' && Templates::group((string) $k) !== $group) {
                continue;
            }
            if ($q !== '' && mb_stripos(mb_strtolower($t['name'].' '.$t['description']), $q) === false) {
                continue;
            }
            $out[$k] = $t;
        }

        return $out;
    }

    /** @param array<string, array<string, mixed>> $templates @return array<string, int> group => count (for the tab badges) */
    public static function templateCounts(array $templates): array
    {
        $c = array_fill_keys(array_keys(Templates::GROUPS), 0);
        foreach (array_keys($templates) as $k) {
            $c[Templates::group((string) $k)]++;
        }

        return $c;
    }
}
