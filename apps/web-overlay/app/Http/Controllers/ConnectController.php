<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Calendar\CalendarAccounts;
use App\Calendar\CalendarService;
use App\Links\ChatHooks;
use App\Links\LinkRepo;
use App\Tenancy\TenantContext;
use App\Productivity\TaskInput;
use DateTimeImmutable;
use Illuminate\Http\Request;

/** "Connections": calendar feeds (read-only, by secret ICS address) and Slack / Discord webhooks.  Everything here belongs to the signed-in person only. */
final class ConnectController extends Controller
{
    public function __construct(private readonly CalendarService $calendar, private readonly CalendarAccounts $accounts, private readonly ChatHooks $hooks, private readonly LinkRepo $links, private readonly TenantContext $tenant) {}

    private static function id(string $v): string
    {
        abort_unless(preg_match('/\A[0-9a-z]{26}\z/', $v) === 1, 404);

        return $v;
    }

    private function uid(Request $r): string
    {
        return (string) $r->user()->id;
    }

    public function index(Request $request)
    {
        $u = $this->uid($request);
        $tz = (string) ($request->user()->timezone ?: 'Asia/Dhaka');
        $up = $this->calendar->upcoming($this->tenant->idOrFail(), $u, $tz, new DateTimeImmutable('now'), 7);

        return view('connect.index', ['feeds' => $this->links->list($u, 'ics'), 'slack' => $this->links->list($u, 'slack'), 'discord' => $this->links->list($u, 'discord'), 'up' => $up,
            'oauth' => $this->accounts->list($u), 'oauthReady' => ['google' => $this->accounts->configured('google'), 'microsoft' => $this->accounts->configured('microsoft')]]);
    }

    public function addFeed(Request $request)
    {
        $d = $request->validate(['name' => ['required', 'string', 'max:60'], 'url' => ['required', 'string', 'max:700']]);
        $r = $this->calendar->addFeed($this->tenant->idOrFail(), $this->uid($request), $d['name'], $d['url']);
        if ($r['status'] === 'ok') {
            return back()->with('status', 'The calendar was added.');
        }

        return back()->withErrors(['connect' => $r['message'] ?? ChatHooks::message($r['status'])])->withInput($request->only('name'));
    }

    /** Adds one event to the person's connected calendar (a real, visible change outside the app, so only on their explicit click). */
    public function addEvent(Request $request)
    {
        $d = $request->validate(['title' => ['required', 'string', 'max:200'], 'start' => ['required', 'string', 'max:20'], 'minutes' => ['required', 'integer', 'min:5', 'max:1440'],
            'location' => ['nullable', 'string', 'max:200'], 'calendar' => ['nullable', 'string', 'regex:/\A[0-9a-z]{26}\z/']]);
        $tz = TaskInput::zone((string) ($request->user()->timezone ?: 'Asia/Dhaka'));
        $r = $this->accounts->create($this->tenant->idOrFail(), $this->uid($request), $d['calendar'] ?? null, $d['title'], $d['start'], (int) $d['minutes'], (string) ($d['location'] ?? ''), $tz);
        if ($r['status'] === 'ok') {
            return back()->with('status', 'The event was added to the calendar.');
        }

        return back()->withErrors(['connect' => CalendarAccounts::message($r['status'])])->withInput($request->only('title', 'start', 'minutes', 'location'));
    }

    public function addHook(Request $request)
    {
        $d = $request->validate(['kind' => ['required', 'in:slack,discord'], 'name' => ['required', 'string', 'max:60'], 'url' => ['required', 'string', 'max:400']]);
        $r = $this->hooks->add($this->tenant->idOrFail(), $this->uid($request), $d['kind'], $d['name'], $d['url']);

        return $r === 'ok' ? back()->with('status', 'Added. "Test message" verify with.') : back()->withErrors(['connect' => ChatHooks::message($r)])->withInput($request->only('name', 'kind'));
    }

    public function test(Request $request, string $id)
    {
        $r = $this->hooks->send($this->tenant->idOrFail(), $this->uid($request), self::id($id), 'Test message from Jarvis One ✅');

        return $r === 'ok' ? back()->with('status', 'A test message was sent — check the channel.') : back()->withErrors(['connect' => ChatHooks::message($r)]);
    }

    public function destroy(Request $request, string $id)
    {
        $r = $this->links->delete($this->tenant->idOrFail(), $this->uid($request), self::id($id));

        return $r === 'ok' ? back()->with('status', 'Deleted.') : back()->withErrors(['connect' => ChatHooks::message($r)]);
    }
}
