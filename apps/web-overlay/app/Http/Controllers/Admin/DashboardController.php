<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

final class DashboardController extends AdminController
{
    public function index()
    {
        $db = $this->db();
        $live = ['active', 'trialing', 'past_due', 'grace'];

        $shops = $db->table('tenants')->whereNull('deleted_at')->where('type', 'organization');
        $members = $db->table('tenant_users as tu')->join('tenants as t', 't.id', '=', 'tu.tenant_id')->where('tu.status', 'active')->whereNull('t.deleted_at');

        return view('admin.dashboard', [
            'shopsTotal' => (clone $shops)->count(),
            'shopsActive' => (clone $shops)->where('status', 'active')->count(),
            'shopsInactive' => (clone $shops)->whereIn('status', ['suspended', 'closed'])->count(),
            'staffTotal' => (clone $members)->where('t.type', 'organization')->count(),
            'trialsRunning' => $db->table('subscriptions')->where('status', 'trialing')->count(),
            'openTickets' => $db->table('support_tickets')->whereIn('status', ['open', 'pending'])->count(),
            'revenueMonth' => $db->table('orders')->whereIn('status', ['paid', 'partially_refunded'])->where('paid_at', '>=', now()->startOfMonth())
                ->selectRaw('currency, sum(total_minor - refunded_minor) as minor')->groupBy('currency')->get(),
            'users' => $db->table('users')->whereNull('deleted_at')->count(),
            'newUsers' => $db->table('users')->whereNull('deleted_at')->where('created_at', '>=', now()->subDays(7))->count(),
            'suspendedUsers' => $db->table('users')->whereNull('deleted_at')->where('status', 'suspended')->count(),
            'tenants' => $db->table('tenants')->whereNull('deleted_at')->where('type', 'organization')->count(),
            'suspendedTenants' => $db->table('tenants')->whereNull('deleted_at')->where('status', 'suspended')->count(),
            'failedLogins' => $db->table('login_logs')->whereIn('result', ['failed', '2fa_failed'])->where('created_at', '>=', now()->subDay())->count(),
            'byPlan' => $db->table('subscriptions as s')->join('plans as p', 'p.id', '=', 's.plan_id')
                ->whereIn('s.status', $live)->groupBy('p.code', 'p.name', 'p.sort')->orderBy('p.sort')
                ->get(['p.name', $db->raw('count(*) as total')]),
            'recent' => $db->table('audit_logs')->orderByDesc('id')->limit(10)->get(['id', 'action', 'actor_id', 'subject_type', 'subject_id', 'created_at']),
        ]);
    }
}
