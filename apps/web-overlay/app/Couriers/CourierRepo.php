<?php

declare(strict_types=1);

namespace App\Couriers;

interface CourierRepo
{
    /** @return list<array{id: string, courier: string, settings: array<string, mixed>, status: string}> */
    public function accounts(): array;

    /** @param array<string, mixed> $settings */
    public function saveAccount(string $tenantId, string $courier, string $cipher, int $version, array $settings): string;

    public function setAccountStatus(string $accountId, string $status): void;

    /** @return array{cipher: string, version: int, courier: string, settings: array<string, mixed>, status: string}|null */
    public function credentials(string $accountId): ?array;

    /** @return array{result: string, shipment_id: ?string, cod_minor: ?int} */
    public function reserve(string $orderId, string $accountId, string $userId): array;

    public function booked(string $shipmentId, string $consignment, string $tracking, string $raw): string;

    public function fail(string $shipmentId, string $error): string;

    public function apply(string $shipmentId, string $status, string $raw): string;

    public function checkFailed(string $shipmentId, string $error): string;

    /** @return array{id: string, order_id: string, account_id: string, courier: string, status: string, consignment_id: ?string, tracking_code: ?string}|null */
    public function shipment(string $shipmentId): ?array;

    /** @return list<array<string, mixed>> */
    public function shipmentsOfOrder(string $orderId): array;

    /** @return list<array<string, mixed>> */
    public function shipments(?string $status, int $limit = 100): array;

    /** @return array<string, mixed> */
    public function reportCalc(string $day): array;

    /** @param array<string, mixed> $data */
    public function reportStore(string $tenantId, string $day, array $data): void;

    /** @return list<array{day: string, data: array<string, mixed>}> */
    public function reports(int $limit = 30): array;
}
