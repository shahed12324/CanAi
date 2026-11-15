<?php

declare(strict_types=1);

namespace App\Mobile;

/** Cross-workspace reads for the push worker (admin connection). Never used by a request. */
interface MobileAdminRepo
{
    /** @return list<array{tenant: string, pending: int}> workspaces with approvals waiting */
    public function tenantsWithPendingApprovals(int $limit): array;

    /** @return list<string> */
    public function deviceUsers(string $tenant): array;

    public function hasUnread(string $tenant, string $user, string $kind): bool;

    public function insertNotification(string $tenant, string $user, string $kind, string $title, string $body, ?string $refKind): string;

    /** @return list<array{id: string, token: string, title: string, body: string, kind: string, ref_kind: ?string, ref_id: ?string, tenant: string}> */
    public function unpushed(int $limit): array;

    public function markPushed(string $id, ?string $error): void;

    public function dropPushToken(string $token): void;

    /** Retires phones whose API token was deleted or has expired, so they stop receiving push. @return int phones retired */
    public function retireDeadDevices(): int;
}
