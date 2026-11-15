<?php

declare(strict_types=1);

namespace App\Chat;

use App\Actions\Tenancy\CreateWorkspace;
use Illuminate\Support\Facades\DB;

/**
 * Chat persistence on the normal app login. The caller must have the tenant context set
 * (RLS then confines every query); user_id filters are explicit on top of that.
 */
final class PgChatStore implements ChatStore
{
    public function conversation(string $tenantId, string $userId, string $conversationId): ?array
    {
        $r = DB::table('conversations')->where('id', $conversationId)->where('tenant_id', $tenantId)
            ->where('user_id', $userId)->whereNull('deleted_at')->first(['id', 'title', 'model_id', 'mode', 'character_id']);

        return $r === null ? null : ['id' => $r->id, 'title' => $r->title, 'model_id' => $r->model_id, 'mode' => $r->mode, 'character_id' => $r->character_id === null ? null : trim((string) $r->character_id)];
    }

    public function createConversation(string $tenantId, string $userId, string $title, ?string $modelId, string $mode = 'general', ?string $characterId = null): string
    {
        $id = CreateWorkspace::newId();
        DB::table('conversations')->insert(['id' => $id, 'tenant_id' => $tenantId, 'user_id' => $userId, 'title' => $title, 'model_id' => $modelId, 'mode' => $mode, 'character_id' => $characterId]);

        return $id;
    }

    public function history(string $tenantId, string $conversationId): array
    {
        $rows = DB::table('messages')->where('tenant_id', $tenantId)->where('conversation_id', $conversationId)
            ->whereNull('hidden_at')->whereIn('status', ['complete', 'partial'])->orderBy('seq')->get(['id', 'role', 'content']);
        $files = DB::table('chat_attachments')->where('tenant_id', $tenantId)->whereIn('message_id', $rows->pluck('id'))
            ->orderBy('created_at')->get(['message_id', 'filename', 'extracted_text'])->groupBy('message_id');

        return $rows->map(fn ($m) => [
            'id' => $m->id, 'role' => $m->role, 'content' => $m->content,
            'attachments' => ($files[$m->id] ?? collect())->map(fn ($f) => ['filename' => $f->filename, 'text' => $f->extracted_text])->all(),
        ])->all();
    }

    public function addUserMessage(string $tenantId, string $userId, string $conversationId, string $text, array $attachments): string
    {
        return DB::transaction(function () use ($tenantId, $userId, $conversationId, $text, $attachments): string {
            $id = CreateWorkspace::newId();
            DB::table('messages')->insert(['id' => $id, 'tenant_id' => $tenantId, 'conversation_id' => $conversationId, 'role' => 'user', 'content' => $text, 'status' => 'complete']);
            foreach ($attachments as $a) {
                DB::table('chat_attachments')->insert([
                    'id' => CreateWorkspace::newId(), 'tenant_id' => $tenantId, 'conversation_id' => $conversationId, 'message_id' => $id, 'user_id' => $userId,
                    'filename' => mb_substr($a['filename'], 0, 255), 'mime' => mb_substr($a['mime'], 0, 100), 'size_bytes' => $a['size'], 'extracted_text' => $a['text'],
                ]);
            }

            return $id;
        });
    }

    public function startAssistant(string $tenantId, string $conversationId, string $modelId, string $providerId): string
    {
        $id = CreateWorkspace::newId();
        DB::table('messages')->insert(['id' => $id, 'tenant_id' => $tenantId, 'conversation_id' => $conversationId, 'role' => 'assistant', 'content' => '',
            'status' => 'streaming', 'model_id' => $modelId, 'provider_id' => $providerId]);

        return $id;
    }

    public function progress(string $messageId, string $content): void
    {
        DB::table('messages')->where('id', $messageId)->where('status', 'streaming')->update(['content' => $content, 'updated_at' => now()]);
    }

    public function finish(string $messageId, string $status, string $content, ?string $errorCode, ?int $inputTokens, ?int $outputTokens, string $creditsCharged, ?string $reservationId): void
    {
        DB::table('messages')->where('id', $messageId)->where('status', 'streaming')->update([
            'status' => $status, 'content' => $content, 'error_code' => $errorCode === null ? null : substr($errorCode, 0, 40),
            'input_tokens' => $inputTokens, 'output_tokens' => $outputTokens, 'credits_charged' => $creditsCharged,
            'reservation_id' => $reservationId, 'updated_at' => now(),
        ]);
    }

    public function touch(string $conversationId): void
    {
        DB::table('conversations')->where('id', $conversationId)->update(['last_message_at' => now(), 'updated_at' => now()]);
    }

    public function hide(string $messageId): void
    {
        DB::table('messages')->where('id', $messageId)->update(['hidden_at' => now()]);
    }
}
