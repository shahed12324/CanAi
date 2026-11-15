<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Ai\ModelCatalog;
use App\Chat\ChatRequest;
use App\Chat\ChatService;
use App\Chat\ChatStore;
use App\Credits\CreditService;
use App\Actions\Tenancy\CreateWorkspace;
use App\Knowledge\KnowledgeRepo;
use App\Vision\ImageAttachment;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** AI assistant: conversation list, history, streaming send (SSE), regenerate, rename/model, delete. */
final class ChatController extends Controller
{
    public const ERROR_TEXT = [
        'insufficient_credits' => 'There were not enough credits.', 'spending_limit_reached' => 'The monthly spending limit was reached.',
        'rate_limited' => 'The provider was busy.', 'timeout' => 'Time ran out.', 'interrupted' => 'The chat stopped midway.',
        'stopped' => 'You stopped it.', 'engine_error' => 'AI Could not connect to the service.',
    ];

    public function index(Request $request, TenantContext $tenant, ModelCatalog $catalog, CreditService $credits, KnowledgeRepo $kb)
    {
        return $this->render($request, $tenant, $catalog, $credits, $kb, null);
    }

    public function show(Request $request, string $id, TenantContext $tenant, ModelCatalog $catalog, CreditService $credits, KnowledgeRepo $kb)
    {
        abort_unless(preg_match('/\A[0-9a-z]{26}\z/', $id) === 1, 404);

        return $this->render($request, $tenant, $catalog, $credits, $kb, $id);
    }

    public function send(Request $request, TenantContext $tenant, ChatService $service, ChatStore $store, ImageAttachment $images): StreamedResponse|JsonResponse
    {
        $data = $request->validate([
            'text' => ['nullable', 'string', 'max:20000'],
            'conversation_id' => ['nullable', 'string', 'regex:/\A[0-9a-z]{26}\z/'],
            'model_id' => ['nullable', 'string', 'regex:/\A[0-9A-Za-z]{26}\z/'],
            'kb' => ['nullable', 'in:0,1'],
            'mode' => ['nullable', 'in:general,code'],
            'character_id' => ['nullable', 'string', 'regex:/\A[0-9a-z]{26}\z/'],
            'files' => ['nullable', 'array', 'max:'.config('chat.attach_max_files')],
            'files.*' => ['file', 'max:'.config('chat.attach_image_max_kb'), 'extensions:'.implode(',', config('chat.attach_extensions'))],
        ]);
        $text = trim((string) ($data['text'] ?? ''));
        $files = $this->extract($request->file('files', []), $images, $tenant->idOrFail(), (string) $request->user()->getAuthIdentifier());
        if (is_string($files)) {
            return response()->json(['message' => $files], 422);
        }
        if ($text === '' && $files === []) {
            return response()->json(['message' => 'Write a message.'], 422);
        }

        return $this->sse($request, $tenant, $service, $store, new ChatRequest(
            $tenant->idOrFail(), (string) $request->user()->getAuthIdentifier(), $data['conversation_id'] ?? null,
            \App\Skills\SkillExpander::expand($tenant->idOrFail(), (string) $request->user()->getAuthIdentifier(), $text === '' ? 'Look at this file.' : $text), $files, $data['model_id'] ?? null, ($data['kb'] ?? '1') !== '0', $data['mode'] ?? 'general', $data['character_id'] ?? null,
        ));
    }

    public function regenerate(Request $request, string $id, TenantContext $tenant, ChatService $service, ChatStore $store): StreamedResponse
    {
        abort_unless(preg_match('/\A[0-9a-z]{26}\z/', $id) === 1, 404);

        return $this->sse($request, $tenant, $service, $store, new ChatRequest($tenant->idOrFail(), (string) $request->user()->getAuthIdentifier(), $id, null, [], null, $request->input('kb') !== '0'));
    }

