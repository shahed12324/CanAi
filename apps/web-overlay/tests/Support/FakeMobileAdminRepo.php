<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Mobile\MobileAdminRepo;

final class FakeMobileAdminRepo implements MobileAdminRepo
{
    public array $tenants = [];

    public array $users = [];

    public array $unread = [];

    public array $inserted = [];

    public array $queue = [];

    public array $pushed = [];

    public array $dropped = [];

    public bool $broken = false;

    public function tenantsWithPendingApprovals(int $limit): array
    {
        return $this->tenants;
    }

    public function deviceUsers(string $tenant): array
    {
        return $this->users[$tenant] ?? [];
    }

    public function hasUnread(string $tenant, string $user, string $kind): bool
    {
        if ($this->broken) {
            throw new \RuntimeException('store down');
        }

        return isset($this->unread["$tenant/$user/$kind"]);
    }

    public function insertNotification(string $tenant, string $user, string $kind, string $title, string $body, ?string $refKind): string
    {
        $this->inserted[] = compact('tenant', 'user', 'kind', 'title', 'body');
        $this->unread["$tenant/$user/$kind"] = true;

        return 'n'.count($this->inserted);
    }

    public function unpushed(int $limit): array
    {
        return $this->queue;
    }

    public function markPushed(string $id, ?string $error): void
    {
        $this->pushed[] = [$id, $error];
    }

    public function dropPushToken(string $token): void
    {
        $this->dropped[] = $token;
    }

    public int $retired = 0;

    public function retireDeadDevices(): int
    {
        return $this->retired;
    }
}
