<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Inbox\Channels\ChannelDispatcher;
use App\Inbox\Channels\ChannelException;
use App\Inbox\Channels\ChannelSecrets;
use App\Inbox\Channels\MetaAdapter;
use App\Inbox\Channels\TelegramBotAdapter;
use App\Inbox\Channels\WebhookVerifier;
use App\Inbox\InboxRepo;
use App\Inbox\InboxText;
use App\Outreach\HttpJson;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;

/** Staff side of the unified inbox. Every change goes through the database functions (24h window, emergency stop, hand-off live there). */
final class InboxController extends Controller
{
    private const FILTERS = ['open', 'unread', 'human', 'mine', 'resolved'];

    public function __construct(private readonly InboxRepo $repo, private readonly TenantContext $tenant, private readonly ChannelDispatcher $dispatcher, private readonly \App\Inbox\Agent\AgentRepo $agent) {}

    private static function id(string $id): string
    {
        abort_unless(preg_match('/\A[0-9a-z]{26}\z/', $id) === 1, 404);

        return $id;
    }

    private function need(Request $r, string $permission): void
    {
        abort_unless($r->user()->can($permission), 403);
    }

    /** @return array<string, mixed> a conversation of THIS workspace, or 404 (another workspace's rows are invisible) */
    private function mine(string $id): array
    {
        return $this->repo->conversation(self::id($id)) ?? abort(404);
    }

    public function index(Request $request)
    {
        $this->need($request, 'inbox.view');
        $filter = in_array($request->query('f'), self::FILTERS, true) ? (string) $request->query('f') : 'open';

        return view('inbox.index', ['rows' => $this->repo->conversations($filter, (string) $request->user()->id), 'filter' => $filter, 'filters' => self::FILTERS, 'canReply' => $request->user()->can('inbox.reply')]);
    }

    public function show(Request $request, string $id)
    {
        $this->need($request, 'inbox.view');
        $c = $this->mine($id);
        if ($c['unread'] > 0 && $request->user()->can('inbox.reply')) {
            $this->repo->markRead($c['id']);
        }
        $windowOpen = ! in_array($c['kind'], ['messenger', 'instagram', 'whatsapp'], true)
            || ($c['last_inbound_at'] !== null && strtotime((string) $c['last_inbound_at']) > time() - 86400);

        return view('inbox.show', ['c' => $c, 'messages' => $this->repo->messages($c['id'], 0, 200), 'windowOpen' => $windowOpen, 'canReply' => $request->user()->can('inbox.reply'), 'kindBn' => InboxText::kindBn($c['kind']), 'draft' => $c['mode'] === 'bot' ? $this->agent->draft($c['id']) : null]);
    }

    public function reply(Request $request, string $id)
    {
        $this->need($request, 'inbox.reply');
        $d = $request->validate(['body' => ['required', 'string', 'max:4000']]);
        $r = $this->repo->send($this->mine($id)['id'], (string) $request->user()->id, 'staff', $d['body']);
        $err = InboxText::sendError($r['result']);
        if ($err === null && $r['result'] === 'queued' && $r['seq'] !== null) {
            try {
                $this->dispatcher->dispatch($r['seq']);          // try right away; the scheduler retries if this fails
            } catch (\Throwable $e) {
                report($e);
            }
        }

        if ($err === null) {
            $this->agent->deleteDraft($this->mine($id)['id']);   // a person answered; the AI suggestion is stale
        }

        return $err === null ? back() : back()->withErrors(['body' => $err])->withInput();
    }

    public function mode(Request $request, string $id)
    {
        $this->need($request, 'inbox.reply');
        $d = $request->validate(['mode' => ['required', 'in:bot,human'], 'reason' => ['nullable', 'string', 'max:200']]);
        $this->repo->setMode($this->mine($id)['id'], (string) $request->user()->id, $d['mode'], $d['reason'] ?? null);

        return back()->with('status', $d['mode'] === 'human' ? 'The chat is with you — the AI will stay quiet.' : 'The chat was handed back to the AI.');
    }

    public function resolve(Request $request, string $id)
    {
        $this->need($request, 'inbox.reply');
        $d = $request->validate(['resolved' => ['required', 'in:0,1']]);
        $this->repo->resolve($this->mine($id)['id'], (string) $request->user()->id, $d['resolved'] === '1');

        return redirect()->route('inbox.index');
    }

    public function messages(Request $request, string $id)
    {
        $this->need($request, 'inbox.view');
        $cid = $this->mine($id)['id'];

        return response()->json(['messages' => $this->repo->messages($cid, max(0, (int) $request->query('after', 0)), 100)]);
    }

    public function channels(Request $request)
    {
        $this->need($request, 'inbox.view');

        return view('inbox.channels', ['base' => rtrim((string) config('app.url'), '/'), 'verify' => fn (string $k) => WebhookVerifier::verifyToken($k), 'channels' => $this->repo->channels(), 'canManage' => $request->user()->can('inbox.manage'), 'kindBn' => fn (string $k) => InboxText::kindBn($k)]);
    }

