<?php

use App\Developer\ApiKeys;

/** The written API description must say exactly what the routes do. */
function spec(): array
{
    return json_decode((string) file_get_contents(__DIR__.'/../../resources/openapi/public-v1.json'), true, 512, JSON_THROW_ON_ERROR);
}

/** @return array<string, string> "METHOD /path" => scope, read from routes/api_public.php */
function routesOf(): array
{
    $src = (string) file_get_contents(__DIR__.'/../../routes/api_public.php');
    preg_match_all("/Route::(get|post)\('([^']+)',[^;]*?ApiKeyAuth::class\.':([a-z:]+)'/", $src, $m, PREG_SET_ORDER);
    $out = [];
    foreach ($m as $r) {
        $out[strtoupper($r[1]).' /'.$r[2]] = $r[3];
    }

    return $out;
}

it('describes exactly the routes that exist, with the scope each one needs', function () {
    $routes = routesOf();
    expect(count($routes))->toBe(5);
    $doc = [];
    foreach (spec()['paths'] as $path => $methods) {
        foreach ($methods as $method => $op) {
            $doc[strtoupper($method).' '.$path] = $op['x-scope'];
        }
    }
    ksort($doc);
    ksort($routes);
    expect($doc)->toBe($routes);
});

it('uses only the scopes the keys can have, and every scope is used', function () {
    $used = array_unique(array_values(routesOf()));
    sort($used);
    $all = array_keys(ApiKeys::SCOPES);
    sort($all);
    expect($used)->toBe($all);
});

it('is a well-formed OpenAPI document that asks for a key and mentions the unauthorised answer everywhere', function () {
    $s = spec();
    expect($s['openapi'])->toBe('3.0.3');
    expect($s['security'])->toBe([['bearerKey' => []]]);
    expect($s['components']['securitySchemes']['bearerKey'])->toBe(['type' => 'http', 'scheme' => 'bearer', 'description' => $s['components']['securitySchemes']['bearerKey']['description']]);
    foreach ($s['paths'] as $path => $methods) {
        foreach ($methods as $op) {
            expect(isset($op['responses']['401'], $op['responses']['403'], $op['responses']['429']) || isset($op['responses']['401']))->toBeTrue();
            expect(isset($op['operationId']) && $op['operationId'] !== '')->toBeTrue();
        }
    }
    $ids = [];
    foreach ($s['paths'] as $methods) {
        foreach ($methods as $op) {
            $ids[] = $op['operationId'];
        }
    }
    expect(count($ids))->toBe(count(array_unique($ids)));
    expect(preg_match('/\{id\}/', json_encode(array_keys($s['paths']))))->toBe(1);
});
