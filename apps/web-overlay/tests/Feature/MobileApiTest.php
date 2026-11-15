<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Needs phases up to 11 migrated and `php artisan roles:sync`. Rolls back after each test. Talks to the real /api/v1/mobile routes.
uses(Illuminate\Foundation\Testing\DatabaseTransactions::class);

const MOBILE_PASSWORD = 'Passw0rd!long';

/** @return array{0: User, 1: string, 2: array<string, string>} user, tenant id, headers with a real bearer token */
function mobileLogin(string $role = 'Owner'): array
{
    $u = User::create(['name' => 'Mob', 'email' => Str::lower(Str::random(10)).'@example.com', 'password' => MOBILE_PASSWORD]);
    $u->forceFill(['email_verified_at' => now()])->save();
    $t = app(App\Actions\Tenancy\CreateWorkspace::class)->organization($u, 'Mob Co');
    if ($role !== 'Owner') {
        App\Tenancy\TenantRoles::assign($u, $t, $role);
    }
    $res = test()->postJson('/api/v1/auth/login', ['email' => $u->email, 'password' => MOBILE_PASSWORD, 'device_name' => 'Pixel'])->assertOk();

    return [$u, $t, ['Authorization' => 'Bearer '.$res->json('data.token'), 'X-Tenant-Id' => $t]];
}

function mobilePending(string $t): string
{
    $acc = App\Actions\Tenancy\CreateWorkspace::newId();
    $camp = App\Actions\Tenancy\CreateWorkspace::newId();
    $vault = app(App\Ai\CredentialVault::class)->encrypt('pw');
    DB::table('email_accounts')->insert(['id' => $acc, 'tenant_id' => $t, 'name' => 's', 'from_email' => 'me@agency.test', 'host' => 'smtp.agency.test', 'port' => 465, 'username' => 'u', 'secret_ct' => $vault['cipher'], 'key_version' => $vault['version']]);
    DB::table('outreach_campaigns')->insert(['id' => $camp, 'tenant_id' => $t, 'account_id' => $acc, 'name' => 'c', 'subject_tpl' => 's', 'body_tpl' => 'b', 'status' => 'active']);
    $id = App\Actions\Tenancy\CreateWorkspace::newId();
    DB::table('outreach_messages')->insert(['id' => $id, 'tenant_id' => $t, 'campaign_id' => $camp, 'to_email' => 'x@client.test', 'subject' => 'Hello', 'body' => 'Body']);

    return $id;
}

it('refuses every mobile route without a token', function () {
    $this->getJson('/api/v1/mobile/bootstrap')->assertUnauthorized();
    $this->getJson('/api/v1/mobile/approvals')->assertUnauthorized();
});

it('boots with the workspaces and what the user may do', function () {
    [$u, $t, $h] = mobileLogin();
    $r = $this->withHeaders($h)->getJson('/api/v1/mobile/bootstrap')->assertOk();
    expect($r->json('data.workspaces.0.id'))->toBe($t);
    expect($r->json('data.can.approve'))->toBeTrue();
    expect($r->json('data.can.emergency'))->toBeTrue();
});

it('needs the phone registered before approvals or a PIN', function () {
    [$u, $t, $h] = mobileLogin();
    $this->withHeaders($h)->postJson('/api/v1/mobile/step-up', ['pin' => '482915'])->assertStatus(428)->assertJsonPath('code', 'device_not_registered');
});

it('sets a PIN only with the account password and refuses weak ones', function () {
    [$u, $t, $h] = mobileLogin();
    $this->withHeaders($h)->postJson('/api/v1/mobile/device', ['name' => 'Pixel', 'platform' => 'android'])->assertOk();
    $this->withHeaders($h)->postJson('/api/v1/mobile/pin', ['pin' => '482915', 'password' => 'wrong'])->assertStatus(422)->assertJsonPath('code', 'bad_password');
    $this->withHeaders($h)->postJson('/api/v1/mobile/pin', ['pin' => '123456', 'password' => MOBILE_PASSWORD])->assertStatus(422)->assertJsonPath('code', 'weak_pin');
    $this->withHeaders($h)->postJson('/api/v1/mobile/pin', ['pin' => '482915', 'password' => MOBILE_PASSWORD])->assertOk();
});

