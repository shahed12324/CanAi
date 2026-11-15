<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Admin\AdminAudit;
use App\Actions\Tenancy\CreateWorkspace;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class TenantController extends AdminController
{
    private const LIVE = ['active', 'trialing', 'past_due', 'grace'];

    public function index(Request $request)
    {
        $this->allow('tenants.view');
        $q = $this->db()->table('tenants as t')
            ->join('users as u', 'u.id', '=', 't.owner_id')
            ->leftJoin('subscriptions as s', fn ($j) => $j->on('s.tenant_id', '=', 't.id')->whereIn('s.status', self::LIVE))
            ->leftJoin('plans as p', 'p.id', '=', 's.plan_id');
        $request->boolean('trashed') ? $q->whereNotNull('t.deleted_at') : $q->whereNull('t.deleted_at');
        if (($term = trim((string) $request->query('q'))) !== '') {
            $like = $this->like($term);
            $q->where(fn ($w) => $w->where('t.name', 'ilike', $like)->orWhere('t.slug', 'ilike', $like)->orWhere('u.email', 'ilike', $like));
        }
        if (in_array($status = $request->query('status'), ['active', 'suspended', 'closed'], true)) {
            $q->where('t.status', $status);
        }
        if (in_array($type = $request->query('type'), ['personal', 'organization'], true)) {
            $q->where('t.type', $type);
        }

        return view('admin.tenants.index', [
            'tenants' => $q->orderByDesc('t.created_at')
                ->paginate(25, ['t.id', 't.name', 't.slug', 't.type', 't.status', 't.created_at', 't.deleted_at', 'u.email as owner_email', 'p.name as plan_name'])
                ->withQueryString(),
        ]);
    }

    public function show(string $id)
    {
        $this->allow('tenants.view');
        $tenant = $this->find($id, withTrashed: true);

        return view('admin.tenants.show', [
            'tenant' => $tenant,
            'owner' => $this->db()->table('users')->where('id', $tenant->owner_id)->first(['id', 'name', 'email']),
            'members' => $this->db()->table('tenant_users as tu')->join('users as u', 'u.id', '=', 'tu.user_id')
                ->where('tu.tenant_id', $id)->orderBy('u.name')->get(['u.id', 'u.name', 'u.email', 'tu.status']),
            'subscription' => $this->db()->table('subscriptions as s')->join('plans as p', 'p.id', '=', 's.plan_id')
                ->where('s.tenant_id', $id)->whereIn('s.status', self::LIVE)->first(['s.status', 's.interval', 's.current_period_end', 'p.name as plan_name']),
            'plans' => $this->db()->table('plans')->where('status', '!=', 'retired')->orderBy('sort')->get(['id', 'name', 'code']),
            'features' => $this->db()->table('features')->orderBy('key')->get(['key', 'type']),
            'overrides' => $this->db()->table('tenant_feature_overrides')->where('tenant_id', $id)->orderBy('feature_key')->get(),
            'audit' => $this->db()->table('audit_logs')->where('tenant_id', $id)->orderByDesc('id')->limit(15)->get(['action', 'actor_id', 'created_at']),
            'wallet' => $this->db()->table('wallets')->where('tenant_id', $id)->first(['balance', 'reserved', 'low_balance_threshold', 'spending_limit_monthly']),
            'creditTx' => $this->db()->table('credit_transactions')->where('tenant_id', $id)->orderByDesc('id')->limit(10)->get(['type', 'amount', 'balance_after', 'note', 'created_at']),
            'adjustKey' => (string) \Illuminate\Support\Str::uuid(),
        ]);
    }

    public function suspend(string $id)
    {
        return $this->setStatus($id, 'suspended', 'admin.tenant.suspended');
    }

    public function activate(string $id)
    {
        return $this->setStatus($id, 'active', 'admin.tenant.activated');
    }

    public function destroy(string $id)
    {
        $this->allow('tenants.manage');
        $tenant = $this->find($id);
        $this->db()->transaction(function () use ($id, $tenant): void {
            $this->db()->table('tenants')->where('id', $id)->update(['deleted_at' => now(), 'updated_at' => now()]);
            AdminAudit::record('admin.tenant.deleted', 'tenant', $id, ['status' => $tenant->status], null, $id);
        });

        return redirect()->route('admin.tenants.index')->with('status', 'Workspace Deleted (can be restored).');
    }

    public function restore(string $id)
    {
        $this->allow('tenants.manage');
        abort_if($this->find($id, withTrashed: true)->deleted_at === null, 404);
        $this->db()->transaction(function () use ($id): void {
            $this->db()->table('tenants')->where('id', $id)->update(['deleted_at' => null, 'updated_at' => now()]);
            AdminAudit::record('admin.tenant.restored', 'tenant', $id, null, null, $id);
        });

        return redirect()->route('admin.tenants.show', $id)->with('status', 'Workspace Restored.');
    }

    /** Manually put a tenant on a plan (payments arrive in Phase 10). Ends the current live subscription. */
    public function assignPlan(Request $request, string $id)
    {
        $this->allow('tenants.manage');
        $this->find($id);
        $data = $request->validate([
            'plan_id' => ['required', 'string', 'size:26'],
            'status' => ['required', Rule::in(['active', 'trialing'])],
            'interval' => ['nullable', Rule::in(['month', 'year'])],
            'period_end' => ['nullable', 'date', 'after:now'],
        ]);
        $plan = $this->db()->table('plans')->where('id', $data['plan_id'])->where('status', '!=', 'retired')->first() ?? abort(422, 'Plan not found.');

        $this->db()->transaction(function () use ($id, $data, $plan): void {
            $old = $this->db()->table('subscriptions as s')->join('plans as p', 'p.id', '=', 's.plan_id')
                ->where('s.tenant_id', $id)->whereIn('s.status', self::LIVE)->first(['s.id', 'p.code']);
            if ($old !== null) {
                $this->db()->table('subscriptions')->where('id', $old->id)->update(['status' => 'cancelled', 'cancel_at' => now(), 'updated_at' => now()]);
            }
            $this->db()->table('subscriptions')->insert([
                'id' => CreateWorkspace::newId(), 'tenant_id' => $id, 'plan_id' => $plan->id,
                'status' => $data['status'], 'interval' => $data['interval'] ?? null,
                'current_period_start' => now(), 'current_period_end' => $data['period_end'] ?? null,
                'gateway' => 'manual', 'created_at' => now(), 'updated_at' => now(),
            ]);
            AdminAudit::record('admin.tenant.plan_assigned', 'tenant', $id, ['plan' => $old->code ?? 'free'], ['plan' => $plan->code, 'status' => $data['status'], 'period_end' => $data['period_end'] ?? null], $id);
        });

        return back()->with('status', 'The plan was changed.');
    }

    public function saveOverride(Request $request, string $id)
    {
        $this->allow('tenants.manage');
        $this->find($id);
        $data = $request->validate([
            'feature_key' => ['required', 'string', Rule::exists('admin.features', 'key')],
            'enabled' => ['required', 'boolean'],
            'limit_value' => ['nullable', 'numeric', 'min:0'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $this->db()->transaction(function () use ($id, $data, $request): void {
            $before = $this->db()->table('tenant_feature_overrides')->where('tenant_id', $id)->where('feature_key', $data['feature_key'])->first();
            $this->db()->table('tenant_feature_overrides')->upsert([[
                'tenant_id' => $id, 'feature_key' => $data['feature_key'], 'enabled' => $data['enabled'],
                'limit_value' => $data['limit_value'] ?? null, 'reason' => $data['reason'],
                'set_by' => $request->user()->getKey(), 'updated_at' => now(),
            ]], ['tenant_id', 'feature_key'], ['enabled', 'limit_value', 'reason', 'set_by', 'updated_at']);
            AdminAudit::record('admin.tenant.override_saved', 'tenant', $id,
                $this->snapshot($before, ['feature_key', 'enabled', 'limit_value', 'reason']),
                ['feature_key' => $data['feature_key'], 'enabled' => (bool) $data['enabled'], 'limit_value' => $data['limit_value'] ?? null, 'reason' => $data['reason']], $id);
        });

        return back()->with('status', 'Override Saved.');
    }

    public function deleteOverride(string $id, string $key)
    {
        $this->allow('tenants.manage');
        $this->db()->transaction(function () use ($id, $key): void {
            $before = $this->db()->table('tenant_feature_overrides')->where('tenant_id', $id)->where('feature_key', $key)->first() ?? abort(404);
            $this->db()->table('tenant_feature_overrides')->where('tenant_id', $id)->where('feature_key', $key)->delete();
            AdminAudit::record('admin.tenant.override_deleted', 'tenant', $id, $this->snapshot($before, ['feature_key', 'enabled', 'limit_value', 'reason']), null, $id);
        });

        return back()->with('status', 'Override Deleted.');
    }

    private function setStatus(string $id, string $status, string $action)
    {
        $this->allow('tenants.manage');
        $tenant = $this->find($id);
        $this->db()->transaction(function () use ($id, $tenant, $status, $action): void {
            $this->db()->table('tenants')->where('id', $id)->update(['status' => $status, 'updated_at' => now()]);
            AdminAudit::record($action, 'tenant', $id, ['status' => $tenant->status], ['status' => $status], $id);
        });

        return back()->with('status', 'Workspace-status was updated.');
    }

    private function find(string $id, bool $withTrashed = false): object
    {
        $q = $this->db()->table('tenants')->where('id', $id);
        if (! $withTrashed) {
            $q->whereNull('deleted_at');
        }

        return $q->first() ?? abort(404);
    }
}
