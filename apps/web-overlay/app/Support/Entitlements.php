<?php

declare(strict_types=1);

namespace App\Support;

use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/** Plan/override based feature gating for the ACTIVE tenant. Backed by SQL effective_feature(). */
final class Entitlements
{
    public function __construct(private readonly TenantContext $context) {}

    public function resolve(string $key): object
    {
        return DB::selectOne('select * from effective_feature(?, ?)', [$this->context->idOrFail(), $key]);
    }

    public function enabled(string $key): bool
    {
        return (bool) $this->resolve($key)->enabled;
    }

    /** null = unlimited; 0 when the feature is not available. */
    public function limit(string $key): ?float
    {
        $e = $this->resolve($key);

        return ! $e->enabled ? 0.0 : ($e->limit_value === null ? null : (float) $e->limit_value);
    }

    /** True if one more unit still fits under the limit, given how many are used now. */
    public function allowsMore(string $key, int $used): bool
    {
        $limit = $this->limit($key);

        return $limit === null || $used < $limit;
    }
}
