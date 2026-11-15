<?php

declare(strict_types=1);

namespace App\Providers;

use App\Ai\CredentialVault;
use App\Couriers\CourierAdapter;
use App\Couriers\CourierException;
use App\Couriers\CourierRepo;
use App\Couriers\CourierSecrets;
use App\Couriers\PathaoAdapter;
use App\Couriers\PgCourierRepo;
use App\Couriers\RedxAdapter;
use App\Couriers\ShipmentService;
use App\Couriers\SteadfastAdapter;
use App\Orders\OrderRepo;
use App\Outreach\HttpJson;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

final class CourierServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CourierRepo::class, PgCourierRepo::class);
        $this->app->bind(CourierSecrets::class, fn ($app) => new CourierSecrets($app->make(CredentialVault::class), $app->make(CourierRepo::class)));
        $this->app->bind(ShipmentService::class, fn ($app) => new ShipmentService(
            $app->make(CourierRepo::class),
            $app->make(OrderRepo::class),
            fn (string $accountId) => $app->make(CourierSecrets::class)->open($accountId),
            fn (string $courier, array $secrets, array $settings): CourierAdapter => match ($courier) {
                'steadfast' => new SteadfastAdapter($app->make(HttpJson::class), $secrets),
                'pathao' => new PathaoAdapter($app->make(HttpJson::class), $secrets, $settings),
                'redx' => new RedxAdapter($app->make(HttpJson::class), $secrets, $settings),
                default => throw new CourierException('unknown courier'),
            },
        ));
    }

    public function boot(): void
    {
        $this->app->booted(function () {
            $s = $this->app->make(Schedule::class);
            $s->command('couriers:sync')->everyFifteenMinutes()->withoutOverlapping(10);
            $s->command('orders:daily-report')->dailyAt('00:10')->timezone('Asia/Dhaka')->withoutOverlapping(30);
        });
    }
}
