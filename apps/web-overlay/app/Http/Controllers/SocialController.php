<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Tenancy\CreateWorkspace;
use App\Social\SocialCalendar;
use App\Social\SocialAccounts;
use App\Social\SocialCaptionService;
use App\Social\SocialGrowthService;
use App\Social\SocialPlanService;
use App\Social\SocialRepo;
use App\Social\SocialService;
use App\Social\SocialText;
use App\Social\YoutubeMeta;
use App\Studio\DesignRepo;
use App\Studio\StudioService;
use App\Tenancy\TenantContext;
use App\Video\VideoFiles;
use App\Video\VideoRepo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/** Social content planner: month calendar, drafts, approval step, "posted" tracking.  Nothing is published automatically (that is Phase 23b). */
final class SocialController extends Controller
{
    public function __construct(
        private readonly SocialService $social,
        private readonly SocialRepo $repo,
        private readonly SocialCaptionService $captions,
        private readonly SocialAccounts $pages,
        private readonly SocialPlanService $planner,
        private readonly SocialGrowthService $growth,
        private readonly DesignRepo $designs,
        private readonly VideoRepo $videos,
        private readonly VideoFiles $files,
        private readonly StudioService $studio,
        private readonly TenantContext $tenant,
    ) {}

    private static function id(string $v): string
    {
        abort_unless(preg_match('/\A[0-9a-z]{26}\z/', $v) === 1, 404);

        return $v;
    }

    private function uid(Request $r): string
    {
        return (string) $r->user()->id;
    }

    private static function can(Request $r, string $p): void
    {
        abort_unless($r->user()->can($p), 403);
    }

    /** @return array<string, mixed> */
    private function post(string $id): array
    {
        $p = $this->repo->find(self::id($id));
        abort_if($p === null, 404);

        return $p;
    }

