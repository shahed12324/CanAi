<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Credits\Bars;
use App\Credits\CreditException;
use App\Credits\CreditMath;
use App\Credits\CreditService;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Customer side of credits: balance, usage charts, ledger history, alert and spending-limit settings. */
final class CreditController extends Controller
{
    public const PERIODS = [7, 30, 90];

    public const TYPE_LABELS = [
        'grant_free' => 'Free credits', 'grant_plan' => 'Plan credits', 'purchase' => 'Purchase', 'bonus' => 'Bonus',
        'reservation' => 'Save', 'reservation_release' => 'Hold refund', 'debit' => 'Uses', 'refund' => 'Refund',
        'adjustment' => 'Adjustment', 'expiry' => 'Expired',
    ];

    public function index(Request $request, TenantContext $tenant, CreditService $credits)
    {
        $this->authorizeView($request);
        $id = $tenant->idOrFail();
        $days = (int) $request->query('days', 30);
        $days = in_array($days, self::PERIODS, true) ? $days : 30;

        return view('credits.index', [
            'wallet' => $credits->wallet($id),
            'spent' => $credits->spentThisMonth($id),
            'days' => $days,
            'periods' => self::PERIODS,
            'byModel' => Bars::scale($this->group($id, $days, 'coalesce(m.display_name, \'—\')')),
            'byAgent' => Bars::scale($this->group($id, $days, 'coalesce(u.agent_id::text, \'—\')')),
            'byDepartment' => Bars::scale($this->group($id, $days, 'coalesce(u.department, \'—\')')),
            'daily' => Bars::scale($this->daily($id, $days)),
            'totalPeriod' => (string) DB::scalar('select coalesce(sum(credits_charged), 0) from usage_records where tenant_id = ? and created_at >= now() - make_interval(days => ?)', [$id, $days]),
            'canManage' => $request->user()->can('workspace.manage'),
        ]);
    }

    public function transactions(Request $request, TenantContext $tenant)
    {
        $this->authorizeView($request);
        $type = $request->query('type');
        $rows = DB::table('credit_transactions')->where('tenant_id', $tenant->idOrFail())
            ->when(is_string($type) && isset(self::TYPE_LABELS[$type]), fn ($q) => $q->where('type', $type))
            ->orderByDesc('id')
            ->paginate(25, ['id', 'type', 'amount', 'balance_after', 'reference_type', 'note', 'created_at'])
            ->withQueryString();

        return view('credits.transactions', ['rows' => $rows, 'types' => self::TYPE_LABELS, 'type' => is_string($type) ? $type : '']);
    }

    public function settings(Request $request, TenantContext $tenant, CreditService $credits)
    {
        abort_unless($request->user()->can('workspace.manage'), 403);
        $money = ['nullable', 'string', 'regex:/\A\d{1,16}(\.\d{1,4})?\z/'];
        $data = $request->validate(['low_balance_threshold' => $money, 'spending_limit_monthly' => $money]);

        try {
            $credits->configure(
                $tenant->idOrFail(),
                filled($data['low_balance_threshold'] ?? null) ? $data['low_balance_threshold'] : null,
                filled($data['spending_limit_monthly'] ?? null) ? $data['spending_limit_monthly'] : null,
            );
        } catch (CreditException $e) {
            return back()->withErrors(['low_balance_threshold' => $e->userMessage()]);
        }

        return back()->with('status', 'Settings were saved.');
    }

    private function authorizeView(Request $request): void
    {
        abort_unless($request->user()->can('billing.view'), 403);
    }

    /** @return array<int, array{label: string, total: string}> */
    private function group(string $tenantId, int $days, string $expr): array
    {
        $rows = DB::select(
            "select {$expr} as label, sum(u.credits_charged) as total
               from usage_records u left join ai_models m on m.id = u.model_id
              where u.tenant_id = ? and u.created_at >= now() - make_interval(days => ?)
              group by 1 order by sum(u.credits_charged) desc, 1 limit 10",
            [$tenantId, $days],
        );

        return array_map(fn ($r) => ['label' => (string) $r->label, 'total' => (string) $r->total], $rows);
    }

    /** @return array<int, array{label: string, total: string}> */
    private function daily(string $tenantId, int $days): array
    {
        $tz = (string) DB::table('tenants')->where('id', $tenantId)->value('timezone');
        $tz = in_array($tz, timezone_identifiers_list(), true) ? $tz : 'UTC';
        $rows = DB::select(
            "select (u.created_at at time zone ?)::date as day, sum(u.credits_charged) as total
               from usage_records u where u.tenant_id = ? and u.created_at >= now() - make_interval(days => ?)
              group by 1 order by 1",
            [$tz, $tenantId, $days],
        );

        return array_map(fn ($r) => ['label' => (string) $r->day, 'total' => (string) $r->total], $rows);
    }
}
