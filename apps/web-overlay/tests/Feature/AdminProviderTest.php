<?php

use App\Ai\CredentialVault;
use App\Ai\ModelCatalog;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/*
 * Needs DB_ADMIN_PASSWORD and AI_VAULT_KEY_1; COMMITS rows (second DB session) — use a throw-away test database.
 * Helpers realAdmin()/asAdmin()/adminDb()/hasAdminLogin() come from AdminTest.php.
 */

function vaultReady(): bool
{
    return hasAdminLogin() && filled(config('ai_engine.vault.keys.1'));
}

function newProviderVia(User $admin, string $adapter = 'openai'): string
{
    $name = 'prov-'.Str::lower(Str::random(8));
    asAdmin($admin)->post('/admin/providers', [
        'name' => $name, 'adapter' => $adapter, 'type' => 'llm', 'timeout_ms' => 30000, 'priority' => 10,
    ])->assertRedirect();

    return adminDb()->table('ai_providers')->where('name', $name)->value('id');
}

it('lets a Provider Manager in and keeps an auditor out', function () {
    if (! vaultReady()) {
        $this->markTestSkipped('admin DB login / vault key missing');
    }
    asAdmin(realAdmin('Provider Manager'))->get('/admin/providers')->assertOk();
    asAdmin(realAdmin('Read-Only Auditor'))->get('/admin/providers')->assertForbidden();
});

it('creates providers disabled, and refuses to enable one without a key', function () {
    if (! vaultReady()) {
        $this->markTestSkipped('admin DB login / vault key missing');
    }
    $admin = realAdmin();
    $id = newProviderVia($admin);
    expect(adminDb()->table('ai_providers')->where('id', $id)->value('status'))->toBe('disabled');

    asAdmin($admin)->put("/admin/providers/{$id}", [
        'name' => adminDb()->table('ai_providers')->where('id', $id)->value('name'), 'adapter' => 'openai', 'type' => 'llm',
        'timeout_ms' => 30000, 'priority' => 10, 'status' => 'active',
    ])->assertStatus(422);
});

it('stores the key encrypted, audits without it, rotates, and revokes', function () {
    if (! vaultReady()) {
        $this->markTestSkipped('admin DB login / vault key missing');
    }
    $admin = realAdmin();
    $id = newProviderVia($admin);
    $key = 'sk-test-'.Str::random(30);

    $res = asAdmin($admin)->post("/admin/providers/{$id}/credential", ['api_key' => $key, 'label' => 'main']);
    $res->assertRedirect();
    expect($res->getContent())->not->toContain($key);

    $db = adminDb();
    $row = $db->table('provider_credentials')->where('provider_id', $id)->where('status', 'active')->first(['id', 'key_version']);
    expect($row)->not->toBeNull();
    $cipher = $db->selectOne('select secret_encrypted from provider_secret_for_test(?)', [$id])->secret_encrypted;
    expect($cipher)->not->toContain($key)
        ->and(app(CredentialVault::class)->decrypt($cipher, $row->key_version))->toBe($key);

    // the show page must never render the key or the ciphertext
    asAdmin($admin)->get("/admin/providers/{$id}")->assertOk()->assertDontSee($key)->assertDontSee($cipher);

    // rotation: one active credential, the old one revoked
    asAdmin($admin)->post("/admin/providers/{$id}/credential", ['api_key' => $key.'-v2'])->assertRedirect();
    expect($db->table('provider_credentials')->where('provider_id', $id)->where('status', 'active')->count())->toBe(1)
        ->and($db->table('provider_credentials')->where('provider_id', $id)->where('status', 'revoked')->count())->toBe(1);

    // no audit row anywhere contains the key
    $audits = $db->table('audit_logs')->where('subject_id', $id)->get(['before', 'after'])->map(fn ($r) => $r->before.$r->after)->implode('|');
    expect($audits)->not->toContain($key)->and($audits)->toContain('key_version');

    // revoking switches the provider off
    asAdmin($admin)->delete("/admin/providers/{$id}/credential")->assertRedirect();
    expect($db->table('ai_providers')->where('id', $id)->value('status'))->toBe('disabled');
});

