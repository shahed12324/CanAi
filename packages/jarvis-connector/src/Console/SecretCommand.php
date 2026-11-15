<?php

declare(strict_types=1);

namespace Jarvis\Connector\Console;

use Illuminate\Console\Command;

final class SecretCommand extends Command
{
    protected $signature = 'jarvis-connector:secret';

    protected $description = 'Print a new random secret for the JARVIS connector';

    public function handle(): int
    {
        $secret = bin2hex(random_bytes(24));
        $this->line('Add this line to your .env (then run `php artisan config:clear`):');
        $this->line('');
        $this->line('JARVIS_CONNECTOR_SECRET='.$secret);
        $this->line('');
        $this->line('Give the same secret to JARVIS ONE when you connect the store. Treat it like a password.');

        return self::SUCCESS;
    }
}
