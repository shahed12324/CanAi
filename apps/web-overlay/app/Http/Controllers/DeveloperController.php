<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Developer\ApiKeyRepo;
use App\Developer\ApiKeys;
use App\Developer\DeveloperText;
use App\Developer\WebhookRepo;
use App\Developer\Webhooks;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;

/** Developer page: make and close API keys.  The key is shown once, right after it is made. */
final class DeveloperController extends Controller
{
    public function __construct(private readonly ApiKeys $keys, private readonly ApiKeyRepo $repo, private readonly TenantContext $tenant, private readonly Webhooks $webhooks, private readonly WebhookRepo $hooks) {}

    public function index(Request $request)
    {
        return view('developer.index', ['keys' => $this->repo->list((string) $request->user()->id), 'usage' => $this->repo->usage((string) $request->user()->id), 'scopes' => ApiKeys::SCOPES, 'new' => session('new_key'), 'hooks' => $this->hooks->list((string) $request->user()->id), 'deliveries' => $this->hooks->deliveries((string) $request->user()->id), 'newSecret' => session('new_secret'), 'base' => rtrim((string) config('app.url'), '/').'/api/public/v1']);
    }

    public function store(Request $request)
    {
        $d = $request->validate(['name' => ['required', 'string', 'max:80'], 'scopes' => ['required', 'array', 'min:1'], 'scopes.*' => ['string', 'in:'.implode(',', array_keys(ApiKeys::SCOPES))], 'days' => ['nullable', 'integer', 'min:1', 'max:365']]);
        $r = $this->keys->issue($this->tenant->idOrFail(), (string) $request->user()->id, $d['name'], $d['scopes'], isset($d['days']) ? (int) $d['days'] : null);
        if ($r['status'] !== 'ok') {
            return back()->withErrors(['key' => DeveloperText::message($r['status'])])->withInput();
        }

        // Shown once: only its hash is kept, so it cannot be shown again.
        return back()->with('new_key', $r['key'])->with('status', 'The key was created — copy it now, it cannot be seen again.');
    }

    public function revoke(Request $request, string $id)
    {
        abort_unless(preg_match('/\A[0-9a-z]{26}\z/', $id) === 1, 404);
        $this->keys->revoke($this->tenant->idOrFail(), (string) $request->user()->id, $id);

        return back()->with('status', 'The key was revoked.');
    }

    public function hookStore(Request $request)
    {
        $d = $request->validate(['name' => ['required', 'string', 'max:80'], 'url' => ['required', 'string', 'max:500']]);
        $r = $this->webhooks->add($this->tenant->idOrFail(), (string) $request->user()->id, $d['name'], $d['url']);
        if ($r['status'] !== 'ok') {
            return back()->withErrors(['hook' => DeveloperText::message($r['status'])])->withInput();
        }

        return back()->with('new_secret', $r['secret'])->with('status', 'The address was added — copy the secret now, it cannot be seen again.');
    }

    public function hookToggle(Request $request, string $id)
    {
        abort_unless(preg_match('/\A[0-9a-z]{26}\z/', $id) === 1, 404);
        $on = $request->boolean('active');
        $this->webhooks->setActive($this->tenant->idOrFail(), (string) $request->user()->id, $id, $on);

        return back()->with('status', $on ? 'Turned on (finished runs will be sent from now on).' : 'Turned off.');
    }

    public function hookEvents(Request $request, string $id)
    {
        abort_unless(preg_match('/\A[0-9a-z]{26}\z/', $id) === 1, 404);
        $d = $request->validate(['events' => ['required', 'array', 'max:4'], 'events.*' => ['string', 'in:'.implode(',', Webhooks::EVENTS)]]);
        $r = $this->webhooks->setEvents($this->tenant->idOrFail(), (string) $request->user()->id, $id, $d['events']);
        if ($r !== 'ok') {
            return back()->withErrors(['hook' => DeveloperText::message($r)]);
        }

        return back()->with('status', 'Events were changed (newly chosen events apply only to things happening from now).');
    }

    public function hookDelete(Request $request, string $id)
    {
        abort_unless(preg_match('/\A[0-9a-z]{26}\z/', $id) === 1, 404);
        $this->webhooks->delete($this->tenant->idOrFail(), (string) $request->user()->id, $id);

        return back()->with('status', 'The address was deleted.');
    }
}