it('tests a connection through the engine and records a health check', function () {
    if (! vaultReady()) {
        $this->markTestSkipped('admin DB login / vault key missing');
    }
    config(['ai_engine.hmac_secret' => str_repeat('h', 40), 'ai_engine.jwt_secret' => str_repeat('j', 40)]);
    Http::fake(['*' => HttpFactory::response(['ok' => true, 'latency_ms' => 42])]);
    $admin = realAdmin();
    $id = newProviderVia($admin);
    asAdmin($admin)->post("/admin/providers/{$id}/credential", ['api_key' => 'sk-test-'.Str::random(30)]);

    asAdmin($admin)->post("/admin/providers/{$id}/test")->assertSessionHas('status');

    expect(adminDb()->table('provider_health_checks')->where('provider_id', $id)->where('status', 'ok')->value('latency_ms'))->toBe(42);
});

it('keeps a model without a price from being switched on, and never edits price history', function () {
    if (! vaultReady()) {
        $this->markTestSkipped('admin DB login / vault key missing');
    }
    $admin = realAdmin();
    $pid = newProviderVia($admin);
    asAdmin($admin)->post("/admin/providers/{$pid}/models", ['model_key' => 'm-'.Str::lower(Str::random(6)), 'display_name' => 'M', 'category' => 'chat'])->assertRedirect();
    $mid = adminDb()->table('ai_models')->where('provider_id', $pid)->value('id');
    $base = ['display_name' => 'M', 'category' => 'chat', 'quality_rank' => 60, 'status' => 'active', 'capabilities' => ['text']];

    asAdmin($admin)->put("/admin/models/{$mid}", $base)->assertStatus(422);

    asAdmin($admin)->post("/admin/models/{$mid}/pricing", ['output_per_1m' => 10, 'currency' => 'USD', 'credit_multiplier' => 2])->assertRedirect();
    asAdmin($admin)->post("/admin/models/{$mid}/pricing", ['output_per_1m' => 12, 'currency' => 'USD', 'credit_multiplier' => 2])->assertRedirect();
    expect(adminDb()->table('model_pricing')->where('model_id', $mid)->count())->toBe(2);

    asAdmin($admin)->put("/admin/models/{$mid}", $base)->assertRedirect();
    expect(adminDb()->table('ai_models')->where('id', $mid)->value('status'))->toBe('active');
});

it('shows a free-plan tenant only free-tier models and respects explicit plan rules', function () {
    if (! vaultReady()) {
        $this->markTestSkipped('admin DB login / vault key missing');
    }
    $admin = realAdmin();
    $pid = newProviderVia($admin);
    asAdmin($admin)->post("/admin/providers/{$pid}/credential", ['api_key' => 'sk-test-'.Str::random(30)]);
    adminDb()->table('ai_providers')->where('id', $pid)->update(['status' => 'active']);
    $free = (string) Str::ulid();
    $paid = (string) Str::ulid();
    foreach ([[$free, 'free-m', true], [$paid, 'paid-m', false]] as [$mid, $key, $isFree]) {
        adminDb()->table('ai_models')->insert(['id' => $mid, 'provider_id' => $pid, 'model_key' => $key, 'display_name' => $key, 'is_free_tier' => $isFree, 'status' => 'active']);
        adminDb()->table('model_capabilities')->insert(['model_id' => $mid, 'capability' => 'text']);
    }
    $owner = User::create(['name' => 'O', 'email' => Str::lower(Str::random(10)).'@example.com', 'password' => 'Passw0rd!long']);
    $tenant = app(\App\Actions\Tenancy\CreateWorkspace::class)->organization($owner, 'ModelCo');

    $ids = fn () => collect(app(TenantContext::class)->run($tenant, fn () => app(ModelCatalog::class)->candidates($tenant)))->pluck('id')->all();
    expect($ids())->toContain($free)->not->toContain($paid);

    adminDb()->table('model_access_rules')->insert(['model_id' => $paid, 'plan_id' => adminDb()->table('plans')->where('code', 'free')->value('id'), 'allowed' => true]);
    expect($ids())->toContain($paid);
});
