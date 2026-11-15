<?php

declare(strict_types=1);

// VENDOR tool (run on YOUR machine, never on a customer server). Needs PHP with ext-sodium.
//   php scripts/license-issue.php keygen                      -> prints a new public/secret key pair (keep the secret OFFLINE)
//   php scripts/license-issue.php issue <secret-file> "<customer>" <seats> <days> [feature,feature]
//   php scripts/license-issue.php verify <public-hex> <key>
require_once __DIR__.'/../apps/web-overlay/app/SelfHost/LicenseKeyException.php';
require_once __DIR__.'/../apps/web-overlay/app/SelfHost/LicenseKey.php';

use App\SelfHost\LicenseKey;
use App\SelfHost\LicenseKeyException;

$a = $argv;
switch ($a[1] ?? '') {
    case 'keygen':
        $k = LicenseKey::keypair();
        echo "PUBLIC (put in JARVIS_LICENSE_PUBKEY / config):\n{$k['public']}\nSECRET (store offline, chmod 600, never ship):\n{$k['secret']}\n";
        exit(0);
    case 'issue':
        if (count($a) < 6 || ! is_file($a[2])) {
            fwrite(STDERR, "usage: issue <secret-file> <customer> <seats> <days> [features]\n");
            exit(2);
        }
        $now = time();
        try {
            echo LicenseKey::issue(trim((string) file_get_contents($a[2])), $a[3], (int) $a[4], $now, $now + (int) $a[5] * 86400, isset($a[6]) && $a[6] !== '' ? explode(',', $a[6]) : []), "\n";
        } catch (Throwable $e) {
            fwrite(STDERR, $e->getMessage()."\n");
            exit(1);
        }
        exit(0);
    case 'verify':
        try {
            echo json_encode(LicenseKey::verify($a[3] ?? '', $a[2] ?? ''), JSON_UNESCAPED_UNICODE), "\n";
            exit(0);
        } catch (LicenseKeyException $e) {
            fwrite(STDERR, 'invalid: '.$e->reason."\n");
            exit(1);
        }
    default:
        fwrite(STDERR, "usage: keygen | issue | verify\n");
        exit(2);
}
