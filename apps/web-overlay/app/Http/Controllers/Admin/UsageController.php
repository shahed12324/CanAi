<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use Illuminate\Http\Request;

/** Usage monitoring: which shops spend the most AI credits, and who is close to running out. */
final class UsageController extends AdminController
{
    public function index(Request $request)
    {
        $this->allow('tenants.view');
        $days = in_array($d = (int) $request->query('days', 30), [7, 30, 90], true) ? $d : 30;
        $db = $this->db();

        $top = $db->table('credit_transactions as c')->join('tenants as t', 't.id', '=', 'c.tenant_id')
            ->where('c.type', 'debit')->where('c.created_at', '>=', now()->subDays($days))
            ->groupBy('t.id', 't.name')->orderByRaw('sum(-c.amount) desc')->limit(25)
            ->get(['t.id', 't.name', $db->raw('sum(-c.amount) as spent'), $db->raw('count(*) as calls')]);

        $low = $db->table('wallets as w')->join('tenants as t', 't.id', '=', 'w.tenant_id')
            ->whereNull('t.deleted_at')->where('t.status', 'active')->whereRaw('w.balance - w.reserved <= coalesce(w.low_balance_threshold, 0)')
            ->orderBy('w.balance')->limit(25)->get(['t.id', 't.name', 'w.balance', 'w.low_balance_threshold']);

        return view('admin.usage.index', [
            'days' => $days, 'top' => $top, 'low' => $low,
            'total' => (float) $db->table('credit_transactions')->where('type', 'debit')->where('created_at', '>=', now()->subDays($days))->sum($db->raw('-amount')),
            'daily' => $db->table('credit_transactions')->where('type', 'debit')->where('created_at', '>=', now()->subDays($days))
                ->groupBy($db->raw('date(created_at)'))->orderBy($db->raw('date(created_at)'))->get([$db->raw('date(created_at) as day'), $db->raw('sum(-amount) as spent')]),
        ]);
    }
}
