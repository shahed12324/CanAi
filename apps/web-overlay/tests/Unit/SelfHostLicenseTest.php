<?php

use App\SelfHost\LicenseKey;
use App\SelfHost\LicenseKeyException;
use App\SelfHost\SelfHostLicense;

function shk(): array
{
    static $k = null;

    return $k ??= LicenseKey::keypair();
}
function shIssue(int $seats = 5, int $days = 90, string $cust = 'Acme Ltd', array $f = ['reseller']): string
{
    $now = time();

    return LicenseKey::issue(shk()['secret'], $cust, $seats, $now, $now + $days * 86400, $f);
}
function shReason(callable $fn): ?string
{
    try {
        $fn();
    } catch (LicenseKeyException $e) {
        return $e->reason;
    }

    return null;
}
function shTmp(): string
{
    return sys_get_temp_dir().'/jv-lic-'.bin2hex(random_bytes(4)).'/license.key';
}

it('verifies a freshly issued key and returns its data', function () {
    $l = LicenseKey::verify(shIssue(7, 30, 'বাংলা কোং'), shk()['public']);
    expect($l['customer'])->toBe('বাংলা কোং');
    expect($l['seats'])->toBe(7);
    expect($l['features'])->toBe(['reseller']);
    expect($l['expires_at'] - $l['issued_at'])->toBe(30 * 86400);
});

it('rejects a key signed by somebody else', function () {
    $other = LicenseKey::keypair();
    $now = time();
    $k = LicenseKey::issue($other['secret'], 'X', 3, $now, $now + 1000);
    expect(shReason(fn () => LicenseKey::verify($k, shk()['public'])))->toBe('bad_signature');
});

it('rejects a payload that was edited after signing (seat inflation)', function () {
    [$p, $payload, $sig] = explode('.', shIssue(5));
    $obj = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);
    $obj['n'] = 5000;
    $forged = rtrim(strtr(base64_encode(json_encode($obj)), '+/', '-_'), '=');
    expect(shReason(fn () => LicenseKey::verify("$p.$forged.$sig", shk()['public'])))->toBe('bad_signature');
});

it('rejects a changed prefix and malformed text', function () {
    $k = shIssue();
    expect(shReason(fn () => LicenseKey::verify(str_replace('JVSH1', 'JVSH2', $k), shk()['public'])))->toBe('malformed');
    expect(shReason(fn () => LicenseKey::verify('garbage', shk()['public'])))->toBe('malformed');
    expect(shReason(fn () => LicenseKey::verify('JVSH1..', shk()['public'])))->toBe('malformed');
    expect(shReason(fn () => LicenseKey::verify('JVSH1.abc.!!!', shk()['public'])))->toBe('malformed');
    expect(shReason(fn () => LicenseKey::verify(explode('.', $k)[0].'.'.explode('.', $k)[1].'.AAAA', shk()['public'])))->toBe('malformed');
});

it('flips one signature byte -> bad_signature', function () {
    [$p, $payload, $sig] = explode('.', shIssue());
    $raw = base64_decode(strtr($sig, '-_', '+/'));
    $raw[0] = chr(ord($raw[0]) ^ 1);
    $bad = rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    expect(shReason(fn () => LicenseKey::verify("$p.$payload.$bad", shk()['public'])))->toBe('bad_signature');
});

it('rejects an expired key and one issued in the future', function () {
    $k = shIssue(5, 10);
    expect(shReason(fn () => LicenseKey::verify($k, shk()['public'], time() + 11 * 86400)))->toBe('expired');
    expect(shReason(fn () => LicenseKey::verify($k, shk()['public'], time() + 10 * 86400 - 5)))->toBeNull();
    expect(shReason(fn () => LicenseKey::verify($k, shk()['public'], time() - 3600)))->toBe('not_yet_valid');
    expect(shReason(fn () => LicenseKey::verify($k, shk()['public'], time() - 200)))->toBeNull();   // within clock skew
});

