<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Needs phases up to 12 migrated and `php artisan roles:sync`. Rolls back after each test.
uses(Illuminate\Foundation\Testing\DatabaseTransactions::class);

/** @return array{0: User, 1: string} */
function catalogUser(string $role = 'Owner'): array
{
    $u = User::create(['name' => 'Cat', 'email' => Str::lower(Str::random(10)).'@example.com', 'password' => 'Passw0rd!long']);
    $u->forceFill(['email_verified_at' => now()])->save();
    $t = app(App\Actions\Tenancy\CreateWorkspace::class)->organization($u, 'Cat Co');
    if ($role !== 'Owner') {
        App\Tenancy\TenantRoles::assign($u, $t, $role);
    }

    return [$u, $t];
}

it('walks a product through draft, approval and publish', function () {
    [$u, $t] = catalogUser();
    $this->actingAs($u)->withSession(['tenant_id' => $t]);
    $res = $this->post(route('catalog.store'), ['title' => 'Tee', 'price' => '500', 'description' => 'Soft']);
    $res->assertRedirect();
    $id = DB::selectOne('select id from products where title = ?', ['Tee'])->id;
    $this->post(route('catalog.flow', [trim($id), 'submit']))->assertSessionHas('status');
    $this->post(route('catalog.flow', [trim($id), 'approve']))->assertSessionHas('status');
    $this->post(route('catalog.flow', [trim($id), 'publish']))->assertSessionHas('status');
    expect(DB::selectOne('select status from products where id = ?', [$id])->status)->toBe('published');
});

it('lets a Member look but not change anything', function () {
    [$u, $t] = catalogUser('Member');
    $this->actingAs($u)->withSession(['tenant_id' => $t]);
    $this->get(route('catalog.index'))->assertOk();
    $this->get(route('catalog.create'))->assertForbidden();
    $this->post(route('catalog.store'), ['title' => 'x'])->assertForbidden();
    $this->post(route('catalog.ai'), ['title' => 'x'])->assertForbidden();
    $this->get(route('catalog.import'))->assertForbidden();
});

it('does not show another workspace its products', function () {
    [$a, $ta] = catalogUser();
    [$b, $tb] = catalogUser();
    $this->actingAs($a)->withSession(['tenant_id' => $ta])->post(route('catalog.store'), ['title' => 'Secret', 'price' => '5']);
    $id = trim(DB::selectOne('select id from products where title = ?', ['Secret'])->id);
    $this->actingAs($b)->withSession(['tenant_id' => $tb])->get(route('catalog.edit', $id))->assertNotFound();
    $this->post(route('catalog.flow', [$id, 'approve']))->assertNotFound();
});

it('imports a CSV and leaves every product as a draft', function () {
    [$u, $t] = catalogUser();
    $this->actingAs($u)->withSession(['tenant_id' => $t]);
    $file = Illuminate\Http\UploadedFile::fake()->createWithContent('p.csv', "sku,title,price,stock\nA,Mug,300,5\n");
    $this->post(route('catalog.import.store'), ['file' => $file])->assertRedirect(route('catalog.import'));
    $row = DB::selectOne('select status from products where sku = ?', ['A']);
    expect($row->status)->toBe('draft');
});

it('shows a product in the phone approval list only to people who can see products', function () {
    [$u, $t] = catalogUser();
    $login = $this->postJson('/api/v1/auth/login', ['email' => $u->email, 'password' => 'Passw0rd!long', 'device_name' => 'Pixel'])->assertOk();
    $h = ['Authorization' => 'Bearer '.$login->json('data.token'), 'X-Tenant-Id' => $t];
    $this->actingAs($u)->withSession(['tenant_id' => $t]);
    $this->post(route('catalog.store'), ['title' => 'Phone Tee', 'price' => '500', 'description' => 'Soft']);
    $id = trim(DB::selectOne('select id from products where title = ?', ['Phone Tee'])->id);
    $this->post(route('catalog.flow', [$id, 'submit']));
    $kinds = collect($this->withHeaders($h)->getJson('/api/v1/mobile/approvals')->assertOk()->json('data'))->pluck('kind')->all();
    expect(in_array('product', $kinds, true))->toBeTrue();
});
