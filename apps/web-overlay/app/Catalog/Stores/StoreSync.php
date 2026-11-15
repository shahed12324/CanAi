<?php

declare(strict_types=1);

namespace App\Catalog\Stores;

use App\Catalog\CatalogRepo;

/**
 * Pull (their products -> our drafts), then push (our APPROVED copy -> their store), then stock.
 *
 *  - Whatever comes in is a draft: a changed product from the store never goes live here without a person approving it.
 *  - Only the approved `live` copy goes out, and only for products published after the last sync. Nothing goes out during an emergency stop.
 *  - Stock is a three-way merge using `remote_stock` (what the store held last time): if only the store changed, we take it;
 *    if only we changed, we push ours; if both changed, the store's number wins and the difference is logged in the ledger.
 *  - One store, one run at a time (database-enforced). A failed product never stops the others; a rejected key stops the run.
 */
final class StoreSync
{
    public const MAX_PAGES = 50;

    public const MAX_PUSH = 200;

    public function __construct(private readonly CatalogRepo $catalog, private readonly StoreRepo $stores, private readonly StoreFactory $factory) {}

    /** @return array{ok: bool, status: string, pulled: int, created: int, updated: int, pushed: int, failed: int, note: ?string, errors: list<array{ref: string, message: string}>} */
    public function run(string $tenantId, string $storeId, string $trigger, ?string $userId): array
    {
        $c = ['pulled' => 0, 'created' => 0, 'updated' => 0, 'pushed' => 0, 'failed' => 0];
        $errors = [];
        $store = $this->stores->store($storeId);
        if ($store === null || $store['tenant_id'] !== $tenantId || $store['platform'] === 'native') {
            return $c + ['ok' => false, 'status' => 'failed', 'note' => 'store_not_found', 'errors' => []];
        }
        if ($store['status'] === 'paused') {
            return $c + ['ok' => false, 'status' => 'failed', 'note' => 'paused', 'errors' => []];
        }
        $this->stores->reap($storeId);
        $run = $this->stores->beginRun($tenantId, $storeId, $trigger, $userId);
        if ($run === null) {
            return $c + ['ok' => false, 'status' => 'failed', 'note' => 'already_running', 'errors' => []];
        }
        $note = null;
        $fatal = null;
        try {
            $adapter = $this->factory->forStore($store);
            $this->pull($tenantId, $store, $adapter, $userId, $c, $errors);
            if ($this->stores->emergency($tenantId)) {
                $note = 'emergency_stop: push skipped';
            } else {
                $this->push($store, $adapter, $c, $errors);
                $this->pushStock($adapter, $storeId, $c, $errors);
            }
        } catch (StoreException $e) {
            $fatal = $e;
            $note = $e->errorCode;
        } catch (\Throwable $e) {
            if (function_exists('report')) {
                report($e);
            }
            $note = 'internal';
            $fatal = new StoreException('bad_response');
        }
        $status = $fatal !== null ? 'failed' : ($c['failed'] > 0 ? 'partial' : 'ok');
        $this->stores->finishRun($run, $status, $c, $note, $errors);
        $authBad = $fatal !== null && $fatal->errorCode === 'auth_failed';
        $this->stores->setStatus($storeId, $authBad ? 'error' : 'active', $fatal?->userMessage(), $fatal === null);

        return $c + ['ok' => $fatal === null, 'status' => $status, 'note' => $note, 'errors' => $errors];
    }

