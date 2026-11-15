<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\SystemSettings;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/** Database-driven maintenance mode, switched from the admin panel. Admin sign-in stays reachable. */
final class CheckMaintenance
{
    private const OPEN_PREFIXES = [
        'admin', 'login', 'logout', 'two-factor-challenge', 'user/confirm-password',
        'forgot-password', 'reset-password',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! SystemSettings::bool('maintenance.enabled', false)) {
            return $next($request);
        }
        if (Str::startsWith($request->path(), self::OPEN_PREFIXES)) {
            return $next($request);
        }
        $allowed = array_filter(array_map('trim', explode(',', (string) SystemSettings::get('maintenance.allowed_ips', ''))));
        if (in_array($request->ip(), $allowed, true) || $request->user()?->is_platform_admin) {
            return $next($request);
        }

        $message = (string) (SystemSettings::get('maintenance.message') ?: 'System maintenance is in progress. Try again shortly.');

        return $request->expectsJson()
            ? response()->json(['message' => $message, 'code' => 'maintenance'], 503)
            : response()->view('maintenance', ['message' => $message], 503);
    }
}
