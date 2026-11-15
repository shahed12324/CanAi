<?php

declare(strict_types=1);

namespace App\Providers;

use App\Brand\BrandRepo;
use App\Brand\PgBrandRepo;
use Illuminate\Support\ServiceProvider;

final class BrandServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(BrandRepo::class, PgBrandRepo::class);
    }
}
