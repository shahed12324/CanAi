<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Couriers\CourierRepo;

/** In-memory stand-in: records every call; `reserveResult` lets a test force the database's answer. */
final class FakeCourierRepo implements CourierRepo
{
    public string $reserveResult = 'ok';

    /** @var list<array{string, mixed}> */
    public array $log = [];

    /** @var array<string, array<string, mixed>> */
    public array $shipments = [];

    public function accounts(): array
    {
        return [];
    }

    public function saveAccount(string $tenantId, string $courier, string $cipher, int $version, array $settings): string
    {
        return str_repeat('a', 26);
    }

    public function setAccountStatus(string $accountId, string $status): void {}

    public function credentials(string $accountId): ?array
    {
        return null;
    }

    public function reserve(string $orderId, string $accountId, string $userId): array
    {
        $this->log[] = ['reserve', $orderId];
        if ($this->reserveResult !== 'ok') {
            return ['result' => $this->reserveResult, 'shipment_id' => null, 'cod_minor' => null];
        }

        return ['result' => 'ok', 'shipment_id' => str_repeat('s', 26), 'cod_minor' => 131050];
    }

    public function booked(string $shipmentId, string $consignment, string $tracking, string $raw): string
    {
        $this->log[] = ['booked', [$consignment, $tracking, $raw]];

        return 'ok';
    }

    public function fail(string $shipmentId, string $error): string
    {
        $this->log[] = ['fail', $error];

        return 'ok';
    }

    public function apply(string $shipmentId, string $status, string $raw): string
    {
        $this->log[] = ['apply', [$status, $raw]];

        return 'changed';
    }

    public function checkFailed(string $shipmentId, string $error): string
    {
        $this->log[] = ['checkFailed', $error];

        return 'retry';
    }

    public function shipment(string $shipmentId): ?array
    {
        return $this->shipments[$shipmentId] ?? null;
    }

    public function shipmentsOfOrder(string $orderId): array
    {
        return [];
    }

    public function shipments(?string $status, int $limit = 100): array
    {
        return [];
    }

    public function reportCalc(string $day): array
    {
        return [];
    }

    public function reportStore(string $tenantId, string $day, array $data): void {}

    public function reports(int $limit = 30): array
    {
        return [];
    }

    public function called(string $name): array
    {
        return array_values(array_map(fn ($l) => $l[1], array_filter($this->log, fn ($l) => $l[0] === $name)));
    }
}
