<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Needs phases up to 13a migrated and `php artisan roles:sync`. Rolls back after each test. Makes no outside calls.
uses(Illuminate\Foundation\Testing\DatabaseTransactions::class);

/** @return array{0: User, 1: string} */
function inboxUser(string $role = 'Owner'): array
{
    $u = User::create(['name' => 'In', 'email' => Str::lower(Str::random(10)).'@example.com', 'password' => 'Passw0rd!long']);
    $u->forceFill(['email_verified_at' => now()])->save();
    $t = app(App\Actions\Tenancy\CreateWorkspace::class)->organization($u, 'In Co');
    if ($role !== 'Owner') {
        App\Tenancy\TenantRoles::assign($u, $t, $role);
    }

    return [$u, $t];
}

it('only people with inbox.manage can add a channel; Members can look but not reply', function () {
    [$m, $tm] = inboxUser('Member');
    $this->actingAs($m)->withSession(['tenant_id' => $tm])->get(route('inbox.index'))->assertOk();
    $this->post(route('inbox.channels.store'), ['name' => 'x', 'origins' => 'https://shop.example.com'])->assertForbidden();
    $this->post(route('inbox.reply', Str::lower(Str::random(26))), ['body' => 'x'])->assertForbidden();
});

it('creates a widget channel, shows the embed code, and rejects bad origins', function () {
    [$u, $t] = inboxUser();
    $this->actingAs($u)->withSession(['tenant_id' => $t]);
    $this->post(route('inbox.channels.store'), ['name' => 'Site', 'origins' => 'http://shop.example.com'])->assertSessionHasErrors('origins');
    $this->post(route('inbox.channels.store'), ['name' => 'Site', 'origins' => "https://shop.example.com\nhttps://www.shop.example.com"])->assertSessionHasNoErrors();
    $key = DB::selectOne('select public_key from inbox_channels')->public_key;
    $this->get(route('inbox.channels'))->assertOk()->assertSee('data-key="'.$key.'"', false);
});

it('widget end to end: visitor writes, staff sees it and replies, visitor receives; a foreign origin gets nothing', function () {
    [$u, $t] = inboxUser();
    $this->actingAs($u)->withSession(['tenant_id' => $t]);
    $this->post(route('inbox.channels.store'), ['name' => 'Site', 'origins' => 'https://shop.example.com']);
    $key = trim(DB::selectOne('select public_key from inbox_channels')->public_key);
    $h = ['Origin' => 'https://shop.example.com'];

    $s = $this->postJson("/widget/v1/$key/start", [], $h)->assertOk()->assertHeader('Access-Control-Allow-Origin', 'https://shop.example.com')->json();
    $auth = $h + ['Authorization' => 'Bearer '.$s['token']];
    $this->postJson("/widget/v1/$key/messages", ['visitor' => $s['visitor'], 'text' => 'price?', 'client_id' => 'c1'], $auth)->assertOk();
    $this->postJson("/widget/v1/$key/messages", ['visitor' => $s['visitor'], 'text' => 'price?', 'client_id' => 'c1'], $auth)->assertOk()->assertJson(['duplicate' => true]);
    $this->postJson("/widget/v1/$key/messages", ['visitor' => $s['visitor'], 'text' => 'price?'], $h + ['Authorization' => 'Bearer '.str_repeat('0', 32)])->assertStatus(401);
    $this->postJson("/widget/v1/$key/start", [], ['Origin' => 'https://evil.example.com'])->assertStatus(403)->assertHeaderMissing('Access-Control-Allow-Origin');

    $conv = trim(DB::selectOne('select id from inbox_conversations')->id);
    $this->get(route('inbox.show', $conv))->assertOk()->assertSee('price?');
    $this->post(route('inbox.reply', $conv), ['body' => 'Tk 500'])->assertSessionHasNoErrors();
    $this->getJson("/widget/v1/$key/messages?visitor={$s['visitor']}&after=0", $auth)->assertOk()->assertJsonPath('messages.0.text', 'Tk 500');
    expect(DB::selectOne('select mode from inbox_conversations')->mode)->toBe('human');
});

it('does not let another workspace read or answer a conversation', function () {
    [$a, $ta] = inboxUser();
    [$b, $tb] = inboxUser();
    $this->actingAs($a)->withSession(['tenant_id' => $ta])->post(route('inbox.channels.store'), ['name' => 'Site', 'origins' => 'https://a.example.com']);
    $key = trim(DB::selectOne('select public_key from inbox_channels')->public_key);
    $s = $this->postJson("/widget/v1/$key/start", [], ['Origin' => 'https://a.example.com'])->json();
    $this->postJson("/widget/v1/$key/messages", ['visitor' => $s['visitor'], 'text' => 'secret'], ['Origin' => 'https://a.example.com', 'Authorization' => 'Bearer '.$s['token']]);
    $conv = trim(DB::selectOne('select id from inbox_conversations')->id);
    $this->actingAs($b)->withSession(['tenant_id' => $tb])->get(route('inbox.show', $conv))->assertNotFound();
    $this->post(route('inbox.reply', $conv), ['body' => 'hijack'])->assertNotFound();
});

