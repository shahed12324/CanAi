<?php

declare(strict_types=1);

namespace App\Catalog\Stores;

use App\Actions\Tenancy\CreateWorkspace;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class PgStoreRepo implements StoreRepo
{
    public function store(string $id): ?array
    {
        $r = DB::selectOne('select id, tenant_id, platform, name, base_url, currency, status, last_sync_at, last_error from store_connections where id = ?', [$id]);

        return $r === null ? null : self::row($r);
    }

    public function stores(): array
    {
        return array_map([self::class, 'row'], DB::select("select id, tenant_id, platform, name, base_url, currency, status, last_sync_at, last_error from store_connections where platform <> 'native' order by created_at"));
    }

    /** @return array<string, mixed> */
    private static function row(object $r): array
    {
        $a = (array) $r;
        $a['id'] = trim($a['id']);
        $a['tenant_id'] = trim($a['tenant_id']);
        $a['currency'] = trim($a['currency']);

        return $a;
    }

    public function createStore(string $tenantId, string $platform, string $name, string $baseUrl, string $cipher, int $keyVersion, string $currency): string
    {
        $id = CreateWorkspace::newId();
        DB::insert('insert into store_connections (id, tenant_id, platform, name, base_url, credentials_ct, key_version, currency) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$id, $tenantId, $platform, $name, $baseUrl, $cipher, $keyVersion, $currency]);

        return $id;
    }

    public function updateStore(string $id, string $name, string $baseUrl, ?string $cipher, ?int $keyVersion, string $currency): void
    {
        if ($cipher === null) {
            DB::update("update store_connections set name = ?, base_url = ?, currency = ? where id = ? and platform <> 'native'", [$name, $baseUrl, $currency, $id]);

            return;
        }
        DB::update("update store_connections set name = ?, base_url = ?, currency = ?, credentials_ct = ?, key_version = ?, status = 'active', last_error = null where id = ? and platform <> 'native'",
            [$name, $baseUrl, $currency, $cipher, $keyVersion, $id]);
    }

    public function credentials(string $id): ?array
    {
        try {
            $r = DB::selectOne('select credentials_ct, key_version from store_credentials(?)', [$id]);
        } catch (QueryException) {
            return null;
        }

        return $r === null || $r->credentials_ct === null ? null : ['cipher' => $r->credentials_ct, 'version' => (int) $r->key_version];
    }

    public function setStatus(string $id, string $status, ?string $lastError, bool $touchSync): void
    {
        DB::update('update store_connections set status = ?, last_error = ?, last_sync_at = case when ? then now() else last_sync_at end where id = ?',
            [$status, $lastError === null ? null : mb_substr($lastError, 0, 200), $touchSync ? 'true' : 'false', $id]);
    }

    public function emergency(string $tenantId): bool
    {
        return (bool) DB::selectOne('select emergency_active(?) as e', [$tenantId])->e;
    }

    public function reap(string $storeId): void
    {
        DB::select('select store_sync_reap(?)', [$storeId]);
    }

    public function beginRun(string $tenantId, string $storeId, string $trigger, ?string $userId): ?string
    {
        $id = CreateWorkspace::newId();
        try {
            DB::insert('insert into store_sync_runs (id, tenant_id, store_id, trigger, user_id) values (?, ?, ?, ?, ?)', [$id, $tenantId, $storeId, $trigger, $userId]);
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'store_sync_one_running')) {
                return null;
            }
            throw $e;
        }

        return $id;
    }

    public function finishRun(string $runId, string $status, array $c, ?string $note, array $errors): void
    {
        DB::update('update store_sync_runs set status = ?, pulled = ?, created = ?, updated = ?, pushed = ?, failed = ?, note = ?, errors = ?::jsonb, finished_at = now() where id = ?',
            [$status, $c['pulled'], $c['created'], $c['updated'], $c['pushed'], $c['failed'], $note === null ? null : mb_substr($note, 0, 200), json_encode(array_slice($errors, 0, 50), JSON_UNESCAPED_UNICODE), $runId]);
    }

    public function runs(string $storeId, int $limit): array
    {
        return array_map(fn ($r) => (array) $r + [], DB::select('select trigger, status, pulled, created, updated, pushed, failed, note, started_at, finished_at from store_sync_runs where store_id = ? order by started_at desc limit ?', [$storeId, max(1, min($limit, 50))]));
    }

    public function idByExternal(string $storeId, string $external): ?string
    {
        $r = DB::selectOne('select id from products where store_id = ? and external_id = ? and deleted_at is null', [$storeId, $external]);

        return $r === null ? null : trim($r->id);
    }

    public function setProductRemote(string $productId, ?string $external, ?string $hash): void
    {
        DB::update('update products set external_id = coalesce(?, external_id), remote_hash = coalesce(?, remote_hash), remote_synced_at = now() where id = ?', [$external, $hash, $productId]);
    }

    public function productRemote(string $productId): ?array
    {
        $r = DB::selectOne('select remote_hash from products where id = ?', [$productId]);

        return $r === null ? null : ['remote_hash' => $r->remote_hash];
    }

    public function variantStates(string $productId): array
    {
        return array_map(fn ($v) => [
            'id' => trim($v->id), 'external_id' => $v->external_id, 'options' => json_decode((string) $v->options, true) ?: [],
            'stock_qty' => (int) $v->stock_qty, 'remote_stock' => $v->remote_stock === null ? null : (int) $v->remote_stock, 'track_stock' => (bool) $v->track_stock,
        ], DB::select('select id, external_id, options, stock_qty, remote_stock, track_stock from product_variants where product_id = ? and archived_at is null order by created_at, id', [$productId]));
    }

    public function setVariantExternal(string $variantId, string $external): void
    {
        DB::update('update product_variants set external_id = ? where id = ?', [$external, $variantId]);
    }

    public function setVariantRemoteStock(string $variantId, ?int $qty): void
    {
        DB::update('update product_variants set remote_stock = ? where id = ?', [$qty, $variantId]);
    }

    public function pushCandidates(string $storeId, int $limit): array
    {
        return array_map(fn ($r) => ['id' => trim($r->id), 'external_id' => $r->external_id],
            DB::select("select id, external_id from products where store_id = ? and status = 'published' and deleted_at is null and (remote_synced_at is null or published_at > remote_synced_at) order by published_at limit ?", [$storeId, max(1, min($limit, 500))]));
    }

    public function stockToPush(string $storeId, int $limit): array
    {
        return array_map(fn ($r) => ['id' => trim($r->id), 'external_id' => (string) $r->external_id, 'stock_qty' => (int) $r->stock_qty],
            DB::select('select v.id, v.external_id, v.stock_qty from product_variants v join products p on p.id = v.product_id
                where p.store_id = ? and p.live is not null and p.deleted_at is null and v.archived_at is null and v.track_stock and v.external_id is not null
                  and (v.remote_stock is null or v.remote_stock <> v.stock_qty) limit ?', [$storeId, max(1, min($limit, 1000))]));
    }
}
