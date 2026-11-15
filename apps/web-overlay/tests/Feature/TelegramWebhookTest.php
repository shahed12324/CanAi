<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/** Needs the real database with phase7 migrated (run by the user / CI). */
beforeEach(function () {
    $this->account = (string) \Illuminate\Support\Str::ulid();
    $this->account = strtolower($this->account);
    DB::connection('admin')->table('channel_accounts')->insert([
        'id' => $this->account, 'channel' => 'telegram', 'name' => 'test', 'token_encrypted' => 'x', 'token_key_version' => 1,
        'webhook_secret_hash' => hash('sha256', 'topsecret'), 'status' => 'active',
    ]);
});

it('rejects a wrong or missing secret with 403 and queues nothing', function () {
    Queue::fake();
    $this->postJson("/webhooks/telegram/{$this->account}", ['update_id' => 1, 'message' => ['text' => 'hi']])->assertForbidden();
    $this->postJson("/webhooks/telegram/{$this->account}", ['update_id' => 1], ['X-Telegram-Bot-Api-Secret-Token' => 'nope'])->assertForbidden();
    Queue::assertNothingPushed();
});

it('accepts a correct secret, queues once, and ignores the duplicate delivery', function () {
    Queue::fake();
    $h = ['X-Telegram-Bot-Api-Secret-Token' => 'topsecret'];
    $body = ['update_id' => 777, 'message' => ['text' => 'hi', 'from' => ['id' => 5], 'chat' => ['id' => 5, 'type' => 'private']]];
    $this->postJson("/webhooks/telegram/{$this->account}", $body, $h)->assertOk();
    $this->postJson("/webhooks/telegram/{$this->account}", $body, $h)->assertOk()->assertJson(['duplicate' => true]);
    Queue::assertPushed(\App\Jobs\ProcessChannelUpdate::class, 1);
});

it('a disabled bot refuses even the right secret', function () {
    DB::connection('admin')->table('channel_accounts')->where('id', $this->account)->update(['status' => 'disabled']);
    $this->postJson("/webhooks/telegram/{$this->account}", ['update_id' => 2], ['X-Telegram-Bot-Api-Secret-Token' => 'topsecret'])->assertForbidden();
});
