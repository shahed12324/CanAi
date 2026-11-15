<?php

declare(strict_types=1);

namespace App\Providers;

use App\Knowledge\KnowledgeContext;
use App\Knowledge\KnowledgeRepo;
use App\Knowledge\PgKnowledgeRepo;
use App\Knowledge\RepoKnowledgeContext;
use Illuminate\Support\ServiceProvider;

final class KnowledgeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(KnowledgeRepo::class, PgKnowledgeRepo::class);
        $this->app->bind(KnowledgeContext::class, RepoKnowledgeContext::class);
    }
}
