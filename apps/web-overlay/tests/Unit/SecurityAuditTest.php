<?php

/**
 * Phase 28: the standing static security audit of the PHP / Blade code.  Each rule says WHY; an allow-list entry means "a person reviewed this and it is safe".
 * New code that trips a rule must either be fixed or be added to the list WITH a reason in review.
 */
function srcFiles(string $dir, string $ext): array
{
    $out = [];
    $base = (string) realpath(__DIR__.'/../..');
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base.'/'.$dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile() && str_ends_with($f->getFilename(), $ext)) {
            $out[substr($f->getPathname(), strlen($base) + 1)] = (string) file_get_contents($f->getPathname());
        }
    }
    ksort($out);

    return $out;
}

/** Strip // and * comments so that talking about a risky function in a comment is not a hit. */
function code(string $s): string
{
    return (string) preg_replace(['#/\*.*?\*/#s', '#(?<![:\'"])//[^\n]*#'], '', $s);
}

it('only prints unescaped HTML in the three reviewed places', function () {
    $hits = [];
    foreach (srcFiles('resources/views', '.blade.php') as $path => $s) {
        if (preg_match_all('/\{!!(.*?)!!\}/s', $s, $m)) {
            foreach ($m[1] as $expr) {
                $hits[] = $path.' :: '.trim(preg_replace('/\s+/', ' ', $expr));
            }
        }
    }
    sort($hits);
    expect($hits)->toBe([
        "resources/views/accounting/trial.blade.php :: \$debit === \$credit ? '<span class=\"text-green-700\">✔ মিলেছে</span>' : '<span class=\"text-red-700\">✘ মিলছে না</span>'",   // constant markup only
        'resources/views/components/layouts/app.blade.php :: $navBrand[\'css\']',                                                                                                         // only a validated #rrggbb colour (BrandService::css)
        'resources/views/settings/security.blade.php :: $user->twoFactorQrCodeSvg()',                                                                                                     // Fortify-generated SVG
    ]);
});

it('has no code-execution or unsafe-deserialisation calls', function () {
    $bad = [];
    foreach (srcFiles('app', '.php') as $path => $s) {
        if (preg_match_all('/(?<![\w>:$])(eval|shell_exec|passthru|popen|unserialize|exec|system|assert)\s*\(/', code($s), $m)) {
            $bad[] = $path.': '.implode(',', array_unique($m[1]));
        }
    }
    expect($bad)->toBe([]);
});

it('runs external programs only with an argument array (no shell), and only where reviewed', function () {
    $uses = [];
    foreach (srcFiles('app', '.php') as $path => $s) {
        if (preg_match_all('/proc_open\(\s*([^\n]{0,20})/', code($s), $m)) {
            foreach ($m[1] as $first) {
                $uses[] = $path.' '.(str_starts_with(trim($first), '[') || str_starts_with(trim($first), '@[') ? 'array' : 'STRING');
            }
        }
    }
    expect(array_filter($uses, fn ($u) => str_ends_with($u, 'STRING')))->toBe([]);
    expect(array_map(fn ($u) => explode(' ', $u)[0], $uses))->toBe(['app/Knowledge/TextExtractor.php']);
});

it('never builds SQL by interpolating variables, except the reviewed fixed fragment', function () {
    $bad = [];
    foreach (srcFiles('app', '.php') as $path => $s) {
        if (preg_match_all('/(DB::(?:select|selectOne|statement|unprepared|scalar|insert|update|delete)|->(?:whereRaw|selectRaw|orderByRaw|havingRaw|groupByRaw|fromRaw))\(\s*"[^"]*\$/', code($s), $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as [$text]) {
                $bad[] = $path;
            }
        }
        if (preg_match('/(whereRaw|selectRaw|orderByRaw)\(\s*\'[^\']*\'\s*\./', code($s))) {
            $bad[] = $path.' (concatenated)';
        }
    }
    // Reviewed: each interpolates a fragment that is built only from string literals, never from request data.
    expect(array_values(array_unique($bad)))->toBe(['app/Http/Controllers/CreditController.php', 'app/Inbox/PgInboxRepo.php', 'app/Reports/PgReportRepo.php']);
    $root = __DIR__.'/../../';
    $report = (string) file_get_contents($root.'app/Reports/PgReportRepo.php');
    expect(preg_match('/\$in = "[^"$]*"/', $report))->toBe(1);                  // $in itself contains no variable
    $credit = (string) file_get_contents($root.'app/Http/Controllers/CreditController.php');
    expect(preg_match_all('/->group\(\$id, \$days, \'[^\'$]*(?:\\\\\'[^\'$]*)*\'\)/', $credit))->toBe(3)->and(preg_match_all('/->group\(/', $credit))->toBe(3);   // every caller passes a literal
    $inbox = (string) file_get_contents($root.'app/Inbox/PgInboxRepo.php');
    expect(preg_match('/\$where = match \(\$filter\) \{(?:\s*\'[a-z]+\' => [\'"][^\$]*?[\'"],)+\s*default => [\'"][^\$]*[\'"],\s*\};/s', $inbox))->toBe(1);   // $where comes only from literals
});

