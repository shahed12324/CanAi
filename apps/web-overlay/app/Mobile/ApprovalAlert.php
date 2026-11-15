<?php

declare(strict_types=1);

namespace App\Mobile;

/**
 * "Your computer is waiting for your approval" - sent to the person's phone at once (not at the next minute's digest).
 * Best effort: a failure here must never stop the request or the plan, and the phone's own polling still shows the item.
 * The text is fixed and generic on purpose: push messages travel through Expo's servers, so no file name, path, goal or command is ever put in one.
 * At most one UNREAD alert per person at a time (a burst of plans does not become a flood); opening the notifications screen clears it.
 */
final class ApprovalAlert
{
    public const KIND = 'pc_approval';

    public function __construct(private readonly MobileAdminRepo $repo, private readonly PushDispatcher $push) {}

    public function computerWaiting(string $tenantId, string $userId, bool $isPlan): bool
    {
        try {
            if ($this->repo->hasUnread($tenantId, $userId, self::KIND)) {
                return false;
            }
            $this->repo->insertNotification($tenantId, $userId, self::KIND, 'Your computer is waiting for approval',
                $isPlan ? 'A plan needs your decision. Open the app to review it.' : 'An action needs your decision. Open the app to review it.', 'pc');
            $this->push->flush(20);

            return true;
        } catch (\Throwable $e) {
            if (function_exists('report')) {
                report($e);
            }

            return false;
        }
    }
}
