<?php

declare(strict_types=1);

namespace App\Ai;

/**
 * Pure model selection (no framework, no I/O) so it can be tested exhaustively.
 *
 * A candidate is an array: id, provider_id, provider_priority, quality_rank (1-100),
 * cost (credit-weighted output price, null = unknown), latency_ms (recent average, null = unknown),
 * capabilities (list<string>).
 *
 * Precedence (see choose()): tenant "manual" default > admin routing rule (only when the tenant left
 * the mode on "balanced") > the tenant's chosen mode.
 */
final class ModelRouter
{
    public const MODES = ['cheapest', 'fastest', 'best_quality', 'balanced', 'manual'];

    /**
     * @param  list<array<string, mixed>>  $candidates  models the tenant is allowed to use
     * @param  array{default_model_id?: ?string, routing_mode?: string}  $prefs
     * @param  array{mode: string, preferred_model_ids: list<string>}|null  $rule  admin rule for the task type
     * @param  list<string>  $needs  required capabilities
     * @return array<string, mixed>|null
     */
    public static function choose(array $candidates, array $prefs, ?array $rule, array $needs = ['text']): ?array
    {
        $pool = array_values(array_filter($candidates, fn ($c) => array_diff($needs, $c['capabilities'] ?? []) === []));
        if ($pool === []) {
            return null;
        }
        $mode = $prefs['routing_mode'] ?? 'balanced';
        $byId = array_column($pool, null, 'id');

        if ($mode === 'manual') {
            $default = $prefs['default_model_id'] ?? null;
            if ($default !== null && isset($byId[$default])) {
                return $byId[$default];
            }
            $mode = 'balanced';   // default not usable for this task: degrade gracefully, never fail
        }

        if ($mode === 'balanced' && $rule !== null) {
            foreach ($rule['preferred_model_ids'] as $id) {
                if (isset($byId[$id])) {
                    return $byId[$id];
                }
            }
            $mode = in_array($rule['mode'], self::MODES, true) && $rule['mode'] !== 'manual' ? $rule['mode'] : 'balanced';
        }

        return self::rank($pool, $mode)[0];
    }

    /**
     * Candidates best-first for a mode. Ties break on provider priority (lower first), then id.
     *
     * @param  list<array<string, mixed>>  $pool
     * @return list<array<string, mixed>>
     */
    public static function rank(array $pool, string $mode): array
    {
        $costRank = self::percentiles($pool, 'cost');
        $score = function (array $c) use ($mode, $costRank): float {
            return match ($mode) {
                'cheapest' => -($c['cost'] ?? INF),
                'fastest' => -($c['latency_ms'] ?? INF),
                'best_quality' => (float) $c['quality_rank'],
                default => $c['quality_rank'] * 0.6 + (100 - $costRank[$c['id']]) * 0.4,
            };
        };
        usort($pool, function (array $a, array $b) use ($score): int {
            return [$score($b), $a['provider_priority'] ?? 100, $a['id']] <=> [$score($a), $b['provider_priority'] ?? 100, $b['id']];
        });

        return $pool;
    }

    /**
     * Provider ids to try, in order: the provider itself, then its fallback chain. Loops and
     * missing/disabled links end the chain instead of looping forever.
     *
     * @param  array<string, array{fallback: ?string, status: string}>  $providers
     * @return list<string>
     */
    public static function fallbackChain(string $start, array $providers): array
    {
        $chain = [];
        for ($id = $start; $id !== null && ! in_array($id, $chain, true) && count($chain) < 10; $id = $providers[$id]['fallback'] ?? null) {
            if (! isset($providers[$id]) || $providers[$id]['status'] === 'disabled') {
                break;
            }
            $chain[] = $id;
        }

        return $chain;
    }

    /**
     * Cost percentile 0..100 per candidate (0 = cheapest). Unknown cost counts as the most expensive.
     *
     * @param  list<array<string, mixed>>  $pool
     * @return array<string, float>
     */
    private static function percentiles(array $pool, string $key): array
    {
        $vals = array_map(fn ($c) => $c[$key] ?? INF, $pool);
        $out = [];
        $n = count($pool);
        foreach ($pool as $i => $c) {
            $below = count(array_filter($vals, fn ($v) => $v < $vals[$i]));
            $out[$c['id']] = $n > 1 ? 100 * $below / ($n - 1) : 0.0;
        }

        return $out;
    }
}
