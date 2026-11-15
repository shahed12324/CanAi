<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Public. The token in the mail is the only credential. GET only asks "are you sure?" (mail scanners open links);
 * the POST does the work and also serves mail clients' one-click List-Unsubscribe.
 */
final class UnsubscribeController extends Controller
{
    public function show(string $token)
    {
        return response()->view('outreach.unsubscribe', ['token' => $token])->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function confirm(Request $request, string $token)
    {
        // same answer whether or not the token exists: the page must not tell who is on which list
        DB::scalar('select outreach_unsubscribe(?)', [$token]);

        return response()->view('outreach.unsubscribed')->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }
}
