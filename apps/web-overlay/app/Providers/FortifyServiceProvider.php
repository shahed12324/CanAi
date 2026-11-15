<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Models\User;
use App\Support\LoginLog;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;

final class FortifyServiceProvider extends ServiceProvider
{
    /** Valid bcrypt hash of a random string; keeps login timing equal for unknown emails. */
    public const DUMMY_HASH = '$2y$12$usB/yZNhnlcHWYTJiMOJbexPBeMcfUmN5gIxm9NSvjF0/PitRN.zi';

    public function boot(): void
    {
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);

        Fortify::loginView(fn () => view('auth.login'));
        Fortify::registerView(fn () => view('auth.register'));
        Fortify::requestPasswordResetLinkView(fn () => view('auth.forgot-password'));
        Fortify::resetPasswordView(fn (Request $request) => view('auth.reset-password', ['request' => $request]));
        Fortify::verifyEmailView(fn () => view('auth.verify-email'));
        Fortify::twoFactorChallengeView(fn () => view('auth.two-factor-challenge'));
        Fortify::confirmPasswordView(fn () => view('auth.confirm-password'));

        Fortify::authenticateUsing(function (Request $request): ?User {
            $user = User::where('email', Str::lower((string) $request->input('email')))->first();
            // Always run one hash comparison so response time does not reveal which emails exist.
            $valid = Hash::check((string) $request->input('password'), $user?->password ?? self::DUMMY_HASH);

            return ($user !== null && $valid && $user->status === 'active') ? $user : null;
        });

        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by(
            Str::transliterate(Str::lower((string) $request->input(Fortify::username())).'|'.$request->ip())
        ));
        RateLimiter::for('two-factor', fn (Request $request) => Limit::perMinute(5)->by(
            (string) $request->session()->get('login.id', $request->ip())
        ));
        RateLimiter::for('fortify-global', fn (Request $request) => Limit::perMinute(30)->by((string) $request->ip()));
        RateLimiter::for('api-auth', fn (Request $request) => Limit::perMinute(10)->by(
            Str::lower((string) $request->input('email')).'|'.$request->ip()
        ));
        RateLimiter::for('invitations', fn (Request $request) => Limit::perMinute(20)->by(
            (string) ($request->user()?->getAuthIdentifier() ?? $request->ip())
        ));

        Event::listen(Login::class, function (Login $event): void {
            LoginLog::record($event->user->getAuthIdentifier(), $event->user->email ?? null, 'success');
            DB::table('users')->where('id', $event->user->getAuthIdentifier())->update(['last_login_at' => now()]);
        });
        Event::listen(Failed::class, function (Failed $event): void {
            LoginLog::record($event->user?->getAuthIdentifier(), $event->credentials['email'] ?? null, 'failed');
        });
    }
}