it('will not approve without a PIN confirmation, then approves once and says so on a repeat', function () {
    [$u, $t, $h] = mobileLogin();
    $mid = mobilePending($t);
    $this->withHeaders($h)->postJson('/api/v1/mobile/device', ['name' => 'Pixel', 'platform' => 'android'])->assertOk();
    $this->withHeaders($h)->postJson('/api/v1/mobile/pin', ['pin' => '482915', 'password' => MOBILE_PASSWORD])->assertOk();
    $this->withHeaders($h)->getJson('/api/v1/mobile/approvals')->assertOk()->assertJsonPath('data.0.ref', $mid);

    $url = "/api/v1/mobile/approvals/outreach_message/{$mid}";
    $this->withHeaders($h)->postJson($url, ['decision' => 'approve'])->assertStatus(403)->assertJsonPath('code', 'step_up_required');
    expect(DB::table('outreach_messages')->where('id', $mid)->value('status'))->toBe('pending_approval');

    $this->withHeaders($h)->postJson('/api/v1/mobile/step-up', ['pin' => '482916'])->assertStatus(422)->assertJsonPath('code', 'bad_pin');
    $this->withHeaders($h)->postJson('/api/v1/mobile/step-up', ['pin' => '482915'])->assertOk()->assertJsonPath('data.expires_in', 300);
    $this->withHeaders($h)->postJson($url, ['decision' => 'approve'])->assertOk()->assertJsonPath('data.status', 'approved');
    $this->withHeaders($h)->postJson($url, ['decision' => 'approve'])->assertStatus(409)->assertJsonPath('code', 'not_pending');
});

it('locks the PIN after five wrong tries', function () {
    [$u, $t, $h] = mobileLogin();
    $this->withHeaders($h)->postJson('/api/v1/mobile/device', ['name' => 'Pixel', 'platform' => 'ios'])->assertOk();
    $this->withHeaders($h)->postJson('/api/v1/mobile/pin', ['pin' => '482915', 'password' => MOBILE_PASSWORD])->assertOk();
    foreach (range(1, 4) as $_) {
        $this->withHeaders($h)->postJson('/api/v1/mobile/step-up', ['pin' => '000001'])->assertStatus(422);
    }
    $this->withHeaders($h)->postJson('/api/v1/mobile/step-up', ['pin' => '000001'])->assertStatus(423);
    $this->withHeaders($h)->postJson('/api/v1/mobile/step-up', ['pin' => '482915'])->assertStatus(423);
});

it('stops the workspace with one tap and needs the PIN to resume', function () {
    [$u, $t, $h] = mobileLogin();
    $mid = mobilePending($t);
    $this->withHeaders($h)->postJson('/api/v1/mobile/device', ['name' => 'Pixel', 'platform' => 'android'])->assertOk();
    $this->withHeaders($h)->postJson('/api/v1/mobile/pin', ['pin' => '482915', 'password' => MOBILE_PASSWORD])->assertOk();
    $this->withHeaders($h)->postJson('/api/v1/mobile/emergency', ['action' => 'stop', 'reason' => 'test'])->assertOk()->assertJsonPath('data.active', true);
    $this->withHeaders($h)->postJson('/api/v1/mobile/step-up', ['pin' => '482915'])->assertOk();
    $this->withHeaders($h)->postJson("/api/v1/mobile/approvals/outreach_message/{$mid}", ['decision' => 'approve'])->assertStatus(423)->assertJsonPath('code', 'emergency_stop');
    $this->artisan('outreach:run')->assertSuccessful();                              // nothing is claimed while stopped
    $this->withHeaders($h)->postJson('/api/v1/mobile/emergency', ['action' => 'resume'])->assertOk()->assertJsonPath('data.active', false);
});

it('lets a Member look but not approve or stop', function () {
    [$u, $t, $h] = mobileLogin('Member');
    $mid = mobilePending($t);
    $this->withHeaders($h)->postJson('/api/v1/mobile/device', ['name' => 'Pixel', 'platform' => 'android'])->assertOk();
    $this->withHeaders($h)->getJson('/api/v1/mobile/approvals')->assertOk();
    $this->withHeaders($h)->postJson("/api/v1/mobile/approvals/outreach_message/{$mid}", ['decision' => 'approve'])->assertForbidden();
    $this->withHeaders($h)->postJson('/api/v1/mobile/emergency', ['action' => 'stop'])->assertForbidden();
    $this->withHeaders($h)->getJson('/api/v1/mobile/health')->assertForbidden();
});

it('does not let one workspace use another workspace', function () {
    [$u, $t, $h] = mobileLogin();
    [$u2, $t2] = mobileLogin();
    $this->withHeaders(['X-Tenant-Id' => $t2] + $h)->getJson('/api/v1/mobile/dashboard')->assertForbidden();
});

it('forgets the phone when the token is revoked on logout', function () {
    [$u, $t, $h] = mobileLogin();
    $this->withHeaders($h)->postJson('/api/v1/mobile/device', ['name' => 'Pixel', 'platform' => 'android', 'push_token' => 'ExponentPushToken[abcdefghij]'])->assertOk();
    $this->withHeaders($h)->postJson('/api/v1/auth/logout')->assertOk();
    expect(DB::table('mobile_devices')->where('user_id', $u->id)->whereNull('revoked_at')->count())->toBe(0);
});
