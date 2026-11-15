<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Pc;

use App\Actions\Tenancy\CreateWorkspace;
use App\Agents\AgentRepo;
use App\Agents\AgentService;
use App\Ai\ModelCatalog;
use App\Chat\ChatRequest;
use App\Chat\ChatService;
use App\Chat\ChatStore;
use App\Chat\ReplyStyle;
use App\Companion\ModeRepo;
use App\Credits\CreditService;
use App\Http\Controllers\Controller;
use App\Memory\MemoryGuard;
use App\Memory\MemoryRepo;
use App\Pc\PcPlanner;
use App\Pc\PcPlanRepo;
use App\Pc\PcRepo;
use App\Pc\PcService;
use App\Tenancy\TenantContext;
use App\Voice\VoiceService;
use App\Workflow\ToolRegistry;
use App\Workflow\WorkflowRepo;
use App\Workflow\WorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * What the CAN AI Desktop window asks for (docs/DESKTOP_API.md), authenticated by the DEVICE TOKEN (PcAgentAuth).
 *
 * Every call runs as the one person who owns that device, inside their workspace, through the same services (credits, limits, RLS) as the website.
 * Nothing here approves a PC action, a plan or a workflow step: the window has no approve button and this API has no approve route.
 * A revoked device stops working at once (the token hash is removed).  `plan` only drafts.
 */
final class DesktopController extends Controller
{
    public function __construct(private readonly PcRepo $pc, private readonly PcPlanRepo $plans) {}

    private static function tenant(Request $r): string
    {
        return (string) $r->attributes->get('pc_tenant');
    }

    private static function user(Request $r): string
    {
        $u = (string) $r->attributes->get('pc_user');
        abort_unless(preg_match('/\A[0-9a-z]{26}\z/', $u) === 1, 403);

        return $u;
    }

    private static function id(string $id): string
    {
        abort_unless(preg_match('/\A[0-9a-z]{26}\z/', $id) === 1, 404);

        return $id;
    }

    private static function err(string $message, int $status = 422): JsonResponse
    {
        return response()->json(['error' => $message], $status);
    }

    public function bootstrap(Request $r, ModelCatalog $catalog, CreditService $credits, WorkflowRepo $wf): JsonResponse
    {
        $t = self::tenant($r);
        $u = self::user($r);
        $w = $credits->wallet($t);
        $name = DB::table('users')->where('id', $u)->value('name');

        return response()->json([
            'user' => ['name' => (string) ($name ?? '')],
            'models' => array_map(fn ($m) => ['id' => (string) $m['id'], 'name' => (string) $m['name']], array_slice($catalog->candidates($t), 0, 40)),
            'credits' => $w === null ? 0 : (int) floor((float) $w->available),
            'pending_approvals' => count($this->approvalItems($t, $u, $wf)),
        ]);
    }

    public function tools(ToolRegistry $tools): JsonResponse
    {
        $out = [];
        foreach ($tools->all() as $t) {
            $out[] = ['key' => $t->key(), 'label' => $t->label(), 'risk' => $t->risk()];
        }

        return response()->json(['tools' => $out]);
    }

    /** @return list<array{id: string, title: string, kind: string, risk: string}> read-only: the window can show them, never decide them */
    private function approvalItems(string $t, string $u, WorkflowRepo $wf): array
    {
        $out = [];
        foreach ($this->pc->actions($t, $u, 30) as $a) {
            if (($a['status'] ?? '') === 'pending') {
                $out[] = ['id' => (string) $a['id'], 'title' => (string) ($a['kind'] ?? ''), 'kind' => 'pc_action', 'risk' => (string) ($a['risk'] ?? 'high')];
            }
        }
        foreach ($this->plans->recent($t, $u, 20) as $p) {
            if (($p['status'] ?? '') === 'pending') {
                $out[] = ['id' => (string) $p['id'], 'title' => mb_substr((string) ($p['goal'] ?? ''), 0, 120), 'kind' => 'pc_plan', 'risk' => 'high'];
            }
        }
        foreach ($wf->pendingApprovals($u) as $a) {
            $out[] = ['id' => (string) $a['run_id'], 'title' => (string) $a['workflow_name'], 'kind' => 'workflow', 'risk' => 'medium'];
        }

        return array_slice($out, 0, 50);
    }

