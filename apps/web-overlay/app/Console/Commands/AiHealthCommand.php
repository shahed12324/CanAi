<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AiEngine\AiEngineClient;
use Illuminate\Console\Command;

final class AiHealthCommand extends Command
{
    protected $signature = 'ai:health {tenant=system : Tenant id placed in the signed token}';

    protected $description = 'Send a signed request to the AI engine and print the result';

    public function handle(AiEngineClient $client): int
    {
        try {
            $this->line((string) json_encode($client->health($this->argument('tenant')), JSON_PRETTY_PRINT));
        } catch (\Throwable $e) {
            $this->error('AI engine check failed: '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