    public function index(Request $request)
    {
        self::can($request, 'social.view');
        $tz = $this->social->zone();
        $now = new \DateTimeImmutable('now', $tz);
        [$y, $m] = SocialCalendar::month($request->query('m'), $now);
        $first = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $y, $m), $tz);
        $from = $first->modify('-7 days')->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:sP');
        $to = $first->modify('+1 month')->modify('+7 days')->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:sP');

        return view('social.index', [
            'grid' => SocialCalendar::grid($y, $m, $this->repo->between($from, $to, false), $tz, $now), 'year' => $y, 'month' => $m, 'tz' => $tz,
            'prev' => SocialCalendar::neighbour($y, $m, -1), 'next' => SocialCalendar::neighbour($y, $m, 1), 'unscheduled' => $this->repo->recent(50),
            'platforms' => SocialText::PLATFORMS, 'status' => SocialText::STATUS, 'can_manage' => $request->user()->can('social.manage'),
        ]);
    }

    private function formData(Request $request, ?array $post): array
    {
        $uid = $this->uid($request);
        $designs = array_map(fn ($d) => ['id' => $d['id'], 'title' => $d['title'], 'sizes' => $d['sizes']], $this->designs->list($uid));
        $videos = array_values(array_map(fn ($v) => ['id' => $v['id'], 'title' => $v['title']], array_filter($this->videos->list($uid), fn ($v) => $v['status'] === 'done')));

        return ['post' => $post, 'pages' => array_values(array_filter($this->repo->accounts(), fn ($a) => $a['status'] === 'active')), 'designs' => $designs, 'videos' => $videos, 'platforms' => SocialText::PLATFORMS, 'tones' => SocialText::TONES, 'limits' => SocialText::LIMITS,
            'scheduled' => $post === null ? ($request->query('d') !== null && preg_match('/\A\d{4}-\d{2}-\d{2}\z/', (string) $request->query('d')) === 1 ? $request->query('d').'T10:00' : '') : $this->social->toLocal($post['scheduled_at']),
            'rid' => CreateWorkspace::newId(), 'tz' => $this->social->zone()->getName()];
    }

    public function create(Request $request)
    {
        self::can($request, 'social.manage');

        return view('social.form', $this->formData($request, null));
    }

    public function edit(Request $request, string $id)
    {
        self::can($request, 'social.manage');
        $p = $this->post($id);
        abort_unless($p['user_id'] === $this->uid($request) && $p['status'] === 'draft', 403);

        return view('social.form', $this->formData($request, $p));
    }

    /** @return array<string, mixed> */
    private function input(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:120'], 'platform' => ['required', 'in:'.implode(',', array_keys(SocialText::PLATFORMS))], 'caption' => ['required', 'string', 'max:3000'],
            'media_kind' => ['required', 'in:none,design,video'], 'media_id' => ['nullable', 'string', 'max:40'], 'media_size' => ['nullable', 'string', 'max:20'], 'scheduled' => ['nullable', 'string', 'max:20'], 'account_id' => ['nullable', 'string', 'max:30'],
        ]);
    }

    public function store(Request $request)
    {
        self::can($request, 'social.manage');
        $in = $this->input($request);
        $r = $this->social->save($this->tenant->idOrFail(), $this->uid($request), null, $in);
        if ($r['status'] !== 'ok') {
            return back()->withErrors(['social' => SocialText::message($r['status'])])->withInput();
        }
        $note = $this->attachPage($request, (string) $r['id'], $in['account_id'] ?? null);

        return redirect()->route('social.show', $r['id'])->with('status', 'The draft was kept.')->with('error', $note);
    }

    public function update(Request $request, string $id)
    {
        self::can($request, 'social.manage');
        $in = $this->input($request);
        $r = $this->social->save($this->tenant->idOrFail(), $this->uid($request), self::id($id), $in);
        if ($r['status'] !== 'ok') {
            return back()->withErrors(['social' => SocialText::message($r['status'])])->withInput();
        }
        $note = $this->attachPage($request, $id, $in['account_id'] ?? null);

        return redirect()->route('social.show', $id)->with('status', 'The change was kept.')->with('error', $note);
    }

    /** Saves which Page will publish the post (only Facebook posts); returns a message when it could not. */
    private function attachPage(Request $request, string $id, ?string $accountId): ?string
    {
        $p = $this->repo->find($id);
        if ($p === null || $p['platform'] !== 'facebook' || ($accountId === null && $p['account_id'] === null)) {
            return null;
        }
        $r = $this->social->attach($this->tenant->idOrFail(), $this->uid($request), $id, $accountId);

        return $r === 'ok' ? null : 'The page could not be chosen: '.SocialText::message($r);
    }

    public function show(Request $request, string $id)
    {
        self::can($request, 'social.view');
        $p = $this->post($id);

        return view('social.show', ['p' => $p, 'platforms' => SocialText::PLATFORMS, 'status' => SocialText::STATUS, 'limit' => SocialText::LIMITS[$p['platform']] ?? 3000, 'local' => $this->social->toLocal($p['scheduled_at']),
            'tz' => $this->social->zone()->getName(), 'stats' => $p['status'] === 'posted' ? $this->repo->stats($p['id']) : null, 'mine' => $p['user_id'] === $this->uid($request), 'can_manage' => $request->user()->can('social.manage'), 'can_approve' => $request->user()->can('social.approve')]);
    }

    private function done(Request $request, string $id, string $result, string $ok)
    {
        return redirect()->route('social.show', $id)->with($result === 'ok' ? 'status' : 'error', $result === 'ok' ? $ok : SocialText::message($result));
    }

    public function submit(Request $request, string $id)
    {
        self::can($request, 'social.manage');
        $problems = YoutubeMeta::problems($this->post(self::id($id)));
        if ($problems !== []) {
            return redirect()->route('social.show', $id)->with('error', implode(' ', $problems));
        }

        return $this->done($request, $id, $this->social->submit($this->tenant->idOrFail(), $this->uid($request), self::id($id)), 'Sent for approval.');
    }

    public function decide(Request $request, string $id)
    {
        self::can($request, 'social.approve');
        $d = $request->validate(['decision' => ['required', 'in:approve,reject'], 'note' => ['nullable', 'string', 'max:300']]);
        $approve = $d['decision'] === 'approve';

        return $this->done($request, $id, $this->social->decide($this->tenant->idOrFail(), $this->uid($request), self::id($id), $approve, $d['note'] ?? null), $approve ? 'Approval was given.' : 'Returned.');
    }

    public function reopen(Request $request, string $id)
    {
        self::can($request, 'social.manage');

        return $this->done($request, $id, $this->social->reopen($this->tenant->idOrFail(), $this->uid($request), self::id($id)), 'Returned to draft.');
    }

    public function retry(Request $request, string $id)
    {
        self::can($request, 'social.manage');

        return $this->done($request, $id, $this->social->retry($this->tenant->idOrFail(), $this->uid($request), self::id($id)), 'Queued again — it will go within a minute.');
    }

    public function posted(Request $request, string $id)
    {
        self::can($request, 'social.manage');
        $d = $request->validate(['url' => ['nullable', 'string', 'max:300']]);

        return $this->done($request, $id, $this->social->markPosted($this->tenant->idOrFail(), $this->uid($request), self::id($id), $d['url'] ?? null), '"Posted" Marked.');
    }

    public function cancel(Request $request, string $id)
    {
        self::can($request, 'social.manage');

        return $this->done($request, $id, $this->social->cancel($this->tenant->idOrFail(), $this->uid($request), self::id($id)), 'Cancelled.');
    }

    public function destroy(Request $request, string $id)
    {
        self::can($request, 'social.manage');
        $r = $this->social->delete($this->tenant->idOrFail(), $this->uid($request), self::id($id));
        if ($r !== 'ok') {
            return $this->done($request, $id, $r, '');
        }

        return redirect()->route('social.index')->with('status', 'Deleted.');
    }

    public function caption(Request $request)
    {
        self::can($request, 'social.manage');
        $d = $request->validate(['rid' => ['required', 'regex:/\A[0-9A-Za-z]{26}\z/'], 'brief' => ['required', 'string', 'max:1500'], 'platform' => ['required', 'string', 'max:20'], 'tone' => ['required', 'string', 'max:20']]);
        $r = $this->captions->write($this->tenant->idOrFail(), $this->uid($request), $d['brief'], $d['platform'], $d['tone'], $d['rid']);
        if ($r['status'] !== 'ok') {
            return response()->json(['ok' => false, 'message' => SocialText::caption($r['status'])], 422);
        }

        return response()->json(['ok' => true, 'caption' => $r['result']['caption'], 'hashtags' => $r['result']['hashtags']]);
    }

    /** The post's picture or video, drawn for any colleague who may see the post (the media itself stays the author's own). */
    public function media(Request $request, string $id)
    {
        self::can($request, 'social.view');
        $p = $this->post($id);
        abort_if($p['media_id'] === null, 404);
        $tenant = $this->tenant->idOrFail();
        if ($p['media_kind'] === 'video') {
            $j = $this->videos->find($p['user_id'], $p['media_id']);
            abort_if($j === null || $j['status'] !== 'done' || $j['file_path'] === null, 404);
            $path = $this->files->path($tenant, $j['file_path']);
            abort_if($path === null, 404);

            return response()->file($path, ['Content-Type' => 'video/mp4', 'Content-Disposition' => ($request->query('dl') === '1' ? 'attachment' : 'inline').'; filename="video-'.substr($j['id'], 0, 8).'.mp4"', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
        }
        $design = $this->designs->find($p['user_id'], $p['media_id']);
        $size = (string) $p['media_size'];
        abort_if($design === null || ! in_array($size, $design['sizes'], true), 404);
        $hit = Cache::remember("social:{$tenant}:{$design['id']}:{$size}", 3600, function () use ($tenant, $p, $design, $size) {
            $r = $this->studio->render($tenant, $p['user_id'], $design, [$size], 'png');

            return isset($r['items'][$size]) ? ['mime' => $r['items'][$size]['mime'], 'b64' => base64_encode($r['items'][$size]['bytes'])] : null;
        });
        if ($hit === null) {
            Cache::forget("social:{$tenant}:{$design['id']}:{$size}");
            abort(502);
        }
        $dl = $request->query('dl') === '1';

        return response((string) base64_decode($hit['b64'], true), 200, ['Content-Type' => $hit['mime'], 'Content-Disposition' => ($dl ? 'attachment' : 'inline').'; filename="post-'.$size.'.png"', 'Cache-Control' => 'private, max-age=600', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function accounts(Request $request)
    {
        self::can($request, 'social.view');

        return view('social.accounts', ['accounts' => $this->repo->accounts(), 'can_connect' => $request->user()->can('social.connect'), 'yt_ready' => (string) config('social.youtube.client_id') !== '' && (string) config('social.youtube.client_secret') !== '']);
    }

    public function connect(Request $request)
    {
        self::can($request, 'social.connect');
        $d = $request->validate(['page_id' => ['required', 'string', 'max:40'], 'token' => ['required', 'string', 'max:700']]);
        $msg = null;
        $r = $this->pages->connect($this->tenant->idOrFail(), $d['page_id'], $d['token'], $msg);
        if ($r !== 'ok') {
            $text = $r === 'limit' ? 'At most 5 pages can be added.' : ($r === 'failed' ? SocialText::message('failed') : SocialText::message($r).($msg ? ' '.$msg : ''));

            return back()->withErrors(['social' => $text])->withInput($request->only('page_id'));
        }

        return redirect()->route('social.accounts')->with('status', 'The page was connected. The token is now sealed — it can no longer be seen.');
    }

    public function disconnect(Request $request, string $id)
    {
        self::can($request, 'social.connect');
        $r = $this->pages->disconnect($this->tenant->idOrFail(), self::id($id));

        return redirect()->route('social.accounts')->with($r === 'ok' ? 'status' : 'error', $r === 'ok' ? 'The page was removed.' : SocialText::message($r));
    }

    public function insights(Request $request)
    {
        self::can($request, 'social.view');
        $days = in_array((int) $request->query('days'), [7, 30, 90], true) ? (int) $request->query('days') : 30;
        $rows = $this->repo->posted($days);
        $sum = fn (string $k) => array_sum(array_map(fn ($r) => (int) ($r[$k] ?? 0), $rows));
        $ranked = $rows;
        usort($ranked, fn ($a, $b) => ((int) $b['reactions'] + (int) $b['comments'] + (int) $b['shares']) <=> ((int) $a['reactions'] + (int) $a['comments'] + (int) $a['shares']));

        return view('social.insights', ['rows' => $rows, 'top' => array_slice(array_filter($ranked, fn ($r) => $r['reactions'] !== null), 0, 5), 'days' => $days, 'platforms' => SocialText::PLATFORMS,
            'totals' => ['posts' => count($rows), 'reactions' => $sum('reactions'), 'comments' => $sum('comments'), 'shares' => $sum('shares'), 'reach' => $sum('reach')], 'tz' => $this->social->zone()]);
    }

    private static function growthDays(Request $request, mixed $v): int
    {
        return in_array((int) $v, [7, 30, 90], true) ? (int) $v : 30;
    }

    private function growthView(int $days, array $extra = [])
    {
        return view('social.growth', $extra + ['days' => $days, 'a' => $this->growth->numbers($days), 'labels' => SocialText::GROUP_LABELS, 'rid' => CreateWorkspace::newId(), 'advice' => null, 'goal' => '', 'can_manage' => request()->user()->can('social.manage')]);
    }

    public function growthPage(Request $request)
    {
        self::can($request, 'social.view');

        return $this->growthView(self::growthDays($request, $request->query('days')));
    }

    public function growthAdvice(Request $request)
    {
        self::can($request, 'social.manage');
        $d = $request->validate(['rid' => ['required', 'regex:/\A[0-9A-Za-z]{26}\z/'], 'days' => ['required', 'integer'], 'goal' => ['nullable', 'string', 'max:500']]);
        $days = self::growthDays($request, $d['days']);
        $goal = (string) ($d['goal'] ?? '');
        $r = $this->growth->advise($this->tenant->idOrFail(), $this->uid($request), $days, $goal, $d['rid']);
        if ($r['status'] !== 'ok') {
            return $this->growthView($days, ['goal' => $goal])->withErrors(['social' => SocialText::growth($r['status'])]);
        }

        return $this->growthView($days, ['advice' => $r['advice'], 'goal' => $goal])->with('status', 'Advice made — this is only advice; nothing was changed or posted.');
    }

    private function planView(array $extra = [])
    {
        return view('social.plan', $extra + ['platforms' => SocialText::PLATFORMS, 'tones' => SocialText::TONES, 'rid' => CreateWorkspace::newId(), 'tz' => $this->social->zone()->getName(), 'proposals' => null]);
    }

    public function plan(Request $request)
    {
        self::can($request, 'social.manage');

        return $this->planView();
    }

    public function generate(Request $request)
    {
        self::can($request, 'social.manage');
        $d = $request->validate(['rid' => ['required', 'regex:/\A[0-9A-Za-z]{26}\z/'], 'brief' => ['required', 'string', 'max:1500'], 'days' => ['required', 'integer', 'min:1', 'max:14'], 'count' => ['required', 'integer', 'min:1', 'max:14'],
            'platforms' => ['required', 'array', 'min:1', 'max:6'], 'platforms.*' => ['required', 'in:'.implode(',', array_keys(SocialText::PLATFORMS))], 'tone' => ['required', 'in:'.implode(',', array_keys(SocialText::TONES))]]);
        $r = $this->planner->plan($this->tenant->idOrFail(), $this->uid($request), $d['brief'], (int) $d['days'], (int) $d['count'], array_values($d['platforms']), $d['tone'], $d['rid']);
        if ($r['status'] !== 'ok') {
            return back()->withErrors(['social' => SocialText::plan($r['status'])])->withInput();
        }

        $request->flash();   // keep the brief in the form below the proposals

        return $this->planView(['proposals' => $r['posts']])->with('status', 'Suggestions made — pick the ones you keep "add to drafts" press. Nothing has been saved or posted yet.');
    }

    public function accept(Request $request)
    {
        self::can($request, 'social.manage');
        $d = $request->validate(['items' => ['required', 'array', 'min:1', 'max:14'], 'items.*.pick' => ['nullable', 'boolean'], 'items.*.title' => ['required', 'string', 'max:120'], 'items.*.platform' => ['required', 'string', 'max:20'],
            'items.*.caption' => ['required', 'string', 'max:3000'], 'items.*.scheduled' => ['nullable', 'string', 'max:20'], 'items.*.idea' => ['nullable', 'string', 'max:500']]);
        $picked = array_values(array_filter($d['items'], fn ($i) => ! empty($i['pick'])));
        if ($picked === []) {
            return redirect()->route('social.plan')->withErrors(['social' => 'No suggestion was picked.']);
        }
        $r = $this->planner->addDrafts($this->tenant->idOrFail(), $this->uid($request), $picked);

        return redirect()->route('social.index')->with('status', $r['added'].' drafts were added'.($r['failed'] > 0 ? " ({$r['failed']} could not be added — data was not valid)" : '').'. Pick an image/video and send for approval.');
    }
}
