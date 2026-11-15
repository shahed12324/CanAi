<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Tenancy\CreateWorkspace;
use App\Admin\AdminAudit;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class PlanController extends AdminController
{
    private const FIELDS = ['name', 'description', 'is_public', 'trial_days', 'included_credits', 'monthly_credit_refill', 'sort', 'status'];

    public function index()
    {
        $this->allow('plans.manage');

        return view('admin.plans.index', [
            'plans' => $this->db()->table('plans as p')
                ->leftJoin('subscriptions as s', fn ($j) => $j->on('s.plan_id', '=', 'p.id')->whereIn('s.status', ['active', 'trialing', 'past_due', 'grace']))
                ->groupBy('p.id')->orderBy('p.sort')
                ->get(['p.id', 'p.code', 'p.name', 'p.status', 'p.is_public', 'p.is_lifetime_free', $this->db()->raw('count(s.id) as subscribers')]),
        ]);
    }

    public function store(Request $request)
    {
        $this->allow('plans.manage');
        $data = $request->validate([
            'code' => ['required', 'regex:/^[a-z][a-z0-9_]{1,31}$/', Rule::unique('admin.plans', 'code')],
            'name' => ['required', 'string', 'max:100'],
        ]);
        $id = CreateWorkspace::newId();

        $this->db()->transaction(function () use ($id, $data): void {
            $this->db()->table('plans')->insert(['id' => $id, 'code' => $data['code'], 'name' => $data['name'], 'status' => 'hidden', 'is_public' => false]);
            AdminAudit::record('admin.plan.created', 'plan', $id, null, $data);
        });

        return redirect()->route('admin.plans.show', $id)->with('status', 'The plan was created (hidden).');
    }

    public function show(string $id)
    {
        $this->allow('plans.manage');
        $plan = $this->find($id);

        return view('admin.plans.show', [
            'plan' => $plan,
            'prices' => $this->db()->table('plan_prices')->where('plan_id', $id)->orderBy('currency')->orderBy('interval')->get(),
            'features' => $this->db()->table('features as f')
                ->leftJoin('plan_features as pf', fn ($j) => $j->on('pf.feature_key', '=', 'f.key')->where('pf.plan_id', $id))
                ->orderBy('f.category')->orderBy('f.key')
                ->get(['f.key', 'f.type', 'f.description', 'pf.enabled', 'pf.limit_value', $this->db()->raw('(pf.plan_id is not null) as included')]),
        ]);
    }

    public function update(Request $request, string $id)
    {
        $this->allow('plans.manage');
        $plan = $this->find($id);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_public' => ['sometimes', 'boolean'],
            'trial_days' => ['required', 'integer', 'min:0', 'max:365'],
            'included_credits' => ['required', 'numeric', 'min:0'],
            'monthly_credit_refill' => ['sometimes', 'boolean'],
            'sort' => ['required', 'integer', 'min:0', 'max:1000'],
            'status' => ['required', Rule::in(['active', 'hidden', 'retired'])],
        ]);
        $data['is_public'] = $request->boolean('is_public');
        $data['monthly_credit_refill'] = $request->boolean('monthly_credit_refill');
        // The free plan is the fallback for every tenant without a subscription: it must stay available.
        abort_if($plan->code === 'free' && ($data['status'] !== 'active' || ! $data['is_public']), 422, 'Free A plan must always be active and public.');
        abort_if($data['status'] === 'retired' && $this->db()->table('subscriptions')->where('plan_id', $id)->whereIn('status', ['active', 'trialing', 'past_due', 'grace'])->exists(), 422, 'A plan that has active subscriptions cannot be retired.');

        $this->db()->transaction(function () use ($id, $plan, $data): void {
            $this->db()->table('plans')->where('id', $id)->update($data + ['updated_at' => now()]);
            AdminAudit::record('admin.plan.updated', 'plan', $id, $this->snapshot($plan, self::FIELDS), $data);
        });

        return back()->with('status', 'The plan was updated.');
    }

    /** Replaces the whole price list of the plan. Empty amount = no price for that row. */
    public function savePrices(Request $request, string $id)
    {
        $this->allow('plans.manage');
        $this->find($id);
        $data = $request->validate([
            'prices' => ['array', 'max:20'],
            'prices.*.currency' => ['required', 'regex:/^[A-Z]{3}$/'],
            'prices.*.interval' => ['required', Rule::in(['month', 'year'])],
            'prices.*.amount' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
        ]);
        $rows = collect($data['prices'] ?? [])->filter(fn ($p) => ($p['amount'] ?? '') !== '' && $p['amount'] !== null)
            ->unique(fn ($p) => $p['currency'].'|'.$p['interval'])->values();

        $this->db()->transaction(function () use ($id, $rows): void {
            $before = $this->db()->table('plan_prices')->where('plan_id', $id)->get(['currency', 'interval', 'amount'])->map(fn ($r) => (array) $r)->all();
            $this->db()->table('plan_prices')->where('plan_id', $id)->delete();
            foreach ($rows as $r) {
                $this->db()->table('plan_prices')->insert(['plan_id' => $id, 'currency' => $r['currency'], 'interval' => $r['interval'], 'amount' => $r['amount']]);
            }
            AdminAudit::record('admin.plan.prices_changed', 'plan', $id, ['prices' => $before], ['prices' => $rows->map(fn ($r) => ['currency' => $r['currency'], 'interval' => $r['interval'], 'amount' => $r['amount']])->all()]);
        });

        return back()->with('status', 'Prices were saved.');
    }

    /** Replaces the plan's feature set from the matrix form. */
    public function saveFeatures(Request $request, string $id)
    {
        $this->allow('plans.manage');
        $this->find($id);
        $known = $this->db()->table('features')->pluck('key')->all();
        $data = $request->validate([
            'features' => ['array'],
            'features.*.included' => ['sometimes', 'boolean'],
            'features.*.limit' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
        ]);

        $rows = [];
        foreach (($data['features'] ?? []) as $key => $f) {
            abort_unless(in_array($key, $known, true), 422, 'Unknown feature.');
            if (! empty($f['included'])) {
                $rows[$key] = ['limit' => ($f['limit'] ?? '') === '' ? null : $f['limit']];
            }
        }

        $this->db()->transaction(function () use ($id, $rows): void {
            $before = $this->db()->table('plan_features')->where('plan_id', $id)->get(['feature_key', 'limit_value'])->map(fn ($r) => (array) $r)->all();
            $this->db()->table('plan_features')->where('plan_id', $id)->delete();
            foreach ($rows as $key => $r) {
                $this->db()->table('plan_features')->insert(['plan_id' => $id, 'feature_key' => $key, 'enabled' => true, 'limit_value' => $r['limit']]);
            }
            AdminAudit::record('admin.plan.features_changed', 'plan', $id, ['features' => $before], ['features' => collect($rows)->map(fn ($r, $k) => ['feature_key' => $k, 'limit_value' => $r['limit']])->values()->all()]);
        });

        return back()->with('status', 'Plan features were saved.');
    }

    private function find(string $id): object
    {
        return $this->db()->table('plans')->where('id', $id)->first() ?? abort(404);
    }
}
