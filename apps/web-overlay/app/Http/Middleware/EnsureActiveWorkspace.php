<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/** Blocks suspended users on existing sessions and picks a default workspace (personal first). */
final class EnsureActiveWorkspace
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user === null) {
            return $next($request);
        }

        if ($user->status !== 'active') {
            abort_unless($request->hasSession(), 403, 'Account unavailable.');
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect('/login')->withErrors(['email' => 'This account is not usable now.']);
        }

        if ($request->hasSession() && ! $request->session()->has('tenant_id')) {
            $id = DB::table('tenant_users as tu')
                ->join('tenants as t', 't.id', '=', 'tu.tenant_id')
                ->where('tu.user_id', $user->getAuthIdentifier())
                ->where('tu.status', 'active')
                ->where('t.status', 'active')
                ->whereNull('t.deleted_at')
                ->orderByRaw("t.type = 'personal' desc")
                ->orderBy('tu.joined_at')
                ->value('t.id');
            if ($id !== null) {
                $request->session()->put('tenant_id', $id);
            }
        }

        return $next($request);
    }
}
