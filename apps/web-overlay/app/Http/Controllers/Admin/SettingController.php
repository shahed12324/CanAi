<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Admin\AdminAudit;
use App\Support\SystemSettings;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class SettingController extends AdminController
{
    public function index()
    {
        $this->allow('settings.manage');

        return view('admin.settings.index', ['groups' => config('admin_settings'), 'values' => SystemSettings::all()]);
    }

    /** Saves one group of the registry; keys outside the registry cannot be written. */
    public function update(Request $request, string $group)
    {
        $this->allow('settings.manage');
        $items = config("admin_settings.$group.items") ?? abort(404);

        $rules = [];
        foreach ($items as $key => $def) {
            $rules[$this->field($key)] = match ($def['type']) {
                'bool' => ['sometimes', 'boolean'],
                'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
                'email' => ['nullable', 'email', 'max:'.($def['max'] ?? 255)],
                'select' => ['required', Rule::in($def['options'])],
                default => ['nullable', 'string', 'max:'.($def['max'] ?? 255)],
            };
        }
        $input = $request->validate($rules);

        $current = SystemSettings::all();
        $changes = [];
        foreach ($items as $key => $def) {
            $new = $def['type'] === 'bool' ? $request->boolean($this->field($key)) : ($input[$this->field($key)] ?? null);
            $new = is_string($new) && trim($new) === '' ? null : $new;
            if (($current[$key] ?? null) !== $new) {
                $changes[$key] = [$current[$key] ?? null, $new];
            }
        }

        if ($changes !== []) {
            $this->db()->transaction(function () use ($changes, $request, $group): void {
                foreach ($changes as $key => [, $new]) {
                    SystemSettings::set($key, $new, $request->user()->getKey());
                }
                AdminAudit::record('admin.settings.updated', 'settings', $group,
                    collect($changes)->map(fn ($c) => $c[0])->all(), collect($changes)->map(fn ($c) => $c[1])->all());
            });
        }

        return back()->with('status', $changes === [] ? 'No changes.' : 'Settings were saved.');
    }

    /** Dots are not allowed in form field names, so keys are sent with underscores. */
    public static function field(string $key): string
    {
        return str_replace('.', '__', $key);
    }
}
