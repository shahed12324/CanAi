<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Tenancy\CreateWorkspace;
use App\GoogleAds\GadsCopyService;
use App\GoogleAds\GadsDraftForm;
use App\GoogleAds\GadsDraftRepo;
use App\GoogleAds\GadsText;
use App\GoogleAds\GoogleAdsRepo;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;

/** Google Ads Search drafts: write (with an AI helper for the words), set the spending caps, send for approval, approve.  Nothing is sent to Google from here. */
final class GadsDraftController extends Controller
{
    private const AI_MESSAGES = [
        'empty' => 'Write what the product/offer is.', 'brief_long' => 'The description is at most 1500 characters.', 'bad_tone' => 'The tone is not valid.', 'no_model' => 'No chat model is enabled for writing.', 'no_credits' => 'Credits ran out.',
        'provider' => 'AI The service is not responding right now. Try again shortly.', 'unreadable' => 'AI-The reply could not be read. Try again (credits were deducted).', 'failed' => 'Could not be done. Try again.',
    ];

    public function __construct(private readonly GadsDraftRepo $repo, private readonly GoogleAdsRepo $accounts, private readonly GadsCopyService $ai, private readonly TenantContext $tenant) {}

    private static function id(string $v): string
    {
        abort_unless(preg_match('/\A[0-9a-z]{26}\z/', $v) === 1, 404);

        return $v;
    }

    private static function can(Request $r, string $p): void
    {
        abort_unless($r->user()->can($p), 403);
    }

    public function index(Request $request)
    {
        self::can($request, 'ads.view');

        return view('gads.drafts', ['drafts' => $this->repo->drafts(), 'caps' => $this->repo->caps(), 'accounts' => $this->accounts->accounts(), 'can_manage' => $request->user()->can('ads.manage'), 'can_caps' => $request->user()->can('ads.connect')]);
    }

    public function saveCaps(Request $request)
    {
        self::can($request, 'ads.connect');
        $d = $request->validate(['max_daily' => ['required', 'regex:/\A[0-9]{1,9}(\.[0-9]{1,2})?\z/'], 'max_total' => ['required', 'regex:/\A[0-9]{1,9}(\.[0-9]{1,2})?\z/']]);
        $r = $this->repo->saveCaps($this->tenant->idOrFail(), (string) $request->user()->id, $d['max_daily'], $d['max_total']);

        return redirect()->route('gads.drafts')->with($r === 'ok' ? 'status' : 'error', $r === 'ok' ? 'The spending limit was saved.' : 'The limit is not valid — the total limit cannot be below the daily one, and both must be above 0.');
    }

    public function create(Request $request)
    {
        self::can($request, 'ads.manage');

        return view('gads.draft', ['d' => null, 'accounts' => $this->accounts->accounts(), 'rid' => strtolower(CreateWorkspace::newId()), 'caps' => $this->repo->caps(), 'can_manage' => true, 'can_approve' => false, 'me' => (string) $request->user()->id]);
    }

    public function edit(Request $request, string $id)
    {
        self::can($request, 'ads.view');
        $d = $this->repo->find(self::id($id));
        abort_if($d === null, 404);

        return view('gads.draft', ['d' => $d, 'accounts' => $this->accounts->accounts(), 'rid' => strtolower(CreateWorkspace::newId()), 'caps' => $this->repo->caps(), 'can_manage' => $request->user()->can('ads.manage') && $d['user_id'] === (string) $request->user()->id,
            'can_approve' => $request->user()->can('ads.approve'), 'me' => (string) $request->user()->id]);
    }

    public function store(Request $request)
    {
        self::can($request, 'ads.manage');

        return $this->persist($request, true, strtolower(CreateWorkspace::newId()));
    }

    public function update(Request $request, string $id)
    {
        self::can($request, 'ads.manage');

        return $this->persist($request, false, self::id($id));
    }

    private function persist(Request $request, bool $new, string $id)
    {
        $f = GadsDraftForm::parse($request->all());
        if (! $f['ok']) {
            return back()->withErrors(['gads' => $f['error']])->withInput();
        }
        $r = $this->repo->save($new, $this->tenant->idOrFail(), (string) $request->user()->id, $id, $f['v']);
        if ($r !== 'ok') {
            return back()->withErrors(['gads' => GadsText::message($r)])->withInput();
        }

        return redirect()->route('gads.draft', $id)->with('status', 'The draft was saved. It has not gone to Google yet.');
    }

    public function ai(Request $request)
    {
        self::can($request, 'ads.manage');
        $d = $request->validate(['rid' => ['required', 'regex:/\A[0-9A-Za-z]{26}\z/'], 'brief' => ['required', 'string', 'max:1500'], 'tone' => ['required', 'string', 'max:20']]);
        $r = $this->ai->suggest($this->tenant->idOrFail(), (string) $request->user()->id, $d['brief'], $d['tone'], $d['rid']);
        if ($r['status'] !== 'ok') {
            return response()->json(['ok' => false, 'message' => self::AI_MESSAGES[$r['status']] ?? self::AI_MESSAGES['failed']], 422);
        }

        return response()->json(['ok' => true] + $r['result']);
    }

    public function submit(Request $request, string $id)
    {
        self::can($request, 'ads.manage');

        return $this->flash($this->repo->submit($this->tenant->idOrFail(), (string) $request->user()->id, self::id($id)), $id, 'Submitted for approval.');
    }

    public function decide(Request $request, string $id)
    {
        self::can($request, 'ads.approve');
        $d = $request->validate(['decision' => ['required', 'in:approve,reject'], 'note' => ['nullable', 'string', 'max:300']]);
        $ok = $d['decision'] === 'approve';
        $r = $this->repo->decide($this->tenant->idOrFail(), (string) $request->user()->id, self::id($id), $ok, $d['note'] ?? null);

        return $this->flash($r, $id, $ok ? 'Approved. Within a minute it will start being created in Google Ads in paused (PAUSED) state — you start it yourself in Google Ads.' : 'Returned.');
    }

    public function retry(Request $request, string $id)
    {
        self::can($request, 'ads.manage');
        $r = $this->repo->retryLaunch($this->tenant->idOrFail(), (string) $request->user()->id, self::id($id), $request->boolean('confirm'));

        return $this->flash($r, $id, 'Queued for retry — it will start within a minute. What was already created will not be created again.');
    }

    public function reopen(Request $request, string $id)
    {
        self::can($request, 'ads.manage');

        return $this->flash($this->repo->reopen($this->tenant->idOrFail(), (string) $request->user()->id, self::id($id)), $id, 'Returned to draft.');
    }

    public function destroy(Request $request, string $id)
    {
        self::can($request, 'ads.manage');
        $r = $this->repo->delete($this->tenant->idOrFail(), (string) $request->user()->id, self::id($id));

        return redirect()->route('gads.drafts')->with($r === 'ok' ? 'status' : 'error', $r === 'ok' ? 'The draft was deleted.' : GadsText::message($r));
    }

    private function flash(string $r, string $id, string $okText)
    {
        return redirect()->route('gads.draft', $id)->with($r === 'ok' ? 'status' : 'error', $r === 'ok' ? $okText : GadsText::message($r));
    }
}
