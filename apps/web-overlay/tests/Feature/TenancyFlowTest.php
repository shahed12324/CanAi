<?php

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Tenancy\CreateWorkspace;
use App\Actions\Tenancy\ManageInvitations;
use App\Exceptions\InvitationException;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// Needs the Phase 2 schema and the jarvis_app role (see ENVIRONMENT_SETUP.md).
uses(Illuminate\Foundation\Testing\DatabaseTransactions::class);

function newUser(string $email, bool $verified = true): User
{
    $u = User::create(['name' => 'T', 'email' => $email, 'password' => 'Passw0rd!long']);
    if ($verified) {
        $u->forceFill(['email_verified_at' => now()])->save();
    }

    return $u;
}

it('registration creates the user and one active personal workspace', function () {
    $user = app(CreateNewUser::class)->create([
        'name' => 'Rahim', 'email' => 'rahim@example.com',
        'password' => 'Str0ngPassword1', 'password_confirmation' => 'Str0ngPassword1',
    ]);
    $rows = DB::table('tenants')->where('owner_id', $user->id)->get();
    expect($rows)->toHaveCount(1)
        ->and($rows[0]->type)->toBe('personal')
        ->and(DB::table('tenant_users')->where('user_id', $user->id)->where('status', 'active')->count())->toBe(1);
});

it('registration rejects weak passwords and duplicate emails', function () {
    $in = fn ($e, $p) => ['name' => 'X', 'email' => $e, 'password' => $p, 'password_confirmation' => $p];
    expect(fn () => app(CreateNewUser::class)->create($in('a@example.com', 'short')))->toThrow(ValidationException::class);
    newUser('dup@example.com');
    expect(fn () => app(CreateNewUser::class)->create($in('DUP@example.com', 'Str0ngPassword1')))->toThrow(ValidationException::class);
});

it('invites and accepts across tenants, and the token cannot be reused', function () {
    $owner = newUser('owner@example.com');
    $tenantId = app(CreateWorkspace::class)->organization($owner, 'Acme');
    $guest = newUser('guest@example.com');

    $token = app(TenantContext::class)->run($tenantId, fn () => app(ManageInvitations::class)->create('guest@example.com', 'Manager', $owner));
    expect(app(ManageInvitations::class)->preview($token)->tenant_id)->toBe($tenantId);

    expect(app(ManageInvitations::class)->accept($guest, $token))->toBe(['tenant_id' => $tenantId, 'role' => 'Manager']);
    expect(fn () => app(ManageInvitations::class)->accept($guest, $token))->toThrow(InvitationException::class);
});

it('rejects an invitation for a different email', function () {
    $owner = newUser('o2@example.com');
    $tenantId = app(CreateWorkspace::class)->organization($owner, 'Beta');
    $other = newUser('other@example.com');
    $token = app(TenantContext::class)->run($tenantId, fn () => app(ManageInvitations::class)->create('someone@example.com', 'Member', $owner));
    expect(fn () => app(ManageInvitations::class)->accept($other, $token))->toThrow(InvitationException::class);
});
