<?php

declare(strict_types=1);

namespace App\Outreach;

use App\Crm\CrmStore;
use App\Proposals\TemplateFiller;
use Closure;

/**
 * Outreach rules, in order of importance:
 *  1. nothing is sent unless a named person approved that exact message (and the database refuses otherwise),
 *  2. a suppressed address never gets mail, checked when queueing, when approving and again right before sending,
 *  3. an account never exceeds its (warm-up) daily limit.
 */
final class OutreachService
{
    public const MAX_ATTEMPTS = 3;

    /**
     * @param  Closure(string, int): string  $decrypt ciphertext, key version => the SMTP password
     * @param  Closure(array): string  $accessToken account => short-lived OAuth access token (Gmail / Graph)
     * @param  Closure(string): string  $unsubscribeUrl token => absolute URL
     * @param  Closure(): string  $today  Y-m-d in the workspace's day (Asia/Dhaka)
     * @param  Closure(): void  $pause  called between two mails so they do not leave in one burst
     * @param  ?Closure(array): array{name: string, mime: string, bytes: string}  $attachment  message => the proposal PDF; throws TransportFailure when it cannot be made
     */
    public function __construct(
        private readonly OutreachRepo $repo,
        private readonly CrmStore $crm,
        private readonly MailTransport $transport,
        private readonly Closure $decrypt,
        private readonly Closure $accessToken,
        private readonly Closure $unsubscribeUrl,
        private readonly Closure $today,
        private readonly Closure $pause,
        private readonly ?Closure $attachment = null,
    ) {}

    /**
     * @param  list<string>  $leadIds
     * @return array{queued: int, no_email: int, suppressed: int, duplicate: int, no_proposal: int}
     */
    public function queue(string $tenantId, string $campaignId, array $leadIds): array
    {
        $out = ['queued' => 0, 'no_email' => 0, 'suppressed' => 0, 'duplicate' => 0, 'no_proposal' => 0];
        $c = $this->repo->campaign($campaignId) ?? throw new \DomainException('campaign_not_found');
        $sender = $this->repo->sender();
        foreach (array_unique($leadIds) as $leadId) {
            $l = $this->repo->leadForOutreach($leadId);
            if ($l === null || $l['email'] === null || in_array($l['email_status'], ['no_mx', 'bounced', 'unsubscribed'], true)) {
                $out['no_email']++;

                continue;
            }
            if ($this->repo->isSuppressed($l['email'])) {
                $out['suppressed']++;

                continue;
            }
            $prop = null;
            if ($c['attach_proposal'] ?? false) {
                $prop = $this->repo->proposalFor($l['lead_id']);
                if ($prop === null) {
                    $out['no_proposal']++;                              // the mail promises an attachment: no proposal, no mail

                    continue;
                }
            }
            $vars = ['client_name' => $l['name'], 'domain' => $l['domain'], 'brand_name' => (string) ($sender['name'] ?? ''), 'gaps_list' => TemplateFiller::gapsList($l['analysis'], 'bn')];
            $m = Compose::fill($c['subject_tpl'], $c['body_tpl'], $vars);
            $id = $this->repo->enqueue($tenantId, $campaignId, $l['lead_id'], $l['contact_id'], $l['email'], 1, $m['subject'], $m['body'], $prop['id'] ?? null, $prop['stamp'] ?? null);
            $id === null ? $out['duplicate']++ : $out['queued']++;
        }

        return $out;
    }

    /**
     * @param  list<string>  $ids
     * @return array{approved: int, skipped: int}
     */
    public function approve(array $ids, string $userId): array
    {
        $ok = 0;
        foreach (array_unique($ids) as $id) {
            $this->repo->approve($id, $userId) ? $ok++ : null;
        }

        return ['approved' => $ok, 'skipped' => count(array_unique($ids)) - $ok];
    }

    /** @param list<string> $ids */
    public function reject(array $ids): int
    {
        $n = 0;
        foreach (array_unique($ids) as $id) {
            $this->repo->reject($id) ? $n++ : null;
        }

        return $n;
    }

