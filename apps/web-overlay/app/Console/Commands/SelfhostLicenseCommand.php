<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\SelfHost\LicenseKeyException;
use App\SelfHost\SelfHostLicense;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Show or activate the self-host licence. With --tenant the seats/expiry are also applied to that workspace (Phase 27c licences). */
final class SelfhostLicenseCommand extends Command
{
    protected $signature = 'selfhost:license {key? : licence key to activate} {--tenant= : workspace id to apply the seats to}';

    protected $description = 'Show or activate the self-host licence key';

    public function handle(): int
    {
        $lic = new SelfHostLicense((string) config('selfhost.key_file'), (string) config('selfhost.public_key'));
        $key = $this->argument('key');
        if (is_string($key) && $key !== '') {
            try {
                $l = $lic->activate($key);
            } catch (LicenseKeyException $e) {
                $this->error('The license key is not valid: '.$e->reason);

                return self::FAILURE;
            }
            $this->info("Active: {$l['customer']} — {$l['seats']} seats, expiry ".gmdate('Y-m-d', $l['expires_at']));
            $tenant = $this->option('tenant');
            if (is_string($tenant) && preg_match('/^[0-9a-z]{26}$/', $tenant)) {
                DB::connection('admin')->select('select license_set(?, ?, ?, ?)', [$tenant, $l['seats'], gmdate('c', $l['expires_at']), 'self-host: '.$l['customer']]);
                $this->info("Workspace {$tenant}-Seats were applied to.");
            }

            return self::SUCCESS;
        }
        $s = $lic->status();
        $this->line(json_encode($s, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return in_array($s['state'], ['valid', 'expiring'], true) ? self::SUCCESS : self::FAILURE;
    }
}
