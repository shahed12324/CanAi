<?php

declare(strict_types=1);

namespace App\Pc;

/** Thin wrapper over the pc_* database functions (the database is the authority; see phase26a.sql). */
interface PcRepo
{
    /** @return 'ok'|'bad_input'|'forbidden'|'limit' */
    public function pairCreate(string $tenantId, string $userId, string $name, string $platform, string $codeHash): string;

    /** @return ?array{device_id: string, tenant_id: string} */
    public function pairRedeem(string $codeHash, string $tokenHash): ?array;

    /** @return ?array{device_id: string, tenant_id: string, user_id: string} */
    public function agentLookup(string $tokenHash): ?array;

    /** Swap the device's token hash. @return ?array{device_id: string, tenant_id: string, user_id: string} null = old token unknown/revoked or the new one is already taken */
    public function agentRotate(string $oldHash, string $newHash): ?array;

    public function deviceRevoke(string $tenantId, string $userId, string $deviceId): string;

    /** @param array<string, mixed> $p roots, apps, commands, hosts (lists) and modes (kind => deny|ask|allow) */
    public function policySave(string $tenantId, string $userId, string $deviceId, array $p): string;

    /** @return array{status: string, reason: ?string, id?: string, risk?: string} */
    public function actionRequest(string $tenantId, string $userId, string $deviceId, string $kind, array $params): array;

    public function actionDecide(string $tenantId, string $userId, string $actionId, string $decision): string;

    /** @return ?array{id: string, kind: string, risk: string, params: array<string, mixed>} */
    public function actionNext(string $tenantId, string $deviceId): ?array;

    /** Is the workspace emergency stop on right now?  (the agent asks while it runs a plan) */
    public function agentEmergency(string $tenantId, string $deviceId): bool;

    public function actionReport(string $tenantId, string $deviceId, string $actionId, bool $ok, string $result): string;

    /** @return list<array{id: string, name: string, platform: string, last_seen_at: ?string, user_id: string}> the user's live devices */
    public function devices(string $tenantId, string $userId): array;

    /** @return array{roots: list<string>, apps: list<string>, commands: list<string>, hosts: list<string>, modes: array<string, string>}|null */
    public function policy(string $tenantId, string $deviceId): ?array;

    /** Newest first. @return list<array{id: string, device_id: string, device: string, kind: string, risk: string, params: array<string, mixed>, status: string, reason: ?string, result: ?string, created_at: string}> */
    public function actions(string $tenantId, string $userId, int $limit): array;

    /** The person's own pending, unexpired actions, for the phone.  @return list<array{id: string, kind: string, risk: string, device: string, params: array<string, mixed>, created_at: string, expires_at: string}> */
    public function mobilePending(string $deviceId, string $userId): array;

    /** Decide from the phone (approving needs a live PIN step-up).  @return 'ok'|'not_found'|'not_pending'|'expired'|'emergency_stop'|'step_up_required'|'bad_input' */
    public function mobileDecide(string $deviceId, string $userId, string $actionId, string $decision): string;

    /** @return ?array<string, mixed> newest monitor sample (cpu, mem, disk, gpu, battery, temp_c, charging, created_at) */
    public function latestMetric(string $tenantId, string $deviceId): ?array;

    /** @return 'ok'|'bad_input'|'not_found'|'too_fast' */
    public function metricsAdd(string $tenantId, string $deviceId, array $m): string;

    /** Where this device's stored audit chain ends.  @return array{status: string, seq?: int, h?: string} */
    public function auditHead(string $tenantId, string $deviceId): array;

    /** Append audit rows [start, start+n) (the database recomputes every hash and checks the links).  @return array{status: string, seq?: int, h?: string, at?: int} status ok|bad_input|seq_mismatch|chain_broken|not_found */
    public function auditIngest(string $tenantId, string $deviceId, int $start, array $rows): array;
}
