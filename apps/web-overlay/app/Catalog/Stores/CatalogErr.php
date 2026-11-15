<?php

declare(strict_types=1);

namespace App\Catalog\Stores;

use App\Catalog\CatalogService;

/** One short, safe sentence for a failed row (never the raw database or HTTP text). */
final class CatalogErr
{
    public static function of(\Throwable $e): string
    {
        return CatalogService::friendly($e);
    }
}
