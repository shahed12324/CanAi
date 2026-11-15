<?php

use App\Actions\Tenancy\CreateWorkspace;
use App\Models\User;
use App\Outreach\MailTransport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FakeMailTransport;

// Needs phases up to 9b migrated and `php artisan roles:sync`. Rolls back after each test.
uses(Illuminate\Foundation\Testing\DatabaseTransactions::class);

function outOwner(): array
{
    $u = User::create(['name' => 'Out Owner', 'email' => Str::lower(Str::random(10)).'@example.com', 'password' => 'Passw0rd!long']);
    $u->forceFill(['email_verified_at' => now()])->save();

    return [$u, app(CreateWorkspace::class)->organization($u, 'Out Co')];
}

function outSetup(string $t): array
{
    $acc = CreateWorkspace::newId();
    $camp = CreateWorkspace::newId();
    $vault = app(App\Ai\CredentialVault::class)->encrypt('pw');
    DB::table('email_accounts')->insert(['id' => $acc, 'tenant_id' => $t, 'name' => 's', 'from_email' => 'me@agency.test', 'host' => 'smtp.agency.test', 'port' => 465, 'username' => 'u', 'secret_ct' => $vault['cipher'], 'key_version' => $vault['version'], 'warmup_started_on' => now()->subDays(30)->toDateString()]);
    DB::table('outreach_campaigns')->insert(['id' => $camp, 'tenant_id' => $t, 'account_id' => $acc, 'name' => 'c', 'subject_tpl' => 'Hi {{domain}}', 'body_tpl' => 'Hello {{client_name}}', 'status' => 'active']);
    $c = CreateWorkspace::newId();
    $l = CreateWorkspace::newId();
    DB::table('companies')->insert(['id' => $c, 'tenant_id' => $t, 'name' => 'আলো', 'domain' => 'alo.test']);
    DB::table('leads')->insert(['id' => $l, 'tenant_id' => $t, 'company_id' => $c, 'score' => 80]);
    DB::table('contacts')->insert(['id' => CreateWorkspace::newId(), 'tenant_id' => $t, 'company_id' => $c, 'email' => 'boss@alo.test', 'email_status' => 'mx_ok']);

    return [$acc, $camp, $l];
}

it('drafts, needs approval, then sends, and the mail carries a working unsubscribe link', function () {
    $tr = new FakeMailTransport;
    app()->instance(MailTransport::class, $tr);
    [$u, $t] = outOwner();
    [$acc, $camp] = outSetup($t);
    $s = $this->actingAs($u)->withSession(['tenant_id' => $t]);

    $s->post("/app/outreach/campaigns/{$camp}/leads", ['min_score' => 10, 'limit' => 10])->assertRedirect();
    $mid = trim((string) DB::table('outreach_messages')->where('tenant_id', $t)->value('id'));
    expect(DB::table('outreach_messages')->where('id', $mid)->value('status'))->toBe('pending_approval');

    app(App\Outreach\OutreachService::class);                                        // resolves with the real wiring
    $this->artisan('outreach:run')->assertSuccessful();
    expect($tr->sent)->toHaveLength(0);                                              // nothing approved: nothing leaves

    $s->get('/app/outreach/queue')->assertOk()->assertSee('boss@alo.test');
    $s->post('/app/outreach/queue', ['ids' => [$mid], 'action' => 'approve'])->assertRedirect();
    $this->artisan('outreach:run')->assertSuccessful();
    expect($tr->sent)->toHaveLength(1);
    $token = DB::table('outreach_messages')->where('id', $mid)->value('unsub_token');
    expect($tr->sent[0]['mail']['body'])->toContain("/u/{$token}");

    auth()->logout();
    $this->get("/u/{$token}")->assertOk()->assertSee('Unsubscribe');
    expect(DB::table('outreach_suppressions')->where('tenant_id', $t)->count())->toBe(0);   // GET changes nothing
    $this->post("/u/{$token}")->assertOk();                                                   // no CSRF token, like a mail client
    expect(DB::table('outreach_suppressions')->where('tenant_id', $t)->where('email', 'boss@alo.test')->value('reason'))->toBe('unsubscribed');
    $this->post('/u/'.str_repeat('0', 32))->assertOk();                                       // unknown token: same answer
    $this->get('/u/not-a-token')->assertNotFound();
});