    public function approvals(Request $r, WorkflowRepo $wf): JsonResponse
    {
        return response()->json(['items' => $this->approvalItems(self::tenant($r), self::user($r), $wf)]);
    }

    // ---------------------------------------------------------------- chat
    public function conversations(Request $r): JsonResponse
    {
        $q = DB::table('conversations')->where('user_id', self::user($r))->whereNull('deleted_at');
        $needle = trim((string) $r->query('q', ''));
        if ($needle !== '') {
            $q->where('title', 'ilike', '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr($needle, 0, 80)).'%');
        }

        return response()->json(['items' => $q->orderByDesc('last_message_at')->limit(50)->get(['id', 'title'])->map(fn ($c) => ['id' => trim((string) $c->id), 'title' => (string) $c->title])->all()]);
    }

    public function messages(Request $r, string $id): JsonResponse
    {
        self::id($id);
        $c = DB::table('conversations')->where('id', $id)->where('user_id', self::user($r))->whereNull('deleted_at')->first(['id']);
        abort_if($c === null, 404);
        $m = DB::table('messages')->where('conversation_id', $id)->whereNull('hidden_at')->orderBy('seq')->limit(200)->get(['role', 'content']);

        return response()->json(['messages' => $m->map(fn ($x) => ['role' => (string) $x->role, 'text' => (string) $x->content])->all()]);
    }

    public function chat(Request $r, ChatService $service, ChatStore $store): JsonResponse
    {
        $d = $r->validate(['message' => ['required', 'string', 'min:1', 'max:20000'], 'conversation_id' => ['nullable', 'string', 'regex:/\A[0-9a-z]{26}\z/'],
            'model_id' => ['nullable', 'string', 'regex:/\A[0-9a-z]{26}\z/'], 'persona' => ['nullable', 'string', 'max:20'], 'source' => ['nullable', 'in:text,voice']]);
        ignore_user_abort(true);
        set_time_limit(150);
        $t = self::tenant($r);
        $u = self::user($r);
        $model = $d['model_id'] ?? null;
        if (($d['conversation_id'] ?? null) !== null) {
            $conv = $store->conversation($t, $u, $d['conversation_id']);
            if ($conv === null) {
                return self::err('conversation_not_found', 404);
            }
            $model = $conv['model_id'];                                  // an existing conversation keeps its own model
        }
        $out = ['conversation_id' => $d['conversation_id'] ?? null, 'reply' => '', 'error' => null];
        foreach ($service->send(new ChatRequest($t, $u, $d['conversation_id'] ?? null, trim($d['message']), [], $model, true, 'general', null, ReplyStyle::personaOrNull($d['persona'] ?? null), ReplyStyle::sourceOrNull($d['source'] ?? null))) as $ev) {
            switch ($ev['type']) {
                case 'meta': $out['conversation_id'] = $ev['conversation_id']; break;
                case 'delta': $out['reply'] .= $ev['text']; break;
                case 'error': $out['error'] = (string) ($ev['message'] ?? $ev['code'] ?? 'failed'); break;
            }
        }
        if ($out['error'] !== null && $out['reply'] === '') {
            return self::err($out['error']);
        }

        return response()->json(['conversation_id' => $out['conversation_id'], 'reply' => $out['reply']]);
    }

    /**
     * Same answer as `chat`, but sent as it is written (server-sent events: meta, delta, done | error).  The money rules are the website's: ChatService reserves credits once,
     * and settles or releases them exactly once whether the answer finishes, fails or the window presses Stop (the connection closes -> the engine stops -> partial text is saved and
     * only that part is charged).  The window never repeats a request that already produced text.
     */
    public function chatStream(Request $r, ChatService $service, ChatStore $store, TenantContext $tenant): StreamedResponse|JsonResponse
    {
        $d = $r->validate(['message' => ['required', 'string', 'min:1', 'max:20000'], 'conversation_id' => ['nullable', 'string', 'regex:/\A[0-9a-z]{26}\z/'],
            'model_id' => ['nullable', 'string', 'regex:/\A[0-9a-z]{26}\z/'], 'persona' => ['nullable', 'string', 'max:20'], 'source' => ['nullable', 'in:text,voice']]);
        $t = self::tenant($r);
        $u = self::user($r);
        $model = $d['model_id'] ?? null;
        if (($d['conversation_id'] ?? null) !== null) {
            $conv = $store->conversation($t, $u, $d['conversation_id']);
            if ($conv === null) {
                return self::err('conversation_not_found', 404);
            }
            $model = $conv['model_id'];
        }
        $chat = new ChatRequest($t, $u, $d['conversation_id'] ?? null, trim($d['message']), [], $model, true, 'general', null, ReplyStyle::personaOrNull($d['persona'] ?? null), ReplyStyle::sourceOrNull($d['source'] ?? null));

        return response()->stream(function () use ($tenant, $service, $chat): void {
            ignore_user_abort(true);                                       // keep going to the end of the bookkeeping even if the window disappears
            set_time_limit(0);
            while (ob_get_level() > 0) {
                ob_end_flush();
            }
            $tenant->run($chat->tenantId, function () use ($service, $chat): void {
                foreach ($service->send($chat, fn (): bool => connection_aborted() === 1) as $event) {
                    $type = $event['type'];
                    unset($event['type']);
                    echo "event: {$type}\ndata: ".json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR)."\n\n";
                    flush();
                }
            });
        }, 200, ['Content-Type' => 'text/event-stream; charset=utf-8', 'Cache-Control' => 'no-cache, no-transform', 'X-Accel-Buffering' => 'no', 'Connection' => 'keep-alive']);
    }

