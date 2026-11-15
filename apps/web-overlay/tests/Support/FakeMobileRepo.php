<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Mobile\MobileRepo;

/** Records calls; answers come from the public properties so a test can script the database's replies. */
final class FakeMobileRepo implements MobileRepo
{
    public array $calls = [];

    public ?array $device = ['id' => 'dev', 'has_pin' => false, 'pin_hash' => null, 'locked_until' => null, 'push_token' => null];

    public string $pinResult = 'ok';

    public string $emergencyResult = 'stopped';

    public array $users = ['u1', 'u2'];

    public array $notified = [];

    private function log(string $m, array $a): void
    {
        $this->calls[] = [$m, $a];
    }

    public function count(string $m): int
    {
        return count(array_filter($this->calls, fn ($c) => $c[0] === $m));
    }

    public function registerDevice(string $tenant, string $user, int $tokenId, string $name, string $platform, ?string $pushToken, ?string $appVersion): string
    {
        $this->log(__FUNCTION__, compact('pushToken', 'name'));

        return 'dev';
    }

    public function device(string $tenant, string $user, int $tokenId): ?array
    {
        return $this->device;
    }

    public function setPushToken(string $deviceId, ?string $token): void
    {
        $this->log(__FUNCTION__, compact('token'));
    }

    public function revokeDevice(string $deviceId): void
    {
        $this->log(__FUNCTION__, []);
    }

    public function revokeToken(string $user, int $tokenId): int
    {
        return 0;
    }

    public function setPin(string $deviceId, string $user, string $hash): void
    {
        $this->log(__FUNCTION__, compact('hash'));
    }

    public function pinAttempt(string $deviceId, string $user, bool $ok): string
    {
        $this->log(__FUNCTION__, compact('ok'));

        return $this->pinResult;
    }

    public function stepUpActive(string $deviceId, string $user): bool
    {
        return false;
    }

    public function approvals(int $limit): array
    {
        return [];
    }

    public function decide(string $deviceId, string $user, string $kind, string $ref, string $decision): string
    {
        $this->log(__FUNCTION__, compact('kind', 'ref', 'decision'));

        return 'approved';
    }

    public function emergency(string $deviceId, string $user, bool $stop, string $reason): string
    {
        $this->log(__FUNCTION__, compact('stop', 'reason'));

        return $this->emergencyResult;
    }

    public function emergencyState(string $tenant): array
    {
        return ['active' => false, 'since' => null, 'reason' => null];
    }

    public function notifications(string $user, bool $onlyUnread, int $limit): array
    {
        return [];
    }

    public function unreadCount(string $user): int
    {
        return 0;
    }

    public function markRead(string $user, ?array $ids): int
    {
        return 0;
    }

    public function notify(string $tenant, string $user, string $kind, string $title, string $body, ?string $refKind = null, ?string $refId = null): string
    {
        $this->notified[] = compact('user', 'kind', 'title');

        return 'n';
    }

    public function deviceUsers(string $tenant): array
    {
        return $this->users;
    }
}
