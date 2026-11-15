<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Tenancy\CreateWorkspace;
use App\Outreach\Pkce;
use App\Social\SocialRepo;
use App\Social\SocialService;
use App\Social\SocialText;
use App\Social\YoutubeAccounts;
use App\Social\YoutubeApi;
use App\Social\YoutubeMeta;
use App\Social\YoutubeSeoService;
use App\Studio\DesignRepo;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;

/** YouTube: connect a channel (Google OAuth), per-post YouTube details, AI title / description / tags suggestions.  Nothing is uploaded here — the approved post is uploaded by the worker. */
final class SocialYoutubeController extends Controller
{
    private const ERRORS = [
        'failed' => 'The connection was not completed. Try again.', 'no_refresh' => 'Google Did not grant permanent permission — restart the connection and keep all permissions ticked.', 'scope' => 'The video upload permission was not ticked — connect again and grant all permissions.',
        'no_channel' => 'No YouTube channel was found in this Google account.', 'limit' => 'At most 5 pages/channels can be added.',
    ];

    private const SEO_MESSAGES = [
        'empty' => 'Write what the video is about.', 'brief_long' => 'The description is at most 1500 characters.', 'bad_tone' => 'The tone is not valid.', 'no_model' => 'No chat model is enabled for writing.', 'no_credits' => 'Credits ran out.',
        'provider' => 'AI The service is not responding right now. Try again shortly.', 'unreadable' => 'AI-The reply could not be read. Try again (credits were deducted).', 'failed' => 'Could not be done. Try again.',
    ];

    public function __construct(private readonly SocialRepo $repo, private readonly SocialService $social, private readonly YoutubeApi $api, private readonly YoutubeAccounts $accounts,
        private readonly YoutubeSeoService $seo, private readonly DesignRepo $designs, private readonly TenantContext $tenant) {}

    private static function id(string $v): string
    {
        abort_unless(preg_match('/\A[0-9a-z]{26}\z/', $v) === 1, 404);

        return $v;
    }

    private static function can(Request $r, string $p): void
    {
        abort_unless($r->user()->can($p), 403);
    }

    private static function redirectUri(): string
    {
        return rtrim((string) config('app.url'), '/').route('social.youtube.callback', [], false);
    }

    public function start(Request $request)
    {
        self::can($request, 'social.connect');
        if (! $this->api->configured()) {
            return redirect()->route('social.accounts')->with('error', 'The YouTube connection key is not set on this server.');
        }
        $state = bin2hex(random_bytes(24));
        $verifier = Pkce::verifier();
        $request->session()->put('social_youtube_oauth', ['state' => $state, 'verifier' => $verifier, 'tenant' => $this->tenant->idOrFail(), 'user' => (string) $request->user()->getAuthIdentifier(), 'expires' => time() + 600]);

        return redirect()->away($this->api->authUrl(self::redirectUri(), $state, Pkce::challenge($verifier)));
    }

    public function callback(Request $request)
    {
        self::can($request, 'social.connect');
        $saved = $request->session()->pull('social_youtube_oauth');                   // one use only, whatever happens next
        $back = redirect()->route('social.accounts');
        if (! is_array($saved) || $saved['expires'] < time() || $saved['tenant'] !== $this->tenant->idOrFail() || $saved['user'] !== (string) $request->user()->getAuthIdentifier()
            || ! is_string($request->query('state')) || ! hash_equals($saved['state'], $request->query('state'))) {
            return $back->with('error', 'The connection request has expired or is invalid. Press the button and start again.');
        }
        if ($request->query('error') !== null || ! is_string($request->query('code')) || $request->query('code') === '') {
            return $back->with('error', 'The connection was cancelled or permission was not granted.');
        }
        $r = $this->accounts->connect($this->tenant->idOrFail(), (string) $request->query('code'), $saved['verifier'], self::redirectUri());

        return $r === 'ok' ? $back->with('status', 'YouTube The channel was connected. The token is sealed — it can no longer be seen.') : $back->with('error', self::ERRORS[$r] ?? self::ERRORS['failed']);
    }

