<?php

declare(strict_types=1);

namespace App\Providers;

use App\Seo\HttpSeoEngine;
use App\Seo\PgSeoRepo;
use App\Seo\SeoEngine;
use App\Seo\SeoRepo;
use Illuminate\Support\ServiceProvider;

final class SeoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SeoEngine::class, HttpSeoEngine::class);
        $this->app->bind(SeoRepo::class, PgSeoRepo::class);
    }
}
