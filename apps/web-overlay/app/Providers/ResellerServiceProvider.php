<?php

declare(strict_types=1);

namespace App\Providers;

use App\Reseller\PgResellerRepo;
use App\Reseller\ResellerRepo;
use Illuminate\Support\ServiceProvider;

final class ResellerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ResellerRepo::class, PgResellerRepo::class);
    }
}
