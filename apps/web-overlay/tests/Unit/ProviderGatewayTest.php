<?php

use App\Ai\CredentialVault;
use App\Ai\ProviderCallException;
use App\Ai\ProviderGateway;
use App\Ai\ProviderStore;
use App\Services\AiEngine\AiEngineClient;
use App\Services\AiEngine\RequestSigner;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;

const KEY_PLAIN = 'sk-live-SUPERSECRET-1234567890';

function fakeStore(array $provider, ?array $secret): ProviderStore
{
    return new class($provider, $secret) implements ProviderStore
    {
        public function __construct(private array $p, private ?array $s) {}

        public function provider(string $id): ?array
        {
            return $id === $this->p['id'] ? $this->p : null;
        }

        public function secret(string $providerId, bool $forTest = false): ?array
        {
            return $this->s;
        }
    };
}

function gw(Factory $http, array $providerOverride = [], bool $withSecret = true): ProviderGateway
{
    $vault = new CredentialVault([1 => CredentialVault::generateKey()], 1);
    $enc = $vault->encrypt(KEY_PLAIN);
    $p = $providerOverride + ['id' => 'P1', 'adapter' => 'openai', 'base_url' => null, 'timeout_ms' => 30000, 'allow_private' => false, 'status' => 'active'];
    $engine = new AiEngineClient($http, new RequestSigner(str_repeat('h', 40), str_repeat('j', 40)), 'http://engine:8000', 10);

    return new ProviderGateway(fakeStore($p, $withSecret ? ['cipher' => $enc['cipher'], 'version' => 1] : null), $vault, $engine);
}

it('sends the decrypted key to the engine inside a signed POST, and nowhere else', function () {
    $http = new Factory;
    $http->fake(['*' => Factory::response(['ok' => true, 'latency_ms' => 12])]);

    $r = gw($http)->testConnection('P1', 'T1');

    expect($r)->toBe(['ok' => true, 'latency_ms' => 12]);
    $http->assertSent(function (Request $req) {
        $body = json_decode($req->body(), true);

        return $req->method() === 'POST'
            && str_ends_with($req->url(), '/internal/v1/providers/test')
            && $body['provider']['api_key'] === KEY_PLAIN
            && $body['provider']['adapter'] === 'openai'
            && $req->hasHeader('X-Signature') && $req->hasHeader('X-Nonce')
            && ! str_contains(json_encode($req->headers()), KEY_PLAIN);   // key only in the body, not headers
    });
});

it('does not return the credential to the caller', function () {
    $http = new Factory;
    $http->fake(['*' => Factory::response(['text' => 'hi', 'model' => 'm', 'input_tokens' => 1, 'output_tokens' => 1, 'finish_reason' => 'stop'])]);

    $r = gw($http)->complete('P1', 'm', [['role' => 'user', 'content' => 'hello']], 'T1', 'U1');

    expect(json_encode($r))->not->toContain(KEY_PLAIN)->and($r['text'])->toBe('hi');
});

it('turns engine errors into a typed exception without the key', function () {
    $http = new Factory;
    $http->fake(['*' => Factory::response(['error' => ['code' => 'rate_limited', 'message' => 'slow down']], 429)]);

    try {
        gw($http)->complete('P1', 'm', [['role' => 'user', 'content' => 'x']], 'T1');
        $this->fail('expected exception');
    } catch (ProviderCallException $e) {
        expect($e->errorCode)->toBe('rate_limited')->and($e->httpStatus)->toBe(429)
            ->and($e->getMessage().$e->getTraceAsString())->not->toContain(KEY_PLAIN);
    }
});

it('refuses disabled providers and missing credentials before calling the engine', function () {
    $http = new Factory;
    $http->fake();

    expect(fn () => gw($http, ['status' => 'disabled'])->testConnection('P1', 'T'))->toThrow(ProviderCallException::class);
    expect(fn () => gw($http, [], false)->testConnection('P1', 'T'))->toThrow(ProviderCallException::class, 'no active credential');
    expect(fn () => gw($http)->testConnection('NOPE', 'T'))->toThrow(ProviderCallException::class);
    $http->assertNothingSent();
});

it('lets an admin test a still-disabled provider, but not use it', function () {
    $http = new Factory;
    $http->fake(['*' => Factory::response(['ok' => true, 'latency_ms' => 5])]);

    expect(gw($http, ['status' => 'disabled'])->testConnection('P1', 'T', true)['ok'])->toBeTrue();
    expect(fn () => gw($http, ['status' => 'disabled'])->complete('P1', 'm', [['role' => 'user', 'content' => 'x']], 'T'))
        ->toThrow(ProviderCallException::class);
});

it('allows a keyless local provider (ollama) to be called', function () {
    $http = new Factory;
    $http->fake(['*' => Factory::response(['ok' => true, 'latency_ms' => 3])]);

    $r = gw($http, ['adapter' => 'ollama', 'allow_private' => true], false)->testConnection('P1', 'T');

    expect($r['ok'])->toBeTrue();
    $http->assertSent(fn (Request $req) => ! isset(json_decode($req->body(), true)['provider']['api_key']));
});

it('hides the payload when the engine is unreachable', function () {
    $http = new Factory;
    $http->fake(fn () => throw new Illuminate\Http\Client\ConnectionException('cURL error: '.KEY_PLAIN));

    try {
        gw($http)->testConnection('P1', 'T');
        $this->fail('expected exception');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('AI engine unreachable.');
    }
});