    /** @return array<string, mixed> */
    private function data(Request $request, string $id): array
    {
        $p = $this->repo->find(self::id($id));
        abort_if($p === null || $p['platform'] !== 'youtube', 404);
        $uid = (string) $request->user()->id;

        return ['p' => $p, 'channels' => array_values(array_filter($this->repo->accounts(), fn ($a) => $a['platform'] === 'youtube' && $a['status'] === 'active')),
            'designs' => array_map(fn ($d) => ['id' => $d['id'], 'title' => $d['title'], 'sizes' => $d['sizes']], $this->designs->list($uid)), 'privacy' => YoutubeMeta::PRIVACY, 'tones' => SocialText::TONES, 'rid' => CreateWorkspace::newId(),
            'editable' => $p['user_id'] === $uid && in_array($p['status'], ['draft', 'pending'], true) && $request->user()->can('social.manage'), 'cap' => (int) config('social.youtube_daily_uploads', 5)];
    }

    public function settings(Request $request, string $id)
    {
        self::can($request, 'social.view');

        return view('social.youtube', $this->data($request, $id));
    }

    public function save(Request $request, string $id)
    {
        self::can($request, 'social.manage');
        $p = $this->repo->find(self::id($id));
        abort_if($p === null || $p['platform'] !== 'youtube', 404);
        $d = $request->validate(['tags' => ['nullable', 'string', 'max:1500'], 'privacy' => ['required', 'in:private,unlisted,public'], 'thumb' => ['nullable', 'string', 'max:40'], 'account_id' => ['nullable', 'string', 'max:30']]);
        $thumbId = null;
        $thumbSize = null;
        if (($d['thumb'] ?? '') !== '') {
            [$thumbId, $thumbSize] = array_pad(explode('|', (string) $d['thumb'], 2), 2, null);
            if (preg_match('/\A[0-9a-z]{26}\z/', (string) $thumbId) !== 1 || ! in_array($thumbSize, ['post', 'portrait', 'story', 'cover', 'ad'], true)) {
                return back()->with('error', 'The thumbnail is not valid.');
            }
        }
        $tags = YoutubeMeta::tags((string) ($d['tags'] ?? ''));
        $uid = (string) $request->user()->id;
        $r = $this->repo->setYoutube($this->tenant->idOrFail(), $uid, $id, $tags === [] ? null : implode(', ', $tags), $d['privacy'], $thumbId, $thumbSize);
        if ($r !== 'ok') {
            return back()->with('error', $r === 'bad_media' ? 'The thumbnail design/size is not yours or does not exist.' : 'Could not be kept (only the author can change their own draft/pending posts).');
        }
        $acc = $d['account_id'] ?? null;
        if ($acc !== null && $acc !== '' || $p['account_id'] !== null) {
            $a = $this->social->attach($this->tenant->idOrFail(), $uid, $id, $acc === '' ? null : $acc);
            if ($a !== 'ok') {
                return back()->with('error', 'The channel could not be chosen: '.SocialText::message($a));
            }
        }

        return redirect()->route('social.youtube', $id)->with('status', 'YouTube Settings were kept. Nothing has been uploaded yet — it will go at the right time after approval.');
    }

    public function suggest(Request $request)
    {
        self::can($request, 'social.manage');
        $d = $request->validate(['rid' => ['required', 'regex:/\A[0-9A-Za-z]{26}\z/'], 'brief' => ['required', 'string', 'max:1500'], 'title' => ['nullable', 'string', 'max:120'], 'tone' => ['required', 'string', 'max:20']]);
        $r = $this->seo->suggest($this->tenant->idOrFail(), (string) $request->user()->id, $d['brief'], (string) ($d['title'] ?? ''), $d['tone'], $d['rid']);
        if ($r['status'] !== 'ok') {
            return response()->json(['ok' => false, 'message' => self::SEO_MESSAGES[$r['status']] ?? self::SEO_MESSAGES['failed']], 422);
        }

        return response()->json(['ok' => true] + $r['result']);
    }
}
