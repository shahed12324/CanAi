<?php

use App\Actions\Tenancy\CreateWorkspace;
use App\Chat\ChatEngine;
use App\Chat\ChatStore;
use App\Chat\ModelPicker;
use App\Credits\CreditService;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FakeEngine;

// Needs phase5 + phase6 schema and at least one catalog model (add one in /admin/providers). Rolls back after each test.
uses(Illuminate\Foundation\Testing\DatabaseTransactions::class);

function chatUser(): array
{
    $u = User::create(['name' => 'Chatter', 'email' => Str::lower(Str::random(10)).'@example.com', 'password' => 'Passw0rd!long']);
    $u->forceFill(['email_verified_at' => now()])->save();

    return [$u, app(CreateWorkspace::class)->organization($u, 'Chat Co')];
}

function realModel(): ?array
{
    $m = DB::selectOne('select m.id, m.provider_id, m.model_key, m.display_name from ai_models m limit 1');

    return $m === null ? null : ['id' => $m->id, 'provider_id' => $m->provider_id, 'model_key' => $m->model_key, 'name' => $m->display_name, 'max_output' => 500, 'context_window' => 8000, 'credit_multiplier' => 1.0];
}

function fakeAi(array $events): void
{
    $m = realModel();
    if ($m === null) {
        test()->markTestSkipped('no catalog model');
    }
    app()->instance(ModelPicker::class, new class($m) implements ModelPicker
    {
        public function __construct(private array $m) {}

        public function pick(string $t, ?string $p): ?array
        {
            return $this->m;
        }
    });
    app()->instance(ChatEngine::class, new FakeEngine($events));
}

it('streams an answer end to end, saves it and charges credits', function () {
    config(['credits.signup_grant' => '100']);
    fakeAi([['text' => 'Hello '], ['text' => 'world', 'input_tokens' => 10, 'output_tokens' => 20]]);
    [$user, $tenant] = chatUser();

    $res = $this->actingAs($user)->withSession(['tenant_id' => $tenant])->post('/app/chat/send', ['text' => 'Say hello']);
    $body = $res->streamedContent();

    expect($body)->toContain('event: meta')->toContain('event: delta')->toContain('event: done');
    $msgs = DB::table('messages')->where('tenant_id', $tenant)->orderBy('seq')->get();
    expect($msgs)->toHaveCount(2)
        ->and($msgs[0]->role)->toBe('user')
        ->and($msgs[1]->content)->toBe('Hello world')
        ->and($msgs[1]->status)->toBe('complete');
    // 10 in x 1/1k + 20 out x 3/1k = 0.07
    expect($msgs[1]->credits_charged)->toBe('0.0700');
    expect(app(CreditService::class)->wallet($tenant)->balance)->toBe('99.9300');
    expect(DB::table('usage_records')->where('tenant_id', $tenant)->where('source', 'chat')->count())->toBe(1);
    expect(DB::table('credit_reservations')->where('tenant_id', $tenant)->where('status', 'held')->count())->toBe(0);
});

it('refuses politely with zero credits and never calls the AI', function () {
    config(['credits.signup_grant' => '0']);
    fakeAi([['text' => 'never']]);
    [$user, $tenant] = chatUser();

    $body = $this->actingAs($user)->withSession(['tenant_id' => $tenant])->post('/app/chat/send', ['text' => 'hi'])->streamedContent();

    expect($body)->toContain('event: error')->toContain('insufficient_credits')->not->toContain('never');
});

it('keeps conversations private to their owner and their workspace', function () {
    config(['credits.signup_grant' => '100']);
    fakeAi([['text' => 'secret answer', 'input_tokens' => 1, 'output_tokens' => 1]]);
    [$a, $tenantA] = chatUser();
    [$b, $tenantB] = chatUser();

    $this->actingAs($a)->withSession(['tenant_id' => $tenantA])->post('/app/chat/send', ['text' => 'private question'])->streamedContent();
    $convId = DB::table('conversations')->where('tenant_id', $tenantA)->value('id');

    $this->actingAs($a)->withSession(['tenant_id' => $tenantA])->get("/app/chat/{$convId}")->assertOk()->assertSee('private question');
    $this->actingAs($b)->withSession(['tenant_id' => $tenantB])->get("/app/chat/{$convId}")->assertNotFound();
    $this->actingAs($b)->withSession(['tenant_id' => $tenantB])->delete("/app/chat/{$convId}")->assertNotFound();
    $this->actingAs($b)->withSession(['tenant_id' => $tenantB])->post("/app/chat/{$convId}/regenerate")->streamedContent();
    expect(DB::table('messages')->where('tenant_id', $tenantB)->count())->toBe(0);
});

it('accepts text attachments and rejects other types', function () {
    config(['credits.signup_grant' => '100']);
    fakeAi([['text' => 'ok', 'input_tokens' => 5, 'output_tokens' => 5]]);
    [$user, $tenant] = chatUser();
    $s = $this->actingAs($user)->withSession(['tenant_id' => $tenant]);

    $s->post('/app/chat/send', ['text' => 'read this', 'files' => [UploadedFile::fake()->createWithContent('notes.txt', "line one\nline two")]])->streamedContent();
    expect(DB::table('chat_attachments')->where('tenant_id', $tenant)->value('extracted_text'))->toBe("line one\nline two");

    $s->post('/app/chat/send', ['text' => 'x', 'files' => [UploadedFile::fake()->create('virus.exe', 10)]])->assertSessionHasErrors('files.0');
});

it('shows the chat page and the dashboard overview', function () {
    config(['credits.signup_grant' => '100']);
    [$user, $tenant] = chatUser();
    $s = $this->actingAs($user)->withSession(['tenant_id' => $tenant]);

    $s->get('/app/chat')->assertOk()->assertSee('New chat');
    $s->get('/app')->assertOk()->assertSee('Usable credits');
    $s->get('/settings/profile')->assertOk();
    $s->put('/settings/profile', ['name' => 'New Name', 'locale' => 'en', 'timezone' => 'Asia/Dhaka'])->assertRedirect();
    expect($user->fresh()->name)->toBe('New Name');
    $s->put('/settings/profile', ['name' => 'X', 'locale' => 'xx', 'timezone' => 'Nowhere/Land'])->assertSessionHasErrors(['locale', 'timezone', 'name']);
});
