<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Tenancy\CreateWorkspace;
use App\Admin\AdminAudit;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ModelController extends AdminController
{
    public const CAPABILITIES = ['text', 'vision', 'audio_in', 'audio_out', 'image_gen', 'video_gen', 'embedding', 'tool_use', 'json_mode'];

    public const CATEGORIES = ['chat', 'reasoning', 'embedding', 'image', 'video', 'speech', 'transcription'];

    private const FIELDS = ['display_name', 'category', 'is_local', 'is_free_tier', 'context_window', 'max_output', 'quality_rank', 'status'];

    /** All models of all providers in one list, with a form to add a model to any provider. */
    public function index()
    {
        $this->allow('providers.manage');

        return view('admin.models.index', [
            'models' => $this->db()->table('ai_models as m')->join('ai_providers as p', 'p.id', '=', 'm.provider_id')
                ->orderBy('p.name')->orderBy('m.display_name')
                ->get(['m.id', 'm.model_key', 'm.display_name', 'm.category', 'm.status', 'm.provider_id', 'p.name as provider']),
            'providers' => $this->db()->table('ai_providers')->orderBy('name')->get(['id', 'name', 'type']),
            'categories' => self::CATEGORIES,
        ]);
    }

    public function store(Request $request, string $providerId)
    {
        $this->allow('providers.manage');
        $this->db()->table('ai_providers')->where('id', $providerId)->exists() || abort(404);
        $data = $request->validate([
            'model_key' => ['required', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:\/-]{0,99}$/',
                Rule::unique('admin.ai_models', 'model_key')->where('provider_id', $providerId)],
            'display_name' => ['required', 'string', 'max:150'],
            'category' => ['required', Rule::in(self::CATEGORIES)],
        ]);
        $id = CreateWorkspace::newId();

        $this->db()->transaction(function () use ($id, $providerId, $data): void {
            // New models start hidden and unpriced: nobody can use them until an admin opens them up.
            $this->db()->table('ai_models')->insert($data + ['id' => $id, 'provider_id' => $providerId, 'status' => 'hidden']);
            $this->db()->table('model_capabilities')->insert(['model_id' => $id, 'capability' => 'text']);
            AdminAudit::record('admin.model.created', 'model', $id, null, $data + ['provider_id' => $providerId]);
        });

        return back()->with('status', 'The model was added (hidden, no price). Set the price and access, then enable.');
    }

    public function update(Request $request, string $id)
    {
        $this->allow('providers.manage');
        $before = $this->find($id);
        $data = $request->validate([
            'display_name' => ['required', 'string', 'max:150'],
            'category' => ['required', Rule::in(self::CATEGORIES)],
            'context_window' => ['nullable', 'integer', 'min:1', 'max:100000000'],
            'max_output' => ['nullable', 'integer', 'min:1', 'max:100000000'],
            'quality_rank' => ['required', 'integer', 'between:1,100'],
            'status' => ['required', Rule::in(['active', 'hidden', 'deprecated'])],
            'capabilities' => ['array'],
            'capabilities.*' => [Rule::in(self::CAPABILITIES)],
        ]);
        $caps = array_values(array_unique($data['capabilities'] ?? []));
        unset($data['capabilities']);
        $data['is_local'] = $request->boolean('is_local');
        $data['is_free_tier'] = $request->boolean('is_free_tier');
        $data['context_window'] = ($data['context_window'] ?? '') === '' ? null : $data['context_window'];
        $data['max_output'] = ($data['max_output'] ?? '') === '' ? null : $data['max_output'];
        $data['deprecated_at'] = $data['status'] === 'deprecated' ? ($before->deprecated_at ?? now()) : null;

        // A model that has no price must not be switched on: usage would otherwise be free of charge.
        if ($data['status'] === 'active' && ! $data['is_free_tier']) {
            abort_unless($this->db()->table('model_current_pricing')->where('model_id', $id)->exists(), 422, 'A model cannot be enabled without setting a price (free-tier is different).');
        }
        abort_if($data['status'] === 'active' && $caps === [], 422, 'Give at least one capability.');

        $this->db()->transaction(function () use ($id, $before, $data, $caps): void {
            $oldCaps = $this->db()->table('model_capabilities')->where('model_id', $id)->pluck('capability')->sort()->values()->all();
            $this->db()->table('ai_models')->where('id', $id)->update($data + ['updated_at' => now()]);
            $this->db()->table('model_capabilities')->where('model_id', $id)->delete();
            foreach ($caps as $c) {
                $this->db()->table('model_capabilities')->insert(['model_id' => $id, 'capability' => $c]);
            }
            sort($caps);
            AdminAudit::record('admin.model.updated', 'model', $id,
                $this->snapshot($before, self::FIELDS) + ['capabilities' => $oldCaps], array_intersect_key($data, array_flip(self::FIELDS)) + ['capabilities' => $caps]);
        });

        return back()->with('status', 'The model was updated.');
    }

    /** Price history is insert-only: this adds a new row that takes effect now (or at a given time). */
    public function addPricing(Request $request, string $id)
    {
        $this->allow('providers.manage');
        $this->find($id);
        $data = $request->validate([
            'input_per_1m' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'output_per_1m' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'per_request' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'per_image' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'per_second' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'currency' => ['required', 'regex:/^[A-Z]{3}$/'],
            'credit_multiplier' => ['required', 'numeric', 'gt:0', 'max:1000'],
            'effective_from' => ['nullable', 'date'],
        ]);
        $row = array_map(fn ($v) => $v === '' ? null : $v, $data);
        $row['effective_from'] = $row['effective_from'] ?? now();
        abort_if(collect(['input_per_1m', 'output_per_1m', 'per_request', 'per_image', 'per_second'])->every(fn ($k) => $row[$k] === null), 422, 'Give at least one price.');

        $before = $this->db()->table('model_current_pricing')->where('model_id', $id)->first();
        $this->db()->transaction(function () use ($id, $row, $before, $request): void {
            $this->db()->table('model_pricing')->insert($row + ['model_id' => $id, 'set_by' => $request->user()->getKey()]);
            AdminAudit::record('admin.model.pricing_added', 'model', $id, $before === null ? null : (array) $before, $row);
        });

        return back()->with('status', 'The new price was added (old history intact).');
    }

    /** Replaces this model's per-plan rules. Missing plan = "no rule" (falls back to the free-tier flag). */
    public function saveAccess(Request $request, string $id)
    {
        $this->allow('providers.manage');
        $this->find($id);
        $plans = $this->db()->table('plans')->pluck('id')->all();
        $data = $request->validate(['access' => ['array'], 'access.*' => [Rule::in(['allow', 'deny', 'default'])]]);

        $rows = [];
        foreach (($data['access'] ?? []) as $planId => $v) {
            abort_unless(in_array($planId, $plans, true), 422, 'Unknown plan.');
            if ($v !== 'default') {
                $rows[$planId] = $v === 'allow';
            }
        }
        $this->db()->transaction(function () use ($id, $rows): void {
            $before = $this->db()->table('model_access_rules')->where('model_id', $id)->pluck('allowed', 'plan_id')->map(fn ($b) => (bool) $b)->all();
            $this->db()->table('model_access_rules')->where('model_id', $id)->delete();
            foreach ($rows as $planId => $allowed) {
                $this->db()->table('model_access_rules')->insert(['model_id' => $id, 'plan_id' => $planId, 'allowed' => $allowed]);
            }
            AdminAudit::record('admin.model.access_changed', 'model', $id, ['rules' => $before], ['rules' => $rows]);
        });

        return back()->with('status', 'Access rules were saved.');
    }

    private function find(string $id): object
    {
        return $this->db()->table('ai_models')->where('id', $id)->first() ?? abort(404);
    }
}
