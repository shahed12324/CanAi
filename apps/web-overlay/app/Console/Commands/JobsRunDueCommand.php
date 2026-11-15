<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Automation\JobRunner;
use Illuminate\Console\Command;

/** Every minute: runs the scheduled AI jobs that are due (at most 10 per minute; the rest wait for the next minute). */
final class JobsRunDueCommand extends Command
{
    protected $signature = 'jobs:run-due';

    protected $description = 'Run due scheduled AI jobs';

    public function handle(JobRunner $runner): int
    {
        foreach ($runner->claim(10) as $job) {
            $r = $runner->execute($job);
            $this->line("job {$job->id} {$r['status']} → {$r['delivered']}");
        }

        return self::SUCCESS;
    }
}
