<?php

use App\Models\User;
use Illuminate\Support\Str;

// Needs phases up to 12b migrated and `php artisan roles:sync`. Rolls back after each test. Makes no outside calls (every case is refused before any request).
uses(Illuminate\Foundation\Testing\DatabaseTransactions::class);

/** @return array{0: User, 1: string} */
function storeUser(string $role = 'Owner'): array
{
    $u = User::create(['name' => 'St', 'email' => Str::lower(Str::random(10)).'@example.com', 'password' => 'Passw0rd!long']);
    $u->forceFill(['email_verified_at' => now()])->save();
    $t = app(App\Actions\Tenancy\CreateWorkspace::class)->organization($u, 'St Co');
    if ($role !== 'Owner') {
        App\Tenancy\TenantRoles::assign($u, $t, $role);
    }

    return [$u, $t];
}

it('shows the stores page to people who can see the catalog, but only Owner/Admin may connect', function () {
    [$m, $tm] = storeUser('Manager');
    $this->actingAs($m)->withSession(['tenant_id' => $tm])->get(route('catalog.stores'))->assertOk();
    $this->post(route('catalog.stores.store'), ['platform' => 'woocommerce', 'name' => 'x', 'address' => 'https://shop.example.com', 'key' => 'a', 'secret' => 'b'])->assertForbidden();
});

it('refuses private, plain-http and look-alike addresses before any request is made', function () {
    [$u, $t] = storeUser();
    $this->actingAs($u)->withSession(['tenant_id' => $t]);
    foreach ([['woocommerce', 'http://shop.example.com'], ['woocommerce', 'https://127.0.0.1'], ['woocommerce', 'https://localhost'], ['shopify', 'evil.com'], ['shopify', 'a.myshopify.com.evil.com']] as [$p, $a]) {
        $this->post(route('catalog.stores.store'), ['platform' => $p, 'name' => 'x', 'address' => $a, 'key' => 'k', 'secret' => 's', 'token' => 't'])->assertSessionHasErrors('address');
    }
    expect(Illuminate\Support\Facades\DB::selectOne("select count(*) as n from store_connections where platform <> 'native'")->n)->toBe(0);
});

it('does not let one workspace sync or edit another workspace store', function () {
    [$a, $ta] = storeUser();
    [$b, $tb] = storeUser();
    $id = App\Actions\Tenancy\CreateWorkspace::newId();
    app(App\Tenancy\TenantContext::class)->run($tb, fn () => Illuminate\Support\Facades\DB::insert("insert into store_connections (id, tenant_id, platform, name, base_url, credentials_ct, key_version) values (?, ?, 'woocommerce', 'B', 'https://b.example.com', 'c', 1)", [$id, $tb]));
    $this->actingAs($a)->withSession(['tenant_id' => $ta]);
    $this->post(route('catalog.stores.sync', $id))->assertNotFound();
    $this->post(route('catalog.stores.toggle', $id))->assertNotFound();
});
