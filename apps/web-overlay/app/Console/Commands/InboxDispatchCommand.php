<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Inbox\Channels\ChannelDispatcher;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Delivers queued Messenger / Instagram / WhatsApp / Telegram replies (retries failures, holds everything during an emergency stop). */
final class InboxDispatchCommand extends Command
{
    protected $signature = 'inbox:dispatch';

    protected $description = 'Send queued inbox messages to their channels';

    public function handle(ChannelDispatcher $dispatcher, TenantContext $tenant): int
    {
        $rows = DB::connection('admin')->select("select m.seq, m.tenant_id from inbox_messages m where m.status = 'queued' and m.created_at > now() - interval '2 days' order by m.seq limit ?",
            [max(1, (int) config('inbox.dispatch_batch', 50))]);
        $n = ['sent' => 0, 'failed' => 0, 'retry' => 0, 'held' => 0, 'skipped' => 0];
        foreach ($rows as $r) {
            try {
                $res = $tenant->run(trim($r->tenant_id), fn () => $dispatcher->dispatch((int) $r->seq));
            } catch (\Throwable $e) {
                $res = 'retry';
                report($e);
            }
            $n[$res] = ($n[$res] ?? 0) + 1;
        }
        $this->line(sprintf('inbox: sent %d, failed %d, retry %d, held %d', $n['sent'], $n['failed'], $n['retry'], $n['held']));

        return self::SUCCESS;
    }
}
