<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Outreach\OutreachInbox;
use App\Outreach\OutreachService;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Sends approved mails within each account's allowance and drafts due follow-ups (which still wait for approval). */
final class OutreachRunCommand extends Command
{
    protected $signature = 'outreach:run';

    protected $description = 'Send approved outreach mails and queue due follow-ups';

    public function handle(OutreachService $svc, OutreachInbox $inbox, TenantContext $tenant): int
    {
        $admin = DB::connection('admin');
        $work = $admin->select("select distinct m.tenant_id, c.account_id from outreach_messages m join outreach_campaigns c on c.id = m.campaign_id where m.status = 'approved' and c.status = 'active' limit 200");
        foreach ($work as $w) {
            $r = $tenant->run(trim($w->tenant_id), fn () => $svc->sendFor(trim($w->account_id)));
            if ($r['sent'] + $r['failed'] > 0 || $r['stopped'] === 'smtp_auth') {
                $this->line(sprintf('account %s: sent %d, failed %d%s', substr(trim($w->account_id), 0, 8), $r['sent'], $r['failed'], $r['stopped'] ? ' ('.$r['stopped'].')' : ''));
            }
        }
        $tenants = $admin->select("select distinct tenant_id from outreach_campaigns where status = 'active' and followup_after_days is not null limit 200");
        foreach ($tenants as $t) {
            $tid = trim($t->tenant_id);
            $n = $tenant->run($tid, fn () => $svc->queueFollowups($tid));
            if ($n > 0) {
                $this->line("{$n} follow-up draft(s) waiting for approval.");
            }
        }

        // replies and bounces: only accounts with a mail still waiting for an answer, at most every 5 minutes each
        $due = $admin->select("select a.tenant_id, a.id as account_id from email_accounts a where (a.provider <> 'smtp' or a.imap_host is not null) and a.status = 'active'
            and (a.inbox_checked_at is null or a.inbox_checked_at < now() - interval '5 minutes')
            and exists (select 1 from outreach_messages m join outreach_campaigns c on c.id = m.campaign_id where c.account_id = a.id and m.status = 'sent' and m.sent_at > now() - interval '45 days') limit 100");
        foreach ($due as $d) {
            $tid = trim($d->tenant_id);
            $r = $tenant->run($tid, fn () => $inbox->poll($tid, trim($d->account_id)));
            if ($r['replied'] + $r['bounced'] > 0) {
                $this->line(sprintf('inbox %s: %d repl(ies), %d bounce(s)', substr(trim($d->account_id), 0, 8), $r['replied'], $r['bounced']));
            }
        }

        return self::SUCCESS;
    }
}
