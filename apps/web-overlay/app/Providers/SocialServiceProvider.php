<?php

declare(strict_types=1);

namespace App\Providers;

use App\Assist\AssistEngine;
use App\Chat\CreditPricing;
use App\Chat\ModelPicker;
use App\Credits\CreditLedger;
use App\Ai\CredentialVault;
use App\Social\FacebookGraph;
use App\Social\LongHttpJson;
use App\Social\PgSocialCommentRepo;
use App\Social\PgSocialCommentWorkRepo;
use App\Social\PgSocialPublishRepo;
use App\Social\PgSocialRepo;
use App\Social\PostMedia;
use App\Social\SocialCommentRepo;
use App\Social\SocialCommentWorkRepo;
use App\Social\SocialPublishRepo;
use App\Social\SocialReplyService;
use App\Social\SocialSecrets;
use App\Social\StudioPostMedia;
use App\Social\TokenSealer;
use App\Ads\AdsCopyService;
use App\Ads\AdsDraftRepo;
use App\Ads\AdsAdviceRepo;
use App\GoogleAds\GadsAdviceRepo;
use App\GoogleAds\GadsDraftRepo;
use App\GoogleAds\GadsPauser;
use App\GoogleAds\PgGadsAdviceRepo;
use App\GoogleAds\GadsLaunchRepo;
use App\GoogleAds\GadsLauncher;
use App\GoogleAds\GoogleAdsLaunchApi;
use App\GoogleAds\PgGadsLaunchRepo;
use App\GoogleAds\GoogleAdsApi;
use App\GoogleAds\GoogleAdsRepo;
use App\GoogleAds\PgGadsDraftRepo;
use App\GoogleAds\PgGoogleAdsRepo;
use App\Ads\AdsLaunchRepo;
use App\Ads\AdsLauncher;
use App\Ads\AdsRepo;
use App\Ads\AdsPauser;
use App\Ads\MetaAdsLaunchApi;
use App\Ads\PgAdsAdviceRepo;
use App\Ads\PgAdsLaunchRepo;
use App\Ads\PgAdsDraftRepo;
use App\Ads\MetaAdsApi;
use App\Ads\PgAdsRepo;
use App\Social\YoutubeApi;
use App\Social\YoutubeSeoService;
use App\Social\SocialCaptionService;
use App\Social\SocialGrowthService;
use App\Social\SocialPlanService;
use App\Social\SocialService;
use App\Social\SocialRepo;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

