<?php

declare(strict_types=1);

namespace Jarvis\Connector;

/**
 * A ProductStore for a typical shop schema, driven by a column map (see config/jarvis-connector.php).
 * Table and column names come from your config (never from a request) and must be plain identifiers.
 * Not covered: custom option tables, per-variant images, categories as separate tables. For those implement ProductStore yourself.
 */
final class DatabaseProductStore implements ProductStore
{
    /** @var array<string, mixed> */
    private array $c;

    /** @param array<string, mixed> $config */
    public function __construct(private readonly Db $db, array $config, private $now = null)
    {
        $this->c = $config + ['id' => 'id', 'price_unit' => 'major', 'images' => [], 'where' => null, 'soft_delete' => null, 'touch' => null, 'insert_defaults' => [],
            'variants' => null, 'description_html' => false, 'sale_empty' => null, 'currency' => 'BDT', 'columns' => []];
        $this->c['columns'] += ['title' => null, 'description' => null, 'price' => null, 'sale_price' => null, 'sku' => null, 'category' => null, 'stock' => null, 'images' => null, 'tags' => null];
        $this->c['images'] += ['format' => 'json', 'base_url' => null, 'write' => false];
        foreach ([$this->c['table'] ?? '', $this->c['id'], $this->c['soft_delete'], $this->c['touch'], ...array_values($this->c['columns']), ...array_keys($this->c['insert_defaults'])] as $ident) {
            if ($ident !== null) {
                self::ident((string) $ident);
            }
        }
        if (! empty($this->c['variants'])) {
            foreach ($this->c['variants'] as $v) {
                if ($v !== null) {
                    self::ident((string) $v);
                }
            }
        }
        if (! in_array($this->c['price_unit'], ['major', 'minor'], true) || ! in_array($this->c['images']['format'], ['json', 'csv'], true)) {
            throw new \InvalidArgumentException('price_unit must be major|minor and images.format json|csv');
        }
        if ($this->c['columns']['title'] === null || $this->c['columns']['price'] === null) {
            throw new \InvalidArgumentException('columns.title and columns.price are required');
        }
    }

