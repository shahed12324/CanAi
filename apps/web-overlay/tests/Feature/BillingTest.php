<?php

use App\Actions\Tenancy\CreateWorkspace;
use App\Billing\GatewayRegistry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Needs phases up to 10 migrated, `php artisan roles:sync`, and (for the priced tests) the admin DB login configured. Rolls back after each test.
uses(Illuminate\Foundation\Testing\DatabaseTransactions::class);

function billOwner(): array
{
    $u = User::create(['name' => 'Bill Owner', 'email' => Str::lower(Str::random(10)).'@example.com', 'password' => 'Passw0rd!long']);
    $u->forceFill(['email_verified_at' => now()])->save();

    return [$u, app(CreateWorkspace::class)->organization($u, 'Bill Co')];
}

function pricedPro(): string
{
    $admin = DB::connection('admin');
    $admin->statement("select set_config('app.bypass_rls', 'on', false)");
    $id = trim((string) $admin->table('plans')->where('code', 'pro')->value('id'));
    $admin->table('plan_prices')->updateOrInsert(['plan_id' => $id, 'currency' => 'BDT', 'interval' => 'month'], ['amount' => '1000.00']);

    return $id;
}

it('shows the billing page to the owner', function () {
    [$u, $t] = billOwner();
    $this->actingAs($u)->withSession(['tenant_id' => $t])->get('/app/billing')->assertOk()->assertSee('Billing and subscription');
});

it('accepts gateway posts without a session or CSRF token and never trusts them', function () {
    config(['billing.demo' => true]);
    app()->forgetInstance(GatewayRegistry::class);
    $this->post('/billing/return/demo', ['ref' => 'demo_'.str_repeat('a', 26), 'result' => 'paid', 'sig' => 'x'])->assertStatus(303);   // not 419
    $this->post('/billing/ipn/sslcommerz', ['tran_id' => str_repeat('a', 26)])->assertStatus(404);                                      // unknown order
});

it('buys a plan through the demo gateway, once, with a VAT invoice', function () {
    config(['billing.demo' => true]);
    app()->forgetInstance(GatewayRegistry::class);
    $pro = pricedPro();
    [$u, $t] = billOwner();
    $s = $this->actingAs($u)->withSession(['tenant_id' => $t]);

    $s->get("/app/billing/checkout?kind=subscription&ref={$pro}&interval=month&currency=BDT")->assertOk()->assertSee('VAT');
    $r = $s->post('/app/billing/pay', ['kind' => 'subscription', 'ref' => $pro, 'interval' => 'month', 'currency' => 'BDT', 'gateway' => 'demo']);
    $r->assertRedirect();
    $orderId = trim((string) DB::table('orders')->where('tenant_id', $t)->value('id'));
    expect(DB::table('orders')->where('id', $orderId)->value('total_minor'))->toBe(115000);

    $page = $s->get("/app/billing/demo/{$orderId}")->assertOk();
    preg_match('/name="sig" value="([0-9a-f]{64})"/', $page->getContent(), $m);
    $pay = ['ref' => 'demo_'.$orderId, 'result' => 'paid', 'sig' => $m[1]];

    $s->post('/billing/return/demo', $pay)->assertRedirect(route('billing.index', ['result' => 'applied']));
    $s->post('/billing/return/demo', $pay)->assertRedirect(route('billing.index', ['result' => 'duplicate']));   // a repeat changes nothing
    expect(DB::table('orders')->where('id', $orderId)->value('status'))->toBe('paid');
    expect(DB::table('invoices')->where('order_id', $orderId)->count())->toBe(1);
    expect(DB::table('subscriptions')->where('tenant_id', $t)->value('status'))->toBe('active');
});

it('refuses a forged demo confirmation', function () {
    config(['billing.demo' => true]);
    app()->forgetInstance(GatewayRegistry::class);
    $pro = pricedPro();
    [$u, $t] = billOwner();
    $s = $this->actingAs($u)->withSession(['tenant_id' => $t]);
    $s->post('/app/billing/pay', ['kind' => 'subscription', 'ref' => $pro, 'interval' => 'month', 'currency' => 'BDT', 'gateway' => 'demo']);
    $orderId = trim((string) DB::table('orders')->where('tenant_id', $t)->value('id'));

    $s->post('/billing/return/demo', ['ref' => 'demo_'.$orderId, 'result' => 'paid', 'sig' => str_repeat('0', 64)])->assertRedirect(route('billing.index', ['result' => 'unverified']));
    expect(DB::table('orders')->where('id', $orderId)->value('status'))->toBe('pending');
    expect(DB::table('subscriptions')->where('tenant_id', $t)->count())->toBe(0);
});

it('does not let a tampered amount or gateway through the checkout form', function () {
    config(['billing.demo' => true]);
    app()->forgetInstance(GatewayRegistry::class);
    $pro = pricedPro();
    [$u, $t] = billOwner();
    $s = $this->actingAs($u)->withSession(['tenant_id' => $t]);
    $s->post('/app/billing/pay', ['kind' => 'subscription', 'ref' => $pro, 'interval' => 'month', 'currency' => 'BDT', 'gateway' => 'demo', 'total_minor' => 1, 'amount' => 1]);
    expect(DB::table('orders')->where('tenant_id', $t)->value('total_minor'))->toBe(115000);            // the price comes from the catalog, never the form
    $s->post('/app/billing/pay', ['kind' => 'subscription', 'ref' => $pro, 'interval' => 'month', 'currency' => 'BDT', 'gateway' => 'free'])->assertSessionHasErrors('billing');   // "free" only when the total is really zero
});

it('keeps invoices private to the workspace', function () {
    [$u2, $t2] = billOwner();
    $this->actingAs($u2)->withSession(['tenant_id' => $t2])->get('/app/billing/invoices/'.CreateWorkspace::newId())->assertNotFound();
});