    // ---------------------------------------------------------------- agents
    public function agents(Request $r, AgentRepo $agents): JsonResponse
    {
        return response()->json(['items' => array_map(fn ($a) => ['id' => (string) $a['id'], 'name' => (string) $a['name'], 'description' => mb_substr((string) ($a['description'] ?? ''), 0, 200),
            'status' => ($a['enabled'] ?? false) ? 'active' : 'disabled'], array_slice($agents->agents(self::user($r)), 0, 100))]);
    }

    public function runAgent(Request $r, string $id, AgentService $agents): JsonResponse
    {
        $d = $r->validate(['input' => ['required', 'string', 'min:1', 'max:4000']]);
        $res = $agents->test(self::tenant($r), self::user($r), self::id($id), $d['input'], CreateWorkspace::newId());
        if ($res['status'] !== 'ok') {
            return self::err(AgentService::MESSAGES[$res['status']] ?? 'The agent could not run.');
        }

        return response()->json(['status' => 'ok', 'output' => $res['text']]);
    }

    // ---------------------------------------------------------------- workflows
    public function workflows(Request $r, WorkflowRepo $wf): JsonResponse
    {
        return response()->json(['items' => array_map(fn ($w) => ['id' => (string) $w['id'], 'name' => (string) $w['name'],
            'status' => ! ($w['enabled'] ?? false) ? 'disabled' : (($w['paused'] ?? false) ? 'paused' : 'active')], array_slice($wf->workflows(self::user($r)), 0, 100))]);
    }

    public function runs(Request $r, WorkflowRepo $wf): JsonResponse
    {
        return response()->json(['items' => array_map(fn ($x) => ['id' => (string) $x['id'], 'name' => (string) $x['workflow_name'], 'status' => (string) $x['status'], 'started' => (string) $x['created_at']], $wf->runs(self::user($r), 30))]);
    }

    public function runWorkflow(Request $r, string $id, WorkflowService $wf): JsonResponse
    {
        $d = $r->validate(['inputs' => ['nullable', 'array', 'max:20']]);
        $res = $wf->start(self::tenant($r), self::user($r), self::id($id), (array) ($d['inputs'] ?? []));
        if ($res['status'] !== 'ok') {
            return self::err(WorkflowService::MESSAGES[$res['status']] ?? 'The workflow could not start.');
        }

        return response()->json(['run_id' => $res['run_id']]);
    }