    public function update(Request $request, string $id, TenantContext $tenant, ModelCatalog $catalog)
    {
        abort_unless(preg_match('/\A[0-9a-z]{26}\z/', $id) === 1, 404);
        $allowed = array_column($catalog->candidates($tenant->idOrFail()), 'id');
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'min:1', 'max:200'],
            'model_id' => ['sometimes', 'nullable', 'string', \Illuminate\Validation\Rule::in($allowed)],
        ]);
        $n = DB::table('conversations')->where('id', $id)->where('user_id', $request->user()->getAuthIdentifier())->whereNull('deleted_at')
            ->update($data + ['updated_at' => now()]);
        abort_if($n === 0, 404);

        return $request->expectsJson() ? response()->json(['ok' => true]) : back();
    }

    public function destroy(Request $request, string $id)
    {
        abort_unless(preg_match('/\A[0-9a-z]{26}\z/', $id) === 1, 404);
        $n = DB::table('conversations')->where('id', $id)->where('user_id', $request->user()->getAuthIdentifier())->whereNull('deleted_at')
            ->update(['deleted_at' => now(), 'updated_at' => now()]);
        abort_if($n === 0, 404);

        return redirect()->route('chat.index')->with('status', 'The chat was deleted.');
    }

    // ---------------------------------------------------------------------------------------------

    /** @return list<array{id: string, name: string, greeting: string}> */
    private function characters(string $tenantId): array
    {
        return array_map(fn ($c) => ['id' => $c['id'], 'name' => $c['name'], 'greeting' => $c['greeting']], app(\App\Character\CharacterRepo::class)->all($tenantId));
    }

    private function render(Request $request, TenantContext $tenant, ModelCatalog $catalog, CreditService $credits, KnowledgeRepo $kb, ?string $id)
    {
        $tenantId = $tenant->idOrFail();
        $userId = (string) $request->user()->getAuthIdentifier();
        $current = null;
        $messages = collect();
        if ($id !== null) {
            $current = DB::table('conversations')->where('id', $id)->where('user_id', $userId)->whereNull('deleted_at')->first(['id', 'title', 'model_id', 'mode', 'character_id']);
            abort_if($current === null, 404);
            $messages = DB::table('messages')->where('conversation_id', $id)->whereNull('hidden_at')->orderBy('seq')
                ->get(['id', 'role', 'content', 'status', 'error_code', 'credits_charged']);
        }

        return view('chat.index', [
            'conversations' => DB::table('conversations')->where('user_id', $userId)->whereNull('deleted_at')->orderByDesc('last_message_at')->limit(50)->get(['id', 'title', 'mode', 'character_id']),
            'characters' => $this->characters($tenantId),
            'current' => $current,
            'messages' => $messages,
            'sources' => $kb->sourcesFor($messages->pluck('id')->map(fn ($m) => trim((string) $m))->all()),
            'models' => $catalog->candidates($tenantId),
            'wallet' => $credits->wallet($tenantId),
            'errors_text' => self::ERROR_TEXT,
            'maxFiles' => config('chat.attach_max_files'),
            'accept' => '.'.implode(',.', config('chat.attach_extensions')),
        ]);
    }

    private function sse(Request $request, TenantContext $tenant, ChatService $service, ChatStore $store, ChatRequest $chat): StreamedResponse
    {
        // A client-chosen conversation keeps its own model; a new one takes the model picked in the composer.
        if ($chat->conversationId !== null && ($conv = $store->conversation($chat->tenantId, $chat->userId, $chat->conversationId)) !== null && $conv['model_id'] !== null) {
            $chat = new ChatRequest($chat->tenantId, $chat->userId, $chat->conversationId, $chat->text, $chat->attachments, $conv['model_id'], $chat->useKnowledge, $chat->mode, $chat->characterId);
        }

        return response()->stream(function () use ($tenant, $service, $chat): void {
            // Keep going if the browser disappears: the answer must still be saved and billed correctly.
            ignore_user_abort(true);
            set_time_limit(0);
            while (ob_get_level() > 0) {
                ob_end_flush();
            }
            // The request middleware has already cleared the tenant context by the time a streamed body runs.
            $tenant->run($chat->tenantId, function () use ($service, $chat): void {
                foreach ($service->send($chat, fn (): bool => connection_aborted() === 1) as $event) {
                    $type = $event['type'];
                    unset($event['type']);
                    echo "event: {$type}\ndata: ".json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR)."\n\n";
                    flush();
                }
            });
        }, 200, [
            'Content-Type' => 'text/event-stream; charset=utf-8',
            'Cache-Control' => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',   // nginx: do not buffer the stream
            'Connection' => 'keep-alive',
        ]);
    }

    /**
     * @param  array<int, UploadedFile>  $files
     * @return list<array{filename: string, mime: string, size: int, text: string}>|string  a Bengali error message when a file cannot be used
     */
    private function extract(array $files, ImageAttachment $images, string $tenantId, string $userId): array|string
    {
        $out = [];
        $pictures = 0;
        foreach ($files as $f) {
            $raw = (string) file_get_contents($f->getRealPath());
            $name = (string) $f->getClientOriginalName();
            if (ImageAttachment::isImage($raw)) {
                if (++$pictures > ImageAttachment::MAX_IMAGES) {
                    return 'At most '.ImageAttachment::MAX_IMAGES.' images can be given at a time.';
                }
                $r = $images->build($tenantId, $userId, $name, $raw, CreateWorkspace::newId());
                if ($r['attachment'] === null) {
                    return (string) $r['error'];
                }
                $out[] = $r['attachment'];

                continue;
            }
            if (strlen($raw) > (int) config('chat.attach_max_kb') * 1024) {
                return 'Text file '.config('chat.attach_max_kb').' KB-cannot exceed.';
            }
            if (! mb_check_encoding($raw, 'UTF-8')) {
                return 'The file could not be read. Give only a UTF-8 text file or an image.';
            }
            $text = str_replace("\0", '', $raw);
            $out[] = [
                'filename' => preg_replace('/[^\p{L}\p{N}._ \-]/u', '_', $name) ?: 'file.txt',
                'mime' => 'text/plain', 'size' => (int) $f->getSize(), 'text' => mb_substr($text, 0, 190000),
            ];
        }

        return $out;
    }
}
