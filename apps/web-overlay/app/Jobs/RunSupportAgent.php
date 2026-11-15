<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Inbox\Agent\SupportAgent;
use App\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

/** One AI answer for one customer message. No retries: a retry could answer twice; SupportAgent also skips when someone already replied. */
final class RunSupportAgent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 90;

    public function __construct(public readonly string $tenantId, public readonly string $conversationId, public readonly int $seq) {}

    public function handle(SupportAgent $agent, TenantContext $tenants): void
    {
        $tenants->run($this->tenantId, function () use ($agent) {
            $name = (string) (DB::selectOne('select name from tenants where id = ?', [$this->tenantId])->name ?? 'Shop');
            $agent->run($this->tenantId, $this->conversationId, $this->seq, $name);
        });
    }
}