it('has no hard-coded secrets', function () {
    $bad = [];
    foreach (['app', 'config', 'routes', 'database', 'resources'] as $dir) {
        foreach (srcFiles($dir, '') as $path => $s) {
            if (preg_match('/sk-[A-Za-z0-9]{20}|AKIA[0-9A-Z]{12}|-----BEGIN (RSA |EC )?PRIVATE|xox[bp]-[0-9A-Za-z]|AIza[0-9A-Za-z_\-]{30}|ghp_[A-Za-z0-9]{30}/', $s)) {
                $bad[] = $path;
            }
        }
    }
    expect($bad)->toBe([]);
});

it('makes tokens and codes only with random_bytes / random_int, never rand / mt_rand / uniqid', function () {
    $bad = [];
    foreach (srcFiles('app', '.php') as $path => $s) {
        if (preg_match('/(?<![\w>:$])(mt_rand|rand|uniqid|lcg_value|mt_srand|srand)\s*\(/', code($s))) {
            $bad[] = $path;
        }
    }
    expect($bad)->toBe([]);
});

it('uses md5 / sha1 only where a gateway or a content fingerprint needs them', function () {
    $uses = [];
    foreach (srcFiles('app', '.php') as $path => $s) {
        if (preg_match('/(?<![\w>:$])(md5|sha1)\s*\(/', code($s))) {
            $uses[] = $path;
        }
    }
    // SslCommerz: the gateway's own verify_sign scheme.  StoreSync: change-detection fingerprint of a product row (not a security use).
    expect($uses)->toBe(['app/Billing/Gateways/SslCommerz.php', 'app/Catalog/Stores/StoreSync.php']);
});

it('keeps the admin database connection to admin code, commands and the reviewed repositories', function () {
    $users = [];
    foreach (srcFiles('app', '.php') as $path => $s) {
        if (str_contains(code($s), "connection('admin')")) {
            $users[] = $path;
        }
    }
    $outside = array_values(array_filter($users, fn ($p) => ! str_starts_with($p, 'app/Http/Controllers/Admin/') && ! str_starts_with($p, 'app/Console/Commands/')));
    expect($outside)->toBe([
        'app/Admin/AdminAudit.php', 'app/Ads/PgAdsAdviceRepo.php', 'app/Ads/PgAdsLaunchRepo.php', 'app/Ads/PgAdsRepo.php', 'app/Ai/DbProviderStore.php', 'app/Developer/PgWebhookRepo.php',
        'app/GoogleAds/PgGadsAdviceRepo.php', 'app/GoogleAds/PgGadsLaunchRepo.php', 'app/GoogleAds/PgGoogleAdsRepo.php', 'app/Http/Middleware/AdminAccess.php', 'app/Mobile/PgMobileAdminRepo.php',
        'app/Social/PgSocialCommentWorkRepo.php', 'app/Social/PgSocialPublishRepo.php', 'app/Support/SystemSettings.php', 'app/Video/PgVideoRepo.php',
    ]);
});

it('checks a permission in every admin controller action (or delegates to a private method that does)', function () {
    $reviewed = ['DashboardController::index', 'TenantController::suspend', 'TenantController::activate', 'UserController::suspend', 'UserController::activate'];
    $unchecked = [];
    foreach (srcFiles('app/Http/Controllers/Admin', '.php') as $path => $s) {
        if (str_contains($s, 'abstract class')) {
            continue;
        }
        preg_match_all('/public function (\w+)\([^)]*\)[^{]*\{(.*?)\n    \}/s', $s, $m, PREG_SET_ORDER);
        foreach ($m as $x) {
            if ($x[1] !== '__construct' && ! str_contains($x[2], 'allow(') && ! str_contains($x[2], '->can(')) {
                $unchecked[] = basename($path, '.php').'::'.$x[1];
            }
        }
    }
    sort($unchecked);
    sort($reviewed);
    expect($unchecked)->toBe($reviewed);
});

it('needs an API key on every public API route except the open spec and the MCP GET stub', function () {
    $src = code((string) file_get_contents(__DIR__.'/../../routes/api_public.php'));
    preg_match_all("/Route::(get|post|put|patch|delete)\('([^']+)'[^;]*;/s", $src, $m, PREG_SET_ORDER);
    $open = [];
    foreach ($m as $r) {
        if (! str_contains($r[0], 'ApiKeyAuth::class')) {
            $open[] = strtoupper($r[1]).' '.$r[2];
        }
    }
    sort($open);
    expect($open)->toBe(['GET mcp', 'GET openapi.json']);
});

it('validates and size-limits every file upload', function () {
    $bad = [];
    foreach (srcFiles('app/Http/Controllers', '.php') as $path => $s) {
        if (preg_match_all('/->file\(\'([\w.]+)\'\)/', $s, $m)) {
            foreach (array_unique($m[1]) as $field) {
                // the field must have a validation rule with a max size, e.g. 'logo' => ['nullable', 'image', 'max:2048']
                if (preg_match('/[\'"]'.preg_quote($field, '/').'[\'"]\s*=>\s*(\[[^\]]*max:|\'[^\']*max:)/', $s) !== 1) {
                    $bad[] = $path.' :: '.$field;
                }
            }
        }
    }
    expect($bad)->toBe([]);
});

it('never stores an API key or webhook secret in clear (hash / encrypted only)', function () {
    $sql = (string) file_get_contents(__DIR__.'/../../database/sql/phase27a.up.sql').(string) file_get_contents(__DIR__.'/../../database/sql/phase27b.up.sql');
    expect($sql)->toContain('key_hash')->and($sql)->toContain('secret_cipher')->and(preg_match('/\bkey\s+(text|varchar)/i', $sql))->toBe(0);
});
