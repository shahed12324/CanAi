<?php

declare(strict_types=1);

namespace App\Providers;

use App\License\LicenseRepo;
use App\License\PgLicenseRepo;
use Illuminate\Support\ServiceProvider;

final class LicenseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(LicenseRepo::class, PgLicenseRepo::class);
    }
}
