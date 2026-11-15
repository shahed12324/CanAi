<?php

declare(strict_types=1);

namespace App\Outreach;

use App\Actions\Tenancy\CreateWorkspace;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** Raw SQL on purpose: every statement runs under the tenant's RLS and is executed against real Postgres in tests/db. */
final class PgOutreachRepo implements OutreachRepo
{
    public function account(string $id): ?array
    {
        $r = DB::selectOne("select id, provider, from_email, from_name, host, port, security, username, secret_ct, refresh_ct, key_version, daily_limit, warmup_started_on::text as warmup_started_on, status, imap_host, imap_port, imap_security from email_accounts where id = ?", [$id]);

        return $r === null ? null : ['id' => trim($r->id), 'provider' => $r->provider, 'from_email' => $r->from_email, 'from_name' => $r->from_name, 'host' => $r->host, 'port' => $r->port === null ? null : (int) $r->port, 'security' => $r->security, 'username' => $r->username, 'secret_ct' => $r->secret_ct, 'imap_host' => $r->imap_host, 'imap_port' => $r->imap_port === null ? null : (int) $r->imap_port, 'imap_security' => $r->imap_security, 'refresh_ct' => $r->refresh_ct, 'key_version' => (int) $r->key_version, 'daily_limit' => (int) $r->daily_limit, 'warmup_started_on' => $r->warmup_started_on, 'status' => $r->status];
    }

    public function campaign(string $id): ?array
    {
        $r = DB::selectOne("select id, account_id, subject_tpl, body_tpl, followup_after_days, followup_body_tpl, status, whatsapp_button, attach_proposal from outreach_campaigns where id = ?", [$id]);

        return $r === null ? null : ['id' => trim($r->id), 'account_id' => trim($r->account_id), 'subject_tpl' => $r->subject_tpl, 'body_tpl' => $r->body_tpl, 'followup_after_days' => $r->followup_after_days === null ? null : (int) $r->followup_after_days, 'followup_body_tpl' => $r->followup_body_tpl, 'status' => $r->status, 'whatsapp_button' => (bool) $r->whatsapp_button, 'attach_proposal' => (bool) $r->attach_proposal];
    }

    public function leadForOutreach(string $leadId): ?array
    {
        $r = DB::selectOne("select l.id as lead_id, c.name, c.domain, c.analysis, k.id as contact_id, k.email, k.email_status
            from leads l join companies c on c.id = l.company_id
            left join lateral (select ct.id, ct.email, ct.email_status from contacts ct
                where ct.company_id = c.id and ct.email is not null and ct.email_status not in ('bounced', 'unsubscribed', 'no_mx')
                order by case ct.email_status when 'mx_ok' then 0 when 'syntax_ok' then 1 else 2 end, ct.collected_at limit 1) k on true
            where l.id = ? and l.deleted_at is null", [$leadId]);

        return $r === null ? null : ['lead_id' => trim($r->lead_id), 'contact_id' => $r->contact_id === null ? null : trim($r->contact_id), 'email' => $r->email, 'email_status' => $r->email_status, 'name' => $r->name, 'domain' => $r->domain, 'analysis' => json_decode((string) $r->analysis, true) ?: []];
    }

    public function sender(): array
    {
        $r = DB::selectOne("select name, address, phone from brand_kits where is_default limit 1");

        return $r === null ? [] : ['name' => $r->name, 'address' => $r->address, 'phone' => $r->phone];
    }

    public function proposalFor(string $leadId): ?array
    {
        $r = DB::selectOne('select id, updated_at::text as stamp from proposals where lead_id = ? and deleted_at is null order by updated_at desc, id desc limit 1', [$leadId]);

        return $r === null ? null : ['id' => trim($r->id), 'stamp' => (string) $r->stamp];
    }

    public function proposalUnchanged(string $proposalId, string $stamp): bool
    {
        return DB::selectOne('select 1 as x from proposals where id = ? and deleted_at is null and updated_at = ?::timestamptz', [$proposalId, $stamp]) !== null;
    }

    public function isSuppressed(string $email): bool
    {
        return DB::selectOne("select 1 as x from outreach_suppressions where email = ?", [strtolower($email)]) !== null;
    }

    public function enqueue(string $tenantId, string $campaignId, ?string $leadId, ?string $contactId, string $email, int $step, string $subject, string $body, ?string $proposalId = null, ?string $proposalStamp = null): ?string
    {
        $id = CreateWorkspace::newId();
        try {
            $n = DB::affectingStatement("insert into outreach_messages (id, tenant_id, campaign_id, lead_id, contact_id, to_email, step, subject, body, proposal_id, proposal_stamp) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?::timestamptz) on conflict (campaign_id, to_email, step) do nothing",
                [$id, $tenantId, $campaignId, $leadId, $contactId, strtolower($email), $step, $subject, $body, $proposalId, $proposalStamp]);
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'suppressed')) {
                return null;
            }
            throw $e;
        }

