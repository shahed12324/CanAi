<?php

declare(strict_types=1);

namespace App\Providers;

use App\Character\CharacterRepo;
use App\Character\PgCharacterRepo;
use Illuminate\Support\ServiceProvider;

final class CharacterServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CharacterRepo::class, PgCharacterRepo::class);
    }
}
