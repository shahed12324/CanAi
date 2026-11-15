<?php

use App\Actions\Tenancy\CreateWorkspace;
use App\Credits\CreditService;
use App\Models\User;
use Illuminate\Support\Str;

/*
 * Needs DB_ADMIN_PASSWORD and COMMITS rows (second DB session) — use a throw-away test database.
 * Helpers realAdmin()/asAdmin()/adminDb()/hasAdminLogin() come from AdminTest.php.
 */

function committedTenant(): string
{
    $u = User::create(['name' => 'Owner', 'email' => Str::lower(Str::random(10)).'@example.com', 'password' => 'Passw0rd!long']);
    $u->forceFill(['email_verified_at' => now()])->save();

    return app(CreateWorkspace::class)->organization($u, 'Adj Co');
}

it('keeps non-finance admins out of credits', function () {
    if (! hasAdminLogin()) {
        $this->markTestSkipped('admin DB login missing');
    }
    asAdmin(realAdmin('Finance Admin'))->get('/admin/credits')->assertOk();
    asAdmin(realAdmin('Support Admin'))->get('/admin/credits')->assertForbidden();
});

it('adjusts a wallet once per form submission and audits it', function () {
    if (! hasAdminLogin()) {
        $this->markTestSkipped('admin DB login missing');
    }
    config(['credits.signup_grant' => '10']);
    $tenant = committedTenant();
    $admin = realAdmin('Finance Admin');
    $payload = ['type' => 'adjustment', 'amount' => '25.5', 'reason' => 'goodwill', 'key' => 'form-'.Str::random(12)];

    asAdmin($admin)->post("/admin/tenants/{$tenant}/credits/adjust", $payload)->assertRedirect();
    asAdmin($admin)->post("/admin/tenants/{$tenant}/credits/adjust", $payload)->assertRedirect();   // double-click

    expect(app(CreditService::class)->wallet($tenant)->balance)->toBe('35.5000');
    expect(adminDb()->table('audit_logs')->where('tenant_id', $tenant)->where('action', 'admin.credit.adjusted')->count())->toBe(1);

    asAdmin($admin)->post("/admin/tenants/{$tenant}/credits/adjust", ['type' => 'adjustment', 'amount' => '-1000', 'reason' => 'too much', 'key' => 'form-'.Str::random(12)])
        ->assertSessionHasErrors('amount');
    expect(app(CreditService::class)->wallet($tenant)->balance)->toBe('35.5000');
});

it('creates packages hidden', function () {
    if (! hasAdminLogin()) {
        $this->markTestSkipped('admin DB login missing');
    }
    $name = 'Pack '.Str::random(6);
    asAdmin(realAdmin('Finance Admin'))->post('/admin/credits/packages', ['name' => $name, 'credits' => '1000', 'price' => '500', 'currency' => 'BDT'])->assertRedirect();

    expect(adminDb()->table('credit_packages')->where('name', $name)->value('status'))->toBe('hidden');
});