        return $n === 1 ? $id : null;
    }

    public function approve(string $id, string $userId): bool
    {
        try {
            return DB::affectingStatement("update outreach_messages set status = 'approved', approved_by = ?, approved_at = now() where id = ? and status = 'pending_approval' and not emergency_active(tenant_id)", [$userId, $id]) === 1;   // a stopped workspace approves nothing
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'suppressed')) {
                return false;
            }
            throw $e;
        }
    }

    public function reject(string $id): bool
    {
        return DB::affectingStatement("update outreach_messages set status = 'rejected' where id = ? and status = 'pending_approval'", [$id]) === 1;
    }

    public function cancelSuppressedApproved(string $accountId): int
    {
        return DB::affectingStatement("update outreach_messages m set status = 'cancelled', error_code = 'suppressed' from outreach_campaigns c
            where c.id = m.campaign_id and c.account_id = ? and m.status = 'approved'
              and exists (select 1 from outreach_suppressions s where s.tenant_id = m.tenant_id and s.email = m.to_email)", [$accountId]);
    }

    public function usedToday(string $accountId): int
    {
        $r = DB::selectOne("select count(*) as n from outreach_messages m join outreach_campaigns c on c.id = m.campaign_id
            where c.account_id = ? and (m.status = 'sending' or m.sent_at >= (date_trunc('day', now() at time zone 'Asia/Dhaka') at time zone 'Asia/Dhaka'))", [$accountId]);

        return (int) $r->n;
    }

    public function claim(string $accountId, int $n): array
    {
        $rows = DB::select("update outreach_messages set status = 'sending', attempts = attempts + 1
            where id in (select m.id from outreach_messages m join outreach_campaigns c on c.id = m.campaign_id
                where c.account_id = ? and c.status = 'active' and m.status = 'approved' and not emergency_active(m.tenant_id)
                order by m.created_at limit ? for update of m skip locked)
            returning id, tenant_id, lead_id, to_email, subject, body, unsub_token, attempts, step, campaign_id, approved_by, proposal_id, proposal_stamp::text as proposal_stamp", [$accountId, $n]);
        $out = [];
        foreach ($rows as $r) {
            $out[] = ['id' => trim($r->id), 'tenant_id' => trim($r->tenant_id), 'campaign_id' => trim($r->campaign_id), 'approved_by' => $r->approved_by === null ? null : trim($r->approved_by), 'proposal_id' => $r->proposal_id === null ? null : trim($r->proposal_id), 'proposal_stamp' => $r->proposal_stamp, 'lead_id' => $r->lead_id === null ? null : trim($r->lead_id), 'to_email' => $r->to_email, 'subject' => $r->subject, 'body' => $r->body, 'unsub_token' => $r->unsub_token, 'attempts' => (int) $r->attempts, 'step' => (int) $r->step];
        }

        return $out;
    }

    public function markSent(string $id, string $rfcMessageId, ?string $providerId, ?string $threadId): void
    {
        DB::update("update outreach_messages set status = 'sent', sent_at = now(), rfc_message_id = ?, provider_message_id = ?, thread_id = ?, error_code = null where id = ? and status = 'sending'", [$rfcMessageId, $providerId, $threadId, $id]);
    }

    public function setAccountError(string $id, ?string $code): void
    {
        DB::update("update email_accounts set last_error = ?, last_error_at = case when ?::text is null then null else now() end where id = ? and last_error is distinct from ?::text", [$code, $code, $id, $code]);
    }

    public function saveRefreshToken(string $id, string $cipher, int $version): void
    {
        DB::update("update email_accounts set refresh_ct = ?, key_version = ?, updated_at = now() where id = ? and provider <> 'smtp'", [$cipher, $version, $id]);
    }

    public function release(string $id, string $code, bool $refund): void
    {
        try {
            DB::update("update outreach_messages set status = 'approved', error_code = ?, attempts = case when ?::int = 1 then greatest(attempts - 1, 0) else attempts end where id = ? and status = 'sending'", [$code, $refund ? 1 : 0, $id]);
        } catch (QueryException $e) {
            if (! str_contains($e->getMessage(), 'suppressed')) {
                throw $e;
            }
            DB::update("update outreach_messages set status = 'cancelled', error_code = 'suppressed' where id = ? and status = 'sending'", [$id]);   // suppressed while it was in flight
        }
    }

    public function fail(string $id, string $code): void
    {
        DB::update("update outreach_messages set status = 'failed', error_code = ? where id = ? and status = 'sending'", [$code, $id]);
    }

    public function dueFollowups(int $limit): array
    {
        $rows = DB::select("select m.campaign_id, m.lead_id, m.contact_id, m.to_email, m.subject from outreach_messages m join outreach_campaigns c on c.id = m.campaign_id
            where m.step = 1 and m.status = 'sent' and c.status = 'active' and c.followup_after_days is not null
              and m.sent_at <= now() - make_interval(days => c.followup_after_days)
              and not exists (select 1 from outreach_messages f where f.campaign_id = m.campaign_id and f.to_email = m.to_email and f.step = 2)
            order by m.sent_at limit ?", [$limit]);
        $out = [];
        foreach ($rows as $r) {
            $out[] = ['campaign_id' => trim($r->campaign_id), 'lead_id' => $r->lead_id === null ? null : trim($r->lead_id), 'contact_id' => $r->contact_id === null ? null : trim($r->contact_id), 'to_email' => $r->to_email, 'subject' => $r->subject];
        }

        return $out;
    }

    public function markReplied(string $id): bool
    {
        $ok = DB::affectingStatement("update outreach_messages set status = 'replied' where id = ? and status = 'sent'", [$id]) === 1;
        if ($ok) {
            DB::update("update outreach_messages f set status = 'cancelled', error_code = 'replied' from outreach_messages m
                where m.id = ? and f.campaign_id = m.campaign_id and f.to_email = m.to_email and f.step = 2 and f.status in ('pending_approval', 'approved')", [$id]);
        }

        return $ok;
    }

    public function markBounced(string $id): bool
    {
        return DB::affectingStatement("update outreach_messages set status = 'bounced' where id = ? and status = 'sent'", [$id]) === 1;
    }

    public function inboundSeen(string $accountId, string $providerMsgId): bool
    {
        return DB::selectOne("select 1 as x from outreach_inbound where account_id = ? and provider_msg_id = ?", [$accountId, $providerMsgId]) !== null;
    }

    public function inboundClaim(string $tenantId, string $accountId, string $providerMsgId, string $kind, ?string $messageId, ?string $receivedAt): bool
    {
        return DB::affectingStatement("insert into outreach_inbound (account_id, provider_msg_id, tenant_id, kind, message_id, received_at) values (?, ?, ?, ?, ?, ?::timestamptz) on conflict do nothing",
            [$accountId, mb_substr($providerMsgId, 0, 200), $tenantId, $kind, $messageId, $receivedAt]) === 1;
    }

    public function findSent(string $accountId, ?string $email, ?string $threadId, ?string $inReplyTo, ?string $receivedAt): ?string
    {
        $email = $email === null ? null : strtolower($email);
        $r = DB::selectOne("select m.id from outreach_messages m join outreach_campaigns c on c.id = m.campaign_id
            where c.account_id = ? and m.status = 'sent' and m.sent_at > now() - interval '45 days'
              and (?::timestamptz is null or m.sent_at <= ?::timestamptz)
              and ((?::text is not null and m.to_email = ?::text) or (?::text is not null and m.thread_id = ?::text) or (?::text is not null and m.rfc_message_id = ?::text))
            order by m.sent_at desc limit 1", [$accountId, $receivedAt, $receivedAt, $email, $email, $threadId, $threadId, $inReplyTo, $inReplyTo]);

        return $r === null ? null : trim($r->id);
    }

    public function pollSince(string $accountId): ?int
    {
        $r = DB::selectOne("select extract(epoch from greatest(coalesce(a.inbox_checked_at, x.first_sent) - interval '10 minutes', now() - interval '45 days'))::bigint as since
            from email_accounts a
            join lateral (select min(m.sent_at) as first_sent, count(*) as n from outreach_messages m join outreach_campaigns c on c.id = m.campaign_id
                where c.account_id = a.id and m.status = 'sent' and m.sent_at > now() - interval '45 days') x on x.n > 0
            where a.id = ?", [$accountId]);

        return $r === null ? null : (int) $r->since;
    }

    public function setInboxChecked(string $accountId, int $epoch): void
    {
        DB::update("update email_accounts set inbox_checked_at = to_timestamp(?) where id = ?", [$epoch, $accountId]);
    }
}
