<?php

declare(strict_types=1);

namespace App\Tenancy;

use RuntimeException;

/** Use on every tenant-owned Eloquent model. The table must also have RLS (see DATABASE_SCHEMA.md). */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model): void {
            $current = app(TenantContext::class)->idOrFail();
            if ($model->tenant_id !== null && $model->tenant_id !== $current) {
                throw new RuntimeException('Refusing to create a record for a different tenant.');
            }
            $model->tenant_id = $current;
        });
    }
}
