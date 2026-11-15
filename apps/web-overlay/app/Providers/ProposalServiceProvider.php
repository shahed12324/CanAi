<?php

declare(strict_types=1);

namespace App\Providers;

use App\Crm\CrmStore;
use App\Proposals\HttpProposalEngine;
use App\Proposals\PgProposalRepo;
use App\Proposals\ProposalEngine;
use App\Proposals\ProposalRepo;
use App\Proposals\ProposalService;
use Illuminate\Support\ServiceProvider;

final class ProposalServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ProposalRepo::class, PgProposalRepo::class);
        $this->app->bind(ProposalEngine::class, HttpProposalEngine::class);
        $this->app->bind(\App\Reports\ReportRepo::class, \App\Reports\PgReportRepo::class);
        $this->app->bind(\App\Clients\ClientRepo::class, \App\Clients\PgClientRepo::class);
        $this->app->bind(ProposalService::class, fn ($app) => new ProposalService($app->make(ProposalRepo::class), $app->make(CrmStore::class), $app->make(ProposalEngine::class)));
    }
}
