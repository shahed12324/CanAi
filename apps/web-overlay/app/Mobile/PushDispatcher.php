<?php

declare(strict_types=1);

namespace App\Mobile;

/** Turns "approvals are waiting" into one in-app notification per person, and sends not-yet-pushed notifications. */
final class PushDispatcher
{
    /** @param  \Closure(string, string): bool  $canApprove  (tenant, user) => may this person approve outreach */
    public function __construct(private readonly MobileAdminRepo $repo, private readonly PushSender $push, private readonly \Closure $canApprove) {}

    /** One unread "approvals waiting" note per person at a time, so a long queue does not become a flood. */
    public function digestApprovals(int $limit = 200): int
    {
        $made = 0;
        foreach ($this->repo->tenantsWithPendingApprovals($limit) as $t) {
            foreach ($this->repo->deviceUsers($t['tenant']) as $user) {
                if ($this->repo->hasUnread($t['tenant'], $user, 'approvals_pending') || ! ($this->canApprove)($t['tenant'], $user)) {
                    continue;
                }
                $this->repo->insertNotification($t['tenant'], $user, 'approvals_pending', 'Awaiting approval '.$t['pending'].' items', 'Open the app to look and decide.', 'approvals');
                $made++;
            }
        }

        return $made;
    }

    /** Phones whose login was deleted or expired stop receiving push. */
    public function retireDeadDevices(): int
    {
        return $this->repo->retireDeadDevices();
    }

    /** @return array{sent: int, failed: int} */
    public function flush(int $limit = 200): array
    {
        $rows = $this->repo->unpushed($limit);
        if ($rows === []) {
            return ['sent' => 0, 'failed' => 0];
        }
        $messages = array_map(fn (array $r) => ['to' => $r['token'], 'title' => $r['title'], 'body' => $r['body'], 'data' => ['id' => $r['id'], 'kind' => $r['kind'], 'ref_kind' => $r['ref_kind'], 'tenant' => $r['tenant']]], $rows);
        $results = $this->push->send($messages);
        $sent = $failed = 0;
        $delivered = [];
        foreach ($rows as $i => $r) {
            $res = $results[$i] ?? 'error:no_result';
            if ($res === 'ok') {
                $delivered[$r['id']] = true;
                $this->repo->markPushed($r['id'], null);
                $sent++;
            } elseif ($res === 'invalid_token') {
                $this->repo->dropPushToken($r['token']);       // the phone uninstalled the app: stop sending to it
                if (! isset($delivered[$r['id']])) {
                    $this->repo->markPushed($r['id'], 'invalid_token');
                }
                $failed++;
            } else {
                if (! isset($delivered[$r['id']])) {
                    $this->repo->markPushed($r['id'], $res);
                }
                $failed++;
            }
        }

        return ['sent' => $sent, 'failed' => $failed];
    }
}
