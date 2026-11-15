<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Mobile;

use App\Chat\ChatRequest;
use App\Chat\ChatService;
use App\Chat\ChatStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Chat for the phone. The answer comes back as one JSON reply (mobile networks and streaming do not mix well). Billing is identical to the web chat. */
final class ChatController extends MobileController
{
    public function conversations(Request $request): JsonResponse
    {
        return response()->json(['data' => DB::table('conversations')->where('user_id', $this->userId($request))->whereNull('deleted_at')
            ->orderByDesc('last_message_at')->limit(50)->get(['id', 'title', 'last_message_at'])]);
    }

    public function messages(Request $request, string $id): JsonResponse
    {
        abort_unless(preg_match('/\A[0-9a-z]{26}\z/', $id) === 1, 404);
        $c = DB::table('conversations')->where('id', $id)->where('user_id', $this->userId($request))->whereNull('deleted_at')->first(['id', 'title']);
        abort_if($c === null, 404);
        $m = DB::table('messages')->where('conversation_id', $id)->whereNull('hidden_at')->orderBy('seq')->get(['id', 'role', 'content', 'status', 'error_code']);

        return response()->json(['data' => ['conversation' => $c, 'messages' => $m]]);
    }

    public function send(Request $request, ChatService $service, ChatStore $store): JsonResponse
    {
        $d = $request->validate(['text' => ['required', 'string', 'min:1', 'max:20000'], 'conversation_id' => ['nullable', 'string', 'regex:/\A[0-9a-z]{26}\z/']]);
        ignore_user_abort(true);
        set_time_limit(150);
        $tenant = $this->tenant->idOrFail();
        $user = $this->userId($request);
        $model = null;
        if (($d['conversation_id'] ?? null) !== null && ($conv = $store->conversation($tenant, $user, $d['conversation_id'])) !== null) {
            $model = $conv['model_id'];
        }
        $out = ['conversation_id' => $d['conversation_id'] ?? null, 'message_id' => null, 'title' => null, 'text' => '', 'credits' => null, 'error' => null];
        foreach ($service->send(new ChatRequest($tenant, $user, $d['conversation_id'] ?? null, trim($d['text']), [], $model)) as $ev) {
            switch ($ev['type']) {
                case 'meta': $out['conversation_id'] = $ev['conversation_id']; $out['message_id'] = $ev['message_id']; $out['title'] = $ev['title']; break;
                case 'delta': $out['text'] .= $ev['text']; break;
                case 'done': $out['credits'] = $ev['credits']; break;
                case 'error': $out['error'] = ['code' => $ev['code'], 'message' => $ev['message']]; $out['credits'] = $ev['credits'] ?? null; break;
            }
        }

        return response()->json(['data' => $out], $out['error'] !== null && $out['text'] === '' ? 422 : 200);
    }
}
