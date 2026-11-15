<?php

declare(strict_types=1);

namespace App\Outreach;

use Closure;

/**
 * Looks for answers to what was sent. A real reply marks the mail "replied" (which also cancels its follow-up);
 * a delivery-failure notice marks it "bounced" (the database then suppresses the address); out-of-office
 * robots and unrelated mail change nothing. Every inbound mail is handled once.
 */
final class OutreachInbox
{
    /**
     * @param  array<string, InboxReader>  $readers  'gmail' | 'graph'
     * @param  Closure(array): string  $accessToken
     * @param  Closure(): int  $now  unix time
     * @param  ?Closure(string, int): string  $decrypt  ciphertext, key version => the mailbox password (IMAP accounts)
     */
    public function __construct(private readonly OutreachRepo $repo, private readonly array $readers, private readonly Closure $accessToken, private readonly Closure $now, private readonly ?Closure $decrypt = null) {}

    /** @return array{replied: int, bounced: int, auto: int, other: int, stopped: ?string} */
    public function poll(string $tenantId, string $accountId): array
    {
        $r = ['replied' => 0, 'bounced' => 0, 'auto' => 0, 'other' => 0, 'stopped' => null];
        $a = $this->repo->account($accountId);
        $imap = $a !== null && $a['provider'] === 'smtp' && ($a['imap_host'] ?? null) !== null && $this->decrypt !== null;
        $reader = $a === null ? null : ($this->readers[$imap ? 'imap' : $a['provider']] ?? null);
        if ($a === null || $reader === null || $a['status'] !== 'active') {
            return ['stopped' => 'no_inbox'] + $r;
        }
        $since = $this->repo->pollSince($accountId);
        if ($since === null) {
            return ['stopped' => 'nothing_pending'] + $r;
        }
        $started = ($this->now)();
        try {
            $conn = $imap
                ? ['type' => 'imap', 'host' => (string) $a['imap_host'], 'port' => (int) $a['imap_port'], 'security' => (string) $a['imap_security'], 'username' => (string) $a['username'], 'password' => ($this->decrypt)((string) $a['secret_ct'], $a['key_version'])]
                : ['type' => $a['provider'], 'access_token' => ($this->accessToken)($a)];
            $msgs = $reader->fetch($conn, gmdate('c', $since), fn (string $id) => $this->repo->inboundSeen($accountId, $id));
        } catch (TransportFailure $e) {
            return ['stopped' => $e->code_] + $r;
        } catch (InboxFailure $e) {
            if (in_array($e->getMessage(), ['inbox_scope', 'imap_auth'], true)) {
                $this->repo->setAccountError($accountId, $e->getMessage());           // the user did not allow reading: sending still works
            }

            return ['stopped' => $e->getMessage()] + $r;
        }
        foreach ($msgs as $m) {
            $kind = InboundClassifier::kind($m, [$a['from_email']]);
            $irt = $m['in_reply_to'] === null ? null : trim($m['in_reply_to'], '<> ');
            $mid = match ($kind) {
                'bounce' => ($addr = InboundClassifier::failedRecipient($m, [$a['from_email']])) === null ? null : $this->repo->findSent($accountId, $addr, null, null, $m['received_at']),
                default => $this->repo->findSent($accountId, $m['from'], $m['thread_id'], $irt, $m['received_at']),
            };
            if ($kind === 'reply' && $mid === null) {
                $kind = 'other';                                                      // somebody we never wrote to
            }
            if ($kind === 'bounce' && $mid === null) {
                $kind = 'other';                                                      // a notice we can not tie to one of our mails
            }
            if (! $this->repo->inboundClaim($tenantId, $accountId, $m['id'], $kind, $mid, $m['received_at'])) {
                continue;
            }
            if ($kind === 'reply' && $mid !== null && $this->repo->markReplied($mid)) {
                $r['replied']++;
            } elseif ($kind === 'bounce' && $mid !== null && $this->repo->markBounced($mid)) {
                $r['bounced']++;
            } else {
                $r[$kind === 'auto' ? 'auto' : 'other']++;
            }
        }
        $this->repo->setInboxChecked($accountId, $started);

        return $r;
    }
}
