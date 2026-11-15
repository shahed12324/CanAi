<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Admin\AdminAudit;
use App\Support\FeatureFlags;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class FlagController extends AdminController
{
    public function index()
    {
        $this->allow('flags.manage');

        return view('admin.flags.index', [
            'flags' => $this->db()->table('feature_flags')->orderBy('key')->get()
                ->map(function ($f) {
                    $f->percentage = json_decode($f->rollout, true)['percentage'] ?? null;

                    return $f;
                }),
        ]);
    }

    public function store(Request $request)
    {
        $this->allow('flags.manage');
        $data = $request->validate([
            'key' => ['required', 'regex:/^[a-z][a-z0-9_.]*$/', 'max:100', Rule::unique('admin.feature_flags', 'key')],
            'description' => ['nullable', 'string', 'max:500'],
        ]);
        $this->db()->transaction(function () use ($data, $request): void {
            $this->db()->table('feature_flags')->insert($data + ['enabled' => false, 'updated_by' => $request->user()->getKey()]);
            AdminAudit::record('admin.flag.created', 'feature_flag', $data['key'], null, $data);
        });
        FeatureFlags::forget();

        return back()->with('status', 'The flag was added (disabled).');
    }

    public function update(Request $request, string $key)
    {
        $this->allow('flags.manage');
        $before = $this->db()->table('feature_flags')->where('key', $key)->first() ?? abort(404);
        $data = $request->validate([
            'percentage' => ['nullable', 'integer', 'min:0', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);
        $enabled = $request->boolean('enabled');
        $rollout = ($data['percentage'] ?? null) === null ? new \stdClass : ['percentage' => (int) $data['percentage']];

        $this->db()->transaction(function () use ($key, $before, $data, $enabled, $rollout, $request): void {
            $this->db()->table('feature_flags')->where('key', $key)->update([
                'enabled' => $enabled, 'rollout' => json_encode($rollout), 'description' => $data['description'] ?? null,
                'updated_by' => $request->user()->getKey(), 'updated_at' => now(),
            ]);
            AdminAudit::record('admin.flag.updated', 'feature_flag', $key,
                ['enabled' => (bool) $before->enabled, 'rollout' => json_decode($before->rollout, true)],
                ['enabled' => $enabled, 'rollout' => $rollout]);
        });
        FeatureFlags::forget();

        return back()->with('status', 'The flag was updated.');
    }

    public function destroy(string $key)
    {
        $this->allow('flags.manage');
        $before = $this->db()->table('feature_flags')->where('key', $key)->first() ?? abort(404);
        $this->db()->transaction(function () use ($key, $before): void {
            $this->db()->table('feature_flags')->where('key', $key)->delete();
            AdminAudit::record('admin.flag.deleted', 'feature_flag', $key, (array) $before);
        });
        FeatureFlags::forget();

        return back()->with('status', 'The flag was deleted.');
    }
}
