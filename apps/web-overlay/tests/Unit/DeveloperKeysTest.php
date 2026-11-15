<?php

use App\Developer\ApiKeys;
use App\Developer\DeveloperText;
use Tests\Support\FakeApiKeyRepo;

function dk(?FakeApiKeyRepo $r = null): array
{
    $r ??= new FakeApiKeyRepo;

    return [new ApiKeys($r), $r];
}

it('makes a key that looks right, stores only its hash and hands the key out once', function () {
    [$k, $r] = dk();
    $x = $k->issue('T', 'U', 'n8n', ['me', 'workflows:run'], 30);
    expect($x['status'])->toBe('ok');
    expect(preg_match('/\Ajk_live_[0-9a-f]{40}\z/', $x['key']))->toBe(1);
    $row = array_values($r->keys)[0];
    expect($row['hash'])->toBe(hash('sha256', $x['key']));
    expect($row['prefix'])->toBe(substr($x['key'], 0, 12));
    expect(str_contains(json_encode($row), $x['key']))->toBeFalse();
    expect($row['days'])->toBe(30);
    expect($k->issue('T', 'U', 'n8n', ['me'], null)['key'] === $x['key'])->toBeFalse();
});

it('refuses bad names, scopes and lifetimes before the database is asked', function () {
    [$k, $r] = dk();
    expect($k->issue('T', 'U', "  \n ", ['me'], null)['status'])->toBe('bad_name');
    expect($k->issue('T', 'U', str_repeat('ক', 81), ['me'], null)['status'])->toBe('bad_name');
    expect($k->issue('T', 'U', 'ok', [], null)['status'])->toBe('bad_scopes');
    expect($k->issue('T', 'U', 'ok', ['admin', 5], null)['status'])->toBe('bad_scopes');
    expect($k->issue('T', 'U', 'ok', ['me'], 0)['status'])->toBe('bad_days');
    expect($k->issue('T', 'U', 'ok', ['me'], 366)['status'])->toBe('bad_days');
    expect($r->keys)->toBe([]);
    expect($k->issue('T', 'U', str_repeat('ক', 80), ['me', 'me'], 365)['status'])->toBe('ok');
    expect(array_values($r->keys)[0]['scopes'])->toBe(['me']);
});

it('reports a full key list as its own outcome and any other refusal as failed', function () {
    [$k, $r] = dk();
    $r->createResult = 'limit';
    expect($k->issue('T', 'U', 'a', ['me'], null)['status'])->toBe('limit');
    $r->createResult = 'duplicate';
    expect($k->issue('T', 'U', 'a', ['me'], null)['status'])->toBe('failed');
    expect($k->issue('T', 'U', 'a', ['me'], null)['key'])->toBeNull();
});

it('reads only a well-formed Bearer key from the header', function () {
    $key = 'jk_live_'.str_repeat('a1', 20);
    expect(ApiKeys::fromHeader('Bearer '.$key))->toBe($key);
    expect(ApiKeys::fromHeader(' Bearer '.$key.' '))->toBe($key);
    foreach ([null, '', $key, 'Bearer', 'Bearer x', 'bearer '.$key, 'Basic '.$key, 'Bearer '.$key.'0', 'Bearer '.substr($key, 0, -1), 'Bearer '.strtoupper($key), "Bearer $key\nX"] as $h) {
        expect(ApiKeys::fromHeader($h))->toBeNull();
    }
});

it('authenticates a good key, and a revoked, wrong or malformed one gets nothing', function () {
    [$k, $r] = dk();
    $x = $k->issue('T1', 'U1', 'n', ['me', 'credits:read'], null);
    $a = $k->authenticate('Bearer '.$x['key']);
    expect($a['tenant_id'])->toBe('T1');
    expect($a['user_id'])->toBe('U1');
    expect(ApiKeys::allows($a, 'me'))->toBeTrue();
    expect(ApiKeys::allows($a, 'workflows:run'))->toBeFalse();
    expect($k->authenticate('Bearer jk_live_'.str_repeat('0', 40)))->toBeNull();
    expect($k->authenticate('nonsense'))->toBeNull();
    expect($k->authenticate(null))->toBeNull();
    $id = array_key_first($r->keys);
    expect($k->revoke('T1', 'U2', $id))->toBe('not_found');
    expect($k->authenticate('Bearer '.$x['key']))->not->toBeNull();
    expect($k->revoke('T1', 'U1', $id))->toBe('ok');
    expect($k->authenticate('Bearer '.$x['key']))->toBeNull();
});

it('lists exactly the four documented scopes, each with a Bengali description', function () {
    expect(array_keys(ApiKeys::SCOPES))->toBe(['me', 'credits:read', 'workflows:read', 'workflows:run']);
    foreach (ApiKeys::SCOPES as $d) {
        expect(mb_strlen($d) > 5)->toBeTrue();
    }
    foreach (['bad_name', 'bad_scopes', 'bad_days', 'limit', 'not_found'] as $c) {
        expect(DeveloperText::message($c))->not->toBe(DeveloperText::message('unknown_code'));
    }
    expect(DeveloperText::message('failed'))->toBe(DeveloperText::MESSAGES['failed']);
});
