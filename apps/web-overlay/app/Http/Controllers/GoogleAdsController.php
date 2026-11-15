<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\GoogleAds\GadsAdviceRepo;
use App\GoogleAds\GadsAdvisor;
use App\GoogleAds\GoogleAdsAccounts;
use App\GoogleAds\GoogleAdsApi;
use App\GoogleAds\GoogleAdsRepo;
use App\Outreach\Pkce;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;

/** Google Ads (read only for now): connect a customer through Google's consent screen, see campaigns and the last days' results copied from Google.  Nothing here can create, change or spend anything on Google. */
final class GoogleAdsController extends Controller
{
    private const ERRORS = [
        'failed' => 'The connection was not completed. Try again.', 'no_refresh' => 'Google Did not grant permanent permission — start again and keep all permissions ticked.', 'scope' => 'Google Ads-The permission for was not ticked — connect again and grant permission.',
        'token' => 'Google Did not accept the permission.', 'rejected' => 'Google Ads The account could not be verified.', 'inactive' => 'This Google Ads account is not active (ENABLED).', 'limit' => 'At most 5 Google Ads accounts can be added.',
    ];

    public function __construct(private readonly GoogleAdsRepo $repo, private readonly GoogleAdsAccounts $accounts, private readonly GoogleAdsApi $api, private readonly GadsAdviceRepo $advice, private readonly TenantContext $tenant) {}

    private static function can(Request $r, string $p): void
    {
        abort_unless($r->user()->can($p), 403);
    }

    private static function redirectUri(): string
    {
        return rtrim((string) config('app.url'), '/').route('gads.callback', [], false);
    }

    public function index(Request $request)
    {
        self::can($request, 'ads.view');
        $days = in_array((int) $request->query('days'), [7, 30], true) ? (int) $request->query('days') : 7;
        $rows = $this->repo->summary($days);
        $sum = fn (string $k) => array_sum(array_map(fn ($r) => (float) $r[$k], $rows));

        $names = [];
        foreach ($this->repo->accounts() as $a) {
            $names[$a['id']] = $a['name'];
        }

        return view('gads.index', ['advice' => GadsAdvisor::advise($this->advice->stats()), 'pauses' => $this->advice->requests(), 'acc_names' => $names, 'can_approve' => $request->user()->can('ads.approve'), 'accounts' => $this->repo->accounts(), 'rows' => $rows, 'days' => $days, 'can_connect' => $request->user()->can('ads.connect'), 'ready' => $this->api->configured(), 'redirect' => self::redirectUri(),
            'totals' => ['cost' => $sum('cost'), 'impressions' => (int) $sum('impressions'), 'clicks' => (int) $sum('clicks'), 'conversions' => $sum('conversions')]]);
    }

    public function pause(Request $request, string $id)
    {
        self::can($request, 'ads.approve');
        abort_unless(preg_match('/\A[0-9a-z]{26}\z/', $id) === 1, 404);
        $d = $request->validate(['campaign' => ['required', 'regex:/\A[0-9]{1,20}\z/'], 'reason' => ['nullable', 'string', 'max:300']]);
        $r = $this->advice->requestPause($this->tenant->idOrFail(), (string) $request->user()->id, $id, $d['campaign'], (string) ($d['reason'] ?? ''));
        $msg = ['ok' => 'The pause request was accepted — it will be PAUSED in Google Ads within a minute.', 'exists' => 'A pause request for this campaign is already in progress.', 'not_active' => 'The campaign is no longer active (per the sync).', 'account' => 'The account is not active — connect again.'][$r] ?? 'Could not be done.';

        return redirect()->route('gads.index')->with($r === 'ok' ? 'status' : 'error', $msg);
    }

    public function start(Request $request)
    {
        self::can($request, 'ads.connect');
        if (! $this->api->configured()) {
            return redirect()->route('gads.index')->with('error', 'The Google Ads connection keys (OAuth and Developer Token) are not set on this server.');
        }
        $d = $request->validate(['customer_id' => ['required', 'string', 'max:20'], 'login_customer_id' => ['nullable', 'string', 'max:20']]);
        $customer = GoogleAdsAccounts::cleanId($d['customer_id']);
        $login = ($d['login_customer_id'] ?? '') === '' ? null : GoogleAdsAccounts::cleanId($d['login_customer_id']);
        if ($customer === null || (($d['login_customer_id'] ?? '') !== '' && $login === null)) {
            return back()->withErrors(['gads' => 'Customer ID Must be 10 digits (for example 123-456-7890).'])->withInput();
        }
        $state = bin2hex(random_bytes(24));
        $verifier = Pkce::verifier();
        $request->session()->put('gads_oauth', ['state' => $state, 'verifier' => $verifier, 'tenant' => $this->tenant->idOrFail(), 'user' => (string) $request->user()->getAuthIdentifier(), 'customer' => $customer, 'login' => $login, 'expires' => time() + 600]);

        return redirect()->away($this->api->authUrl(self::redirectUri(), $state, Pkce::challenge($verifier)));
    }

    public function callback(Request $request)
    {
        self::can($request, 'ads.connect');
        $saved = $request->session()->pull('gads_oauth');                              // one use only, whatever happens next
        $back = redirect()->route('gads.index');
        if (! is_array($saved) || $saved['expires'] < time() || $saved['tenant'] !== $this->tenant->idOrFail() || $saved['user'] !== (string) $request->user()->getAuthIdentifier()
            || ! is_string($request->query('state')) || ! hash_equals($saved['state'], $request->query('state'))) {
            return $back->with('error', 'The connection request has expired or is invalid. Start again.');
        }
        if ($request->query('error') !== null || ! is_string($request->query('code')) || $request->query('code') === '') {
            return $back->with('error', 'The connection was cancelled or permission was not granted.');
        }
        $msg = null;
        $r = $this->accounts->connect($this->tenant->idOrFail(), $saved['customer'], $saved['login'], (string) $request->query('code'), $saved['verifier'], self::redirectUri(), $msg);

        return $r === 'ok' ? $back->with('status', 'Google Ads The account was connected. The token is sealed — it can no longer be seen.') : $back->with('error', (self::ERRORS[$r] ?? self::ERRORS['failed']).($msg ? ' '.$msg : ''));
    }

    public function disconnect(Request $request, string $id)
    {
        self::can($request, 'ads.connect');
        abort_unless(preg_match('/\A[0-9a-z]{26}\z/', $id) === 1, 404);
        $r = $this->accounts->disconnect($this->tenant->idOrFail(), $id);

        return redirect()->route('gads.index')->with($r === 'ok' ? 'status' : 'error', $r === 'ok' ? 'The account was removed; nothing changed in Google.' : 'Could not be removed.');
    }
}