final class SocialServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SocialRepo::class, PgSocialRepo::class);
        $this->app->bind(SocialPlanService::class, fn ($app) => new SocialPlanService($app->make(ModelPicker::class), $app->make(AssistEngine::class), $app->make(CreditLedger::class), CreditPricing::fromConfig(), $app->make(SocialRepo::class), $app->make(SocialService::class)));
        $this->app->bind(SocialGrowthService::class, fn ($app) => new SocialGrowthService($app->make(SocialRepo::class), $app->make(ModelPicker::class), $app->make(AssistEngine::class), $app->make(CreditLedger::class), CreditPricing::fromConfig(), $app->make(SocialService::class)));
        $this->app->bind(SocialCommentRepo::class, PgSocialCommentRepo::class);
        $this->app->bind(SocialCommentWorkRepo::class, PgSocialCommentWorkRepo::class);
        $this->app->bind(SocialReplyService::class, fn ($app) => new SocialReplyService($app->make(SocialCommentRepo::class), $app->make(ModelPicker::class), $app->make(AssistEngine::class), $app->make(CreditLedger::class), CreditPricing::fromConfig()));
        $this->app->bind(AdsRepo::class, PgAdsRepo::class);
        $this->app->bind(AdsDraftRepo::class, PgAdsDraftRepo::class);
        $this->app->bind(AdsLaunchRepo::class, PgAdsLaunchRepo::class);
        $this->app->bind(MetaAdsLaunchApi::class, fn () => new MetaAdsLaunchApi(new LongHttpJson, (string) config('social.graph_version', 'v24.0')));
        $this->app->bind(AdsAdviceRepo::class, PgAdsAdviceRepo::class);
        $this->app->bind(AdsPauser::class, fn ($app) => new AdsPauser($app->make(AdsAdviceRepo::class), $app->make(AdsRepo::class), $app->make(TokenSealer::class), $app->make(MetaAdsLaunchApi::class)));
        $this->app->bind(AdsLauncher::class, fn ($app) => new AdsLauncher($app->make(AdsLaunchRepo::class), $app->make(AdsRepo::class), $app->make(TokenSealer::class), $app->make(MetaAdsLaunchApi::class)));
        $this->app->bind(AdsCopyService::class, fn ($app) => new AdsCopyService($app->make(ModelPicker::class), $app->make(AssistEngine::class), $app->make(CreditLedger::class), CreditPricing::fromConfig()));
        $this->app->bind(MetaAdsApi::class, fn () => new MetaAdsApi(new LongHttpJson, (string) config('social.graph_version', 'v24.0')));
        $this->app->bind(GoogleAdsRepo::class, PgGoogleAdsRepo::class);
        $this->app->bind(GadsDraftRepo::class, PgGadsDraftRepo::class);
        $this->app->bind(GoogleAdsApi::class, fn () => new GoogleAdsApi(new LongHttpJson, config('social.google_ads.client_id'), config('social.google_ads.client_secret'), config('social.google_ads.developer_token'), (string) config('social.google_ads.version', 'v24')));
        $this->app->bind(GadsLaunchRepo::class, PgGadsLaunchRepo::class);
        $this->app->bind(GoogleAdsLaunchApi::class, fn () => new GoogleAdsLaunchApi(new LongHttpJson, config('social.google_ads.developer_token'), (string) config('social.google_ads.version', 'v24')));
        $this->app->bind(GadsLauncher::class, fn ($app) => new GadsLauncher($app->make(GadsLaunchRepo::class), $app->make(GoogleAdsRepo::class), $app->make(TokenSealer::class), $app->make(GoogleAdsApi::class), $app->make(GoogleAdsLaunchApi::class)));
        $this->app->bind(GadsAdviceRepo::class, PgGadsAdviceRepo::class);
        $this->app->bind(GadsPauser::class, fn ($app) => new GadsPauser($app->make(GadsAdviceRepo::class), $app->make(GoogleAdsRepo::class), $app->make(TokenSealer::class), $app->make(GoogleAdsApi::class), $app->make(GoogleAdsLaunchApi::class)));
        $this->app->bind(YoutubeApi::class, fn () => new YoutubeApi(new LongHttpJson, config('social.youtube.client_id'), config('social.youtube.client_secret')));
        $this->app->bind(YoutubeSeoService::class, fn ($app) => new YoutubeSeoService($app->make(ModelPicker::class), $app->make(AssistEngine::class), $app->make(CreditLedger::class), CreditPricing::fromConfig()));
        $this->app->bind(SocialPublishRepo::class, PgSocialPublishRepo::class);
        $this->app->bind(PostMedia::class, StudioPostMedia::class);
        $this->app->bind(SocialSecrets::class, fn ($app) => new SocialSecrets($app->make(CredentialVault::class)));
        $this->app->bind(TokenSealer::class, SocialSecrets::class);
        $this->app->bind(FacebookGraph::class, fn () => new FacebookGraph(new LongHttpJson, (string) config('social.graph_version', 'v24.0')));
        $this->app->bind(SocialCaptionService::class, fn ($app) => new SocialCaptionService($app->make(ModelPicker::class), $app->make(AssistEngine::class), $app->make(CreditLedger::class), CreditPricing::fromConfig()));
    }

    public function boot(): void
    {
        $this->app->booted(function () {
            $this->app->make(Schedule::class)->command('social:publish')->everyMinute()->withoutOverlapping(10);
            $this->app->make(Schedule::class)->command('social:youtube')->everyMinute()->withoutOverlapping(30);
            $this->app->make(Schedule::class)->command('social:reply')->everyMinute()->withoutOverlapping(10);
            $this->app->make(Schedule::class)->command('social:comments')->everyTenMinutes()->withoutOverlapping(30);
            $this->app->make(Schedule::class)->command('social:insights')->everyFifteenMinutes()->withoutOverlapping(30);
            $this->app->make(Schedule::class)->command('ads:sync')->everyThirtyMinutes()->withoutOverlapping(30);
            $this->app->make(Schedule::class)->command('ads:launch')->everyMinute()->withoutOverlapping(30);
            $this->app->make(Schedule::class)->command('ads:pause')->everyMinute()->withoutOverlapping(10);
            $this->app->make(Schedule::class)->command('gads:launch')->everyMinute()->withoutOverlapping(30);
            $this->app->make(Schedule::class)->command('gads:pause')->everyMinute()->withoutOverlapping(10);
            $this->app->make(Schedule::class)->command('gads:sync')->everyThirtyMinutes()->withoutOverlapping(30);
        });
    }
}
