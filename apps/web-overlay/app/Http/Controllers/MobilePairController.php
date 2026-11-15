<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Api\AuthController;
use Illuminate\Http\Request;

/** A signed-in user shows a short-lived QR; the CANAI phone app scans it and is logged in as that user (no password typed). */
final class MobilePairController extends Controller
{
    public function show(Request $request)
    {
        $payload = null;
        if (session()->has('pair_code')) {
            $payload = 'canai://pair?server='.rawurlencode(rtrim((string) config('app.url'), '/')).'&code='.session()->pull('pair_code');
        }

        return view('settings.mobile', [
            'payload' => $payload, 'minutes' => AuthController::PAIR_TTL_MINUTES,
            'devices' => $request->user()->tokens()->orderByDesc('id')->limit(20)->get(['id', 'name', 'last_used_at', 'created_at']),
        ]);
    }

    public function create(Request $request)
    {
        return redirect()->route('mobile.show')->with('pair_code', AuthController::makePairCode((string) $request->user()->getAuthIdentifier()));
    }

    public function revoke(Request $request, int $id)
    {
        $request->user()->tokens()->whereKey($id)->delete();
        \Illuminate\Support\Facades\DB::select('select mobile_revoke_token(?, ?)', [(string) $request->user()->getAuthIdentifier(), $id]);

        return back()->with('status', 'Device disconnected.');
    }
}
