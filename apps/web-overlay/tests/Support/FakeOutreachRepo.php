<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Outreach\OutreachRepo;

/** Same rules as the SQL + triggers in phase9b: approval required, suppression blocks, one mail per person and step. */
final class FakeOutreachRepo implements OutreachRepo
{
    public array $accounts = [];

    public array $campaigns = [];

    public array $leads = [];

    public array $suppressed = [];

    public array $messages = [];

    public array $sender = ['name' => 'Agency', 'address' => 'Dhaka', 'phone' => null];

    /** @var array<string, array{id: string, stamp: string}> lead id => newest proposal */
    public array $proposals = [];

    /** @var array<string, string> proposal id => current stamp (missing = deleted) */
    public array $proposalNow = [];

    public string $now = '2026-11-02';

    private int $n = 0;

    public function account(string $id): ?array
    {
        return $this->accounts[$id] ?? null;
    }

    public function campaign(string $id): ?array
    {
        return $this->campaigns[$id] ?? null;
    }

    public function leadForOutreach(string $leadId): ?array
    {
        return $this->leads[$leadId] ?? null;
    }

    public function sender(): array
    {
        return $this->sender;
    }

    public function proposalFor(string $leadId): ?array
    {
        return $this->proposals[$leadId] ?? null;
    }

    public function proposalUnchanged(string $proposalId, string $stamp): bool
    {
        return ($this->proposalNow[$proposalId] ?? null) === $stamp;
    }

    public function isSuppressed(string $email): bool
    {
        return isset($this->suppressed[strtolower($email)]);
    }

    public function enqueue(string $tenantId, string $campaignId, ?string $leadId, ?string $contactId, string $email, int $step, string $subject, string $body, ?string $proposalId = null, ?string $proposalStamp = null): ?string
    {
        $email = strtolower($email);
        foreach ($this->messages as $m) {
            if ($m['campaign_id'] === $campaignId && $m['to_email'] === $email && $m['step'] === $step) {
                return null;
            }
        }
        if ($this->isSuppressed($email)) {
            return null;
        }
        $id = 'msg'.str_pad((string) ++$this->n, 23, '0', STR_PAD_LEFT);
        $this->messages[$id] = ['id' => $id, 'tenant_id' => $tenantId, 'campaign_id' => $campaignId, 'lead_id' => $leadId, 'contact_id' => $contactId, 'to_email' => $email, 'step' => $step, 'subject' => $subject, 'body' => $body, 'status' => 'pending_approval', 'proposal_id' => $proposalId, 'proposal_stamp' => $proposalStamp, 'approved_by' => null, 'attempts' => 0, 'unsub_token' => md5($id), 'error_code' => null, 'sent_day' => null, 'sent_age_days' => null];

        return $id;
    }

    public function approve(string $id, string $userId): bool
    {
        $m = $this->messages[$id] ?? null;
        if ($m === null || $m['status'] !== 'pending_approval' || $this->isSuppressed($m['to_email'])) {
            return false;
        }
        $this->messages[$id]['status'] = 'approved';
        $this->messages[$id]['approved_by'] = $userId;

        return true;
    }

    public function reject(string $id): bool
    {
        if (($this->messages[$id]['status'] ?? '') !== 'pending_approval') {
            return false;
        }
        $this->messages[$id]['status'] = 'rejected';

        return true;
    }

    private function accountOf(array $m): ?string
    {
        return $this->campaigns[$m['campaign_id']]['account_id'] ?? null;
    }

    public function cancelSuppressedApproved(string $accountId): int
    {
        $n = 0;
        foreach ($this->messages as $id => $m) {
            if ($m['status'] === 'approved' && $this->accountOf($m) === $accountId && $this->isSuppressed($m['to_email'])) {
                $this->messages[$id]['status'] = 'cancelled';
                $this->messages[$id]['error_code'] = 'suppressed';
                $n++;
            }
        }

        return $n;
    }

    public function usedToday(string $accountId): int
    {
        $n = 0;
        foreach ($this->messages as $m) {
            if ($this->accountOf($m) === $accountId && ($m['status'] === 'sending' || $m['sent_day'] === $this->now)) {
                $n++;
            }
        }

        return $n;
    }

    public function claim(string $accountId, int $n): array
    {
        $out = [];
        foreach ($this->messages as $id => $m) {
            if (count($out) >= $n) {
                break;
            }
            if ($m['status'] === 'approved' && $this->accountOf($m) === $accountId && ($this->campaigns[$m['campaign_id']]['status'] ?? '') === 'active') {
                $this->messages[$id]['status'] = 'sending';
                $this->messages[$id]['attempts']++;
                $out[] = $this->messages[$id];
            }
        }

        return $out;
    }

    public array $errors = [];

