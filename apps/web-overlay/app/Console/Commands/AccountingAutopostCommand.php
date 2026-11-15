<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Accounting\AutopostRepo;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Posts the delivered / returned shop orders that are waiting in each workspace. One workspace failing never stops the others. */
final class AccountingAutopostCommand extends Command
{
    protected $signature = 'accounting:autopost';

    protected $description = 'Post waiting shop orders into the books';

    public function handle(AutopostRepo $repo, TenantContext $tenant): int
    {
        $rows = DB::connection('admin')->select("select q.tenant_id, (select u.user_id from tenant_users u where u.tenant_id = q.tenant_id and u.status = 'active' order by u.created_at limit 1) as user_id from autopost_queue q where q.status in ('pending', 'failed') group by q.tenant_id limit 100");
        $tot = ['posted' => 0, 'failed' => 0, 'skipped' => 0, 'error' => 0];
        foreach ($rows as $r) {
            $tid = trim((string) $r->tenant_id);
            $uid = trim((string) ($r->user_id ?? ''));
            if ($uid === '') {
                $tot['error']++;
                continue;
            }
            try {
                $res = $tenant->run($tid, fn () => $repo->run($tid, $uid, 200));
                foreach (['posted', 'failed', 'skipped'] as $k) {
                    $tot[$k] += $res[$k];
                }
            } catch (\Throwable $e) {
                $tot['error']++;
                report($e);
            }
        }
        $this->line('autopost: posted '.$tot['posted'].', failed '.$tot['failed'].', skipped '.$tot['skipped'].', workspace errors '.$tot['error']);

        return self::SUCCESS;
    }
}
