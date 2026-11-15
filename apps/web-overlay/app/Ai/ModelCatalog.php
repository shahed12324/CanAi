<?php

declare(strict_types=1);

namespace App\Ai;

use Illuminate\Support\Facades\DB;

/**
 * Customer-side view of the catalog. Runs on the normal app login: the tenant context decides
 * which plan applies (available_models), and no provider cost or credential is ever selected here.
 */
final class ModelCatalog
{
    /** @return list<array<string, mixed>> */
    public function candidates(string $tenantId): array
    {
        $rows = DB::select(
            'select m.id, m.provider_id, m.model_key, m.display_name, m.category, m.context_window, m.max_output, m.quality_rank,
                    m.is_free_tier, m.is_local, p.name as provider_name, p.priority as provider_priority,
                    pr.credit_multiplier,
                    (pr.output_per_1m * pr.credit_multiplier) as cost,
                    (select round(avg(h.latency_ms)) from provider_health_checks h
                      where h.provider_id = p.id and h.status = \'ok\' and h.checked_at > now() - interval \'24 hours\') as latency_ms
               from available_models(?) a
               join ai_models m on m.id = a.model_id
               join ai_providers p on p.id = m.provider_id
               left join model_current_pricing pr on pr.model_id = m.id
              order by m.quality_rank desc, m.display_name',
            [$tenantId],
        );
        $caps = DB::table('model_capabilities')->whereIn('model_id', array_map(fn ($r) => $r->id, $rows))
            ->get(['model_id', 'capability'])->groupBy('model_id');

        return array_map(fn ($r) => [
            'id' => $r->id, 'provider_id' => $r->provider_id, 'model_key' => $r->model_key, 'name' => $r->display_name, 'provider' => $r->provider_name,
            'category' => $r->category, 'context_window' => $r->context_window, 'max_output' => $r->max_output,
            'quality_rank' => (int) $r->quality_rank, 'is_free_tier' => (bool) $r->is_free_tier, 'is_local' => (bool) $r->is_local,
            'provider_priority' => (int) $r->provider_priority,
            'credit_multiplier' => $r->credit_multiplier === null ? null : (float) $r->credit_multiplier,
            'cost' => $r->cost === null ? null : (float) $r->cost,
            'latency_ms' => $r->latency_ms === null ? null : (float) $r->latency_ms,
            'capabilities' => ($caps[$r->id] ?? collect())->pluck('capability')->all(),
        ], $rows);
    }

    /** @return array{default_model_id: ?string, routing_mode: string} */
    public function preferences(): array
    {
        $r = DB::table('tenant_model_preferences')->first(['default_model_id', 'routing_mode']);   // RLS: this tenant only

        return ['default_model_id' => $r->default_model_id ?? null, 'routing_mode' => $r->routing_mode ?? 'balanced'];
    }

    /**
     * The model to use for a task. Throws nothing: returns null when the tenant has no usable model.
     *
     * @param  list<string>  $needs
     * @return array<string, mixed>|null
     */
    public function choose(string $tenantId, string $taskType = 'chat', array $needs = ['text']): ?array
    {
        $rule = DB::table('routing_rules')->where('task_type', $taskType)->where('status', 'active')->orderBy('priority')->first(['mode', 'preferred_model_ids']);

        return ModelRouter::choose(
            $this->candidates($tenantId),
            $this->preferences(),
            $rule === null ? null : ['mode' => $rule->mode, 'preferred_model_ids' => json_decode($rule->preferred_model_ids, true) ?: []],
            $needs,
        );
    }
}