    private static function ident(string $s): string
    {
        if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]{0,63}\z/', $s) !== 1) {
            throw new \InvalidArgumentException("Not a plain table/column name: $s");
        }

        return $s;
    }

    private function t(): string
    {
        return $this->c['table'];
    }

    private function col(string $k): ?string
    {
        return $this->c['columns'][$k];
    }

    private function time(): string
    {
        return gmdate('Y-m-d H:i:s', $this->now !== null ? ($this->now)() : time());
    }

    /** @return array{0: string, 1: list<mixed>} */
    private function filter(): array
    {
        $w = [];
        if ($this->c['where'] !== null && $this->c['where'] !== '') {
            $w[] = '('.$this->c['where'].')';
        }
        if ($this->c['soft_delete'] !== null) {
            $w[] = $this->c['soft_delete'].' IS NULL';
        }

        return [$w === [] ? '1=1' : implode(' AND ', $w), []];
    }

    private static function key(string $v): string|int
    {
        return ctype_digit($v) ? (int) $v : $v;
    }

    public function page(?string $cursor, int $limit): array
    {
        $limit = max(1, min(100, $limit));
        [$where] = $this->filter();
        $id = $this->c['id'];
        $args = [];
        if ($cursor !== null) {
            $where .= " AND $id > ?";
            $args[] = self::key($cursor);
        }
        $rows = $this->db->select('SELECT * FROM '.$this->t()." WHERE $where ORDER BY $id ASC LIMIT ".($limit + 1), $args);
        $next = null;
        if (count($rows) > $limit) {
            array_pop($rows);
            $next = (string) $rows[count($rows) - 1][$id];
        }

        return ['items' => array_map(fn ($r) => $this->build($r), $rows), 'next' => $next];
    }

    public function find(string $id): ?array
    {
        $row = $this->row($id);

        return $row === null ? null : $this->build($row);
    }

    /** @return array<string, mixed>|null */
    private function row(string $id): ?array
    {
        [$where] = $this->filter();

        return $this->db->select('SELECT * FROM '.$this->t()." WHERE $where AND {$this->c['id']} = ? LIMIT 1", [self::key($id)])[0] ?? null;
    }

    /** @param array<string, mixed> $r @return array<string, mixed> */
    private function build(array $r): array
    {
        $id = (string) $r[$this->c['id']];
        $get = fn (string $k) => $this->col($k) === null ? null : ($r[$this->col($k)] ?? null);
        $price = $this->minor($get('price')) ?? 0;
        $sale = $this->minor($get('sale_price'));
        $variants = [];
        if (! empty($this->c['variants'])) {
            $v = $this->c['variants'];
            foreach ($this->db->select("SELECT * FROM {$v['table']} WHERE {$v['product_id']} = ? ORDER BY {$v['id']} ASC", [self::key($id)]) as $vr) {
                $opts = $v['options'] !== null ? json_decode((string) ($vr[$v['options']] ?? ''), true) : [];
                $o = [];
                foreach (is_array($opts) ? $opts : [] as $k => $val) {
                    if (is_string($k) && is_scalar($val)) {
                        $o[$k] = (string) $val;
                    }
                }
                ksort($o);
                $variants[] = ['external_id' => $id.'/'.$vr[$v['id']], 'sku' => ($vr[$v['sku']] ?? '') === '' ? null : (string) $vr[$v['sku']], 'options' => $o,
                    'price_minor' => isset($v['price'], $vr[$v['price']]) && $vr[$v['price']] !== null ? $this->minor($vr[$v['price']]) : null,
                    'stock' => isset($v['stock'], $vr[$v['stock']]) && $vr[$v['stock']] !== null ? max(0, (int) $vr[$v['stock']]) : null];
            }
        }
        if ($variants === []) {
            $stock = $get('stock');
            $variants[] = ['external_id' => $id, 'sku' => null, 'options' => [], 'price_minor' => null, 'stock' => $stock === null ? null : max(0, (int) $stock)];
        }
        $tags = $get('tags');

        return [
            'external_id' => $id, 'title' => trim((string) $get('title')) ?: "Product $id", 'description' => self::plain($get('description')),
            'price_minor' => $price, 'sale_price_minor' => $sale !== null && $sale > 0 && $sale < $price ? $sale : null, 'currency' => $this->c['currency'],
            'sku' => ($get('sku') ?? '') === '' ? null : (string) $get('sku'), 'category' => ($get('category') ?? '') === '' ? null : (string) $get('category'),
            'tags' => $tags === null || $tags === '' ? [] : array_values(array_filter(array_map('trim', explode(',', (string) $tags)))), 'images' => $this->readImages($get('images')), 'variants' => $variants,
        ];
    }

    /** @return list<string> */
    private function readImages(mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }
        $list = $this->c['images']['format'] === 'json' ? json_decode((string) $raw, true) : explode(',', (string) $raw);
        $base = $this->c['images']['base_url'];
        $out = [];
        foreach (is_array($list) ? $list : [] as $i) {
            $u = trim((string) (is_array($i) ? ($i['url'] ?? $i['path'] ?? '') : $i));
            if ($u === '') {
                continue;
            }
            if (preg_match('#\Ahttps?://#i', $u) !== 1 && $base !== null && ! str_contains($u, ':')) {
                $u = rtrim($base, '/').'/'.ltrim($u, '/');
            }
            if (preg_match('#\Ahttps?://[^\s]{4,500}\z#i', $u) === 1) {
                $out[] = $u;
            }
        }

        return array_slice($out, 0, 10);
    }

    private static function plain(mixed $html): string
    {
        $s = preg_replace('#<\s*br\s*/?>|</\s*(p|div|li|h[1-6])\s*>#i', "\n", (string) $html) ?? '';
        $s = html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace("/\n{3,}/", "\n\n", preg_replace("/[ \t]+/", ' ', $s) ?? $s) ?? $s);
    }

    private function minor(mixed $v): ?int
    {
        if ($v === null || $v === '') {
            return null;
        }
        if ($this->c['price_unit'] === 'minor') {
            return max(0, (int) round((float) $v));
        }

        return max(0, (int) str_replace('.', '', sprintf('%.2f', (float) $v)));
    }

    private function money(?int $minor): int|string|null
    {
        if ($minor === null) {
            return null;
        }

        return $this->c['price_unit'] === 'minor' ? $minor : sprintf('%d.%02d', intdiv($minor, 100), $minor % 100);
    }

    public function upsert(?string $id, array $p): string
    {
        return $this->db->transaction(function () use ($id, $p) {
            $set = [];
            $put = function (string $key, mixed $val) use (&$set) {
                if ($this->col($key) !== null) {
                    $set[$this->col($key)] = $val;
                }
            };
            $put('title', $p['title']);
            $put('description', $this->c['description_html'] ? nl2br(htmlspecialchars($p['description'], ENT_QUOTES | ENT_HTML5, 'UTF-8'), false) : $p['description']);
            $put('price', $this->money($p['price_minor']));
            $put('sale_price', $p['sale_price_minor'] === null ? $this->c['sale_empty'] : $this->money($p['sale_price_minor']));
            $put('sku', $p['sku']);
            $put('category', $p['category']);
            $put('tags', $this->col('tags') !== null ? implode(',', $p['tags']) : null);
            if ($this->c['images']['write'] && $p['images'] !== []) {
                $put('images', $this->c['images']['format'] === 'json' ? json_encode($p['images'], JSON_UNESCAPED_SLASHES) : implode(',', $p['images']));
            }
            $withVariants = ! empty($this->c['variants']) && array_filter($p['variants'], fn ($v) => $v['options'] !== []) !== [];
            if (! $withVariants && $p['variants'][0]['stock'] !== null) {
                $put('stock', $p['variants'][0]['stock']);
            }
            if ($this->c['touch'] !== null) {
                $set[$this->c['touch']] = $this->time();
            }

            if ($id === null) {
                $set += $this->c['insert_defaults'];
                $cols = array_keys($set);
                $this->db->execute('INSERT INTO '.$this->t().' ('.implode(', ', $cols).') VALUES ('.implode(', ', array_fill(0, count($cols), '?')).')', array_values($set));
                $id = $this->db->lastId();
            } else {
                if ($this->row($id) === null) {
                    throw new \OutOfBoundsException('product not found');
                }
                if ($set !== []) {
                    $this->db->execute('UPDATE '.$this->t().' SET '.implode(', ', array_map(fn ($c) => "$c = ?", array_keys($set)))." WHERE {$this->c['id']} = ?", [...array_values($set), self::key($id)]);
                }
            }
            if ($withVariants) {
                $this->upsertVariants($id, $p['variants']);
            }

            return (string) $id;
        });
    }

    /** @param list<array<string, mixed>> $variants */
    private function upsertVariants(string $productId, array $variants): void
    {
        $v = $this->c['variants'];
        $have = [];
        foreach ($this->db->select("SELECT * FROM {$v['table']} WHERE {$v['product_id']} = ?", [self::key($productId)]) as $row) {
            $o = $v['options'] !== null ? json_decode((string) ($row[$v['options']] ?? ''), true) : [];
            $o = is_array($o) ? array_map('strval', $o) : [];
            ksort($o);
            $have[json_encode($o, JSON_UNESCAPED_UNICODE)] = $row[$v['id']];
        }
        foreach ($variants as $var) {
            if ($var['options'] === []) {
                continue;
            }
            $set = [];
            foreach (['sku' => $var['sku'], 'price' => $this->money($var['price_minor']), 'stock' => $var['stock']] as $k => $val) {
                if (isset($v[$k]) && $v[$k] !== null && ($val !== null || $k === 'price')) {
                    $set[$v[$k]] = $val;
                }
            }
            $sig = json_encode($var['options'], JSON_UNESCAPED_UNICODE);
            if (isset($have[$sig])) {
                if ($set !== []) {
                    $this->db->execute("UPDATE {$v['table']} SET ".implode(', ', array_map(fn ($c) => "$c = ?", array_keys($set)))." WHERE {$v['id']} = ?", [...array_values($set), $have[$sig]]);
                }
            } else {
                $set[$v['product_id']] = self::key($productId);
                if ($v['options'] !== null) {
                    $set[$v['options']] = json_encode($var['options'], JSON_UNESCAPED_UNICODE);
                }
                $this->db->execute("INSERT INTO {$v['table']} (".implode(', ', array_keys($set)).') VALUES ('.implode(', ', array_fill(0, count($set), '?')).')', array_values($set));
            }
        }
    }

    public function setStock(string $variantExternalId, int $qty): void
    {
        if (str_contains($variantExternalId, '/')) {
            [$pid, $vid] = explode('/', $variantExternalId, 2);
            $v = $this->c['variants'];
            if (empty($v) || ! isset($v['stock']) || $v['stock'] === null) {
                throw new \InvalidArgumentException('variant stock is not configured');
            }
            $n = $this->db->execute("UPDATE {$v['table']} SET {$v['stock']} = ? WHERE {$v['id']} = ? AND {$v['product_id']} = ?", [$qty, self::key($vid), self::key($pid)]);
            if ($n === 0 && $this->db->select("SELECT 1 AS x FROM {$v['table']} WHERE {$v['id']} = ? AND {$v['product_id']} = ?", [self::key($vid), self::key($pid)]) === []) {
                throw new \OutOfBoundsException('variant not found');
            }

            return;
        }
        if ($this->col('stock') === null) {
            throw new \InvalidArgumentException('stock is not configured');
        }
        if ($this->row($variantExternalId) === null) {
            throw new \OutOfBoundsException('product not found');
        }
        $this->db->execute('UPDATE '.$this->t()." SET {$this->col('stock')} = ? WHERE {$this->c['id']} = ?", [$qty, self::key($variantExternalId)]);
    }
}