it('lets a Member look but not approve, add accounts or suppress', function () {
    [$owner, $t] = outOwner();
    $m = User::create(['name' => 'M', 'email' => Str::lower(Str::random(10)).'@example.com', 'password' => 'Passw0rd!long']);
    $m->forceFill(['email_verified_at' => now()])->save();
    DB::table('tenant_users')->insert(['id' => CreateWorkspace::newId(), 'tenant_id' => $t, 'user_id' => $m->id, 'status' => 'active', 'joined_at' => now()]);
    \App\Tenancy\TenantRoles::assign($m, $t, 'Member');
    $s = $this->actingAs($m)->withSession(['tenant_id' => $t]);
    $s->get('/app/outreach')->assertOk();
    $s->post('/app/outreach/queue', ['ids' => [CreateWorkspace::newId()], 'action' => 'approve'])->assertForbidden();
    $s->post('/app/outreach/suppressions', ['email' => 'x@y.test'])->assertForbidden();
    $s->post('/app/outreach/accounts', ['name' => 'n'])->assertForbidden();
});

it('refuses an SMTP host inside the private network', function () {
    [$u, $t] = outOwner();
    $r = $this->actingAs($u)->withSession(['tenant_id' => $t])->post('/app/outreach/accounts', ['name' => 'x', 'from_email' => 'a@b.test', 'host' => '169.254.169.254', 'port' => 465, 'security' => 'tls', 'username' => 'u', 'password' => 'p', 'daily_limit' => 10]);
    $r->assertSessionHasErrors('host');
    expect(DB::table('email_accounts')->where('tenant_id', $t)->count())->toBe(0);
});

it('connects Gmail: state is checked, the refresh token is stored encrypted, a replay is refused', function () {
    $fake = new Tests\Support\FakeOAuthProvider;
    app()->instance('outreach.oauth', ['gmail' => $fake, 'graph' => $fake]);
    [$u, $t] = outOwner();
    $s = $this->actingAs($u)->withSession(['tenant_id' => $t]);

    $res = $s->post('/app/outreach/oauth/gmail/start')->assertRedirect();
    parse_str(parse_url($res->headers->get('Location'), PHP_URL_QUERY), $q);
    expect($res->headers->get('Location'))->toStartWith('https://idp.test/auth')->and($q['code_challenge_method'])->toBe('S256');
    $state = $q['state'];

    $s->get('/app/outreach/oauth/gmail/callback?code=C&state=WRONG')->assertRedirect(route('outreach.accounts'))->assertSessionHasErrors('oauth');
    expect(DB::table('email_accounts')->where('tenant_id', $t)->count())->toBe(0);
    $s->get("/app/outreach/oauth/gmail/callback?code=C&state={$state}")->assertRedirect(route('outreach.accounts'))->assertSessionHasErrors('oauth');   // the failed try used the state up

    $res = $s->post('/app/outreach/oauth/gmail/start');
    parse_str(parse_url($res->headers->get('Location'), PHP_URL_QUERY), $q2);
    $s->get("/app/outreach/oauth/gmail/callback?code=C&state={$q2['state']}")->assertSessionHasNoErrors();
    $row = DB::table('email_accounts')->where('tenant_id', $t)->first();
    expect($row->provider)->toBe('gmail')->and($row->from_email)->toBe('me@gmail.test')->and($row->refresh_ct === "REFRESH-SECRET")->toBeFalse();
    expect(app(App\Ai\CredentialVault::class)->decrypt($row->refresh_ct, $row->key_version))->toBe('REFRESH-SECRET');
    $s->get("/app/outreach/oauth/gmail/callback?code=C&state={$q2['state']}")->assertSessionHasErrors('oauth');       // replay
    expect(count($fake->exchanged))->toBe(1);
});

it('stores nothing when the provider refuses the grant', function () {
    $fake = new Tests\Support\FakeOAuthProvider;
    $fake->fail = 'google_scope_missing';
    app()->instance('outreach.oauth', ['gmail' => $fake, 'graph' => $fake]);
    [$owner, $t] = outOwner();
    $s = $this->actingAs($owner)->withSession(['tenant_id' => $t]);
    $res = $s->post('/app/outreach/oauth/gmail/start');
    parse_str(parse_url($res->headers->get('Location'), PHP_URL_QUERY), $q);
    $s->get("/app/outreach/oauth/gmail/callback?code=C&state={$q['state']}")->assertSessionHasErrors('oauth');
    expect(DB::table('email_accounts')->where('tenant_id', $t)->count())->toBe(0);
    $s->get('/app/outreach/accounts')->assertOk();
});
