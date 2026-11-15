<?php

use App\Actions\Fortify\CreateNewUser;
use App\Admin\PlatformRoles;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/*
 * Part 1 needs only the normal DB login (nothing is written).
 * Part 2 needs DB_ADMIN_PASSWORD (the jarvis_admin_login role) and COMMITS rows, because the admin
 * connection is a second DB session and cannot see another session's open transaction.
 * Run part 2 only against a throw-away test database (unique e-mails are used; audit rows stay by design).
 */

function ghostUser(array $attrs = []): User
{
    $u = new User(['name' => 'Ghost', 'email' => Str::random(8).'@example.com']);
    $u->forceFill($attrs + ['id' => (string) Str::ulid(), 'status' => 'active', 'email_verified_at' => now()]);

    return $u;
}

function adminDb(): \Illuminate\Database\Connection
{
    $c = DB::connection('admin');
    $c->select("select set_config('app.bypass_rls', 'on', false)");

    return $c;
}

function hasAdminLogin(): bool
{
    return filled(config('database.connections.admin.password'));
}

/** A committed, verified, 2FA-confirmed user promoted through the real artisan command. */
function realAdmin(string $role = 'Super Admin'): User
{
    $email = Str::lower(Str::random(10)).'@example.com';
    $user = User::create(['name' => 'Admin', 'email' => $email, 'password' => 'Passw0rd!long']);
    $user->forceFill(['email_verified_at' => now()])->save();
    adminDb()->table('users')->where('id', $user->id)->update(['two_factor_confirmed_at' => now(), 'two_factor_secret' => 'x']);
    expect(Artisan::call('admin:promote', ['email' => $email, '--role' => $role]))->toBe(0);

    return User::findOrFail($user->id);
}

function asAdmin(User $u)
{
    return test()->actingAs($u)->withSession(['auth.password_confirmed_at' => time()]);
}

// ───────── Part 1: gate behaviour (no admin DB needed) ─────────

it('sends guests to login', function () {
    $this->get('/admin')->assertRedirect('/login');
});

it('returns 403 for a normal user on every admin page', function () {
    $user = ghostUser();
    foreach (['/admin', '/admin/users', '/admin/tenants', '/admin/roles', '/admin/plans', '/admin/features', '/admin/flags', '/admin/settings', '/admin/audit'] as $path) {
        asAdmin($user)->get($path)->assertForbidden();
    }
});

it('returns 403 when the platform-admin flag is set but the account is suspended', function () {
    asAdmin(ghostUser(['is_platform_admin' => true, 'status' => 'suspended', 'two_factor_confirmed_at' => now()]))
        ->get('/admin')->assertForbidden();
});

it('sends a platform admin without confirmed 2FA to the security page', function () {
    asAdmin(ghostUser(['is_platform_admin' => true]))->get('/admin')->assertRedirect(route('security.show'));
});

it('forces password re-confirmation after 30 idle minutes', function () {
    if (! hasAdminLogin()) {
        $this->markTestSkipped('DB_ADMIN_PASSWORD not set');
    }
    $user = ghostUser(['is_platform_admin' => true, 'two_factor_confirmed_at' => now()]);
    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time(), 'admin.last_activity' => time() - 1900])
        ->get('/admin')->assertRedirect(route('password.confirm'));
});

// ───────── Part 2: real admin flows (needs the admin DB login; commits data) ─────────

it('lets a Super Admin in and renders the dashboard', function () {
    if (! hasAdminLogin()) {
        $this->markTestSkipped('DB_ADMIN_PASSWORD not set');
    }
    asAdmin(realAdmin())->get('/admin')->assertOk()->assertSee('Dashboard');
});

it('hides pages from a role without the permission (Read-Only Auditor cannot manage users)', function () {
    if (! hasAdminLogin()) {
        $this->markTestSkipped('DB_ADMIN_PASSWORD not set');
    }
    $auditor = realAdmin('Read-Only Auditor');
    $target = User::create(['name' => 'T', 'email' => Str::lower(Str::random(10)).'@example.com', 'password' => 'Passw0rd!long']);

    asAdmin($auditor)->get('/admin/audit')->assertOk();
    asAdmin($auditor)->post("/admin/users/{$target->id}/suspend")->assertForbidden();
    expect(adminDb()->table('users')->where('id', $target->id)->value('status'))->toBe('active');
});

it('audits user suspension with before/after and revokes the user\'s access', function () {
    if (! hasAdminLogin()) {
        $this->markTestSkipped('DB_ADMIN_PASSWORD not set');
    }
    $admin = realAdmin();
    $target = User::create(['name' => 'T', 'email' => Str::lower(Str::random(10)).'@example.com', 'password' => 'Passw0rd!long']);

    asAdmin($admin)->post("/admin/users/{$target->id}/suspend")->assertRedirect();

    $db = adminDb();
    expect($db->table('users')->where('id', $target->id)->value('status'))->toBe('suspended');
    $log = $db->table('audit_logs')->where('action', 'admin.user.suspended')->where('subject_id', $target->id)->first();
    expect($log)->not->toBeNull()
        ->and($log->actor_id)->toBe($admin->id)
        ->and(json_decode($log->before, true)['status'])->toBe('active')
        ->and(json_decode($log->after, true)['status'])->toBe('suspended');
});

