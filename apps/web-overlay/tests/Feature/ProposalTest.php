<?php

use App\Actions\Tenancy\CreateWorkspace;
use App\Models\User;
use App\Proposals\ProposalEngine;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FakeProposalEngine;

// Needs phase8 + phase8b + phase9a migrated and `php artisan roles:sync`. Rolls back after each test.
uses(Illuminate\Foundation\Testing\DatabaseTransactions::class);

function propOwner(): array
{
    $u = User::create(['name' => 'Prop Owner', 'email' => Str::lower(Str::random(10)).'@example.com', 'password' => 'Passw0rd!long']);
    $u->forceFill(['email_verified_at' => now()])->save();

    return [$u, app(CreateWorkspace::class)->organization($u, 'Prop Co')];
}

function shippedTemplate(string $kind = 'web_design', string $lang = 'bn'): string
{
    return trim((string) DB::table('proposal_templates')->whereNull('tenant_id')->where('kind', $kind)->where('language', $lang)->value('id'));
}

it('builds a proposal from a lead and a shipped template, edits it and downloads it', function () {
    $engine = new FakeProposalEngine;
    app()->instance(ProposalEngine::class, $engine);
    [$u, $t] = propOwner();
    $c = CreateWorkspace::newId();
    $l = CreateWorkspace::newId();
    DB::table('companies')->insert(['id' => $c, 'tenant_id' => $t, 'name' => 'আলো ডিজিটাল', 'domain' => 'alo.com', 'analysis' => json_encode(['gaps' => [['code' => 'no_fb_pixel', 'label' => 'Facebook Pixel নেই', 'service' => 'Meta Ads']]], JSON_UNESCAPED_UNICODE)]);
    DB::table('leads')->insert(['id' => $l, 'tenant_id' => $t, 'company_id' => $c]);
    $s = $this->actingAs($u)->withSession(['tenant_id' => $t]);

    $s->post('/app/brand', ['name' => 'Jarvis Studio', 'primary_color' => '#112233', 'accent_color' => '#aa5500'])->assertRedirect();
    $s->get("/app/proposals/new?lead={$l}")->assertOk()->assertSee('আলো ডিজিটাল');
    $res = $s->post('/app/proposals', ['template_id' => shippedTemplate(), 'lead_id' => $l])->assertRedirect();
    $id = DB::table('proposals')->where('tenant_id', $t)->value('id');
    expect($res->headers->get('Location'))->toContain($id);
    $s->get("/app/proposals/{$id}")->assertOk()->assertSee('Facebook Pixel নেই');
    expect(DB::table('lead_activities')->where('lead_id', $l)->where('kind', 'proposal')->count())->toBe(1);

    $s->post("/app/proposals/{$id}", ['title' => 'New', 'language' => 'bn', 'client_name' => 'আলো', 'sections' => [['heading' => 'ভূমিকা', 'body' => 'হ্যালো']], 'pricing_text' => "Web | 1 | 50,000\nSEO | 12500", 'discount' => '10', 'vat' => '15'])->assertRedirect();
    $s->post("/app/proposals/{$id}", ['title' => '', 'pricing_text' => 'oops'])->assertSessionHasErrors(['title', 'pricing']);
    $s->post("/app/proposals/{$id}/status", ['status' => 'sent'])->assertRedirect();
    expect(DB::table('proposals')->where('id', $id)->value('status'))->toBe('sent');

    $pdf = $s->get("/app/proposals/{$id}/download/pdf")->assertOk();
    expect($pdf->headers->get('Content-Type'))->toBe('application/pdf')->and($engine->calls[0]['payload']['pricing']['totals'][3]['value'])->toBe('৳ ৬৪,৬৮৭.৫০');
    $s->get("/app/proposals/{$id}/download/docx")->assertOk();
    $s->get("/app/proposals/{$id}/download/exe")->assertNotFound();
    $engine->pdfDown = true;
    $s->get("/app/proposals/{$id}/download/pdf")->assertSessionHasErrors('download');
});

it('keeps proposals, portfolio and brand private to their workspace', function () {
    [$a, $ta] = propOwner();
    [$b, $tb] = propOwner();
    $sa = $this->actingAs($a)->withSession(['tenant_id' => $ta]);
    $sa->post('/app/portfolio', ['title' => 'গোপন কাজ', 'url' => 'https://a.test'])->assertRedirect();
    $sa->post('/app/proposals', ['template_id' => shippedTemplate('general', 'en')]);
    $pid = DB::table('proposals')->where('tenant_id', $ta)->value('id');
    $sb = $this->actingAs($b)->withSession(['tenant_id' => $tb]);
    $sb->get('/app/portfolio')->assertOk()->assertDontSee('গোপন কাজ');
    $sb->get("/app/proposals/{$pid}")->assertNotFound();
    $sb->post("/app/proposals/{$pid}", ['title' => 'x'])->assertNotFound();
    $sb->get("/app/proposals/{$pid}/download/pdf")->assertNotFound();
});

it('validates portfolio links and brand logos', function () {
    [$u, $t] = propOwner();
    $s = $this->actingAs($u)->withSession(['tenant_id' => $t]);
    $s->post('/app/portfolio', ['title' => 'x', 'url' => 'javascript:alert(1)'])->assertSessionHasErrors('url');
    $s->post('/app/portfolio', ['title' => '', 'url' => 'https://a.test'])->assertSessionHasErrors('title');
    $s->post('/app/brand', ['name' => 'B', 'primary_color' => 'red', 'accent_color' => '#000000'])->assertSessionHasErrors('primary_color');
    $s->post('/app/brand', ['name' => 'B', 'primary_color' => '#000000', 'accent_color' => '#000000', 'logo' => UploadedFile::fake()->create('logo.svg', 5, 'image/svg+xml')])->assertSessionHasErrors('logo');
    $s->post('/app/brand', ['name' => 'B', 'primary_color' => '#000000', 'accent_color' => '#000000', 'logo' => UploadedFile::fake()->image('logo.png', 40, 40)])->assertRedirect();
    expect(DB::table('brand_kits')->where('tenant_id', $t)->value('logo_b64'))->not->toBeNull();
});

it('a viewer-only member can read but not write or generate', function () {
    [$owner, $t] = propOwner();
    $m = User::create(['name' => 'M', 'email' => Str::lower(Str::random(10)).'@example.com', 'password' => 'Passw0rd!long']);
    $m->forceFill(['email_verified_at' => now()])->save();
    DB::table('tenant_users')->insert(['id' => CreateWorkspace::newId(), 'tenant_id' => $t, 'user_id' => $m->id, 'status' => 'active', 'joined_at' => now()]);
    \App\Tenancy\TenantRoles::assign($m, $t, 'Member');
    $s = $this->actingAs($m)->withSession(['tenant_id' => $t]);
    $s->get('/app/proposals')->assertOk();
    $s->get('/app/proposals/new')->assertForbidden();
    $s->post('/app/proposals', ['template_id' => shippedTemplate()])->assertForbidden();
    $s->post('/app/portfolio', ['title' => 'x'])->assertForbidden();
    $s->post('/app/brand', ['name' => 'B', 'primary_color' => '#000000', 'accent_color' => '#000000'])->assertForbidden();
});
