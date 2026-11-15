<?php
// Needs the illuminate sources side by side in one folder (git clone --depth 1 https://github.com/illuminate/{view,support,contracts,container,filesystem,collections,macroable,conditionable,events} and https://github.com/php-fig/container as `psr`).
// Packagist is unreachable in the build sandbox, so Composer cannot install them; this loader lets Laravel's REAL BladeCompiler check our views anyway.  Copy this file into that folder, or edit the paths.
// Compiles Blade templates with Laravel's real BladeCompiler (illuminate/view from GitHub) and lints the PHP it produces.  Usage: php compile.php file.blade.php ...
$SRC = getenv('BLADE_SRC') ?: __DIR__;   // folder holding the cloned illuminate/* sources
spl_autoload_register(function ($c) use ($SRC) {
    $map = ['Psr\\Container\\' => 'psr/src/', 'Illuminate\\View\\' => 'view/', 'Illuminate\\Support\\' => 'support/', 'Illuminate\\Contracts\\' => 'contracts/', 'Illuminate\\Container\\' => 'container/', 'Illuminate\\Filesystem\\' => 'filesystem/',
        'Illuminate\\Collections\\' => 'collections/', 'Illuminate\\Macroable\\' => 'macroable/', 'Illuminate\\Conditionable\\' => 'conditionable/', 'Illuminate\\Events\\' => 'events/'];
    foreach ($map as $p => $d) {
        if (str_starts_with($c, $p)) {
            $rel = substr($c, strlen($p)); $dir = $SRC.'/'.$d;
            foreach ([$dir.str_replace('\\', '/', $rel).'.php', $SRC.'/collections/'.str_replace('\\', '/', $rel).'.php', $SRC.'/macroable/'.str_replace('\\', '/', $rel).'.php', $SRC.'/conditionable/'.str_replace('\\', '/', $rel).'.php'] as $f) { if (is_file($f)) { require $f; return; } }
        }
    }
});
foreach (['collections/helpers.php', 'collections/functions.php', 'support/helpers.php', 'support/functions.php'] as $h) { if (is_file($SRC.'/'.$h)) { require_once $SRC.'/'.$h; } }
if (!function_exists('array_first')) { function array_first(array $a) { return $a === [] ? null : $a[array_key_first($a)]; } function array_last(array $a) { return $a === [] ? null : $a[array_key_last($a)]; } }
$fs = new Illuminate\Filesystem\Filesystem;
$bc = new Illuminate\View\Compilers\BladeCompiler($fs, sys_get_temp_dir());
$bad = 0;
foreach (array_slice($argv, 1) as $f) {
    try {
        $src = preg_replace(['/<x-[a-z0-9._\-]+(\s[^>]*)?\/>/', '/<x-[a-z0-9._\-]+(\s[^>]*)?>/', '/<\/x-[a-z0-9._\-]+>/'], ['', '<div>', '</div>'], file_get_contents($f)); $php = $bc->compileString($src);  // <x-layouts.app> needs the full framework; everything else is compiled for real
    } catch (Throwable $e) { echo "COMPILE FAIL $f: ".get_class($e).': '.$e->getMessage()."\n"; $bad++; continue; }
    $tmp = tempnam(sys_get_temp_dir(), 'bl').'.php'; file_put_contents($tmp, $php);
    exec('php -l '.escapeshellarg($tmp).' 2>&1', $out, $rc); unlink($tmp);
    if ($rc !== 0) { echo "PHP LINT FAIL $f: ".implode(' ', $out)."\n"; $bad++; } else { echo "ok   $f (".strlen($php)." bytes of PHP)\n"; }
    $out = [];
}
if (realpath($_SERVER["SCRIPT_FILENAME"]) === __FILE__) { exit($bad ? 1 : 0); }