it('refuses to suspend yourself', function () {
    if (! hasAdminLogin()) {
        $this->markTestSkipped('DB_ADMIN_PASSWORD not set');
    }
    $admin = realAdmin();
    asAdmin($admin)->post("/admin/users/{$admin->id}/suspend")->assertStatus(422);
    expect(adminDb()->table('users')->where('id', $admin->id)->value('status'))->toBe('active');
});

it('protects the last Super Admin from demotion', function () {
    if (! hasAdminLogin()) {
        $this->markTestSkipped('DB_ADMIN_PASSWORD not set');
    }
    $a = realAdmin();
    $b = realAdmin();
    // Make $a the only Super Admin: demote everyone else who is a Super Admin to System Admin directly.
    foreach (adminDb()->table('users')->where('is_platform_admin', true)->where('id', '!=', $a->id)->pluck('id') as $id) {
        PlatformRoles::assign(User::findOrFail($id), 'System Admin');
    }
    expect(PlatformRoles::superAdminCount())->toBe(1);

    // $b (now System Admin) cannot touch roles; $a cannot demote themselves.
    asAdmin($a)->put("/admin/users/{$a->id}/platform-role", ['role' => 'System Admin'])->assertStatus(422);
    expect(PlatformRoles::roleOf($a->id))->toBe('Super Admin');
    asAdmin($b->fresh())->put("/admin/users/{$a->id}/platform-role", ['role' => ''])->assertForbidden();
});

it('blocks new registrations when the signup switch is off, and audits the settings change', function () {
    if (! hasAdminLogin()) {
        $this->markTestSkipped('DB_ADMIN_PASSWORD not set');
    }
    $admin = realAdmin();
    $payload = ['system__signup_enabled' => '0', 'system__default_locale' => 'bn', 'system__default_currency' => 'BDT'];
    try {
        asAdmin($admin)->put('/admin/settings/system', $payload)->assertRedirect();
        $in = ['name' => 'X', 'email' => Str::lower(Str::random(8)).'@example.com', 'password' => 'Str0ngPassword1', 'password_confirmation' => 'Str0ngPassword1'];
        expect(fn () => app(CreateNewUser::class)->create($in))->toThrow(HttpException::class);
        expect(adminDb()->table('audit_logs')->where('action', 'admin.settings.updated')->where('actor_id', $admin->id)->exists())->toBeTrue();
    } finally {
        asAdmin($admin)->put('/admin/settings/system', ['system__signup_enabled' => '1'] + $payload);
    }
});

it('shows the maintenance page to visitors but keeps the admin panel reachable', function () {
    if (! hasAdminLogin()) {
        $this->markTestSkipped('DB_ADMIN_PASSWORD not set');
    }
    $admin = realAdmin();
    $on = ['maintenance__enabled' => '1', 'maintenance__message' => 'Back soon', 'maintenance__allowed_ips' => ''];
    try {
        asAdmin($admin)->put('/admin/settings/maintenance', $on)->assertRedirect();
        $this->flushSession();
        auth()->logout();
        $this->get('/login')->assertStatus(503);
        asAdmin($admin)->get('/admin')->assertOk();
    } finally {
        asAdmin($admin)->put('/admin/settings/maintenance', ['maintenance__message' => 'Back soon']);
    }
});

it('assigns a plan to a workspace and writes an audit row', function () {
    if (! hasAdminLogin()) {
        $this->markTestSkipped('DB_ADMIN_PASSWORD not set');
    }
    $admin = realAdmin();
    $owner = User::create(['name' => 'O', 'email' => Str::lower(Str::random(10)).'@example.com', 'password' => 'Passw0rd!long']);
    $tenantId = app(\App\Actions\Tenancy\CreateWorkspace::class)->organization($owner, 'PlanCo');
    $planId = adminDb()->table('plans')->where('code', 'pro')->value('id');

    asAdmin($admin)->post("/admin/tenants/{$tenantId}/plan", ['plan_id' => $planId, 'status' => 'active'])->assertRedirect();

    $db = adminDb();
    expect($db->table('subscriptions')->where('tenant_id', $tenantId)->where('status', 'active')->count())->toBe(1);
    $log = $db->table('audit_logs')->where('action', 'admin.tenant.plan_assigned')->where('tenant_id', $tenantId)->first();
    expect($log)->not->toBeNull()->and(json_decode($log->after, true)['plan'])->toBe('pro');
});

it('requires a reason for a tenant feature override', function () {
    if (! hasAdminLogin()) {
        $this->markTestSkipped('DB_ADMIN_PASSWORD not set');
    }
    $admin = realAdmin();
    $owner = User::create(['name' => 'O', 'email' => Str::lower(Str::random(10)).'@example.com', 'password' => 'Passw0rd!long']);
    $tenantId = app(\App\Actions\Tenancy\CreateWorkspace::class)->organization($owner, 'OverCo');

    asAdmin($admin)->post("/admin/tenants/{$tenantId}/overrides", ['feature_key' => 'limit.members', 'enabled' => 1, 'limit_value' => 50])
        ->assertSessionHasErrors('reason');
    asAdmin($admin)->post("/admin/tenants/{$tenantId}/overrides", ['feature_key' => 'limit.members', 'enabled' => 1, 'limit_value' => 50, 'reason' => 'pilot customer'])
        ->assertRedirect();
    expect(adminDb()->table('tenant_feature_overrides')->where('tenant_id', $tenantId)->value('limit_value'))->toEqual(50);
});
