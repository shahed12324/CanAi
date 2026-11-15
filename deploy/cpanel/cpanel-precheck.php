<?php
// JARVIS ONE - cPanel readiness check. Upload, open once in the browser (or `php cpanel-precheck.php`), then DELETE IT.
// Prints only pass/fail lines - no passwords, no phpinfo. Edit the two lines below for the network checks.
const DB_HOST = '';          // e.g. vps.your-domain.example   (leave empty to skip)
const ENGINE_URL = '';       // e.g. https://vps.your-domain.example/engine   (leave empty to skip)

$cli = PHP_SAPI === 'cli';
if (!$cli) { header('Content-Type: text/plain; charset=utf-8'); header('X-Robots-Tag: noindex'); }
$bad = 0;
$line = function (bool $ok, string $what, string $hint = '') use (&$bad): void {
    if (!$ok) { $bad++; }
    echo ($ok ? '[ OK ] ' : '[FAIL] ').$what.(!$ok && $hint !== '' ? "  -> $hint" : '')."\n";
};
echo "JARVIS ONE cPanel check\n";
$line(PHP_VERSION_ID >= 80300, 'PHP '.PHP_VERSION, 'need 8.3+: cPanel > MultiPHP Manager');
foreach (['pdo_pgsql', 'mbstring', 'openssl', 'curl', 'xml', 'zip', 'intl', 'ctype', 'fileinfo', 'tokenizer', 'dom'] as $e) {
    $line(extension_loaded($e), "extension $e", 'enable in cPanel > Select PHP Version > Extensions');
}
$root = dirname(__DIR__);
foreach (['storage', 'storage/logs', 'storage/framework', 'bootstrap/cache'] as $d) {
    $line(is_dir("$root/$d") && is_writable("$root/$d"), "writable $d", 'chmod 775 (or 755 if the PHP user owns the files)');
}
$line(is_file("$root/vendor/autoload.php"), 'vendor/ present', 'upload the bundle made by make-cpanel-bundle.sh');
$line(is_file("$root/.env"), '.env present', 'copy .env.example to .env and fill it in');
$line(is_file(__DIR__.'/build/manifest.json'), 'public/build present (front-end assets)');
$line((int) ini_get('memory_limit') === -1 || (int) ini_get('memory_limit') >= 128, 'memory_limit '.ini_get('memory_limit'), 'raise to 256M');
if (DB_HOST !== '') {
    $t = @fsockopen(DB_HOST, 5432, $en, $es, 5);
    $line($t !== false, 'TCP to PostgreSQL '.DB_HOST.':5432', 'outbound port 5432 blocked by the host, or VPS firewall: run deploy/enable-remote.sh with this server IP');
    if ($t) { fclose($t); }
}
if (ENGINE_URL !== '') {
    $ctx = stream_context_create(['http' => ['timeout' => 8, 'ignore_errors' => true]]);
    $r = @file_get_contents(rtrim(ENGINE_URL, '/').'/healthz', false, $ctx);
    $line($r !== false && str_contains((string) $r, 'ok'), 'AI engine /healthz via '.ENGINE_URL, 'HTTPS on the VPS? nginx /engine/ allow-listed for THIS server IP?');
}
echo $bad === 0 ? "\nAll good.\n" : "\n$bad problem(s). Fix them, re-run.\n";
echo "Delete this file now.\n";
