<?php
// Opens the main pages as the first user and prints the real error behind any 500.  Run via ./diag.sh
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$http = $app->make(Illuminate\Contracts\Http\Kernel::class);
$email = $argv[1] ?? null;
$q = App\Models\User::query();
$user = $email ? $q->where('email', $email)->first() : $q->orderBy('created_at')->first();
if (! $user) { fwrite(STDERR, "No user found. Register first.\n"); exit(1); }
$tenant = Illuminate\Support\Facades\DB::table('tenant_users')->where('user_id', $user->getKey())->where('status', 'active')->value('tenant_id');
echo "user: {$user->email}  tenant: ".($tenant ?: 'NONE')."\n";
foreach (['/app/journey', '/app/skills', '/app/jobs', '/app/memory', '/app/tools', '/app/photoshop', '/app/chat'] as $url) {
    $app['auth']->guard('web')->setUser($user);
    $req = Illuminate\Http\Request::create($url, 'GET', [], [], [], ['HTTP_HOST' => 'localhost']);
    $req->headers->set('X-Tenant-Id', (string) $tenant);
    $resp = $http->handle($req);
    $e = $resp->exception ?? null;
    echo str_pad($url, 16), $resp->getStatusCode();
    if ($e) { echo "   ".get_class($e).": ".substr(str_replace("\n", ' ', $e->getMessage()), 0, 400)."\n      at ".$e->getFile().':'.$e->getLine(); }
    echo "\n";
}
