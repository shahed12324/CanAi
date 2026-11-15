<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

/** Platform-wide chat health: volume, spend, per-model error rate, provider failures. Read-only. */
final class ChatAnalyticsController extends AdminController
{
    private const DAYS = [7, 30, 90];

    public function index()
    {
        $this->allow('providers.manage');
        $days = (int) request()->query('days', 30);
        $days = in_array($days, self::DAYS, true) ? $days : 30;
        $db = $this->db();
        $since = now()->subDays($days);

        return view('admin.chat.index', [
            'days' => $days,
            'periods' => self::DAYS,
            'totals' => $db->table('messages')->where('role', 'assistant')->where('created_at', '>=', $since)->selectRaw(
                "count(*) as answers, count(*) filter (where status = 'complete') as ok, count(*) filter (where status in ('error','partial')) as failed,
                 coalesce(sum(credits_charged),0) as credits, coalesce(sum(input_tokens),0) as input_tokens, coalesce(sum(output_tokens),0) as output_tokens,
                 count(distinct tenant_id) as tenants"
            )->first(),
            'daily' => $db->table('messages')->where('role', 'assistant')->where('created_at', '>=', $since)
                ->selectRaw("created_at::date as day, count(*) as answers, count(*) filter (where status in ('error','partial')) as failed, coalesce(sum(credits_charged),0) as credits")
                ->groupByRaw('1')->orderByRaw('1 desc')->limit(31)->get(),
            'models' => $db->table('messages as m')->leftJoin('ai_models as a', 'a.id', '=', 'm.model_id')->where('m.role', 'assistant')->where('m.created_at', '>=', $since)
                ->selectRaw("coalesce(a.display_name, '—') as name, count(*) as answers, count(*) filter (where m.status in ('error','partial')) as failed,
                             coalesce(sum(m.credits_charged),0) as credits, coalesce(sum(m.output_tokens),0) as output_tokens")
                ->groupByRaw('1')->orderByRaw('count(*) desc')->limit(20)->get(),
            'failures' => $db->table('messages as m')->leftJoin('ai_providers as p', 'p.id', '=', 'm.provider_id')->where('m.role', 'assistant')
                ->whereIn('m.status', ['error', 'partial'])->where('m.created_at', '>=', $since)
                ->selectRaw("coalesce(p.name, '—') as provider, coalesce(m.error_code, 'unknown') as code, count(*) as n, max(m.created_at) as last_at")
                ->groupByRaw('1, 2')->orderByRaw('count(*) desc')->limit(30)->get(),
        ]);
    }
}
