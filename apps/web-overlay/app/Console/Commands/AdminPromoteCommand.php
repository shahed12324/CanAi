<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Admin\AdminAudit;
use App\Admin\PlatformRoles;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Bootstrap the first Super Admin (the only way in before the panel exists).
 * Promotes an EXISTING, verified, active user; never handles passwords.
 */
final class AdminPromoteCommand extends Command
{
    protected $signature = 'admin:promote {email} {--role=Super Admin}';

    protected $description = 'Make an existing verified user a platform admin';

    public function handle(): int
    {
        $role = (string) $this->option('role');
        if (! in_array($role, PlatformRoles::names(), true)) {
            $this->error('Unknown role. Choose one of: '.implode(', ', PlatformRoles::names()));

            return self::FAILURE;
        }
        if (blank(config('database.connections.admin.password'))) {
            $this->error('Set DB_ADMIN_USERNAME / DB_ADMIN_PASSWORD first (see ENVIRONMENT_SETUP.md).');

            return self::FAILURE;
        }

        $user = User::where('email', mb_strtolower((string) $this->argument('email')))->first();
        if ($user === null || $user->email_verified_at === null || $user->status !== 'active') {
            $this->error('User must exist, be active and have a verified email.');

            return self::FAILURE;
        }

        PlatformRoles::assign($user, $role);
        $admin = DB::connection('admin');
        $admin->transaction(function () use ($admin, $user, $role): void {
            $admin->select("select set_config('app.bypass_rls', 'on', true)");
            $admin->table('users')->where('id', $user->getKey())->update(['is_platform_admin' => true, 'updated_at' => now()]);
            $admin->table('audit_logs')->insert([
                'actor_type' => 'cli', 'action' => 'admin.promoted', 'subject_type' => 'user',
                'subject_id' => $user->getKey(), 'after' => json_encode(['role' => $role]), 'channel' => 'cli',
            ]);
        });

        $this->info("{$user->email} is now {$role}. Admin access still requires 2FA.");

        return self::SUCCESS;
    }
}
