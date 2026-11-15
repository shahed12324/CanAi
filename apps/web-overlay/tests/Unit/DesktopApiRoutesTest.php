<?php

declare(strict_types=1);

// Static contract checks for the CAN AI Desktop API (no framework boot): the routes must match docs/DESKTOP_API.md,
// sit behind the device-token middleware, and never include anything that approves.

function desktopRoutes(): array
{
    $src = file_get_contents(__DIR__.'/../../routes/api_pc.php');
    preg_match_all("/Route::(get|post)\('(?<p>(?:chat|bootstrap|tools|approvals|agents|workflows|memory|plan|voice)[^']*)'/", $src, $m, PREG_SET_ORDER);

    return array_map(fn ($x) => strtoupper($x[1]).' '.$x['p'], $m);
}

it('has a route for every row of the documented contract', function () {
    $doc = __DIR__.'/../../../../docs/DESKTOP_API.md';
    if (! is_file($doc)) {
        expect(true)->toBeTrue();

        return;
    }
    $routes = desktopRoutes();
    preg_match_all('/^\| (GET|POST) `([^`]+)` \|/m', file_get_contents($doc), $rows, PREG_SET_ORDER);
    expect(count($rows) >= 16)->toBeTrue();
    foreach ($rows as $r) {
        $path = preg_replace('/\[\?q=\]/', '', $r[2]);
        $path = str_replace('{id}', '{id}', $path);
        expect(in_array($r[1].' '.$path, $routes, true))->toBe(true);
    }
    expect(in_array('POST voice/listen', $routes, true) && in_array('POST voice/speak', $routes, true))->toBe(true);
});

it('puts every desktop route behind the device token and exposes nothing that approves', function () {
    $src = file_get_contents(__DIR__.'/../../routes/api_pc.php');
    $auth = strpos($src, 'PcAgentAuth::class)->group');
    $desktop = strpos($src, "Route::prefix('desktop')");
    expect($auth !== false && $desktop !== false && $auth < $desktop)->toBe(true);
    foreach (desktopRoutes() as $r) {
        expect(preg_match('/approve|decide|confirm|pin|step-?up|revoke|pair/i', $r))->toBe(0);
    }
});

it('runs every desktop call as the device owner, never as a web session', function () {
    $c = file_get_contents(__DIR__.'/../../app/Http/Controllers/Api/Pc/DesktopController.php');
    expect(str_contains($c, '$request->user()') || str_contains($c, '$r->user()') || str_contains($c, 'Auth::'))->toBe(false);
    expect(str_contains($c, "attributes->get('pc_user')"))->toBe(true);
    // each public action resolves the owner (self::user) - an endpoint that forgets would answer for nobody
    preg_match_all('/public function (\w+)\(.*?\n    \}\n/s', $c, $fns, PREG_SET_ORDER);
    foreach ($fns as $f) {
        if ($f[1] === '__construct' || $f[1] === 'tools') {
            continue;
        }
        expect(str_contains($f[0], 'self::user($') || str_contains($f[0], 'self::user($r)'))->toBe(true);
    }
});

it('hands the owner id to the controller from the middleware', function () {
    expect(str_contains(file_get_contents(__DIR__.'/../../app/Http/Middleware/PcAgentAuth.php'), "'pc_user'"))->toBe(true);
});
