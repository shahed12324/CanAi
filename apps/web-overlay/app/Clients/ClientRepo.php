<?php

declare(strict_types=1);

namespace App\Clients;

interface ClientRepo
{
    /** @return list<array{id: string, name: string, notes: string, primary_color: ?string, accent_color: ?string, has_logo: bool, email: ?string, phone: ?string, website: ?string, address: ?string}> active clients */
    public function clients(): array;

    /** @return list<array{kind: 'meta'|'google'|'social', id: string, name: string, detail: string, client_id: ?string}> every connected account the workspace has */
    public function accounts(): array;

    /** @param array<string, mixed> $f name, primary, accent, email, phone, website, address, notes; logo (base64|null) + set_logo @return string ok|exists|limit|missing */
    public function save(string $tenantId, string $userId, ?string $clientId, array $f): string;

    /** @return string ok|missing */
    public function archive(string $tenantId, string $userId, string $clientId): string;

    /** @param 'meta'|'google'|'social' $kind @return string ok|account|client */
    public function assign(string $tenantId, string $userId, string $kind, string $accountId, ?string $clientId): string;
}
