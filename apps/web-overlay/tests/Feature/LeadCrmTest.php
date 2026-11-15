<?php

use App\Actions\Tenancy\CreateWorkspace;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Needs phase8 + phase8b migrated and `php artisan roles:sync`. Rolls back after each test.
uses(Illuminate\Foundation\Testing\DatabaseTransactions::class);

function crmOwner(): array
{
    $u = User::create(['name' => 'Crm Owner', 'email' => Str::lower(Str::random(10)).'@example.com', 'password' => 'Passw0rd!long']);
    $u->forceFill(['email_verified_at' => now()])->save();

    return [$u, app(CreateWorkspace::class)->organization($u, 'Crm Co')];
}

function crmLead(string $tenant, string $domain = 'alo.com'): string
{
    $c = CreateWorkspace::newId();
    $l = CreateWorkspace::newId();
    DB::table('companies')->insert(['id' => $c, 'tenant_id' => $tenant, 'name' => 'Alo', 'domain' => $domain]);
    DB::table('leads')->insert(['id' => $l, 'tenant_id' => $tenant, 'company_id' => $c, 'score' => 60]);

    return $l;
}

it('moves a lead on the board and keeps the history', function () {
    [$u, $t] = crmOwner();
    $id = crmLead($t);
    $s = $this->actingAs($u)->withSession(['tenant_id' => $t]);
    $s->get('/app/leads/board')->assertOk()->assertSee('alo.com');
    $s->post("/app/leads/{$id}/stage", ['stage' => 'qualified', 'back_to_board' => 1])->assertRedirect('/app/leads/board');
    expect(DB::table('leads')->where('id', $id)->value('stage'))->toBe('qualified');
    expect(DB::table('lead_activities')->where('lead_id', $id)->where('kind', 'stage')->count())->toBe(1);
    $s->post("/app/leads/{$id}/stage", ['stage' => 'banana'])->assertSessionHasErrors('crm');
});

it('adds notes, tasks, tags and a follow-up', function () {
    [$u, $t] = crmOwner();
    $id = crmLead($t);
    $s = $this->actingAs($u)->withSession(['tenant_id' => $t]);
    $s->post("/app/leads/{$id}/notes", ['body' => 'কল করেছি'])->assertRedirect();
    $s->post("/app/leads/{$id}/tasks", ['title' => 'প্রস্তাব পাঠাও', 'due' => now()->addDay()->format('Y-m-d\TH:i')])->assertRedirect();
    $s->post("/app/leads/{$id}/tags", ['tags' => 'VIP, ঢাকা'])->assertRedirect();
    $s->post("/app/leads/{$id}/follow-up", ['date' => now()->addDays(3)->format('Y-m-d')])->assertRedirect();
    $s->post("/app/leads/{$id}/follow-up", ['date' => '2020-01-01'])->assertSessionHasErrors('crm');
    $s->get("/app/leads/{$id}")->assertOk()->assertSee('কল করেছি')->assertSee('প্রস্তাব পাঠাও')->assertSee('vip');
    $task = DB::table('crm_tasks')->where('lead_id', $id)->value('id');
    $s->post("/app/leads/tasks/{$task}/done")->assertRedirect();
    expect(DB::table('crm_tasks')->where('id', $task)->value('done_at'))->not->toBeNull();
    $s->get('/app/leads/tasks')->assertOk();
});

it('keeps one workspace out of another workspace\'s leads', function () {
    [$a, $ta] = crmOwner();
    [$b, $tb] = crmOwner();
    $id = crmLead($ta);
    $sb = $this->actingAs($b)->withSession(['tenant_id' => $tb]);
    $sb->get("/app/leads/{$id}")->assertNotFound();
    $sb->post("/app/leads/{$id}/notes", ['body' => 'x'])->assertNotFound();
    $sb->post("/app/leads/{$id}/stage", ['stage' => 'won'])->assertNotFound();
    expect(DB::table('lead_activities')->where('lead_id', $id)->count())->toBe(0);
});

it('a viewer-only member can look but not write', function () {
    [$owner, $t] = crmOwner();
    $id = crmLead($t);
    $m = User::create(['name' => 'M', 'email' => Str::lower(Str::random(10)).'@example.com', 'password' => 'Passw0rd!long']);
    $m->forceFill(['email_verified_at' => now()])->save();
    DB::table('tenant_users')->insert(['id' => CreateWorkspace::newId(), 'tenant_id' => $t, 'user_id' => $m->id, 'status' => 'active', 'joined_at' => now()]);
    \App\Tenancy\TenantRoles::assign($m, $t, 'Member');
    $s = $this->actingAs($m)->withSession(['tenant_id' => $t]);
    $s->get('/app/leads/board')->assertOk();
    $s->get("/app/leads/{$id}")->assertOk();
    $s->post("/app/leads/{$id}/notes", ['body' => 'x'])->assertForbidden();
    $s->post("/app/leads/{$id}/stage", ['stage' => 'won'])->assertForbidden();
    $s->get('/app/leads/import')->assertForbidden();
});

it('imports a CSV without duplicates and exports it back safely', function () {
    [$u, $t] = crmOwner();
    $s = $this->actingAs($u)->withSession(['tenant_id' => $t]);
    $csv = "website,name,email,phone,tags,stage\nalo.com,=cmd|evil,info@alo.com,01712345678,vip,qualified\nbeta.com.bd,Beta,,,,\nnot a site,X,,,,\n";
    $file = fn () => UploadedFile::fake()->createWithContent('leads.csv', $csv);
    $s->post('/app/leads/import', ['csv' => $file()])->assertRedirect('/app/leads');
    $s->post('/app/leads/import', ['csv' => $file()])->assertRedirect('/app/leads');              // again: nothing doubles
    expect(DB::table('leads')->where('tenant_id', $t)->count())->toBe(2);
    expect(DB::table('contacts')->where('tenant_id', $t)->count())->toBe(2);
    expect(DB::table('leads')->where('tenant_id', $t)->where('stage', 'qualified')->count())->toBe(1);

    $out = $s->get('/app/leads/export')->assertOk()->streamedContent();
    expect($out)->toContain('+8801712345678')->toContain("'=cmd|evil");
    $s->post('/app/leads/import', ['csv' => UploadedFile::fake()->createWithContent('x.csv', "name\nA\n")])->assertSessionHasErrors('csv');
});
