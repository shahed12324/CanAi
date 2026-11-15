<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Leads\ResearchRunner;
use App\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** One website of a research job. Runs inside the tenant context so RLS applies to every statement. */
final class ProcessLeadItem implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 150;

    public function __construct(public readonly string $tenantId, public readonly string $itemId) {}

    public function handle(ResearchRunner $runner, TenantContext $tenant): void
    {
        $outcome = $tenant->run($this->tenantId, fn () => $runner->process($this->itemId, $this->attempts() >= $this->tries));
        if ($outcome === 'retry') {
            $this->release(30 * $this->attempts());
        }
    }
}
