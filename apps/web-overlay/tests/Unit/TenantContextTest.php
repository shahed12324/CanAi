<?php

use App\Tenancy\TenantContext;
use Illuminate\Database\ConnectionInterface;

$valid = '01hzzzzzzzzzzzzzzzzzzzzzzz';

it('validates tenant ids strictly', function () use ($valid) {
    expect(TenantContext::isValidId($valid))->toBeTrue()
        ->and(TenantContext::isValidId($valid.'x'))->toBeFalse()
        ->and(TenantContext::isValidId(substr($valid, 1)))->toBeFalse()
        ->and(TenantContext::isValidId("$valid\n"))->toBeFalse()
        ->and(TenantContext::isValidId("' or 1=1 --"))->toBeFalse();
});

it('refuses to set an invalid id and never touches the database', function () {
    $db = Mockery::mock(ConnectionInterface::class);
    $db->shouldNotReceive('select');
    (new TenantContext($db))->set('bad');
})->throws(InvalidArgumentException::class);

it('sets and clears the session setting', function () use ($valid) {
    $db = Mockery::mock(ConnectionInterface::class);
    $db->shouldReceive('select')->once()->with("select set_config('app.tenant_id', ?, false)", [$valid]);
    $db->shouldReceive('select')->once()->with("select set_config('app.tenant_id', '', false)");
    $ctx = new TenantContext($db);
    $ctx->set($valid);
    expect($ctx->id())->toBe($valid);
    $ctx->clear();
    expect($ctx->id())->toBeNull();
});

it('restores the previous tenant after run(), even when the callback throws', function () use ($valid) {
    $other = '02hzzzzzzzzzzzzzzzzzzzzzzz';
    $db = Mockery::mock(ConnectionInterface::class);
    $db->shouldReceive('select')->andReturn([]);
    $ctx = new TenantContext($db);
    $ctx->set($valid);
    try {
        $ctx->run($other, fn () => throw new RuntimeException('boom'));
    } catch (RuntimeException) {
    }
    expect($ctx->id())->toBe($valid);
});

it('fails when no tenant is active', function () {
    (new TenantContext(Mockery::mock(ConnectionInterface::class)))->idOrFail();
})->throws(RuntimeException::class);
