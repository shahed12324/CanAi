<?php

declare(strict_types=1);

namespace App\Couriers;

use App\Orders\OrderRepo;
use Closure;

/**
 * Booking in three steps so a crash or a timeout can never book an order twice:
 *   1. reserve in the database (one live shipment per order),  2. call the courier,  3. record the outcome.
 * A definite refusal frees the order. An uncertain outcome (timeout, 5xx) leaves the reservation in place for a person to check.
 */
final class ShipmentService
{
    /** @param Closure(string, array<string, mixed>, array<string, mixed>): CourierAdapter $adapter courier, secrets, settings */
    public function __construct(
        private readonly CourierRepo $couriers,
        private readonly OrderRepo $orders,
        private readonly Closure $open,
        private readonly Closure $adapter,
    ) {}

    /**
     * @param array<string, string> $hints
     * @return array{result: string, message: ?string} result: ok | not_found | bad_status | emergency | no_account | account_disabled | already_booked | no_credentials | refused | uncertain
     */
    public function book(string $orderId, string $accountId, string $userId, array $hints = []): array
    {
        $o = $this->orders->order($orderId);
        if ($o === null) {
            return ['result' => 'not_found', 'message' => null];
        }
        $res = $this->couriers->reserve($orderId, $accountId, $userId);
        if ($res['result'] !== 'ok') {
            return ['result' => $res['result'], 'message' => null];
        }
        $sid = (string) $res['shipment_id'];
        $opened = ($this->open)($accountId);
        if ($opened === null || $opened['status'] !== 'active') {
            $this->couriers->fail($sid, 'credentials could not be opened');

            return ['result' => 'no_credentials', 'message' => null];
        }
        $ord = $o['order'];
        try {
            $adapter = ($this->adapter)($opened['courier'], $opened['secrets'], $opened['settings']);
            $out = $adapter->book([
                'invoice' => 'ORD-'.$ord['number'], 'name' => (string) $ord['customer_name'], 'phone' => self::localPhone((string) $ord['phone']), 'address' => (string) $ord['address'],
                'cod' => self::taka((int) $res['cod_minor']), 'qty' => max(1, (int) array_sum(array_column($o['items'], 'qty'))), 'note' => $ord['note'] === null ? null : (string) $ord['note'],
                'hints' => array_map('strval', $hints),
            ]);
        } catch (CourierException $e) {
            if ($e->uncertain) {
                return ['result' => 'uncertain', 'message' => $e->getMessage()];       // stays reserved: check the courier's panel, then release
            }
            $this->couriers->fail($sid, $e->getMessage());

            return ['result' => 'refused', 'message' => $e->getMessage()];
        } catch (\Throwable $e) {
            self::log($e);

            return ['result' => 'uncertain', 'message' => 'unexpected error'];
        }
        $this->couriers->booked($sid, $out['consignment_id'], $out['tracking_code'], $out['raw_status']);

        return ['result' => 'ok', 'message' => null];
    }

    /** A person checked the courier and the parcel does not exist: let the order be booked again. */
    public function release(string $shipmentId): string
    {
        return $this->couriers->fail($shipmentId, 'released by a person (no parcel at the courier)');
    }

    /** Asks the courier where one parcel is and applies it. @return string changed|same|terminal|mismatch|retry|attention|skipped */
    public function sync(string $shipmentId): string
    {
        $s = $this->couriers->shipment($shipmentId);
        if ($s === null || ! in_array($s['status'], ['booked', 'in_transit', 'attention'], true) || $s['consignment_id'] === null) {
            return 'skipped';
        }
        $opened = ($this->open)($s['account_id']);
        if ($opened === null) {
            return $this->couriers->checkFailed($shipmentId, 'credentials could not be opened');
        }
        try {
            $raw = ($this->adapter)($opened['courier'], $opened['secrets'], $opened['settings'])->status((string) $s['consignment_id'], $s['tracking_code']);
        } catch (CourierException $e) {
            return $this->couriers->checkFailed($shipmentId, $e->getMessage());
        } catch (\Throwable $e) {
            self::log($e);

            return $this->couriers->checkFailed($shipmentId, 'unexpected error');
        }

        return $this->couriers->apply($shipmentId, CourierStatus::map($s['courier'], $raw), $raw);
    }

    private static function log(\Throwable $e): void
    {
        if (function_exists('report')) {
            report($e);
        }
    }

    /** +8801712345678 -> 01712345678 */
    public static function localPhone(string $e164): string
    {
        return preg_match('/\A\+88(01[3-9][0-9]{8})\z/', $e164, $m) === 1 ? $m[1] : $e164;
    }

    /** Minor units (poisha) -> taka, whole when it can be. */
    public static function taka(int $minor): int|float
    {
        return $minor % 100 === 0 ? intdiv($minor, 100) : round($minor / 100, 2);
    }
}