it('refuses to verify without a valid public key', function () {
    expect(shReason(fn () => LicenseKey::verify(shIssue(), '')))->toBe('no_public_key');
    expect(shReason(fn () => LicenseKey::verify(shIssue(), 'zz')))->toBe('no_public_key');
});

it('refuses to issue silly licences', function () {
    $now = time();
    foreach ([['', 5, $now + 9], ['A', 0, $now + 9], ['A', 100001, $now + 9], ['A', 5, $now], [str_repeat('x', 121), 5, $now + 9]] as [$c, $n, $e]) {
        $threw = false;
        try {
            LicenseKey::issue(shk()['secret'], $c, $n, $now, $e);
        } catch (InvalidArgumentException) {
            $threw = true;
        }
        expect($threw)->toBeTrue();
    }
});

it('reports missing / valid / expiring / expired / invalid states', function () {
    $f = shTmp();
    $svc = new SelfHostLicense($f, shk()['public']);
    expect($svc->status()['state'])->toBe('missing');

    $svc->activate(shIssue(9, 90));
    $s = $svc->status();
    expect($s['state'])->toBe('valid');
    expect($s['seats'])->toBe(9);
    expect($s['customer'])->toBe('Acme Ltd');

    expect($svc->status(time() + 80 * 86400)['state'])->toBe('expiring');
    $e = $svc->status(time() + 91 * 86400);
    expect($e['state'])->toBe('expired');
    expect($e['seats'])->toBeNull();

    file_put_contents($f, 'JVSH1.bad.bad');
    expect($svc->status()['state'])->toBe('invalid');
    file_put_contents($f, "  \n");
    expect($svc->status()['state'])->toBe('missing');
});

it('does not store a bad key and stores a good one with mode 0600', function () {
    $f = shTmp();
    $svc = new SelfHostLicense($f, shk()['public']);
    expect(shReason(fn () => $svc->activate('nope')))->toBe('malformed');
    expect(is_file($f))->toBeFalse();
    $svc->activate(shIssue());
    expect(is_file($f))->toBeTrue();
    expect(substr(sprintf('%o', fileperms($f)), -4))->toBe('0600');
});

it('vendor CLI issues keys the server verifies', function () {
    $root = dirname(__DIR__, 4);
    $out = shell_exec('php '.escapeshellarg("$root/scripts/license-issue.php").' keygen');
    preg_match('/PUBLIC.*?:\n([0-9a-f]{64})\nSECRET.*?:\n([0-9a-f]{128})/s', (string) $out, $m);
    expect(count($m))->toBe(3);
    $sf = tempnam(sys_get_temp_dir(), 'sk');
    file_put_contents($sf, $m[2]);
    $key = trim((string) shell_exec('php '.escapeshellarg("$root/scripts/license-issue.php").' issue '.escapeshellarg($sf).' "Test Co" 12 60 a,b'));
    unlink($sf);
    $l = LicenseKey::verify($key, $m[1]);
    expect($l['seats'])->toBe(12);
    expect($l['features'])->toBe(['a', 'b']);
});

it('rejects a correctly signed but nonsensical payload (zero seats / wrong types)', function () {
    foreach ([['v' => 1, 'c' => 'A', 'n' => 0, 'iat' => time(), 'exp' => time() + 99, 'f' => []], ['v' => 1, 'c' => 'A', 'n' => '5', 'iat' => time(), 'exp' => time() + 99, 'f' => []], ['v' => 2, 'c' => 'A', 'n' => 5, 'iat' => time(), 'exp' => time() + 99, 'f' => []]] as $p) {
        $seg = rtrim(strtr(base64_encode(json_encode($p)), '+/', '-_'), '=');
        $sig = rtrim(strtr(base64_encode(sodium_crypto_sign_detached('JVSH1.'.$seg, sodium_hex2bin(shk()['secret']))), '+/', '-_'), '=');
        expect(shReason(fn () => LicenseKey::verify("JVSH1.$seg.$sig", shk()['public'])))->toBe('bad_payload');
    }
});
