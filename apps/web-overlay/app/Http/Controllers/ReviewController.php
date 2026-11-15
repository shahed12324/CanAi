<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Studio\StudioService;
use App\Studio\StudioText;
use Illuminate\Http\Request;

/**
 * Public, for a client.  The long random token in the link is the only credential; it grants one thing: look at one picture and answer once.
 * Nothing here shows who owns the design or anything else of the workspace.
 */
final class ReviewController extends Controller
{
    public function __construct(private readonly StudioService $studio) {}

    private function page(string $view, array $data, int $status = 200)
    {
        return response()->view($view, $data, $status)->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer')->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function show(string $token)
    {
        $v = $this->studio->publicView($token);
        if ($v === null) {
            return $this->page('review.show', ['v' => null, 'token' => $token, 'message' => StudioText::answer('not_found')], 404);
        }

        return $this->page('review.show', ['v' => $v, 'token' => $token, 'message' => session('review_message')]);
    }

    public function image(string $token)
    {
        $v = $this->studio->publicView($token);
        abort_if($v === null || in_array($v['status'], ['revoked', 'expired'], true), 404);
        $bytes = (string) base64_decode($v['preview_b64'], true);
        $mime = str_starts_with($bytes, "\xFF\xD8\xFF") ? 'image/jpeg' : (str_starts_with($bytes, "\x89PNG") ? 'image/png' : null);
        abort_if($mime === null, 404);

        return response($bytes, 200, ['Content-Type' => $mime, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Referrer-Policy' => 'no-referrer', 'X-Robots-Tag' => 'noindex']);
    }

    public function decide(Request $request, string $token)
    {
        $d = $request->validate(['decision' => ['required', 'in:approved,changes'], 'comment' => ['nullable', 'string', 'max:1000']]);
        $r = $this->studio->decide($token, $d['decision'], (string) ($d['comment'] ?? ''));

        return redirect()->route('review.show', ['token' => $token])->with('review_message', StudioText::answer($r))->withHeaders(['Referrer-Policy' => 'no-referrer']);
    }
}
