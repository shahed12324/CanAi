<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Catalog\Stores\StoreSync;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Re-syncs every active connected store that has not synced for a while (pull -> approved push -> stock). */
final class CatalogSyncCommand extends Command
{
    protected $signature = 'catalog:sync';

    protected $description = 'Sync connected WooCommerce/Shopify stores';

    public function handle(StoreSync $sync, TenantContext $tenant): int
    {
        $every = max(5, (int) config('catalog.sync_every_minutes', 15));
        $due = DB::connection('admin')->select("select id, tenant_id from store_connections where platform <> 'native' and status = 'active'
            and (last_sync_at is null or last_sync_at < now() - make_interval(mins => ?)) order by last_sync_at nulls first limit 50", [$every]);
        foreach ($due as $d) {
            $tid = trim($d->tenant_id);
            $sid = trim($d->id);
            $r = $tenant->run($tid, fn () => $sync->run($tid, $sid, 'schedule', null));
            $this->line(sprintf('store %s: %s (pulled %d, new %d, updated %d, pushed %d, failed %d)%s', substr($sid, 0, 8), $r['status'], $r['pulled'], $r['created'], $r['updated'], $r['pushed'], $r['failed'], $r['note'] ? ' — '.$r['note'] : ''));
        }

        return self::SUCCESS;
    }
}
