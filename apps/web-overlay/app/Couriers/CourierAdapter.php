<?php

declare(strict_types=1);

namespace App\Couriers;

interface CourierAdapter
{
    /**
     * @param array{invoice: string, name: string, phone: string, address: string, cod: int|float, qty: int, note: ?string, hints: array<string, string>} $parcel  phone is local 01XXXXXXXXX; cod is in taka
     * @return array{consignment_id: string, tracking_code: string, raw_status: string}
     *
     * @throws CourierException
     */
    public function book(array $parcel): array;

    /** The courier's own wording of the parcel's current status. @throws CourierException */
    public function status(string $consignmentId, ?string $trackingCode): string;
}
