<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Tenancy\CreateWorkspace;
use App\Admin\AdminAudit;
use App\Credits\AdminCredits;
use App\Credits\CreditException;
use App\Credits\CreditMath;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Admin: credit packages, reconciliation report, provider cost records, manual wallet adjustments. */
final class CreditController extends AdminController
{
    private const MONEY = ['required', 'string', 'regex:/\A\d{1,16}(\.\d{1,4})?\z/'];

    public function index()
    {
        $this->allow('credits.manage');
        $db = $this->db();

        return view('admin.credits.index', [
            'problems' => (new AdminCredits($db))->reconcile(),
            'totals' => $db->table('wallets')->selectRaw('count(*) as wallets, coalesce(sum(balance),0) as balance, coalesce(sum(reserved),0) as reserved')->first(),
            'openAlerts' => $db->table('credit_alerts')->whereNull('handled_at')->count(),
            'packages' => $db->table('credit_packages')->orderBy('sort')->orderBy('name')->get(),
            'providers' => $db->table('ai_providers')->orderBy('name')->get(['id', 'name']),
            'costs' => $db->table('provider_cost_records as c')->join('ai_providers as p', 'p.id', '=', 'c.provider_id')
                ->orderByDesc('c.id')->limit(20)->get(['c.period', 'p.name', 'c.amount', 'c.currency', 'c.source', 'c.notes', 'c.created_at']),
        ]);
    }

    public function storePackage(Request $request)
    {
        $this->allow('credits.manage');
        $data = $this->validatedPackage($request, null);
        $id = CreateWorkspace::newId();
        $this->db()->transaction(function () use ($data, $id): void {
            $this->db()->table('credit_packages')->insert($data + ['id' => $id, 'status' => 'hidden']);   // hidden until reviewed
            AdminAudit::record('admin.credit_package.created', 'credit_package', $id, null, $data);
        });

        return back()->with('status', 'The package was created (hidden).');
    }

    public function updatePackage(Request $request, string $id)
    {
        $this->allow('credits.manage');
        $before = $this->db()->table('credit_packages')->where('id', $id)->first();
        abort_if($before === null, 404);
        $data = $this->validatedPackage($request, $id) + $request->validate(['status' => ['required', Rule::in(['active', 'hidden', 'retired'])]]);
        $this->db()->transaction(function () use ($data, $id, $before): void {
            $this->db()->table('credit_packages')->where('id', $id)->update($data + ['updated_at' => now()]);
            AdminAudit::record('admin.credit_package.updated', 'credit_package', $id, $this->snapshot($before, array_keys($data)), $data);
        });

        return back()->with('status', 'The package was updated.');
    }

    public function storeCost(Request $request)
    {
        $this->allow('credits.manage');
        $data = $request->validate([
            'provider_id' => ['required', 'string', Rule::exists('admin.ai_providers', 'id')],
            'period' => ['required', 'regex:/\A\d{4}-(0[1-9]|1[0-2])\z/'],
            'amount' => self::MONEY,
            'currency' => ['required', 'regex:/\A[A-Z]{3}\z/'],
            'source' => ['required', Rule::in(['computed', 'invoice'])],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $this->db()->transaction(function () use ($data, $request): void {
            $this->db()->table('provider_cost_records')->insert($data + ['created_by' => $request->user()->getAuthIdentifier()]);
            AdminAudit::record('admin.provider_cost.recorded', 'provider', $data['provider_id'], null, $data);
        });

        return back()->with('status', 'A cost record was added (cannot be changed).');
    }

    public function adjust(Request $request, string $id)
    {
        $this->allow('credits.manage');
        abort_unless($this->db()->table('tenants')->where('id', $id)->exists(), 404);
        $data = $request->validate([
            'type' => ['required', Rule::in(['adjustment', 'bonus'])],
            'amount' => ['required', 'string', 'regex:/\A-?\d{1,16}(\.\d{1,4})?\z/'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'key' => ['required', 'string', 'regex:/\A[A-Za-z0-9:_.\-]{8,128}\z/'],   // minted when the form was rendered: double-submit safe
        ]);

        try {
            $result = $this->db()->transaction(function () use ($data, $id, $request): array {
                $r = (new AdminCredits($this->db()))->adjust($id, $data['type'], $data['amount'], $data['reason'], 'admin:'.$data['key'], (string) $request->user()->getAuthIdentifier());
                if (! $r['replayed']) {
                    AdminAudit::record('admin.credit.adjusted', 'tenant', $id, null, ['type' => $data['type'], 'amount' => $data['amount'], 'reason' => $data['reason']], $id);
                }

                return $r;
            });
        } catch (CreditException $e) {
            return back()->withErrors(['amount' => $e->userMessage()])->withInput();
        }

        return back()->with('status', $result['replayed'] ? 'This adjustment was already applied.' : 'The credit adjustment was completed.');
    }

    /** @return array<string, mixed> */
    private function validatedPackage(Request $request, ?string $ignoreId): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('admin.credit_packages', 'name')->ignore($ignoreId)],
            'credits' => self::MONEY,
            'bonus_credits' => ['nullable', 'string', 'regex:/\A\d{1,16}(\.\d{1,4})?\z/'],
            'price' => self::MONEY,
            'currency' => ['required', 'regex:/\A[A-Z]{3}\z/'],
            'expires_after_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'sort' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);
        if (! CreditMath::isPositive(CreditMath::normalize($data['credits']))) {
            abort(422, 'credits must be positive');
        }

        return [
            'name' => $data['name'], 'credits' => $data['credits'], 'bonus_credits' => $data['bonus_credits'] ?? '0',
            'price' => $data['price'], 'currency' => $data['currency'],
            'expires_after_days' => $data['expires_after_days'] ?? null, 'sort' => $data['sort'] ?? 0,
        ];
    }
}
