<?php

use App\Actions\Tenancy\CreateWorkspace;
use App\Models\User;
use App\Notifications\InvitationNotification;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

// Needs the Phase 2 schema, run as the restricted `jarvis_app` role (ENVIRONMENT_SETUP.md).
uses(DatabaseTransactions::class);

beforeEach(fn () => $this->withoutVite());

const PASSWORD = 'Str0ngPassword1';

function makeUser(?string $email = null, bool $verified = true, string $status = 'active'): User
{
    $user = new User(['name' => 'Test', 'email' => $email ?? Str::lower(Str::random(10)).'@example.com', 'password' => PASSWORD]);
    $user->forceFill(['email_verified_at' => $verified ? now() : null, 'status' => $status])->save();

    return $user;
}

function workspaceFor(User $owner, string $name = 'Acme'): string
{
    return app(CreateWorkspace::class)->organization($owner, $name);
}

function addMember(string $tenantId, User $user, string $role): void
{
    DB::table('tenant_users')->insert([
        'id' => CreateWorkspace::newId(), 'tenant_id' => $tenantId, 'user_id' => $user->id,
        'status' => 'active', 'joined_at' => now(),
    ]);
    App\Tenancy\TenantRoles::assign($user, $tenantId, $role);
}

it('sends guests to the login page', function () {
    $this->get('/app')->assertRedirect('/login');
    $this->get('/login')->assertOk()->assertSee('Log in');
});

it('registers a user with a personal workspace and requires email verification', function () {
    $this->post('/register', [
        'name' => 'Karim', 'email' => 'karim@example.com',
        'password' => PASSWORD, 'password_confirmation' => PASSWORD,
    ])->assertRedirect();

    $user = User::where('email', 'karim@example.com')->firstOrFail();
    expect(DB::table('tenants')->where('owner_id', $user->id)->where('type', 'personal')->count())->toBe(1);
    $this->actingAs($user)->get('/app')->assertRedirect('/email/verify');
});

it('logs in, records the attempt and sets a default workspace', function () {
    $user = makeUser('login@example.com');
    app(CreateWorkspace::class)->personal($user);

    $this->post('/login', ['email' => 'login@example.com', 'password' => 'wrong'])->assertSessionHasErrors('email');
    expect(DB::table('login_logs')->where('email', 'login@example.com')->where('result', 'failed')->count())->toBeGreaterThan(0);

    $this->post('/login', ['email' => 'LOGIN@example.com', 'password' => PASSWORD])->assertRedirect('/app');
    expect($user->fresh()->last_login_at)->not->toBeNull();
    $this->get('/app')->assertOk()->assertSessionHas('tenant_id');
});

it('refuses suspended users, even with the right password', function () {
    makeUser('suspended@example.com', status: 'suspended');
    $this->post('/login', ['email' => 'suspended@example.com', 'password' => PASSWORD])->assertSessionHasErrors('email');
    $this->assertGuest();
});

it('throttles repeated failed logins', function () {
    $email = Str::lower(Str::random(8)).'@example.com';
    foreach (range(1, 5) as $i) {
        $this->post('/login', ['email' => $email, 'password' => 'bad'.$i]);
    }
    $this->post('/login', ['email' => $email, 'password' => 'bad6'])->assertStatus(429);
});

it('does not let a user switch into a workspace they do not belong to', function () {
    $alice = makeUser();
    $bob = makeUser();
    workspaceFor($alice, 'Alice Co');
    $bobs = workspaceFor($bob, 'Bob Co');

    $this->actingAs($alice)->post('/workspaces/switch', ['tenant_id' => $bobs])->assertForbidden();
});

it('shows only the active workspace\'s members', function () {
    $alice = makeUser();
    $bob = makeUser();
    $a = workspaceFor($alice, 'Alice Co');
    workspaceFor($bob, 'Bob Co');

    $this->actingAs($alice)->withSession(['tenant_id' => $a])->get('/app/members')
        ->assertOk()->assertSee($alice->email)->assertDontSee($bob->email);
});

it('lets a session point at a workspace only if membership is active', function () {
    $alice = makeUser();
    $bob = makeUser();
    workspaceFor($alice);
    $bobs = workspaceFor($bob);

    // A forged session value must not grant access.
    $this->actingAs($alice)->withSession(['tenant_id' => $bobs])->get('/app/members')->assertForbidden();
});

it('only members with the invite permission can invite', function () {
    Notification::fake();
    $owner = makeUser();
    $tenant = workspaceFor($owner);
    $member = makeUser();
    addMember($tenant, $member, 'Member');

    $this->actingAs($member)->withSession(['tenant_id' => $tenant])
        ->post('/app/members/invitations', ['email' => 'x@example.com', 'role' => 'Member'])->assertForbidden();

    $this->actingAs($owner)->withSession(['tenant_id' => $tenant])
        ->post('/app/members/invitations', ['email' => 'x@example.com', 'role' => 'Owner'])->assertSessionHasErrors('role');

    $this->actingAs($owner)->withSession(['tenant_id' => $tenant])
        ->post('/app/members/invitations', ['email' => 'x@example.com', 'role' => 'Manager'])->assertSessionHasNoErrors();
    Notification::assertSentOnDemand(InvitationNotification::class);
});