    /** @param array<string, mixed> $store */
    private function pull(string $tenantId, array $store, StoreAdapter $adapter, ?string $userId, array &$c, array &$errors): void
    {
        $cursor = null;
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $r = $adapter->page($cursor);
            foreach ($r['items'] as $item) {
                $c['pulled']++;
                try {
                    $this->apply($tenantId, $store['id'], $userId, $item, $c);
                } catch (StoreException $e) {
                    throw $e;
                } catch (\Throwable $e) {
                    $c['failed']++;
                    self::note($errors, (string) ($item['external_id'] ?? '?'), CatalogErr::of($e));
                }
            }
            $cursor = $r['next'];
            if ($cursor === null) {
                return;
            }
        }
    }

    /** Canonical content hash: same remote content always gives the same value (stock excluded — it has its own merge). */
    public static function hash(array $item): string
    {
        $v = array_map(fn ($x) => [$x['external_id'], $x['sku'] ?? null, $x['options'], $x['price_minor'] ?? null], $item['variants']);

        return sha1(json_encode([$item['title'], $item['description'], $item['price_minor'], $item['sale_price_minor'], $item['sku'] ?? null, $item['category'] ?? null, array_values($item['tags']), array_values($item['images']), $v], JSON_UNESCAPED_UNICODE));
    }

    /** @param array<string, mixed> $item */
    private function apply(string $tenantId, string $storeId, ?string $userId, array $item, array &$c): void
    {
        $ext = (string) $item['external_id'];
        $hash = self::hash($item);
        $pid = $this->stores->idByExternal($storeId, $ext);
        if ($pid === null && ! empty($item['sku'])) {
            $pid = $this->catalog->idBySku($storeId, (string) $item['sku']);      // an existing local product with that SKU is linked, not duplicated
        }
        $fields = [
            'title' => mb_substr((string) $item['title'], 0, 250), 'description' => (string) $item['description'], 'price_minor' => (int) $item['price_minor'], 'sale_price_minor' => $item['sale_price_minor'],
            'currency' => $item['currency'], 'sku' => $item['sku'] ?? null, 'category' => $item['category'] ?? null, 'tags' => $item['tags'], 'images' => $item['images'], 'seo' => [], 'external_id' => $ext, 'ai_generated' => false,
        ];
        if ($pid === null) {
            $pid = $this->catalog->create($tenantId, $userId, $storeId, $fields);
            $c['created']++;
            $this->variants($tenantId, $pid, $item, true);
            $this->stores->setProductRemote($pid, $ext, $hash);
        } else {
            $cur = $this->catalog->find($pid);
            if ($cur === null || $cur['status'] === 'archived') {
                return;
            }
            $remote = $this->stores->productRemote($pid);
            if (($remote['remote_hash'] ?? null) !== $hash) {
                $this->catalog->update($pid, ['description' => $fields['description'], 'seo' => $cur['seo']] + $fields);
                $this->variants($tenantId, $pid, $item, false);
                $this->stores->setProductRemote($pid, $ext, $hash);
                $c['updated']++;
            }
        }
        $this->mergeStock($pid, $item);
    }

    /** @param array<string, mixed> $item */
    private function variants(string $tenantId, string $pid, array $item, bool $fresh): void
    {
        $states = $this->stores->variantStates($pid);
        $realOptions = false;
        foreach ($item['variants'] as $rv) {
            $realOptions = $realOptions || $rv['options'] !== [];
        }
        foreach ($item['variants'] as $rv) {
            $match = null;
            foreach ($states as $s) {
                if ($s['external_id'] === $rv['external_id'] || ($s['external_id'] === null && BaseAdapter::sig($s['options']) === BaseAdapter::sig($rv['options']))) {
                    $match = $s;
                    break;
                }
            }
            $track = $rv['stock'] !== null;
            if ($match === null) {
                $vid = $this->catalog->addVariant($tenantId, $pid, $rv['sku'] ?? null, $rv['options'], $rv['price_minor'] ?? null, $track);
                $this->stores->setVariantExternal($vid, $rv['external_id']);
            } else {
                $this->catalog->updateVariant($match['id'], ['sku' => $rv['sku'] ?? null, 'price_minor' => $rv['price_minor'] ?? null, 'track_stock' => $track]);
                if ($match['external_id'] !== $rv['external_id']) {
                    $this->stores->setVariantExternal($match['id'], $rv['external_id']);
                }
            }
        }
        if ($realOptions) {
            foreach ($states as $s) {
                if ($s['options'] === [] && $s['external_id'] === null) {
                    $this->catalog->archiveVariant($s['id']);       // the placeholder "no options" variant is replaced by the store's real ones
                }
            }
        }
    }

    /** @param array<string, mixed> $item */
    private function mergeStock(string $pid, array $item): void
    {
        $byExt = [];
        foreach ($this->stores->variantStates($pid) as $s) {
            if ($s['external_id'] !== null) {
                $byExt[$s['external_id']] = $s;
            }
        }
        foreach ($item['variants'] as $rv) {
            $s = $byExt[$rv['external_id']] ?? null;
            if ($s === null || $rv['stock'] === null || ! $s['track_stock']) {
                continue;
            }
            $remote = (int) $rv['stock'];
            if ($s['remote_stock'] === null || $remote !== $s['remote_stock']) {
                $delta = $remote - $s['stock_qty'];
                if ($delta !== 0) {
                    $this->catalog->stock($s['id'], $delta, 'sync', 'store', null);
                }
                $this->stores->setVariantRemoteStock($s['id'], $remote);
            }
        }
    }

    /** @param array<string, mixed> $store */
    private function push(array $store, StoreAdapter $adapter, array &$c, array &$errors): void
    {
        foreach ($this->stores->pushCandidates($store['id'], self::MAX_PUSH) as $cand) {
            try {
                $p = $this->catalog->find($cand['id']);
                if ($p === null || $p['live'] === null) {
                    continue;
                }
                $stock = [];
                foreach ($this->stores->variantStates($p['id']) as $s) {
                    $stock[$s['id']] = $s['track_stock'] ? $s['stock_qty'] : null;
                }
                $live = $p['live'];
                $variants = [];
                foreach ($live['variants'] ?? [] as $lv) {
                    $variants[] = ['sku' => $lv['sku'] ?? null, 'options' => $lv['options'] ?? [], 'price_minor' => $lv['price_minor'] ?? null, 'stock' => $stock[trim((string) $lv['id'])] ?? null];
                }
                $payload = ['title' => $live['title'], 'description' => $live['description'], 'price_minor' => (int) $live['price_minor'], 'sale_price_minor' => $live['sale_price_minor'] ?? null,
                    'currency' => $live['currency'], 'sku' => $live['sku'] ?? null, 'category' => $live['category'] ?? null, 'tags' => $live['tags'] ?? [], 'images' => $live['images'] ?? [], 'variants' => $variants];
                $ext = $adapter->push($payload, $cand['external_id']);
                $back = $adapter->fetch($ext);                       // what the store now holds, so the next pull sees "no change"
                if ($back !== null) {
                    $this->linkVariants($p['id'], $back);
                    $this->stores->setProductRemote($p['id'], $ext, self::hash($back));
                    foreach ($this->stores->variantStates($p['id']) as $s) {
                        foreach ($back['variants'] as $rv) {
                            if ($rv['external_id'] === $s['external_id'] && $rv['stock'] !== null) {
                                $this->stores->setVariantRemoteStock($s['id'], (int) $rv['stock']);
                            }
                        }
                    }
                } else {
                    $this->stores->setProductRemote($p['id'], $ext, null);
                }
                $c['pushed']++;
            } catch (StoreException $e) {
                if (in_array($e->errorCode, ['auth_failed', 'rate_limited'], true)) {
                    throw $e;
                }
                $c['failed']++;
                self::note($errors, $cand['id'], $e->userMessage());
            }
        }
    }

    /** Remembers the store's variant ids for variants we created locally. @param array<string, mixed> $back */
    private function linkVariants(string $pid, array $back): void
    {
        $remote = [];
        foreach ($back['variants'] as $rv) {
            $remote[BaseAdapter::sig($rv['options'])] = $rv['external_id'];
        }
        foreach ($this->stores->variantStates($pid) as $s) {
            $ext = $remote[BaseAdapter::sig($s['options'])] ?? null;
            if ($ext !== null && $s['external_id'] !== $ext) {
                $this->stores->setVariantExternal($s['id'], $ext);
            }
        }
    }

    private function pushStock(StoreAdapter $adapter, string $storeId, array &$c, array &$errors): void
    {
        foreach ($this->stores->stockToPush($storeId, 500) as $v) {
            try {
                $adapter->pushStock($v['external_id'], $v['stock_qty']);
                $this->stores->setVariantRemoteStock($v['id'], $v['stock_qty']);
            } catch (StoreException $e) {
                if (in_array($e->errorCode, ['auth_failed', 'rate_limited'], true)) {
                    throw $e;
                }
                $c['failed']++;
                self::note($errors, $v['id'], $e->userMessage());
            }
        }
    }

    /** @param list<array{ref: string, message: string}> $errors */
    private static function note(array &$errors, string $ref, string $message): void
    {
        if (count($errors) < 50) {
            $errors[] = ['ref' => $ref, 'message' => $message];
        }
    }
}
