<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Ai\ModelCatalog;
use App\Ai\ModelRouter;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class ModelPreferenceController extends Controller
{
    public function index(TenantContext $tenant, ModelCatalog $catalog)
    {
        return view('models.index', [
            'models' => $catalog->candidates($tenant->idOrFail()),
            'prefs' => $catalog->preferences(),
            'modes' => ModelRouter::MODES,
            'canManage' => request()->user()->can('workspace.manage'),
        ]);
    }

    public function update(Request $request, TenantContext $tenant, ModelCatalog $catalog)
    {
        abort_unless($request->user()->can('workspace.manage'), 403);
        $allowed = array_column($catalog->candidates($tenant->idOrFail()), 'id');
        $data = $request->validate([
            'routing_mode' => ['required', Rule::in(ModelRouter::MODES)],
            'default_model_id' => ['nullable', 'string', Rule::in($allowed)],   // only a model this plan may use
        ]);
        $default = ($data['default_model_id'] ?? '') === '' ? null : $data['default_model_id'];
        if ($data['routing_mode'] === 'manual' && $default === null) {
            return back()->withErrors(['default_model_id' => 'Manual In mode choose a default model.']);
        }

        DB::table('tenant_model_preferences')->upsert(
            [['tenant_id' => $tenant->idOrFail(), 'default_model_id' => $default, 'routing_mode' => $data['routing_mode'], 'updated_at' => now()]],
            ['tenant_id'], ['default_model_id', 'routing_mode', 'updated_at'],
        );

        return back()->with('status', 'Preference saved.');
    }
}
