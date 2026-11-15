<?php

declare(strict_types=1);

namespace App\Outreach;

interface OutreachRepo
{
    /** @return array{id: string, provider: string, from_email: string, from_name: ?string, host: ?string, port: ?int, security: string, username: ?string, secret_ct: ?string, refresh_ct: ?string, key_version: int, daily_limit: int, warmup_started_on: string, status: string}|null */
    public function account(string $id): ?array;

    /** @return array{id: string, account_id: string, subject_tpl: string, body_tpl: string, followup_after_days: ?int, followup_body_tpl: ?string, status: string}|null */
    public function campaign(string $id): ?array;

    /** @return array{lead_id: string, contact_id: ?string, email: ?string, email_status: ?string, name: string, domain: string, analysis: array<string, mixed>}|null */
    public function leadForOutreach(string $leadId): ?array;

    /** the lead's newest proposal that is not deleted (the one a mail would carry), if any */
    public function proposalFor(string $leadId): ?array;

    /** true while that proposal still exists, is not deleted and has not been edited since $stamp (a timestamp string as returned by proposalFor) */
    public function proposalUnchanged(string $proposalId, string $stamp): bool;

    /** name, address and phone of the default brand kit, if any */
    public function sender(): array;

    public function isSuppressed(string $email): bool;

    /** null when this person already has this step in this campaign */
    public function enqueue(string $tenantId, string $campaignId, ?string $leadId, ?string $contactId, string $email, int $step, string $subject, string $body, ?string $proposalId = null, ?string $proposalStamp = null): ?string;

    /** false when the message is not pending or the address is suppressed */
    public function approve(string $id, string $userId): bool;

    public function reject(string $id): bool;

    /** approved mails to addresses that have since been suppressed are cancelled; returns how many */
    public function cancelSuppressedApproved(string $accountId): int;

    /** mails that took a slot today (sent or being sent) */
    public function usedToday(string $accountId): int;

    /** @return list<array{id: string, tenant_id: string, campaign_id: string, approved_by: ?string, proposal_id: ?string, proposal_stamp: ?string, lead_id: ?string, to_email: string, subject: string, body: string, unsub_token: string, attempts: int, step: int}> */
    public function claim(string $accountId, int $n): array;

    public function markSent(string $id, string $rfcMessageId, ?string $providerId, ?string $threadId): void;

    /** null clears the error; a code is shown in the UI so the owner knows to reconnect */
    public function setAccountError(string $id, ?string $code): void;

    public function saveRefreshToken(string $id, string $cipher, int $version): void;

    /** back to "approved" for another try; $refund gives the attempt back (the mail itself did nothing wrong) */
    public function release(string $id, string $code, bool $refund): void;

    public function fail(string $id, string $code): void;

    /** @return list<array{campaign_id: string, lead_id: ?string, contact_id: ?string, to_email: string, subject: string}> first mails old enough for a follow-up and not yet followed up */
    public function dueFollowups(int $limit): array;

    /** sent -> replied (also cancels the pending follow-up) */
    public function markReplied(string $id): bool;

    /** sent -> bounced (the database then suppresses the address) */
    public function markBounced(string $id): bool;

    public function inboundSeen(string $accountId, string $providerMsgId): bool;

    /** false when this inbound mail was already handled (so a repeated poll changes nothing) */
    public function inboundClaim(string $tenantId, string $accountId, string $providerMsgId, string $kind, ?string $messageId, ?string $receivedAt): bool;

    /** the newest still-"sent" mail of this account that this inbound mail can belong to: same address, same thread or same Message-ID, sent before it arrived */
    public function findSent(string $accountId, ?string $email, ?string $threadId, ?string $inReplyTo, ?string $receivedAt): ?string;

    /** unix time to look from, or null when no sent mail is waiting for an answer */
    public function pollSince(string $accountId): ?int;

    public function setInboxChecked(string $accountId, int $epoch): void;
}
