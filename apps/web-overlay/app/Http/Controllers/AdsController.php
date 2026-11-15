<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Ads\AdsAccounts;
use App\Ads\AdsAdviceRepo;
use App\Ads\AdsAdvisor;
use App\Ads\AdsRepo;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;

/** Meta Ads (read only for now): connect an ad account, see campaigns and the last days' results copied from Meta.  Nothing here can create, change or spend anything on Meta. */
final class AdsController extends Controller
{
    private const MESSAGES = [
        'bad_account' => 'The ad account ID is not valid (digits only, "act_" is also fine).', 'bad_token' => 'The token structure is not valid.', 'mismatch' => 'The ID did not match — the token belongs to another account.',
        'token' => 'Meta The token was not accepted (expired or too few permissions).', 'rejected' => 'Meta The account could not be verified.', 'limit' => 'At most 5 ad accounts can be added.', 'failed' => 'Could not be done. Try again.',
    ];

    public function __construct(private readonly AdsRepo $repo, private readonly AdsAccounts $accounts, private readonly AdsAdviceRepo $advice, private readonly TenantContext $tenant) {}

    private static function can(Request $r, string $p): void
    {
        abort_unless($r->user()->can($p), 403);
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

        return view('ads.index', ['advice' => AdsAdvisor::advise($this->advice->stats()), 'pauses' => $this->advice->requests(), 'acc_names' => $names, 'can_approve' => $request->user()->can('ads.approve'), 'accounts' => $this->repo->accounts(), 'rows' => $rows, 'days' => $days, 'can_connect' => $request->user()->can('ads.connect'),
            'totals' => ['spend' => $sum('spend'), 'impressions' => (int) $sum('impressions'), 'clicks' => (int) $sum('clicks'), 'results' => (int) $sum('results')]]);
    }

    public function connect(Request $request)
    {
        self::can($request, 'ads.connect');
        $d = $request->validate(['account_id' => ['required', 'string', 'max:40'], 'token' => ['required', 'string', 'max:700']]);
        $msg = null;
        $r = $this->accounts->connect($this->tenant->idOrFail(), $d['account_id'], $d['token'], $msg);
        if ($r !== 'ok') {
            return back()->withErrors(['ads' => (self::MESSAGES[$r] ?? self::MESSAGES['failed']).($msg ? ' '.$msg : '')])->withInput($request->only('account_id'));
        }

        return redirect()->route('ads.index')->with('status', 'The account was connected. The token is sealed — it can no longer be seen. The first sync will happen within 30 minutes.');
    }

    public function pixel(Request $request, string $id)
    {
        self::can($request, 'ads.connect');
        abort_unless(preg_match('/\A[0-9a-z]{26}\z/', $id) === 1, 404);
        $d = $request->validate(['pixel_id' => ['nullable', 'regex:/\A[0-9]{5,30}\z/']]);
        $r = $this->repo->setPixel($this->tenant->idOrFail(), $id, $d['pixel_id'] ?? '');

        return redirect()->route('ads.index')->with($r === 'ok' ? 'status' : 'error', $r === 'ok' ? 'Pixel Saved.' : 'Pixel The ID is not valid (digits only).');
    }

    public function pause(Request $request, string $id)
    {
        self::can($request, 'ads.approve');
        abort_unless(preg_match('/\A[0-9a-z]{26}\z/', $id) === 1, 404);
        $d = $request->validate(['campaign' => ['required', 'regex:/\A[0-9]{5,30}\z/'], 'reason' => ['nullable', 'string', 'max:300']]);
        $r = $this->advice->requestPause($this->tenant->idOrFail(), (string) $request->user()->id, $id, $d['campaign'], (string) ($d['reason'] ?? ''));
        $msg = ['ok' => 'The pause request was accepted — it will be PAUSED in Meta within a minute.', 'exists' => 'A pause request for this campaign is already in progress.', 'not_active' => 'The campaign is no longer active (per the sync).', 'account' => 'The account is not active — connect again.'][$r] ?? 'Could not be done.';

        return redirect()->route('ads.index')->with($r === 'ok' ? 'status' : 'error', $msg);
    }

    public function disconnect(Request $request, string $id)
    {
        self::can($request, 'ads.connect');
        abort_unless(preg_match('/\A[0-9a-z]{26}\z/', $id) === 1, 404);
        $r = $this->accounts->disconnect($this->tenant->idOrFail(), $id);

        return redirect()->route('ads.index')->with($r === 'ok' ? 'status' : 'error', $r === 'ok' ? 'The account was removed. Nothing changed in Meta.' : \App\Ads\AdsText::message($r));
    }
}
