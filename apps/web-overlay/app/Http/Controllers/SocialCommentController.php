<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Tenancy\CreateWorkspace;
use App\Social\SocialCommentRepo;
use App\Social\SocialReplyService;
use App\Social\SocialService;
use App\Social\SocialText;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;

/** Comment inbox: fetched comments, AI / person drafts, approval.  Nothing is sent without a person approving that exact text. */
final class SocialCommentController extends Controller
{
    public function __construct(private readonly SocialCommentRepo $repo, private readonly SocialReplyService $replies, private readonly SocialService $social, private readonly TenantContext $tenant) {}

    private static function id(string $v): string
    {
        abort_unless(preg_match('/\A[0-9a-z]{26}\z/', $v) === 1, 404);

        return $v;
    }

    private static function can(Request $r, string $p): void
    {
        abort_unless($r->user()->can($p), 403);
    }

    private function back(string $msg, bool $ok)
    {
        return redirect()->route('social.comments', request()->only('f'))->with($ok ? 'status' : 'error', $msg);
    }

    public function index(Request $request)
    {
        self::can($request, 'social.view');
        $f = in_array((string) $request->query('f'), ['todo', 'replied', 'ignored', 'all'], true) ? (string) $request->query('f') : 'todo';

        return view('social.comments', ['rows' => $this->repo->list($f, 100), 'counts' => $this->repo->counts(), 'f' => $f, 'rid' => CreateWorkspace::newId(), 'statusLabels' => SocialText::COMMENT_STATUS,
            'can_manage' => $request->user()->can('social.manage'), 'can_approve' => $request->user()->can('social.approve'), 'tz' => $this->social->zone()]);
    }

    public function draft(Request $request, string $id)
    {
        self::can($request, 'social.manage');
        $d = $request->validate(['reply' => ['required', 'string', 'max:1000']]);
        $r = $this->repo->draft($this->tenant->idOrFail(), (string) $request->user()->id, self::id($id), $d['reply']);

        return $r === 'ok' ? $this->back('Draft saved — nothing has been sent yet.', true) : $this->back(SocialText::reply($r), false);
    }

    public function ai(Request $request, string $id)
    {
        self::can($request, 'social.manage');
        $d = $request->validate(['rid' => ['required', 'regex:/\A[0-9A-Za-z]{26}\z/'], 'notes' => ['nullable', 'string', 'max:500']]);
        $r = $this->replies->draft($this->tenant->idOrFail(), (string) $request->user()->id, self::id($id), (string) ($d['notes'] ?? ''), $d['rid']);

        return $r['status'] === 'ok' ? $this->back('AI Draft made — read and fix it, then approve. Nothing has been sent yet.', true) : $this->back(SocialText::reply($r['status']), false);
    }

    public function approve(Request $request, string $id)
    {
        self::can($request, 'social.approve');
        $d = $request->validate(['reply' => ['required', 'string', 'max:1000']]);
        $r = $this->repo->approve($this->tenant->idOrFail(), (string) $request->user()->id, self::id($id), $d['reply']);

        return $r === 'ok' ? $this->back('Approved — the reply will go to Facebook within a minute.', true) : $this->back('Not approved — the draft changed or is no longer pending. Refresh the page and look again.', false);
    }

    public function withdraw(Request $request, string $id)
    {
        self::can($request, 'social.manage');
        $r = $this->repo->withdraw($this->tenant->idOrFail(), (string) $request->user()->id, self::id($id));

        return $r === 'ok' ? $this->back('Approval withdrawn — a draft again.', true) : $this->back('Could not be withdrawn (sending may have started).', false);
    }

    public function ignore(Request $request, string $id)
    {
        self::can($request, 'social.manage');
        $r = $this->repo->ignore($this->tenant->idOrFail(), (string) $request->user()->id, self::id($id));

        return $r === 'ok' ? $this->back('The comment was discarded.', true) : $this->back('Could not be discarded.', false);
    }

    public function retry(Request $request, string $id)
    {
        self::can($request, 'social.approve');
        $r = $this->repo->retry($this->tenant->idOrFail(), (string) $request->user()->id, self::id($id));

        return $r === 'ok' ? $this->back('Approved again — it will be sent assuming you checked the Facebook page first.', true) : $this->back('Could not be retried.', false);
    }
}
