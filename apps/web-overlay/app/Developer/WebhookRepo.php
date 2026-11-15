<?php

declare(strict_types=1);

namespace App\Developer;

interface WebhookRepo
{
    /** @return string ok|bad_input|limit|duplicate */
    public function create(string $tenantId, string $userId, string $id, string $name, string $url, string $cipher, int $version): string;

    /** @return string ok|not_found */
    public function delete(string $tenantId, string $userId, string $id): string;

    /** @return string ok|not_found */
    public function setActive(string $tenantId, string $userId, string $id, bool $active): string;

    /** A workflow's text to one of the person's own addresses (event workflow.message).  @return string ok|not_found|limit|bad_input */
    public function sendMessage(string $tenantId, string $userId, string $name, string $text): string;

    /** @param list<string> $events @return string ok|bad_input|not_found */
    public function setEvents(string $tenantId, string $userId, string $id, array $events): string;

    /** @return list<array{id: string, name: string, url: string, active: bool, events: list<string>, failures: int, last_ok_at: ?string, last_error: ?string, created_at: string}> never the secret */
    public function list(string $userId): array;

    /** @return list<array{endpoint: string, event: string, run_id: string, status: string, attempts: int, last_code: ?int, next_at: string, created_at: string}> the person's 15 newest messages (no payload, no secret) */
    public function deliveries(string $userId): array;

    /** Worker: queues a message for every finished run that has none yet.  @return int how many were queued */
    public function enqueue(): int;

    /** Worker: due messages (leased for 5 minutes).  @return list<array{id: string, tenant_id: string, url: string, cipher: string, version: int, payload: array<string, mixed>, attempts: int}> */
    public function claim(int $limit): array;

    /** Worker: how a send went. */
    public function result(string $id, bool $ok, ?int $code, ?string $error): void;
}
