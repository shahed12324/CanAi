<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Admin\AdminAudit;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class FeatureController extends AdminController
{
    public function index()
    {
        $this->allow('features.manage');

        return view('admin.features.index', [
            'features' => $this->db()->table('features as f')
                ->leftJoin('plan_features as pf', 'pf.feature_key', '=', 'f.key')
                ->groupBy('f.key')->orderBy('f.category')->orderBy('f.key')
                ->get(['f.key', 'f.category', 'f.type', 'f.description', $this->db()->raw('count(pf.plan_id) as plans')]),
        ]);
    }

    public function store(Request $request)
    {
        $this->allow('features.manage');
        $data = $request->validate([
            'key' => ['required', 'regex:/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/', 'max:100', Rule::unique('admin.features', 'key')],
            'category' => ['required', 'string', 'max:50'],
            'type' => ['required', Rule::in(['boolean', 'limit', 'meter'])],
            'description' => ['nullable', 'string', 'max:500'],
        ]);
        $this->db()->transaction(function () use ($data): void {
            $this->db()->table('features')->insert($data);
            AdminAudit::record('admin.feature.created', 'feature', $data['key'], null, $data);
        });

        return back()->with('status', 'The feature was added.');
    }

    public function update(Request $request, string $key)
    {
        $this->allow('features.manage');
        $before = $this->db()->table('features')->where('key', $key)->first() ?? abort(404);
        $data = $request->validate([
            'category' => ['required', 'string', 'max:50'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);
        $this->db()->transaction(function () use ($key, $before, $data): void {
            $this->db()->table('features')->where('key', $key)->update($data + ['updated_at' => now()]);
            AdminAudit::record('admin.feature.updated', 'feature', $key, $this->snapshot($before, ['category', 'description']), $data);
        });

        return back()->with('status', 'The feature was updated.');
    }

    public function destroy(string $key)
    {
        $this->allow('features.manage');
        $before = $this->db()->table('features')->where('key', $key)->first() ?? abort(404);
        try {
            $this->db()->transaction(function () use ($key, $before): void {
                $this->db()->table('features')->where('key', $key)->delete();
                AdminAudit::record('admin.feature.deleted', 'feature', $key, (array) $before);
            });
        } catch (QueryException $e) {
            if (in_array($e->getCode(), ['23503', '23001'], true)) {   // foreign_key_violation / restrict_violation (ON DELETE RESTRICT): still used by a plan or override
                return back()->withErrors(['feature' => 'This feature is used in a plan or override; remove it from there first.']);
            }
            throw $e;
        }

        return back()->with('status', 'The feature was deleted.');
    }
}