    public function cancelRun(Request $r, string $id, WorkflowService $wf): JsonResponse
    {
        $res = $wf->cancel(self::tenant($r), self::user($r), self::id($id));

        return $res === 'ok' ? response()->json(['ok' => true]) : self::err(WorkflowService::MESSAGES[$res] ?? 'Could not cancel.');
    }

    // ---------------------------------------------------------------- memory
    public function memory(Request $r, MemoryRepo $memory): JsonResponse
    {
        $needle = mb_strtolower(trim((string) $r->query('q', '')));
        $items = [];
        foreach ($memory->list(self::user($r)) as $m) {
            if ($m['active'] && ($needle === '' || str_contains(mb_strtolower($m['content']), $needle))) {
                $items[] = ['id' => $m['id'], 'text' => $m['content']];
            }
        }

        return response()->json(['items' => array_slice($items, 0, 100)]);
    }

    public function addMemory(Request $r, MemoryRepo $memory): JsonResponse
    {
        $d = $r->validate(['text' => ['required', 'string', 'max:1000']]);
        $check = MemoryGuard::check($d['text']);
        if ($check !== 'ok') {
            return self::err($check === 'sensitive' ? 'This looks like a secret (password, key, card number) — it will not be remembered.' : 'The text is empty or too long.');
        }
        $id = CreateWorkspace::newId();
        $res = $memory->add(self::tenant($r), self::user($r), $id, 'user', 'other', MemoryGuard::normalize($d['text']), false, 'user', null);

        return $res === 'ok' ? response()->json(['id' => $id], 201) : self::err('Could not save: '.$res);
    }

    public function deleteMemory(Request $r, string $id, MemoryRepo $memory): JsonResponse
    {
        $res = $memory->delete(self::tenant($r), self::user($r), self::id($id), false);

        return $res === 'ok' ? response()->json(['ok' => true]) : self::err('Not found.', 404);
    }

    // ---------------------------------------------------------------- plan (drafts only)
    public function plan(Request $r, PcPlanner $planner, ModeRepo $modes): JsonResponse
    {
        $d = $r->validate(['goal' => ['required', 'string', 'min:3', 'max:1000']]);
        $t = self::tenant($r);
        $u = self::user($r);
        if ($modes->get($t, $u)['mode'] === 'companion') {
            return self::err(PcService::REASONS['companion_mode']);
        }
        $res = $planner->draft($t, $u, (string) $r->attributes->get('pc_device'), $d['goal'], CreateWorkspace::newId());
        if ($res['status'] === 'ok') {
            return response()->json(['status' => 'ok']);
        }

        return response()->json(['status' => (string) $res['status'], 'message' => PcService::plannerMessage((string) $res['status'], $res['reason'] ?? null)], 422);
    }

    // ---------------------------------------------------------------- voice (credit-metered like the website)
    public function listen(Request $r, VoiceService $voice): JsonResponse
    {
        $d = $r->validate(['audio_b64' => ['required', 'string', 'max:2400000'], 'mime' => ['nullable', 'string', 'max:40'], 'language' => ['nullable', 'string', 'max:3']]);
        $bytes = base64_decode($d['audio_b64'], true);
        if ($bytes === false || $bytes === '') {
            return self::err('The audio is not valid.');
        }
        $res = $voice->listen(self::tenant($r), self::user($r), $bytes, $d['language'] ?? null, CreateWorkspace::newId());
        if ($res['status'] === 'no_speech') {
            return response()->json(['text' => '']);
        }
        if ($res['status'] !== 'ok') {
            return self::err('voice_'.$res['status']);
        }

        return response()->json(['text' => (string) $res['text']]);
    }

    public function speak(Request $r, VoiceService $voice): JsonResponse
    {
        $d = $r->validate(['text' => ['required', 'string', 'max:2000'], 'language' => ['nullable', 'string', 'max:3'], 'voice' => ['nullable', 'string', 'max:20']]);
        $res = $voice->speak(self::tenant($r), self::user($r), $d['text'], $d['voice'] ?? 'alloy', CreateWorkspace::newId());
        if ($res['status'] !== 'ok') {
            return self::err('voice_'.$res['status']);
        }

        return response()->json(['audio_b64' => $res['audio_b64'], 'mime' => $res['mime']]);
    }
}
