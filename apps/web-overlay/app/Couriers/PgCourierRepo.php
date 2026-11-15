<?php

declare(strict_types=1);

namespace App\Couriers;

use App\Actions\Tenancy\CreateWorkspace;
use Illuminate\Support\Facades\DB;

final class PgCourierRepo implements CourierRepo
{
    public function accounts(): array
    {
        return array_map(fn ($r) => ['id' => trim((string) $r->id), 'courier' => (string) $r->courier, 'settings' => json_decode((string) $r->settings, true) ?: [], 'status' => (string) $r->status],
            DB::select('select id, courier, settings, status from courier_accounts order by courier'));
    }

    public function saveAccount(string $tenantId, string $courier, string $cipher, int $version, array $settings): string
    {
        return trim((string) DB::selectOne('select courier_account_save(?, ?, ?, ?, ?::smallint, ?::jsonb) as id', [CreateWorkspace::newId(), $tenantId, $courier, $cipher, $version, json_encode((object) $settings)])->id);
    }

    public function setAccountStatus(string $accountId, string $status): void
    {
        DB::select('select courier_account_status(?, ?)', [$accountId, $status]);
    }

    public function credentials(string $accountId): ?array
    {
        $r = DB::selectOne('select credentials_ct, key_version, courier, settings, status from courier_credentials(?)', [$accountId]);

        return $r === null ? null : ['cipher' => (string) $r->credentials_ct, 'version' => (int) $r->key_version, 'courier' => (string) $r->courier, 'settings' => json_decode((string) $r->settings, true) ?: [], 'status' => (string) $r->status];
    }

    public function reserve(string $orderId, string $accountId, string $userId): array
    {
        $r = DB::selectOne('select result, shipment_id, cod_minor from shipment_reserve(?, ?, ?, ?)', [CreateWorkspace::newId(), $orderId, $accountId, $userId]);

        return ['result' => (string) $r->result, 'shipment_id' => $r->shipment_id === null ? null : trim((string) $r->shipment_id), 'cod_minor' => $r->cod_minor === null ? null : (int) $r->cod_minor];
    }

    public function booked(string $shipmentId, string $consignment, string $tracking, string $raw): string
    {
        return (string) DB::selectOne('select shipment_booked(?, ?, ?, ?) as r', [$shipmentId, $consignment, $tracking, $raw])->r;
    }

    public function fail(string $shipmentId, string $error): string
    {
        return (string) DB::selectOne('select shipment_fail(?, ?) as r', [$shipmentId, $error])->r;
    }

    public function apply(string $shipmentId, string $status, string $raw): string
    {
        return (string) DB::selectOne('select shipment_apply(?, ?, ?) as r', [$shipmentId, $status, $raw])->r;
    }

    public function checkFailed(string $shipmentId, string $error): string
    {
        return (string) DB::selectOne('select shipment_check_failed(?, ?) as r', [$shipmentId, $error])->r;
    }

    public function shipment(string $shipmentId): ?array
    {
        $r = DB::selectOne('select id, order_id, account_id, courier, status, consignment_id, tracking_code from order_shipments where id = ?', [$shipmentId]);

        return $r === null ? null : ['id' => trim((string) $r->id), 'order_id' => trim((string) $r->order_id), 'account_id' => trim((string) $r->account_id), 'courier' => (string) $r->courier,
            'status' => (string) $r->status, 'consignment_id' => $r->consignment_id, 'tracking_code' => $r->tracking_code];
    }

    public function shipmentsOfOrder(string $orderId): array
    {
        return array_map(fn ($r) => ['id' => trim((string) $r->id)] + (array) $r,
            DB::select("select id, courier, status, consignment_id, tracking_code, cod_minor, raw_status, error, created_at, updated_at, last_checked_at, (status = 'booking' and created_at < now() - interval '10 minutes') as stuck from order_shipments where order_id = ? order by created_at desc", [$orderId]));
    }

    public function shipments(?string $status, int $limit = 100): array
    {
        return array_map(fn ($r) => ['id' => trim((string) $r->id), 'order_id' => trim((string) $r->order_id)] + (array) $r,
            DB::select("select s.id, s.order_id, o.number, o.customer_name, s.courier, s.status, s.consignment_id, s.cod_minor, s.raw_status, s.updated_at from order_shipments s join store_orders o on o.id = s.order_id and o.tenant_id = s.tenant_id where (? = 'all' or s.status = ?) order by s.updated_at desc limit ?",
                [$status ?? 'all', $status ?? 'all', min(300, max(1, $limit))]));
    }

    public function reportCalc(string $day): array
    {
        return json_decode((string) DB::selectOne('select order_report_calc(?::date) as r', [$day])->r, true) ?: [];
    }

    public function reportStore(string $tenantId, string $day, array $data): void
    {
        DB::select('select order_report_store(?, ?::date, ?::jsonb)', [$tenantId, $day, json_encode($data, JSON_UNESCAPED_UNICODE)]);
    }

    public function reports(int $limit = 30): array
    {
        return array_map(fn ($r) => ['day' => (string) $r->day, 'data' => json_decode((string) $r->data, true) ?: []], DB::select('select day, data from order_reports order by day desc limit ?', [min(90, max(1, $limit))]));
    }
}
