<?php

declare(strict_types=1);

namespace App\Providers;

use App\Ai\CredentialVault;
use App\Crm\CrmStore;
use App\Http\Controllers\UnsubscribeController;
use App\Outreach\AccessTokens;
use App\Outreach\DnsResolver;
use App\Outreach\GmailInbox;
use App\Outreach\GmailTransport;
use App\Outreach\GoogleOAuth;
use App\Outreach\GraphInbox;
use App\Outreach\GraphTransport;
use App\Outreach\HttpJson;
use App\Outreach\ImapInbox;
use App\Outreach\LaravelHttpJson;
use App\Outreach\MicrosoftOAuth;
use App\Outreach\RoutingMailTransport;
use App\Outreach\MailTransport;
use App\Outreach\NativeDnsResolver;
use App\Outreach\OutreachInbox;
use App\Outreach\OutreachRepo;
use App\Outreach\OutreachService;
use App\Outreach\PgOutreachRepo;
use App\Outreach\StreamImapConnection;
use App\Outreach\SymfonyMailTransport;
use App\Outreach\TransportFailure;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class OutreachServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->bindInbox();
        $this->app->bind(OutreachRepo::class, PgOutreachRepo::class);
        $this->app->singleton(HttpJson::class, LaravelHttpJson::class);
        $this->app->singleton('outreach.oauth', fn ($app) => [
            'gmail' => new GoogleOAuth($app->make(HttpJson::class), config('outreach.google.client_id'), config('outreach.google.client_secret')),
            'graph' => new MicrosoftOAuth($app->make(HttpJson::class), config('outreach.microsoft.client_id'), config('outreach.microsoft.client_secret'), (string) config('outreach.microsoft.tenant', 'common')),
        ]);
        $this->app->bind(AccessTokens::class, fn ($app) => new AccessTokens(
            $app->make('outreach.oauth'), $app->make(OutreachRepo::class),
            fn (string $c, int $v) => $app->make(CredentialVault::class)->decrypt($c, $v),
            fn (string $plain) => $app->make(CredentialVault::class)->encrypt($plain),
        ));
        $this->app->bind(MailTransport::class, fn ($app) => new RoutingMailTransport(new SymfonyMailTransport, new GmailTransport($app->make(HttpJson::class)), new GraphTransport($app->make(HttpJson::class))));
        $this->app->bind(DnsResolver::class, NativeDnsResolver::class);
        $this->app->bind(OutreachService::class, fn ($app) => new OutreachService(
            $app->make(OutreachRepo::class),
            $app->make(CrmStore::class),
            $app->make(MailTransport::class),
            fn (string $cipher, int $version) => $app->make(CredentialVault::class)->decrypt($cipher, $version),
            fn (array $account) => $app->make(AccessTokens::class)->forAccount($account),
            fn (string $token) => route('outreach.unsubscribe', ['token' => $token]),
            fn () => now('Asia/Dhaka')->toDateString(),
            fn () => usleep(random_int(2_000_000, 6_000_000)),         // 2-6 s between two mails: no burst
            function (array $m): array {                                // the proposal PDF, made by the proposal service under the approver's name
                $r = $m['approved_by'] === null ? ['ok' => false] : $app->make(\App\Proposals\ProposalService::class)->render($m['tenant_id'], $m['approved_by'], $m['proposal_id'], 'pdf', 'Asia/Dhaka');
                if (! ($r['ok'] ?? false)) {
                    throw new TransportFailure('proposal_unavailable', false);
                }

                return ['name' => $r['filename'], 'mime' => $r['mime'], 'bytes' => $r['bytes']];
            },
        ));
    }

    private function bindInbox(): void
    {
        $this->app->bind(OutreachInbox::class, fn ($app) => new OutreachInbox(
            $app->make(OutreachRepo::class),
            ['gmail' => new GmailInbox($app->make(HttpJson::class)), 'graph' => new GraphInbox($app->make(HttpJson::class)), 'imap' => new ImapInbox(fn () => new StreamImapConnection)],
            fn (array $account) => $app->make(AccessTokens::class)->forAccount($account),
            fn () => time(),
            fn (string $cipher, int $version) => $app->make(CredentialVault::class)->decrypt($cipher, $version),
        ));
    }

    public function boot(): void
    {
        // 'api' group: no session and no CSRF, because mail clients POST here (RFC 8058 one-click). The 128-bit token is the credential.
        Route::middleware(['api', 'throttle:30,1'])->post('u/{token}', [UnsubscribeController::class, 'confirm'])->where('token', '[0-9a-f]{32}')->name('outreach.unsubscribe.confirm');

        $this->app->booted(function () {
            $this->app->make(Schedule::class)->command('outreach:run')->everyMinute()->withoutOverlapping(30);
        });
    }
}
