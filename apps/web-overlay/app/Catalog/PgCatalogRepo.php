<?php

declare(strict_types=1);

namespace App\Catalog;

use App\Actions\Tenancy\CreateWorkspace;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** Raw SQL on purpose: each statement runs under RLS and is executed against real Postgres in tests/db. */
final class PgCatalogRepo implements CatalogRepo
{
    public function ensureStore(string $tenantId): string
    {
        $r = DB::selectOne("select id from store_connections where platform = 'native' limit 1");
        if ($r !== null) {
            return trim($r->id);
        }
        $id = CreateWorkspace::newId();
        DB::insert("insert into store_connections (id, tenant_id, platform, name) values (?, ?, 'native', 'Main store') on conflict do nothing", [$id, $tenantId]);

        return trim((string) DB::selectOne("select id from store_connections where platform = 'native' limit 1")->id);
    }

    public function list(?string $status, ?string $search, int $limit): array
    {
        $rows = DB::select(
            "select p.id, p.title, p.status, p.price_minor, p.currency, p.sku, p.category, p.ai_generated, p.updated_at,
                    (select coalesce(sum(v.stock_qty), 0) from product_variants v where v.product_id = p.id and v.archived_at is null and v.track_stock) as stock,
                    (select count(*) from product_variants v where v.product_id = p.id and v.archived_at is null) as variants
               from products p
              where p.deleted_at is null and (?::text is null or p.status = ?) and (?::text is null or p.title ilike ? or p.sku ilike ?)
              order by p.updated_at desc limit ?",
            [$status, $status, $search, $search === null ? null : '%'.addcslashes($search, '%_\\').'%', $search === null ? null : '%'.addcslashes($search, '%_\\').'%', max(1, min($limit, 200))],
        );

        return array_map(fn ($r) => (array) $r + [], $rows);
    }

    public function find(string $id): ?array
    {
        $p = DB::selectOne('select id, tenant_id, store_id, title, description, status, price_minor, sale_price_minor, currency, sku, category, tags, images, seo, ai_generated, review_note, submitted_at, approved_at, published_at, live, updated_at from products where id = ? and deleted_at is null', [$id]);
        if ($p === null) {
            return null;
        }
        $a = (array) $p;
        $a['id'] = trim($a['id']);
        $a['tenant_id'] = trim($a['tenant_id']);
        $a['tags'] = self::pgArray((string) $a['tags']);
        $a['images'] = json_decode((string) $a['images'], true) ?: [];
        $a['seo'] = json_decode((string) $a['seo'], true) ?: [];
        $a['live'] = $a['live'] === null ? null : json_decode((string) $a['live'], true);
        $a['variants'] = array_map(function ($v) {
            $v = (array) $v;
            $v['id'] = trim($v['id']);
            $v['options'] = json_decode((string) $v['options'], true) ?: [];

            return $v;
        }, DB::select('select id, sku, options, price_minor, stock_qty, track_stock from product_variants where product_id = ? and archived_at is null order by created_at, id', [$id]));

        return $a;
    }

    public function create(string $tenantId, ?string $userId, string $storeId, array $f): string
    {
        $id = CreateWorkspace::newId();
        DB::insert('insert into products (id, tenant_id, store_id, title, description, price_minor, sale_price_minor, currency, sku, category, tags, images, seo, created_by, external_id) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?::text[], ?::jsonb, ?::jsonb, ?, ?)',
            [$id, $tenantId, $storeId, $f['title'], $f['description'] ?? '', $f['price_minor'] ?? 0, $f['sale_price_minor'] ?? null, $f['currency'] ?? 'BDT', $f['sku'] ?? null,
                $f['category'] ?? null, self::toPgArray($f['tags'] ?? []), json_encode($f['images'] ?? []), json_encode((object) ($f['seo'] ?? [])), $userId, $f['external_id'] ?? null]);

        return $id;
    }

    public function update(string $id, array $f): void
    {
        DB::update('update products set title = ?, description = ?, price_minor = ?, sale_price_minor = ?, currency = ?, sku = ?, category = ?, tags = ?::text[], images = ?::jsonb, seo = ?::jsonb, ai_generated = ? where id = ?',
            [$f['title'], $f['description'], $f['price_minor'], $f['sale_price_minor'], $f['currency'], $f['sku'], $f['category'], self::toPgArray($f['tags']), json_encode($f['images']), json_encode((object) $f['seo']), $f['ai_generated'] ? 'true' : 'false', $id]);
    }

    public function idBySku(string $storeId, string $sku): ?string
    {
        $r = DB::selectOne('select id from products where store_id = ? and lower(sku) = lower(?) and deleted_at is null', [$storeId, $sku]);

        return $r === null ? null : trim($r->id);
    }

    public function addVariant(string $tenantId, string $productId, ?string $sku, array $options, ?int $priceMinor, bool $track): string
    {
        $id = CreateWorkspace::newId();
        DB::insert('insert into product_variants (id, tenant_id, product_id, sku, options, price_minor, track_stock) values (?, ?, ?, ?, ?::jsonb, ?, ?)',
            [$id, $tenantId, $productId, $sku, json_encode((object) $options, JSON_UNESCAPED_UNICODE), $priceMinor, $track ? 'true' : 'false']);

        return $id;
    }