    public array $refreshSaved = [];

    public function setAccountError(string $id, ?string $code): void
    {
        $this->errors[$id] = $code;
    }

    public function saveRefreshToken(string $id, string $cipher, int $version): void
    {
        $this->refreshSaved[$id] = [$cipher, $version];
    }

    public function markSent(string $id, string $rfcMessageId, ?string $providerId, ?string $threadId): void
    {
        $this->messages[$id]['status'] = 'sent';
        $this->messages[$id]['sent_day'] = $this->now;
        $this->messages[$id]['sent_age_days'] = 0;
        $this->messages[$id]['rfc'] = $rfcMessageId;
        $this->messages[$id]['provider_id'] = $providerId;
        $this->messages[$id]['thread_id'] = $threadId;
    }

    public function release(string $id, string $code, bool $refund): void
    {
        $this->messages[$id]['status'] = $this->isSuppressed($this->messages[$id]['to_email']) ? 'cancelled' : 'approved';
        $this->messages[$id]['error_code'] = $code;
        if ($refund) {
            $this->messages[$id]['attempts'] = max(0, $this->messages[$id]['attempts'] - 1);
        }
    }

    public function fail(string $id, string $code): void
    {
        $this->messages[$id]['status'] = 'failed';
        $this->messages[$id]['error_code'] = $code;
    }

    public function dueFollowups(int $limit): array
    {
        $out = [];
        foreach ($this->messages as $m) {
            $c = $this->campaigns[$m['campaign_id']] ?? null;
            if ($m['step'] !== 1 || $m['status'] !== 'sent' || $c === null || $c['status'] !== 'active' || $c['followup_after_days'] === null || $m['sent_age_days'] < $c['followup_after_days']) {
                continue;
            }
            foreach ($this->messages as $f) {
                if ($f['campaign_id'] === $m['campaign_id'] && $f['to_email'] === $m['to_email'] && $f['step'] === 2) {
                    continue 2;
                }
            }
            $out[] = ['campaign_id' => $m['campaign_id'], 'lead_id' => $m['lead_id'], 'contact_id' => $m['contact_id'], 'to_email' => $m['to_email'], 'subject' => $m['subject']];
        }

        return array_slice($out, 0, $limit);
    }

    public function markReplied(string $id): bool
    {
        $m = $this->messages[$id] ?? null;
        if ($m === null || $m['status'] !== 'sent') {
            return false;
        }
        $this->messages[$id]['status'] = 'replied';
        foreach ($this->messages as $k => $f) {
            if ($f['campaign_id'] === $m['campaign_id'] && $f['to_email'] === $m['to_email'] && $f['step'] === 2 && in_array($f['status'], ['pending_approval', 'approved'], true)) {
                $this->messages[$k]['status'] = 'cancelled';
            }
        }

        return true;
    }

    public function markBounced(string $id): bool
    {
        if (($this->messages[$id]['status'] ?? '') !== 'sent') {
            return false;
        }
        $this->messages[$id]['status'] = 'bounced';
        $this->suppressed[$this->messages[$id]['to_email']] = 'bounced';

        return true;
    }

    public array $inbound = [];

    public array $checked = [];

    public function inboundSeen(string $accountId, string $providerMsgId): bool
    {
        return isset($this->inbound["$accountId|$providerMsgId"]);
    }

    public function inboundClaim(string $tenantId, string $accountId, string $providerMsgId, string $kind, ?string $messageId, ?string $receivedAt): bool
    {
        if ($this->inboundSeen($accountId, $providerMsgId)) {
            return false;
        }
        $this->inbound["$accountId|$providerMsgId"] = ['kind' => $kind, 'message_id' => $messageId];

        return true;
    }

    public function findSent(string $accountId, ?string $email, ?string $threadId, ?string $inReplyTo, ?string $receivedAt): ?string
    {
        $best = null;
        foreach ($this->messages as $id => $m) {
            if ($m['status'] !== 'sent' || $this->accountOf($m) !== $accountId) {
                continue;
            }
            $hit = ($email !== null && $m['to_email'] === strtolower($email)) || ($threadId !== null && ($m['thread_id'] ?? null) === $threadId) || ($inReplyTo !== null && ($m['rfc'] ?? null) === $inReplyTo);
            if ($hit) {
                $best = $id;                                   // later rows are newer in this fake
            }
        }

        return $best;
    }

    public function pollSince(string $accountId): ?int
    {
        foreach ($this->messages as $m) {
            if ($m['status'] === 'sent' && $this->accountOf($m) === $accountId) {
                return $this->checked[$accountId] ?? 1_000;
            }
        }

        return null;
    }

    public function setInboxChecked(string $accountId, int $epoch): void
    {
        $this->checked[$accountId] = $epoch;
    }
}