it('runs the whole invite and accept flow over HTTP', function () {
    Notification::fake();
    $owner = makeUser();
    $tenant = workspaceFor($owner, 'Gamma');
    $guest = makeUser('guest-http@example.com');

    $this->actingAs($owner)->withSession(['tenant_id' => $tenant])
        ->post('/app/members/invitations', ['email' => 'guest-http@example.com', 'role' => 'Auditor']);

    $url = null;
    Notification::assertSentOnDemand(InvitationNotification::class, function ($n, $channels, $notifiable) use (&$url) {
        $url = $n->toMail($notifiable)->actionUrl;

        return true;
    });
    preg_match('#/invitations/([a-f0-9]{64})#', $url, $m);
    $token = $m[1];

    $this->get("/invitations/$token")->assertOk()->assertSee('Gamma');

    $this->actingAs($guest)->post("/invitations/$token/accept")->assertRedirect('/app');
    expect(DB::table('tenant_users')->where('tenant_id', $tenant)->where('user_id', $guest->id)->value('status'))->toBe('active');

    $this->actingAs($guest)->withSession(['tenant_id' => $tenant])->get('/app/members')->assertOk();
    // Auditor can view but not invite.
    $this->actingAs($guest)->withSession(['tenant_id' => $tenant])
        ->post('/app/members/invitations', ['email' => 'y@example.com', 'role' => 'Member'])->assertForbidden();

    // Second use fails with a readable error.
    $this->actingAs($guest)->post("/invitations/$token/accept")->assertSessionHasErrors('invitation');
});

it('removes members but never the owner or oneself', function () {
    $owner = makeUser();
    $tenant = workspaceFor($owner);
    $admin = makeUser();
    addMember($tenant, $admin, 'Admin');
    $member = makeUser();
    addMember($tenant, $member, 'Member');

    $as = fn (User $u) => $this->actingAs($u)->withSession(['tenant_id' => $tenant]);
    $as($admin)->delete("/app/members/{$owner->id}")->assertStatus(422);
    $as($admin)->delete("/app/members/{$admin->id}")->assertStatus(422);
    $as($admin)->delete("/app/members/{$member->id}")->assertRedirect();
    expect(DB::table('tenant_users')->where('user_id', $member->id)->where('tenant_id', $tenant)->value('status'))->toBe('removed');
    $as($member)->get('/app/members')->assertForbidden();
});

it('requires password confirmation for the security page', function () {
    $user = makeUser();
    workspaceFor($user);
    $this->actingAs($user)->get('/settings/security')->assertRedirect('/user/confirm-password');
    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])->get('/settings/security')->assertOk();
});

it('issues and revokes API tokens', function () {
    $user = makeUser('api@example.com');
    workspaceFor($user);

    $res = $this->postJson('/api/v1/auth/login', ['email' => 'api@example.com', 'password' => PASSWORD, 'device_name' => 'phone'])
        ->assertOk()->assertJsonStructure(['data' => ['token', 'token_type', 'expires_at', 'user']]);
    $token = $res->json('data.token');

    $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.user.email', 'api@example.com');
    $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();
    expect(DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->count())->toBe(0);
});

it('rejects bad API credentials and unverified emails', function () {
    makeUser('v@example.com', verified: false);
    $this->postJson('/api/v1/auth/login', ['email' => 'v@example.com', 'password' => 'nope', 'device_name' => 'x'])->assertStatus(422);
    $this->postJson('/api/v1/auth/login', ['email' => 'v@example.com', 'password' => PASSWORD, 'device_name' => 'x'])
        ->assertForbidden()->assertJsonPath('code', 'email_unverified');
    $this->getJson('/api/v1/auth/me')->assertUnauthorized();
});

it('requires a tenant header the user belongs to on tenant API routes', function () {
    $alice = makeUser();
    $bob = makeUser();
    $a = workspaceFor($alice);
    $b = workspaceFor($bob);
    $token = $alice->createToken('t')->plainTextToken;

    $this->withToken($token)->getJson('/api/v1/workspace')->assertForbidden();
    $this->withToken($token)->withHeader('X-Tenant-Id', $b)->getJson('/api/v1/workspace')->assertForbidden();
    $this->withToken($token)->withHeader('X-Tenant-Id', $a)->getJson('/api/v1/workspace')->assertOk()->assertJsonPath('data.id', $a);
});

it('asks for a second factor on the API when 2FA is confirmed', function () {
    $user = makeUser('tfa@example.com');
    $user->forceFill([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['recovery-one'])),
        'two_factor_confirmed_at' => now(),
    ])->save();

    $login = fn (array $extra = []) => $this->postJson('/api/v1/auth/login', ['email' => 'tfa@example.com', 'password' => PASSWORD, 'device_name' => 'p'] + $extra);
    $login()->assertStatus(422)->assertJsonPath('code', 'two_factor_required');
    $login(['code' => '000000'])->assertStatus(422);
    $login(['recovery_code' => 'recovery-one'])->assertOk();
    $login(['recovery_code' => 'recovery-one'])->assertStatus(422); // used codes are replaced
});
