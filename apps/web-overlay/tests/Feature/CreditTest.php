<?php

use App\Actions\Tenancy\CreateWorkspace;
use App\Credits\CreditException;
use App\Credits\CreditService;
use App\Models\User;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantRoles;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Needs the Phase 5 schema (phase5.up.sql) and the jarvis_app login. Rolls back after every test.
uses(Illuminate\Foundation\Testing\DatabaseTransactions::class);

function creditOwner(): array
{
    $u = User::create(['name' => 'Owner', 'email' => Str::lower(Str::random(10)).'@example.com', 'password' => 'Passw0rd!long']);
    $u->forceFill(['email_verified_at' => now()])->save();
    $tenant = app(CreateWorkspace::class)->organization($u, 'Credit Co');

    return [$u, $tenant];
}

function creditKey(): string
{
    return 'test-'.Str::lower(Str::random(16));
}

it('gives a new workspace its welcome credits exactly once', function () {
    config(['credits.signup_grant' => '100']);
    [, $tenant] = creditOwner();
    $svc = app(CreditService::class);

    expect($svc->wallet($tenant)->balance)->toBe('100.0000');
    expect($svc->grantSignup($tenant)['replayed'])->toBeTrue();      // retry is a no-op
    expect($svc->wallet($tenant)->balance)->toBe('100.0000');
});

it('can disable welcome credits', function () {
    config(['credits.signup_grant' => '0']);
    [, $tenant] = creditOwner();

    expect(app(CreditService::class)->wallet($tenant))->toBeNull();
});

it('reserves, settles and records usage', function () {
    config(['credits.signup_grant' => '100']);
    [, $tenant] = creditOwner();
    $svc = app(CreditService::class);

    $res = $svc->reserve($tenant, '30', creditKey(), 'req-1');
    expect($svc->wallet($tenant))->toMatchObject(['reserved' => '30.0000', 'available' => '70.0000']);

    $done = $svc->settle($tenant, $res['reservation_id'], '12.5', creditKey(), ['source' => 'chat', 'department' => 'Sales', 'input_tokens' => 100]);
    expect($done)->toMatchObject(['charged' => '12.5000', 'shortfall' => '0.0000', 'replayed' => false]);
    expect($svc->wallet($tenant))->toMatchObject(['balance' => '87.5000', 'reserved' => '0.0000']);
    expect($svc->spentThisMonth($tenant))->toBe('12.5000');
    expect(DB::table('usage_records')->where('tenant_id', $tenant)->value('department'))->toBe('Sales');
});

it('replays a repeated request instead of charging twice', function () {
    config(['credits.signup_grant' => '100']);
    [, $tenant] = creditOwner();
    $svc = app(CreditService::class);
    $key = creditKey();

    $a = $svc->reserve($tenant, '10', $key);
    $b = $svc->reserve($tenant, '10', $key);
    expect($b)->toMatchArray(['reservation_id' => $a['reservation_id'], 'replayed' => true]);
    expect($svc->wallet($tenant)->reserved)->toBe('10.0000');
});

it('refuses when there are not enough credits, and releases give them back', function () {
    config(['credits.signup_grant' => '10']);
    [, $tenant] = creditOwner();
    $svc = app(CreditService::class);

    expect(fn () => $svc->reserve($tenant, '11', creditKey()))->toThrow(CreditException::class);
    $res = $svc->reserve($tenant, '10', creditKey());
    $svc->release($tenant, $res['reservation_id'], creditKey());
    expect($svc->wallet($tenant))->toMatchObject(['available' => '10.0000', 'reserved' => '0.0000']);
});

it('rejects floats and bad idempotency keys before touching the database', function () {
    [, $tenant] = creditOwner();
    $svc = app(CreditService::class);

    expect(fn () => $svc->reserve($tenant, '1.23456', creditKey()))->toThrow(InvalidArgumentException::class);
    expect(fn () => $svc->reserve($tenant, '1', 'short'))->toThrow(InvalidArgumentException::class);
});

it('has no way to write money tables directly', function () {
    config(['credits.signup_grant' => '100']);
    [, $tenant] = creditOwner();
    app(TenantContext::class)->set($tenant);

    expect(fn () => DB::statement('update wallets set balance = balance + 1000 where tenant_id = ?', [$tenant]))->toThrow(Illuminate\Database\QueryException::class);
});

it('cannot touch another workspace through the SQL functions', function () {
    [, $mine] = creditOwner();
    [, $other] = creditOwner();
    app(TenantContext::class)->set($mine);

    expect(fn () => DB::select("select * from credit_grant(?::char(26), 'grant_free', 5, ?)", [$other, creditKey()]))
        ->toThrow(Illuminate\Database\QueryException::class);
});

function memberOf(string $tenant, string $role): User
{
    $u = User::create(['name' => $role, 'email' => Str::lower(Str::random(10)).'@example.com', 'password' => 'Passw0rd!long']);
    $u->forceFill(['email_verified_at' => now()])->save();
    DB::table('tenant_users')->insert(['id' => CreateWorkspace::newId(), 'tenant_id' => $tenant, 'user_id' => $u->id, 'status' => 'active', 'joined_at' => now()]);
    TenantRoles::assign($u, $tenant, $role);

    return $u;
}

it('shows the credit page to owners and hides it from plain members', function () {
    config(['credits.signup_grant' => '100']);
    [$owner, $tenant] = creditOwner();
    $member = memberOf($tenant, 'Member');

    $this->actingAs($owner)->withSession(['tenant_id' => $tenant])->get('/app/credits')->assertOk()->assertSee('Credits and usage')->assertSee('100');
    $this->actingAs($owner)->withSession(['tenant_id' => $tenant])->get('/app/credits/transactions')->assertOk();
    $this->actingAs($member)->withSession(['tenant_id' => $tenant])->get('/app/credits')->assertForbidden();
});

it('lets only managers change alert and spending-limit settings, and validates them', function () {
    config(['credits.signup_grant' => '100']);
    [$owner, $tenant] = creditOwner();
    $admin = memberOf($tenant, 'Admin');   // billing.view but not workspace.manage

    $this->actingAs($owner)->withSession(['tenant_id' => $tenant])
        ->put('/app/credits/settings', ['low_balance_threshold' => '25', 'spending_limit_monthly' => '500'])->assertRedirect();
    expect(app(CreditService::class)->wallet($tenant))->toMatchObject(['low_balance_threshold' => '25.0000', 'spending_limit_monthly' => '500.0000']);

    $this->actingAs($owner)->withSession(['tenant_id' => $tenant])
        ->put('/app/credits/settings', ['low_balance_threshold' => '-5'])->assertSessionHasErrors('low_balance_threshold');
    $this->actingAs($admin)->withSession(['tenant_id' => $tenant])
        ->put('/app/credits/settings', ['low_balance_threshold' => '1'])->assertForbidden();
});

it('shows only this workspace\'s ledger', function () {
    config(['credits.signup_grant' => '100']);
    [$owner, $tenant] = creditOwner();
    [, $other] = creditOwner();
    app(CreditService::class)->grant($other, 'grant_free', '7', creditKey(), null, null, null, null, 'OTHER-TENANT-NOTE');

    $this->actingAs($owner)->withSession(['tenant_id' => $tenant])->get('/app/credits/transactions')->assertOk()->assertDontSee('OTHER-TENANT-NOTE');
});
