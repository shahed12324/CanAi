<?php

declare(strict_types=1);

namespace App\Mobile;

/** Everything the phone API needs from the database, always inside the caller's workspace (RLS). */
interface MobileRepo
{
    public function registerDevice(string $tenant, string $user, int $tokenId, string $name, string $platform, ?string $pushToken, ?string $appVersion): string;

    /** @return array{id: string, has_pin: bool, pin_hash: ?string, locked_until: ?string, push_token: ?string}|null */
    public function device(string $tenant, string $user, int $tokenId): ?array;

    public function setPushToken(string $deviceId, ?string $token): void;

    public function revokeDevice(string $deviceId): void;

    public function revokeToken(string $user, int $tokenId): int;

    public function setPin(string $deviceId, string $user, string $hash): void;

    /** @return string ok|bad|locked|no_pin */
    public function pinAttempt(string $deviceId, string $user, bool $ok): string;

    public function stepUpActive(string $deviceId, string $user): bool;

    /** @return list<array{kind: string, ref: string, title: string, summary: string, created_at: string}> */
    public function approvals(int $limit): array;

    /** @return string approved|rejected|not_pending|suppressed|step_up_required|emergency|unknown_kind */
    public function decide(string $deviceId, string $user, string $kind, string $ref, string $decision): string;

    /** @return string stopped|resumed|unchanged|step_up_required */
    public function emergency(string $deviceId, string $user, bool $stop, string $reason): string;

    /** @return array{active: bool, since: ?string, reason: ?string} */
    public function emergencyState(string $tenant): array;

    /** @return list<array{id: string, kind: string, title: string, body: string, ref_kind: ?string, ref_id: ?string, created_at: string, read: bool}> */
    public function notifications(string $user, bool $onlyUnread, int $limit): array;

    public function unreadCount(string $user): int;

    /** @param  list<string>|null  $ids  null = all */
    public function markRead(string $user, ?array $ids): int;

    public function notify(string $tenant, string $user, string $kind, string $title, string $body, ?string $refKind = null, ?string $refId = null): string;

    /** @return list<string> users that have a live device in this workspace */
    public function deviceUsers(string $tenant): array;
}
