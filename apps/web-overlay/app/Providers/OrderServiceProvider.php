<?php

declare(strict_types=1);

namespace App\Providers;

use App\Orders\OrderRepo;
use App\Orders\PgOrderRepo;
use Illuminate\Support\ServiceProvider;

final class OrderServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(OrderRepo::class, PgOrderRepo::class);
    }
}
