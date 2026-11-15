<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Tenancy\CreateWorkspace;
use App\Admin\AdminAudit;
use App\Ai\ModelRouter;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class RoutingController extends AdminController
{
    public function index()
    {
        $this->allow('providers.manage');

        return view('admin.routing.index', [
            'rules' => $this->db()->table('routing_rules')->orderBy('task_type')->orderBy('priority')->get()
                ->map(function ($r) {
                    $r->models = json_decode($r->preferred_model_ids, true) ?: [];

                    return $r;
                }),
            'models' => $this->db()->table('ai_models as m')->join('ai_providers as p', 'p.id', '=', 'm.provider_id')
                ->orderBy('p.name')->orderBy('m.display_name')->get(['m.id', 'm.display_name', 'p.name as provider']),
            'modes' => ModelRouter::MODES,
        ]);
    }

    public function store(Request $request)
    {
        $this->allow('providers.manage');
        $data = $this->validated($request);
        $id = CreateWorkspace::newId();
        $this->db()->transaction(function () use ($id, $data): void {
            $this->db()->table('routing_rules')->insert($this->row($data) + ['id' => $id]);
            AdminAudit::record('admin.routing.created', 'routing_rule', $id, null, $data);
        });

        return back()->with('status', 'The routing rule was added.');
    }

    public function update(Request $request, string $id)
    {
        $this->allow('providers.manage');
        $before = $this->db()->table('routing_rules')->where('id', $id)->first() ?? abort(404);
        $data = $this->validated($request);
        $this->db()->transaction(function () use ($id, $data, $before): void {
            $this->db()->table('routing_rules')->where('id', $id)->update($this->row($data) + ['updated_at' => now()]);
            AdminAudit::record('admin.routing.updated', 'routing_rule', $id, (array) $before, $data);
        });

        return back()->with('status', 'The routing rule was updated.');
    }

    public function destroy(string $id)
    {
        $this->allow('providers.manage');
        $before = $this->db()->table('routing_rules')->where('id', $id)->first() ?? abort(404);
        $this->db()->transaction(function () use ($id, $before): void {
            $this->db()->table('routing_rules')->where('id', $id)->delete();
            AdminAudit::record('admin.routing.deleted', 'routing_rule', $id, (array) $before);
        });

        return back()->with('status', 'The routing rule was deleted.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'task_type' => ['required', 'regex:/^[a-z][a-z0-9_]{1,49}$/'],
            'mode' => ['required', Rule::in(array_diff(ModelRouter::MODES, ['manual']))],
            'preferred_model_ids' => ['array', 'max:20'],
            'preferred_model_ids.*' => ['string', 'size:26', Rule::exists('admin.ai_models', 'id')],
            'priority' => ['required', 'integer', 'between:0,10000'],
            'status' => ['required', Rule::in(['active', 'disabled'])],
        ]);
        $data['preferred_model_ids'] = array_values(array_unique($data['preferred_model_ids'] ?? []));

        return $data;
    }

    /** @param array<string, mixed> $data */
    private function row(array $data): array
    {
        return ['task_type' => $data['task_type'], 'mode' => $data['mode'], 'priority' => $data['priority'], 'status' => $data['status'],
            'preferred_model_ids' => json_encode($data['preferred_model_ids'])];
    }
}
