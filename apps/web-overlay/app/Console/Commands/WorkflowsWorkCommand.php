<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Tenancy\TenantContext;
use App\Workflow\WorkflowRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Every minute: queue the daily schedules that are due, then run the queued workflow runs (each one inside its own workspace). */
final class WorkflowsWorkCommand extends Command
{
    protected $signature = 'workflows:work {--limit=10}';

    protected $description = 'Queue due workflow schedules and run queued workflow runs';

    public function handle(TenantContext $tenants, WorkflowRunner $runner): int
    {
        $admin = DB::connection('admin');
        $due = (int) $admin->selectOne('select wf_due() as r')->r;
        $runs = $admin->select('select run_id, tenant_id from wf_run_claim(?)', [max(1, min(50, (int) $this->option('limit')))]);
        foreach ($runs as $r) {
            $tid = trim((string) $r->tenant_id);
            $rid = trim((string) $r->run_id);
            try {
                $tenants->run($tid, fn () => $runner->execute($tid, $rid));
            } catch (\Throwable $e) {
                report($e);
                $this->error("run {$rid} failed: ".$e->getMessage());
            }
        }
        if ($due > 0 || $runs !== []) {
            $this->line("scheduled {$due}, ran ".count($runs));
        }

        return self::SUCCESS;
    }
}
