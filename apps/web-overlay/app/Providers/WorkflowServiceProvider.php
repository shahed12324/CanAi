<?php

declare(strict_types=1);

namespace App\Providers;

use App\Assist\AssistEngine;
use App\Calendar\CalendarAccounts;
use App\Calendar\CalendarService;
use App\Chat\CreditPricing;
use App\Chat\ModelPicker;
use App\Credits\CreditLedger;
use App\Knowledge\KnowledgeContext;
use App\Links\ChatHooks;
use App\Marketing\MarketingAccess;
use App\Marketing\RoleMarketingAccess;
use App\Reports\ReportRepo;
use App\Seo\SeoRepo;
use App\Mobile\MobileRepo;
use App\Productivity\TaskRepo;
use App\Workflow\AiStep;
use App\Workflow\CreditedAiStep;
use App\Workflow\PgWorkflowRepo;
use App\Workflow\ToolRegistry;
use App\Workflow\Tools\CalendarAddEventTool;
use App\Workflow\Tools\CalendarUpcomingTool;
use App\Workflow\Tools\ChatPostTool;
use App\Workflow\Tools\KnowledgeSearchTool;
use App\Workflow\Tools\MarketingSnapshotTool;
use App\Workflow\Tools\NotesAddTool;
use App\Workflow\Tools\NotifyMeTool;
use App\Workflow\Tools\PlanTodayTool;
use App\Workflow\Tools\SeoLatestTool;
use App\Workflow\Tools\PcListDirTool;
use App\Workflow\Tools\PcReadFileTool;
use App\Workflow\Tools\PcResultTool;
use App\Workflow\Tools\PcWriteFileTool;
use App\Workflow\Tools\WebhookSendTool;
use App\Pc\PcRepo;
use App\Pc\PcService;
use App\Developer\WebhookRepo;
use App\Workflow\Tools\TasksAddTool;
use App\Workflow\Tools\TasksListTool;
use App\Workflow\WorkflowRepo;
use App\Workflow\WorkflowRunner;
use App\Workflow\WorkflowService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

final class WorkflowServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The Agent Builder rides on the workflow engine. Registering it here too (idempotent) means a stale
        // bootstrap/providers.php can never leave /app/agent-builder without its AgentRepo binding.
        $this->app->register(AgentsServiceProvider::class);
        $this->app->bind(WorkflowRepo::class, PgWorkflowRepo::class);
        $this->app->bind(MarketingAccess::class, RoleMarketingAccess::class);
        $this->app->singleton(ToolRegistry::class, fn ($app) => new ToolRegistry([
            new TasksAddTool($app->make(TaskRepo::class)), new TasksListTool($app->make(TaskRepo::class)), new PlanTodayTool($app->make(TaskRepo::class)),
            new NotesAddTool($app->make(TaskRepo::class)), new KnowledgeSearchTool($app->make(KnowledgeContext::class)), new NotifyMeTool($app->make(MobileRepo::class)),
            new CalendarUpcomingTool($app->make(CalendarService::class)), new ChatPostTool($app->make(ChatHooks::class)), new CalendarAddEventTool($app->make(CalendarAccounts::class)),
            new MarketingSnapshotTool($app->make(ReportRepo::class), $app->make(MarketingAccess::class)), new SeoLatestTool($app->make(SeoRepo::class), $app->make(MarketingAccess::class)), new WebhookSendTool($app->make(WebhookRepo::class)),
            new PcReadFileTool($app->make(PcService::class), $app->make(PcRepo::class)), new PcListDirTool($app->make(PcService::class), $app->make(PcRepo::class)),
            new PcWriteFileTool($app->make(PcService::class), $app->make(PcRepo::class)), new PcResultTool($app->make(PcRepo::class)),
        ]));
        $this->app->bind(AiStep::class, fn ($app) => new CreditedAiStep($app->make(ModelPicker::class), $app->make(AssistEngine::class), $app->make(CreditLedger::class), CreditPricing::fromConfig()));
        $this->app->bind(WorkflowRunner::class, fn ($app) => new WorkflowRunner($app->make(WorkflowRepo::class), $app->make(ToolRegistry::class), $app->make(AiStep::class), $app->make(MobileRepo::class)));
        $this->app->bind(WorkflowService::class, fn ($app) => new WorkflowService($app->make(WorkflowRepo::class), $app->make(ToolRegistry::class)));
    }

    public function boot(): void
    {
        $this->app->booted(function () {
            $this->app->make(Schedule::class)->command('workflows:work')->everyMinute()->withoutOverlapping(10);
        });
    }
}
