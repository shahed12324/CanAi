<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class SecurityController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user();

        return view('settings.security', [
            'user' => $user,
            'currentSession' => $request->session()->getId(),
            'sessions' => DB::table('sessions')->where('user_id', $user->getKey())->orderByDesc('last_activity')->get(),
            'tokens' => $user->tokens()->orderByDesc('id')->get(['id', 'name', 'last_used_at', 'expires_at', 'created_at']),
        ]);
    }

    public function destroySession(Request $request, string $id)
    {
        DB::table('sessions')
            ->where('user_id', $request->user()->getKey())->where('id', $id)
            ->where('id', '!=', $request->session()->getId())
            ->delete();

        return back()->with('status', 'The session was closed.');
    }

    public function destroyOtherSessions(Request $request)
    {
        DB::table('sessions')
            ->where('user_id', $request->user()->getKey())
            ->where('id', '!=', $request->session()->getId())
            ->delete();

        return back()->with('status', 'All other sessions were closed.');
    }

    public function destroyToken(Request $request, int $id)
    {
        $request->user()->tokens()->whereKey($id)->delete();
        \Illuminate\Support\Facades\DB::select('select mobile_revoke_token(?, ?)', [(string) $request->user()->getKey(), $id]);   // that phone stops receiving push

        return back()->with('status', 'API The token was revoked.');
    }
}
