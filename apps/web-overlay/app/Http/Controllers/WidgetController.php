<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Inbox\InboxRepo;
use App\Inbox\WidgetService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Public endpoints of the website chat widget. Cross-origin access is granted only to origins the shop owner listed. */
final class WidgetController extends Controller
{
    public function __construct(private readonly WidgetService $widget, private readonly InboxRepo $repo) {}

    private static function origin(Request $r): ?string
    {
        $o = $r->headers->get('Origin');

        return is_string($o) && strlen($o) <= 200 ? $o : null;
    }

    /** @param array{0: int, 1: array<string, mixed>, 2: ?string} $res */
    private static function out(array $res)
    {
        $resp = response()->json($res[1], $res[0]);
        if ($res[2] !== null) {
            $resp->headers->set('Access-Control-Allow-Origin', $res[2]);
            $resp->headers->set('Vary', 'Origin');
        }
        $resp->headers->set('Cache-Control', 'no-store');

        return $resp;
    }

    private static function bearer(Request $r): ?string
    {
        $h = (string) $r->headers->get('Authorization', '');

        return str_starts_with($h, 'Bearer ') ? substr($h, 7) : null;
    }

    public function preflight(Request $request, string $key)
    {
        $ch = preg_match('/\A[0-9a-f]{32}\z/', $key) === 1 ? $this->repo->channelByKey($key) : null;
        $origin = self::origin($request);
        $resp = new Response('', 204);
        if ($ch !== null && $origin !== null && in_array($origin, $ch['allowed_origins'], true)) {
            $resp->headers->set('Access-Control-Allow-Origin', $origin);
            $resp->headers->set('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
            $resp->headers->set('Access-Control-Allow-Headers', 'Authorization, Content-Type');
            $resp->headers->set('Access-Control-Max-Age', '600');
            $resp->headers->set('Vary', 'Origin');
        }

        return $resp;
    }

    public function start(Request $request, string $key)
    {
        return self::out($this->widget->start($key, self::origin($request), $this->str($request->input('visitor')), self::bearer($request)));
    }

    public function post(Request $request, string $key)
    {
        return self::out($this->widget->post($key, self::origin($request), $this->str($request->input('visitor')), self::bearer($request),
            (string) $request->input('text', ''), $this->str($request->input('client_id'))));
    }

    public function poll(Request $request, string $key)
    {
        return self::out($this->widget->poll($key, self::origin($request), $this->str($request->query('visitor')), self::bearer($request), (int) $request->query('after', 0)));
    }

    private function str(mixed $v): ?string
    {
        return is_string($v) && strlen($v) <= 100 ? $v : null;
    }
}