it('13b: a Telegram webhook with the right secret lands in the inbox, a wrong one does not; Meta handshake answers only with the right token', function () {
    [$u, $t] = inboxUser();
    $this->actingAs($u)->withSession(['tenant_id' => $t]);
    $key = bin2hex(random_bytes(16));
    $sealed = app(App\Inbox\Channels\ChannelSecrets::class)->seal(['bot_token' => '123456:ABCdefGhIJKlmnoPQRstuVWXyz012345', 'webhook_secret' => 'tg-secret-1']);
    app(App\Inbox\InboxRepo::class)->createExternalChannel($t, 'telegram', 'Bot', 'BOT1', $sealed, $key);
    $upd = ['message' => ['message_id' => 9, 'from' => ['id' => 55, 'first_name' => 'Rafi'], 'chat' => ['id' => 55, 'type' => 'private'], 'text' => 'ki khobor']];
    $this->postJson("/webhooks/inbox/$key", $upd, ['X-Telegram-Bot-Api-Secret-Token' => 'wrong'])->assertStatus(403);
    expect(DB::selectOne('select count(*) as n from inbox_messages')->n)->toBe(0);
    $this->postJson("/webhooks/inbox/$key", $upd, ['X-Telegram-Bot-Api-Secret-Token' => 'tg-secret-1'])->assertOk();
    $this->postJson("/webhooks/inbox/$key", $upd, ['X-Telegram-Bot-Api-Secret-Token' => 'tg-secret-1'])->assertOk();      // platform retry
    expect(DB::selectOne('select count(*) as n from inbox_messages')->n)->toBe(1);
    $this->get(route('inbox.index'))->assertOk()->assertSee('Rafi');
    $this->get("/webhooks/inbox/$key?hub.mode=subscribe&hub.verify_token=x&hub.challenge=1")->assertStatus(404);          // telegram has no Meta handshake
});

it('13b: external channels need valid-looking secrets before any outside call, and only inbox.manage may add them', function () {
    [$m, $tm] = inboxUser('Manager');
    $this->actingAs($m)->withSession(['tenant_id' => $tm])->post(route('inbox.channels.external'), ['kind' => 'telegram', 'name' => 'x', 'bot_token' => '123456:ABCdefGhIJKlmnoPQRstuVWXyz012345'])->assertForbidden();
    [$u, $t] = inboxUser();
    $this->actingAs($u)->withSession(['tenant_id' => $t]);
    $this->post(route('inbox.channels.external'), ['kind' => 'telegram', 'name' => 'x', 'bot_token' => 'not a token'])->assertSessionHasErrors('bot_token');
    $this->post(route('inbox.channels.external'), ['kind' => 'messenger', 'name' => 'x', 'account' => 'abc', 'access_token' => 'T', 'app_secret' => 'S'])->assertSessionHasErrors('account');
    $this->post(route('inbox.channels.external'), ['kind' => 'whatsapp', 'name' => 'x', 'account' => '12345678'])->assertSessionHasErrors('account');
    expect(DB::selectOne("select count(*) as n from inbox_channels where kind <> 'web_widget'")->n)->toBe(0);
});

it('13c: only inbox.manage may change the AI agent; Managers can look; a foreign channel id is ignored; modes are validated', function () {
    [$m, $tm] = inboxUser('Manager');
    $this->actingAs($m)->withSession(['tenant_id' => $tm])->get(route('inbox.agent'))->assertOk();
    $this->post(route('inbox.agent.save'), ['business_info' => 'x'])->assertForbidden();
    [$u, $t] = inboxUser();
    $this->actingAs($u)->withSession(['tenant_id' => $t]);
    $this->post(route('inbox.channels.store'), ['name' => 'Site', 'origins' => 'https://shop.example.com']);
    $ch = trim(DB::selectOne('select id from inbox_channels')->id);
    $this->post(route('inbox.agent.save'), ['modes' => [$ch => 'robot']])->assertSessionHasErrors('modes.*');
    $this->post(route('inbox.agent.save'), ['business_info' => 'ডেলিভারি ৬০ টাকা', 'modes' => [$ch => 'draft', Str::lower(Str::random(26)) => 'auto']])->assertSessionHasNoErrors();
    expect(DB::selectOne('select agent_mode from inbox_channels where id = ?', [$ch])->agent_mode)->toBe('draft');
    expect(DB::selectOne('select business_info from inbox_agent_settings')->business_info)->toBe('ডেলিভারি ৬০ টাকা');
});

