<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/** For installs without working e-mail yet: marks an address as verified from the server shell. */
final class UserVerifyCommand extends Command
{
    protected $signature = 'user:verify {email}';

    protected $description = 'Mark a user\'s e-mail address as verified (server-side, no e-mail needed)';

    public function handle(): int
    {
        $user = User::where('email', mb_strtolower(trim((string) $this->argument('email'))))->first();
        if ($user === null) {
            $this->error('No such user.');

            return self::FAILURE;
        }
        if ($user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }
        $this->info("Verified: {$user->email}");

        return self::SUCCESS;
    }
}