    public function channelStore(Request $request)
    {
        $this->need($request, 'inbox.manage');
        $d = $request->validate(['name' => ['required', 'string', 'max:120'], 'origins' => ['required', 'string', 'max:2000'], 'greeting' => ['nullable', 'string', 'max:300']]);
        [$origins, $err] = InboxText::origins($d['origins']);
        if ($err !== null || $origins === []) {
            return back()->withErrors(['origins' => $err ?? 'Give at least one website address.'])->withInput();
        }
        $this->repo->createChannel($this->tenant->idOrFail(), 'web_widget', trim($d['name']), $origins, isset($d['greeting']) ? trim($d['greeting']) ?: null : null);

        return back()->with('status', 'The channel was created — place the code below on your site.');
    }

    public function channelUpdate(Request $request, string $id)
    {
        $this->need($request, 'inbox.manage');
        $id = self::id($id);
        $ch = null;
        foreach ($this->repo->channels() as $x) {
            $ch = $x['id'] === $id ? $x : $ch;
        }
        abort_if($ch === null, 404);
        $d = $request->validate(['name' => ['required', 'string', 'max:120'], 'status' => ['required', 'in:active,paused'], 'origins' => ['nullable', 'string', 'max:2000'], 'greeting' => ['nullable', 'string', 'max:300']]);
        $origins = [];
        if ($ch['kind'] === 'web_widget') {
            [$origins, $err] = InboxText::origins((string) ($d['origins'] ?? ''));
            if ($err !== null || $origins === []) {
                return back()->withErrors(['origins' => $err ?? 'Give at least one website address.']);
            }
        }
        $this->repo->updateChannel($id, trim($d['name']), $d['status'], $origins, isset($d['greeting']) ? trim($d['greeting']) ?: null : null);

        return back()->with('status', 'Saved.');
    }

    /** Messenger / Instagram / WhatsApp / Telegram. The secret is checked with one real call first and stored encrypted; it is never shown again. */
    public function channelStoreExternal(Request $request, ChannelSecrets $secrets, HttpJson $http)
    {
        $this->need($request, 'inbox.manage');
        $d = $request->validate([
            'kind' => ['required', 'in:messenger,instagram,whatsapp,telegram'], 'name' => ['required', 'string', 'max:120'],
            'account' => ['nullable', 'string', 'max:60', 'regex:/\A[0-9]{5,25}\z/'], 'access_token' => ['nullable', 'string', 'max:600', 'regex:/\A[\w\-.]+\z/'],
            'app_secret' => ['nullable', 'string', 'max:100', 'regex:/\A[\w\-.]+\z/'], 'bot_token' => ['nullable', 'string', 'max:120', 'regex:/\A[0-9]{3,15}:[\w-]{20,80}\z/'],
        ]);
        $key = bin2hex(random_bytes(16));
        try {
            if ($d['kind'] === 'telegram') {
                $bot = new TelegramBotAdapter($http);
                $token = (string) ($d['bot_token'] ?? '');
                $me = $bot->call($token, 'getMe');
                $ref = (string) ($me['id'] ?? '');
                $secret = bin2hex(random_bytes(16));
                $bot->call($token, 'setWebhook', ['url' => rtrim((string) config('app.url'), '/').'/webhooks/inbox/'.$key, 'secret_token' => $secret, 'allowed_updates' => ['message']]);
                $sealed = $secrets->seal(['bot_token' => $token, 'webhook_secret' => $secret]);
            } else {
                foreach (['account', 'access_token', 'app_secret'] as $f) {
                    if (($d[$f] ?? '') === '') {
                        return back()->withErrors(['account' => 'Fill in all fields (account ID, access token, app secret).'])->withInput($request->except(['access_token', 'app_secret', 'bot_token']));
                    }
                }
                $ref = $d['account'];
                (new MetaAdapter($http, $d['kind'], (string) config('inbox.graph_version', 'v21.0')))->ping(['access_token' => $d['access_token']], $ref);
                $sealed = $secrets->seal(['access_token' => $d['access_token'], 'app_secret' => $d['app_secret']]);
            }
        } catch (ChannelException $e) {
            return back()->withErrors(['account' => $d['kind'] === 'telegram' ? 'The bot token could not be verified or the webhook could not be set — check that the token is correct.' : 'The platform did not accept the token — check that the ID and token are correct.'])->withInput($request->except(['access_token', 'app_secret', 'bot_token']));
        }
        try {
            $this->repo->createExternalChannel($this->tenant->idOrFail(), $d['kind'], trim($d['name']), $ref, $sealed, $key);
        } catch (QueryException) {
            return back()->withErrors(['account' => 'This account is already linked to a workspace.'])->withInput($request->except(['access_token', 'app_secret', 'bot_token']));
        }

        return back()->with('status', $d['kind'] === 'telegram' ? 'The bot was added — the webhook was set automatically.' : 'The channel was added — set the webhook address and verify token below in Meta.');
    }
}