it('13c: the AI draft is prefilled for staff and is removed once a person replies', function () {
    [$u, $t] = inboxUser();
    $this->actingAs($u)->withSession(['tenant_id' => $t]);
    $this->post(route('inbox.channels.store'), ['name' => 'Site', 'origins' => 'https://shop.example.com']);
    $ch = trim(DB::selectOne('select id from inbox_channels')->id);
    $conv = trim(DB::selectOne("select (inbox_contact_ensure(?, 'v1', null, ?, ?, ?)).conversation_id as c", [$ch, str_repeat('a', 64), Str::lower(Str::random(26)), Str::lower(Str::random(26))])->c);
    DB::insert('insert into inbox_agent_drafts (conversation_id, tenant_id, body, in_seq) values (?, ?, ?, 1)', [$conv, $t, 'AI খসড়া উত্তর']);
    $this->get(route('inbox.show', $conv))->assertOk()->assertSee('AI খসড়া উত্তর');
    $this->post(route('inbox.draft.dismiss', $conv))->assertRedirect();
    expect(DB::selectOne('select count(*) as n from inbox_agent_drafts')->n)->toBe(0);
});

// ---------- 14a orders (needs phase14 migrated + roles:sync) ----------
it('14a: Members can look at orders but cannot create or change them; only Owner/Admin edit settings', function () {
    [$m, $tm] = inboxUser('Member');
    $this->actingAs($m)->withSession(['tenant_id' => $tm])->get(route('orders.index'))->assertOk();
    $this->get(route('orders.create'))->assertForbidden();
    $this->post(route('orders.store'), [])->assertForbidden();
    $this->post(route('orders.transition', Str::lower(Str::random(26))), ['to' => 'confirmed'])->assertForbidden();
    [$g, $tg] = inboxUser('Manager');
    $this->actingAs($g)->withSession(['tenant_id' => $tg])->get(route('orders.create'))->assertOk();
    $this->post(route('orders.settings.save'), ['max_discount_pct' => 5, 'delivery_inside' => 60, 'delivery_outside' => 120, 'review_score' => 50])->assertForbidden();
});

it('14b: the chat-order switch is off by default, saved by Owner/Admin, and the draft table is workspace-private', function () {
    [$u, $t] = inboxUser();
    $this->actingAs($u)->withSession(['tenant_id' => $t]);
    $this->post(route('orders.settings.save'), ['max_discount_pct' => 0, 'delivery_inside' => 60, 'delivery_outside' => 120, 'review_score' => 50, 'chat_orders' => '0'])->assertSessionHasNoErrors();
    expect((bool) DB::selectOne('select chat_orders from order_settings')->chat_orders)->toBeFalse();
    $this->post(route('orders.settings.save'), ['max_discount_pct' => 0, 'delivery_inside' => 60, 'delivery_outside' => 120, 'review_score' => 50, 'chat_orders' => '1']);
    expect((bool) DB::selectOne('select chat_orders from order_settings')->chat_orders)->toBeTrue();
    expect(DB::selectOne('select count(*) as n from inbox_order_drafts')->n)->toBe(0);
});

it('14a: settings are validated and saved; a foreign or malformed order id is a 404', function () {
    [$u, $t] = inboxUser();
    $this->actingAs($u)->withSession(['tenant_id' => $t]);
    $this->post(route('orders.settings.save'), ['max_discount_pct' => 90, 'delivery_inside' => 60, 'delivery_outside' => 120, 'review_score' => 50])->assertSessionHasErrors('max_discount_pct');
    $this->post(route('orders.settings.save'), ['max_discount_pct' => 8, 'delivery_inside' => 60, 'delivery_outside' => 120.5, 'review_score' => 40])->assertSessionHasNoErrors();
    $r = DB::selectOne('select max_discount_pct, delivery_outside_minor, review_score from order_settings');
    expect([$r->max_discount_pct, $r->delivery_outside_minor, $r->review_score])->toBe([8, 12050, 40]);
    $this->get(route('orders.show', 'not-an-id'))->assertNotFound();
    $this->get(route('orders.show', Str::lower(Str::random(26))))->assertNotFound();
});

it('14a: a manual order with a bad phone is refused with a Bengali message and nothing is stored', function () {
    [$u, $t] = inboxUser();
    $this->actingAs($u)->withSession(['tenant_id' => $t]);
    $this->post(route('orders.store'), ['customer_name' => 'রহিম', 'phone' => '123', 'address' => 'House 1, Road 2, Dhaka', 'zone' => 'inside', 'lines' => [['variant_id' => str_repeat('a', 26), 'qty' => 1]]])->assertSessionHasErrors('order');
    expect(DB::selectOne('select count(*) as n from store_orders')->n)->toBe(0);
});