    /** Sends what is approved, up to today's allowance of one account. @return array{sent: int, failed: int, deferred: int, stopped: ?string} */
    public function sendFor(string $accountId): array
    {
        $r = ['sent' => 0, 'failed' => 0, 'deferred' => 0, 'stopped' => null];
        $a = $this->repo->account($accountId);
        if ($a === null || $a['status'] !== 'active') {
            return ['stopped' => 'account_inactive'] + $r;
        }
        $this->repo->cancelSuppressedApproved($accountId);
        $allowance = Warmup::limitFor($a['daily_limit'], $a['warmup_started_on'], ($this->today)()) - $this->repo->usedToday($accountId);
        if ($allowance <= 0) {
            return ['stopped' => 'daily_limit'] + $r;
        }
        try {
            $conn = $a['provider'] === 'smtp'
                ? ['type' => 'smtp', 'host' => (string) $a['host'], 'port' => (int) $a['port'], 'security' => $a['security'], 'username' => (string) $a['username'], 'password' => ($this->decrypt)((string) $a['secret_ct'], $a['key_version'])]
                : ['type' => $a['provider'], 'access_token' => ($this->accessToken)($a)];
        } catch (TransportFailure $e) {
            return ['stopped' => $e->code_] + $r;                      // nothing was claimed: the approved mails wait for the account to be fixed
        }
        $sender = $this->repo->sender();

        $campaigns = [];
        $batch = $this->repo->claim($accountId, $allowance);
        foreach ($batch as $i => $m) {
            if ($r['stopped'] !== null) {
                $this->repo->release($m['id'], $r['stopped'], true);
                $r['deferred']++;

                continue;
            }
            if ($i > 0) {
                ($this->pause)();
            }
            $url = ($this->unsubscribeUrl)($m['unsub_token']);
            $camp = $campaigns[$m['campaign_id']] ??= $this->repo->campaign($m['campaign_id']);
            $wa = ($camp['whatsapp_button'] ?? false) ? WhatsAppLink::url($sender['phone'] ?? null, 'Contacting after looking at your email'.(($sender['name'] ?? '') !== '' ? ' — '.$sender['name'] : '')) : null;
            $mail = [
                'from_email' => $a['from_email'], 'from_name' => $a['from_name'], 'to' => $m['to_email'], 'subject' => $m['subject'],
                'body' => Compose::finalBody($m['body'], $url, $a['from_name'], $sender['address'] ?? null, $wa), 'headers' => Compose::headers($url),
            ];
            if ($m['proposal_id'] !== null) {
                // the approver approved THIS version of the proposal: if it was edited or deleted since, nothing is sent
                if ($m['proposal_stamp'] === null || ! $this->repo->proposalUnchanged($m['proposal_id'], $m['proposal_stamp'])) {
                    $this->repo->fail($m['id'], 'proposal_changed');
                    $r['failed']++;

                    continue;
                }
                try {
                    if ($this->attachment === null) {
                        throw new TransportFailure('proposal_unavailable', false);
                    }
                    $mail['attachments'] = [($this->attachment)($m)];
                } catch (TransportFailure $e) {
                    $this->repo->release($m['id'], $e->code_, true);        // the proposal service is down: try again later, the account is fine
                    $r['deferred']++;

                    continue;
                }
            }
            try {
                $sent = $this->transport->send($conn, $mail);
            } catch (TransportFailure $e) {
                if ($e->permanent || $m['attempts'] >= self::MAX_ATTEMPTS) {
                    $this->repo->fail($m['id'], $e->code_);
                    $r['failed']++;
                } else {
                    $this->repo->release($m['id'], $e->code_, true);
                    $r['deferred']++;
                }
                if (in_array($e->code_, ['smtp_auth', 'oauth_scope', 'oauth_revoked'], true)) {
                    $this->repo->setAccountError($a['id'], $e->code_);          // the owner sees why nothing is leaving
                }
                if (! $e->permanent) {
                    $r['stopped'] = $e->code_;          // the account (login, quota, outage) is the problem; do not hammer it with the rest
                }

                continue;
            }
            $this->repo->markSent($m['id'], $sent['message_id'], $sent['provider_id'], $sent['thread_id']);
            $r['sent']++;
            $r['sent'] === 1 ? $this->repo->setAccountError($a['id'], null) : null;
            if ($m['lead_id'] !== null) {
                $this->crm->addActivity($m['tenant_id'], $m['lead_id'], null, 'email', ($m['step'] === 2 ? 'Follow-up email sent: ' : 'Email sent: ').$m['subject'], ['to' => $m['to_email']]);
            }
        }

        return $r;
    }

    /** Creates the follow-up drafts that are due. They still wait for approval like any other mail. */
    public function queueFollowups(string $tenantId, int $limit = 100): int
    {
        $n = 0;
        foreach ($this->repo->dueFollowups($limit) as $d) {
            if ($this->repo->isSuppressed($d['to_email'])) {
                continue;
            }
            $c = $this->repo->campaign($d['campaign_id']);
            if ($c === null || $c['status'] !== 'active' || $c['followup_body_tpl'] === null) {
                continue;
            }
            $l = $d['lead_id'] === null ? null : $this->repo->leadForOutreach($d['lead_id']);
            $vars = ['client_name' => $l['name'] ?? '', 'domain' => $l['domain'] ?? '', 'brand_name' => (string) ($this->repo->sender()['name'] ?? ''), 'gaps_list' => $l === null ? '' : TemplateFiller::gapsList($l['analysis'], 'bn')];
            $m = Compose::fill('', $c['followup_body_tpl'], $vars);
            $subject = mb_substr(preg_match('/^re:/i', $d['subject']) === 1 ? $d['subject'] : 'Re: '.$d['subject'], 0, 200);   // the old subject is plain text, never a template
            $this->repo->enqueue($tenantId, $d['campaign_id'], $d['lead_id'], $d['contact_id'], $d['to_email'], 2, $subject, $m['body']) !== null ? $n++ : null;
        }

        return $n;
    }

    public function markReplied(string $id): bool
    {
        return $this->repo->markReplied($id);
    }

    public function markBounced(string $id): bool
    {
        return $this->repo->markBounced($id);
    }
}
