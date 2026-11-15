<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Tenancy\CreateWorkspace;
use App\Admin\AdminAudit;
use Illuminate\Http\Request;

/** Free-trial management: who is on a trial, grant one, extend it, or end it. A trial is a subscription with status "trialing". */
final class TrialController extends AdminController
{
    private const LIVE = ['active', 'trialing', 'past_due', 'grace'];

    public function index()
    {
        $this->allow('plans.manage');

        return view('admin.trials.index', [
            'trials' => $this->db()->table('subscriptions as s')->join('tenants as t', 't.id', '=', 's.tenant_id')->join('plans as p', 'p.id', '=', 's.plan_id')
                ->where('s.status', 'trialing')->orderBy('s.current_period_end')
                ->get(['s.id', 's.tenant_id', 't.name as shop', 'p.name as plan', 's.current_period_start', 's.current_period_end']),
            'plans' => $this->db()->table('plans')->where('status', '!=', 'retired')->orderBy('sort')->get(['id', 'name', 'trial_days']),
            'recentEnded' => $this->db()->table('subscriptions as s')->join('tenants as t', 't.id', '=', 's.tenant_id')->join('plans as p', 'p.id', '=', 's.plan_id')
                ->where('s.gateway', 'manual')->whereIn('s.status', ['expired', 'cancelled'])->whereNotNull('s.current_period_end')->orderByDesc('s.updated_at')->limit(10)
                ->get(['t.name as shop', 'p.name as plan', 's.current_period_end', 's.status']),
        ]);
    }

    public function grant(Request $request)
    {
        $this->allow('plans.manage');
        $d = $request->validate(['tenant' => ['required', 'string', 'max:200'], 'plan_id' => ['required', 'string', 'size:26'], 'days' => ['required', 'integer', 'min:1', 'max:365']]);
        $t = $this->db()->table('tenants')->whereNull('deleted_at')->where(fn ($w) => $w->where('id', $d['tenant'])->orWhere('slug', $d['tenant']))->first() ?? abort(422, 'Shop not found (use its slug or id).');
        $plan = $this->db()->table('plans')->where('id', $d['plan_id'])->where('status', '!=', 'retired')->first() ?? abort(422, 'Plan not found.');
        $live = $this->db()->table('subscriptions')->where('tenant_id', $t->id)->whereIn('status', self::LIVE)->first();
        abort_if($live !== null && $live->status !== 'trialing', 422, 'This shop already has a paid or running subscription. Change its plan from the shop page instead.');

        $this->db()->transaction(function () use ($t, $plan, $d, $live): void {
            if ($live !== null) {
                $this->db()->table('subscriptions')->where('id', $live->id)->update(['status' => 'cancelled', 'cancel_at' => now(), 'updated_at' => now()]);
            }
            $this->db()->table('subscriptions')->insert([
                'id' => CreateWorkspace::newId(), 'tenant_id' => $t->id, 'plan_id' => $plan->id, 'status' => 'trialing',
                'current_period_start' => now(), 'current_period_end' => now()->addDays((int) $d['days']), 'gateway' => 'manual', 'created_at' => now(), 'updated_at' => now(),
            ]);
            AdminAudit::record('admin.trial.granted', 'tenant', $t->id, null, ['plan' => $plan->code, 'days' => (int) $d['days']], $t->id);
        });

        return back()->with('status', 'Trial started for '.$t->name.'.');
    }

    public function extend(Request $request, string $id)
    {
        $this->allow('plans.manage');
        $d = $request->validate(['days' => ['required', 'integer', 'min:1', 'max:365']]);
        $s = $this->db()->table('subscriptions')->where('id', $id)->where('status', 'trialing')->first() ?? abort(404);
        $base = $s->current_period_end !== null && $s->current_period_end > now() ? \Illuminate\Support\Carbon::parse($s->current_period_end) : now();
        $this->db()->table('subscriptions')->where('id', $id)->update(['current_period_end' => $base->copy()->addDays((int) $d['days']), 'updated_at' => now()]);
        AdminAudit::record('admin.trial.extended', 'tenant', $s->tenant_id, ['period_end' => $s->current_period_end], ['days' => (int) $d['days']], $s->tenant_id);

        return back()->with('status', 'Trial extended.');
    }

    public function end(string $id)
    {
        $this->allow('plans.manage');
        $s = $this->db()->table('subscriptions')->where('id', $id)->where('status', 'trialing')->first() ?? abort(404);
        $this->db()->table('subscriptions')->where('id', $id)->update(['status' => 'cancelled', 'cancel_at' => now(), 'current_period_end' => now(), 'updated_at' => now()]);
        AdminAudit::record('admin.trial.ended', 'tenant', $s->tenant_id, ['period_end' => $s->current_period_end], null, $s->tenant_id);

        return back()->with('status', 'Trial ended. The shop is back on the free plan.');
    }
}