    public function updateVariant(string $id, array $f): void
    {
        DB::update('update product_variants set sku = ?, price_minor = ?, track_stock = ? where id = ?', [$f['sku'], $f['price_minor'], $f['track_stock'] ? 'true' : 'false', $id]);
    }

    public function archiveVariant(string $id): void
    {
        DB::update('update product_variants set archived_at = now() where id = ? and archived_at is null', [$id]);
    }

    public function variantByOptions(string $productId, array $options): ?string
    {
        $r = DB::selectOne('select id from product_variants where product_id = ? and options = ?::jsonb and archived_at is null', [$productId, json_encode((object) $options, JSON_UNESCAPED_UNICODE)]);

        return $r === null ? null : trim($r->id);
    }

    public function softDelete(string $id): void
    {
        DB::update('update products set deleted_at = now() where id = ? and deleted_at is null', [$id]);
    }

    public function stock(string $variantId, int $delta, string $reason, ?string $note, ?string $userId): int
    {
        try {
            return (int) DB::selectOne('select stock_adjust(?, ?, ?, ?, ?) as q', [$variantId, $delta, $reason, $note, $userId])->q;
        } catch (QueryException $e) {
            foreach (['insufficient_stock', 'not_tracked', 'variant_archived', 'zero_delta'] as $code) {
                if (str_contains($e->getMessage(), $code)) {
                    throw new \RuntimeException($code, 0, $e);
                }
            }
            throw $e;
        }
    }

    public function submit(string $id, string $userId): string
    {
        return (string) DB::selectOne('select catalog_submit(?, ?) as r', [$id, $userId])->r;
    }

    public function decide(string $id, string $userId, string $decision, ?string $note): string
    {
        return (string) DB::selectOne('select catalog_decide(?, ?, ?, ?) as r', [$id, $userId, $decision, $note])->r;
    }

    public function publish(string $id, string $userId): string
    {
        return (string) DB::selectOne('select catalog_publish(?, ?) as r', [$id, $userId])->r;
    }

    public function archive(string $id, string $userId): string
    {
        return (string) DB::selectOne('select catalog_archive(?, ?) as r', [$id, $userId])->r;
    }

    public function restore(string $id, string $userId): string
    {
        return (string) DB::selectOne('select catalog_restore(?, ?) as r', [$id, $userId])->r;
    }

    public function events(string $id): array
    {
        return array_map(fn ($r) => (array) $r + [], DB::select('select e.action, e.note, e.created_at, u.name as user_name from product_events e left join users u on u.id = e.user_id where e.product_id = ? order by e.seq desc limit 50', [$id]));
    }

    public function movements(string $variantId, int $limit): array
    {
        return array_map(fn ($r) => (array) $r + [], DB::select('select delta, qty_after, reason, note, created_at from stock_movements where variant_id = ? order by seq desc limit ?', [$variantId, max(1, min($limit, 100))]));
    }

    public function recordImport(string $tenantId, string $userId, string $filename, int $total, int $created, int $updated, int $failed, array $errors): void
    {
        DB::insert('insert into catalog_imports (id, tenant_id, user_id, filename, total, created, updated, failed, errors) values (?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb)',
            [CreateWorkspace::newId(), $tenantId, $userId, mb_substr($filename, 0, 200), $total, $created, $updated, $failed, json_encode(array_slice($errors, 0, 100), JSON_UNESCAPED_UNICODE)]);
    }

    public function imports(int $limit): array
    {
        return array_map(fn ($r) => (array) $r + [], DB::select('select filename, total, created, updated, failed, created_at from catalog_imports order by created_at desc limit ?', [max(1, min($limit, 50))]));
    }

    /** @param list<string> $a */
    public static function toPgArray(array $a): string
    {
        return '{'.implode(',', array_map(fn ($x) => '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $x).'"', $a)).'}';
    }

    /** Parses a one-dimensional postgres text[] literal: {a,"b c","q\\"x"}. @return list<string> */
    public static function pgArray(string $s): array
    {
        $s = trim($s);
        if ($s === '' || $s === '{}') {
            return [];
        }
        $s = substr($s, 1, -1);
        $out = [];
        $cur = '';
        $quoted = false;
        $inQ = false;
        for ($i = 0, $n = strlen($s); $i < $n; $i++) {
            $c = $s[$i];
            if ($inQ) {
                if ($c === '\\' && $i + 1 < $n) {
                    $cur .= $s[++$i];
                } elseif ($c === '"') {
                    $inQ = false;
                } else {
                    $cur .= $c;
                }
            } elseif ($c === '"') {
                $inQ = true;
                $quoted = true;
            } elseif ($c === ',') {
                $out[] = $cur;
                $cur = '';
                $quoted = false;
            } else {
                $cur .= $c;
            }
        }
        $out[] = $cur;

        return $out;
    }
}
