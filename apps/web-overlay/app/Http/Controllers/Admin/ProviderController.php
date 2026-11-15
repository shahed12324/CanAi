<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Tenancy\CreateWorkspace;
use App\Admin\AdminAudit;
use App\Ai\CredentialVault;
use App\Ai\ProviderCallException;
use App\Ai\ProviderGateway;
use App\Tenancy\TenantRoles;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ProviderController extends AdminController
{
    private const ADAPTERS = ['openai', 'anthropic', 'gemini', 'openrouter', 'groq', 'ollama', 'openai_compatible'];

    private const FIELDS = ['name', 'adapter', 'type', 'base_url', 'timeout_ms', 'priority', 'fallback_provider_id', 'allow_private', 'status'];

    public function index()
    {
        $this->allow('providers.manage');

        return view('admin.providers.index', [
            'providers' => $this->db()->table('ai_providers as p')
                ->leftJoin('ai_models as m', 'm.provider_id', '=', 'p.id')
                ->leftJoin('provider_credentials as c', fn ($j) => $j->on('c.provider_id', '=', 'p.id')->where('c.status', 'active'))
                ->groupBy('p.id', 'c.id')->orderBy('p.priority')->orderBy('p.name')
                ->get(['p.id', 'p.name', 'p.adapter', 'p.status', 'p.priority', 'c.id as credential_id',
                    $this->db()->raw('count(m.id) as models'),
                    $this->db()->raw("(select status from provider_health_checks h where h.provider_id = p.id order by h.id desc limit 1) as last_check"),
                    $this->db()->raw("(select round(100.0 * avg((status = 'ok')::int)) from provider_health_checks h where h.provider_id = p.id and h.checked_at > now() - interval '24 hours') as uptime_24h"),
                    $this->db()->raw("(select round(avg(latency_ms)) from provider_health_checks h where h.provider_id = p.id and h.status = 'ok' and h.checked_at > now() - interval '24 hours') as avg_latency")]),
            'adapters' => self::ADAPTERS,
        ]);
    }

    public function store(Request $request)
    {
        $this->allow('providers.manage');
        $data = $this->validated($request, null);
        $id = CreateWorkspace::newId();
        $data = $data + ['id' => $id, 'status' => 'disabled'];   // always starts disabled: add key, test, then enable

        $this->db()->transaction(function () use ($data, $id): void {
            $this->db()->table('ai_providers')->insert($data);
            AdminAudit::record('admin.provider.created', 'provider', $id, null, array_diff_key($data, ['id' => 1]));
        });

        return redirect()->route('admin.providers.show', $id)->with('status', 'Provider Created (disabled). Now give an API key and Test.');
    }

    public function show(string $id)
    {
        $this->allow('providers.manage');
        $provider = $this->find($id);

        return view('admin.providers.show', [
            'provider' => $provider,
            'adapters' => self::ADAPTERS,
            'others' => $this->db()->table('ai_providers')->where('id', '!=', $id)->orderBy('name')->get(['id', 'name']),
            'credential' => $this->db()->table('provider_credentials')->where('provider_id', $id)->where('status', 'active')->first(['id', 'label', 'key_version', 'last_rotated_at', 'created_at']),
            'history' => $this->db()->table('provider_credentials')->where('provider_id', $id)->where('status', 'revoked')->orderByDesc('created_at')->limit(5)->get(['label', 'key_version', 'created_at']),
            'models' => $this->db()->table('ai_models as m')
                ->leftJoin('model_current_pricing as pr', 'pr.model_id', '=', 'm.id')
                ->where('m.provider_id', $id)->orderBy('m.display_name')
                ->get(['m.id', 'm.model_key', 'm.display_name', 'm.category', 'm.status', 'm.is_free_tier', 'm.is_local', 'm.context_window', 'm.max_output', 'm.quality_rank',
                    'pr.input_per_1m', 'pr.output_per_1m', 'pr.per_request', 'pr.credit_multiplier', 'pr.currency']),
            'capabilities' => $this->db()->table('model_capabilities as c')->join('ai_models as m', 'm.id', '=', 'c.model_id')->where('m.provider_id', $id)
                ->get(['c.model_id', 'c.capability'])->groupBy('model_id')->map(fn ($g) => $g->pluck('capability')->all()),
            'plans' => $this->db()->table('plans')->where('status', '!=', 'retired')->orderBy('sort')->get(['id', 'code', 'name']),
            'rules' => $this->db()->table('model_access_rules as r')->join('ai_models as m', 'm.id', '=', 'r.model_id')->where('m.provider_id', $id)
                ->get(['r.model_id', 'r.plan_id', 'r.allowed'])->groupBy('model_id'),
            'checks' => $this->db()->table('provider_health_checks')->where('provider_id', $id)->orderByDesc('id')->limit(10)->get(['status', 'latency_ms', 'error_code', 'checked_at']),
            'capabilityList' => ModelController::CAPABILITIES,
            'categories' => ModelController::CATEGORIES,
        ]);
    }

    public function update(Request $request, string $id)
    {
        $this->allow('providers.manage');
        $before = $this->find($id);
        $data = $this->validated($request, $id) + ['status' => $request->validate(['status' => ['required', Rule::in(['active', 'disabled', 'degraded'])]])['status']];

        // Pointing a provider at a different host must not silently carry the old credential along.
        $hostChanged = ($data['base_url'] ?? null) !== $before->base_url || $data['adapter'] !== $before->adapter;
        if ($hostChanged && $this->db()->table('provider_credentials')->where('provider_id', $id)->where('status', 'active')->exists()) {
            $request->validate(['confirm_credential_reuse' => ['accepted']], ['accepted' => 'Adapter/URL If changed, the saved key will go to the new address — confirm.']);
        }
        if ($data['status'] === 'active') {
            abort_unless($this->db()->table('provider_credentials')->where('provider_id', $id)->where('status', 'active')->exists() || $data['adapter'] === 'ollama', 422, 'To activate, first give an API key.');
        }

        try {
            $this->db()->transaction(function () use ($id, $before, $data): void {
                $this->db()->table('ai_providers')->where('id', $id)->update($data + ['updated_at' => now()]);
                AdminAudit::record('admin.provider.updated', 'provider', $id, $this->snapshot($before, self::FIELDS), $data);
            });
        } catch (QueryException $e) {
            if ($e->getCode() === '23514') {   // fallback cycle
                return back()->withErrors(['fallback_provider_id' => 'Fallback The chain is forming a loop.'])->withInput();
            }
            throw $e;
        }

        return back()->with('status', 'Provider Updated.');
    }

    /** Adds (or rotates to) a new API key. The key is encrypted immediately and never shown again. */
    public function saveCredential(Request $request, string $id, CredentialVault $vault)
    {
        $this->allow('providers.manage');
        $this->find($id);
        $data = $request->validate([
            'api_key' => ['required', 'string', 'min:8', 'max:500'],
            'label' => ['nullable', 'string', 'max:100'],
        ]);
        $enc = $vault->encrypt(trim($data['api_key']));
        $label = $data['label'] ?: 'main';

        $this->db()->transaction(function () use ($id, $enc, $label): void {
            $old = $this->db()->table('provider_credentials')->where('provider_id', $id)->where('status', 'active')->first(['id', 'label', 'key_version']);
            $this->db()->table('provider_credentials')->where('provider_id', $id)->where('status', 'active')->update(['status' => 'revoked']);
            $this->db()->table('provider_credentials')->insert([
                'id' => CreateWorkspace::newId(), 'provider_id' => $id, 'label' => $label,
                'secret_encrypted' => $enc['cipher'], 'key_version' => $enc['version'],
                'last_rotated_at' => $old === null ? null : now(),
            ]);
            // The key itself is never part of the audit record.
            AdminAudit::record($old === null ? 'admin.provider.credential_added' : 'admin.provider.credential_rotated', 'provider', $id,
                $old === null ? null : ['label' => $old->label, 'key_version' => $old->key_version], ['label' => $label, 'key_version' => $enc['version']]);
        });

        return back()->with('status', 'API key Saved (encrypted). It can no longer be seen.');
    }

    public function revokeCredential(string $id)
    {
        $this->allow('providers.manage');
        $provider = $this->find($id);
        $this->db()->transaction(function () use ($id, $provider): void {
            $n = $this->db()->table('provider_credentials')->where('provider_id', $id)->where('status', 'active')->update(['status' => 'revoked']);
            abort_if($n === 0, 404);
            // No credential, no serving: switch the provider off in the same step.
            $this->db()->table('ai_providers')->where('id', $id)->update(['status' => 'disabled', 'updated_at' => now()]);
            AdminAudit::record('admin.provider.credential_revoked', 'provider', $id, ['status' => $provider->status], ['status' => 'disabled']);
        });

        return back()->with('status', 'Key Revoked; the provider was disabled.');
    }

    public function test(string $id, ProviderGateway $gateway)
    {
        $this->allow('providers.manage');
        $this->find($id);
        try {
            $r = $gateway->testConnection($id, TenantRoles::PLATFORM_TEAM, adminTest: true);
            $ok = (bool) ($r['ok'] ?? false);
            $code = $ok ? null : substr((string) ($r['error_code'] ?? 'failed'), 0, 40);
            $message = $ok ? "The connection is fine ({$r['latency_ms']} ms)" : 'Failed: '.($r['message'] ?? $code);
            $latency = $ok ? (int) $r['latency_ms'] : null;
        } catch (ProviderCallException $e) {
            [$ok, $code, $latency, $message] = [false, substr($e->errorCode, 0, 40), null, 'Failed: '.$e->getMessage()];
        } catch (\RuntimeException) {
            [$ok, $code, $latency, $message] = [false, 'engine_unreachable', null, 'AI engine-could not be reached.'];
        }
        $this->db()->table('provider_health_checks')->insert(['provider_id' => $id, 'status' => $ok ? 'ok' : 'failed', 'latency_ms' => $latency, 'error_code' => $code]);
        $this->db()->transaction(fn () => AdminAudit::record('admin.provider.tested', 'provider', $id, null, ['ok' => $ok, 'error_code' => $code]));

        return back()->with($ok ? 'status' : 'test_failed', $message);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?string $id): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('admin.ai_providers', 'name')->ignore($id)],
            'adapter' => ['required', Rule::in(self::ADAPTERS)],
            'type' => ['required', Rule::in(['llm', 'voice', 'image', 'video', 'embedding'])],
            'base_url' => ['nullable', 'url:http,https', 'max:300'],
            'timeout_ms' => ['required', 'integer', 'between:1000,300000'],
            'priority' => ['required', 'integer', 'between:0,10000'],
            'fallback_provider_id' => ['nullable', 'string', 'size:26', Rule::exists('admin.ai_providers', 'id')],
            'allow_private' => ['sometimes', 'boolean'],
        ]);
        $data['allow_private'] = $request->boolean('allow_private');
        $data['base_url'] = ($data['base_url'] ?? '') === '' ? null : rtrim($data['base_url'], '/');
        $data['fallback_provider_id'] = ($data['fallback_provider_id'] ?? '') === '' ? null : $data['fallback_provider_id'];
        abort_if($data['adapter'] === 'openai_compatible' && $data['base_url'] === null, 422, 'A base URL is needed for this adapter.');
        // Private/localhost targets are an SSRF risk; only the local-model adapter may use them.
        abort_if($data['allow_private'] && $data['adapter'] !== 'ollama' && $data['adapter'] !== 'openai_compatible', 422, 'Private The address is allowed only for Ollama/compatible adapters.');
        abort_if($data['fallback_provider_id'] !== null && $data['fallback_provider_id'] === $id, 422, 'It cannot fall back to itself.');

        return $data;
    }

    private function find(string $id): object
    {
        return $this->db()->table('ai_providers')->where('id', $id)->first() ?? abort(404);
    }
}
