<?php

namespace Database\Seeders;

use App\Actions\Tenancy\CreateWorkspace;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Local development only. Run it with the OWNER database role (`jarvis_owner`), because
 * creating a platform admin needs the RLS-bypass context, which the app role cannot get.
 * Passwords are random and printed once; they are never stored in the repo.
 */
class DevSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->isLocal()) {
            $this->command?->error('DevSeeder runs only when APP_ENV=local.');

            return;
        }

        DB::select("select set_config('app.bypass_rls', 'on', false)");
        try {
            $this->user('admin@jarvis.test', 'Platform Admin (dev)', platformAdmin: true);
            $this->user('owner@jarvis.test', 'Owner (dev)');
        } finally {
            DB::select("select set_config('app.bypass_rls', '', false)");
        }
    }

    private function user(string $email, string $name, bool $platformAdmin = false): void
    {
        $user = User::where('email', $email)->first();
        if ($user === null) {
            $password = Str::password(16);
            $user = new User(['name' => $name, 'email' => $email, 'password' => $password]);
            $user->forceFill(['email_verified_at' => now(), 'is_platform_admin' => $platformAdmin])->save();
            app(CreateWorkspace::class)->personal($user);
            $this->command?->info("Dev user: $email / $password");
        }
    }
}
