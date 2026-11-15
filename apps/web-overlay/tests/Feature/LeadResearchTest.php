<?php

use App\Actions\Tenancy\CreateWorkspace;
use App\Jobs\ProcessLeadItem;
use App\Leads\LeadEngine;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\FakeLeadEngine;

// Needs phase8 migrated. Rolls back after each test. Owners hold every permission (leads.* included after `php artisan roles:sync`).
uses(Illuminate\Foundation\Testing\DatabaseTransactions::class);

function leadOwner(): array
{
    $u = User::create(['name' => 'Lead Owner', 'email' => Str::lower(Str::random(10)).'@example.com', 'password' => 'Passw0rd!long']);
    $u->forceFill(['email_verified_at' => now()])->save();

    return [$u, app(CreateWorkspace::class)->organization($u, 'Lead Co')];
}

it('starts a research job, queues one task per unique valid domain', function () {
    Queue::fake();
    [$user, $tenant] = leadOwner();
    $this->actingAs($user)->withSession(['tenant_id' => $tenant])
        ->post('/app/leads/research', ['urls' => "alo.com\nhttps://www.alo.com/x\nbeta.com.bd\n127.0.0.1\nnot a url"])
        ->assertRedirect();
    Queue::assertPushed(ProcessLeadItem::class, 2);
    expect(DB::table('lead_research_items')->where('tenant_id', $tenant)->count())->toBe(2);
});

it('rejects an empty or all-invalid list', function () {
    Queue::fake();
    [$user, $tenant] = leadOwner();
    $this->actingAs($user)->withSession(['tenant_id' => $tenant])->post('/app/leads/research', ['urls' => "127.0.0.1\nlocalhost"])->assertSessionHasErrors('urls');
    Queue::assertNothingPushed();
});

it('processes queued items into leads that only their own workspace can see', function () {
    [$a, $ta] = leadOwner();
    [$b, $tb] = leadOwner();
    $engine = new FakeLeadEngine;
    $engine->results['alo.com'] = FakeLeadEngine::okResult('alo.com');
    app()->instance(LeadEngine::class, $engine);

    $this->actingAs($a)->withSession(['tenant_id' => $ta])->post('/app/leads/research', ['urls' => 'alo.com'])->assertRedirect();   // sync queue in tests
    $this->actingAs($a)->withSession(['tenant_id' => $ta])->get('/app/leads')->assertOk()->assertSee('alo.com');
    $this->actingAs($b)->withSession(['tenant_id' => $tb])->get('/app/leads')->assertOk()->assertDontSee('alo.com');
    expect(app(TenantContext::class)->id())->toBeNull();
});

it('a viewer-only member cannot start research', function () {
    [$owner, $tenant] = leadOwner();
    $m = User::create(['name' => 'M', 'email' => Str::lower(Str::random(10)).'@example.com', 'password' => 'Passw0rd!long']);
    $m->forceFill(['email_verified_at' => now()])->save();
    DB::table('tenant_users')->insert(['id' => CreateWorkspace::newId(), 'tenant_id' => $tenant, 'user_id' => $m->id, 'status' => 'active', 'joined_at' => now()]);
    \App\Tenancy\TenantRoles::assign($m, $tenant, 'Member');
    $this->actingAs($m)->withSession(['tenant_id' => $tenant])->post('/app/leads/research', ['urls' => 'alo.com'])->assertForbidden();
    $this->actingAs($m)->withSession(['tenant_id' => $tenant])->get('/app/leads')->assertOk();
});
