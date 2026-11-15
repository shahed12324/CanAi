<?php

declare(strict_types=1);

namespace App\Messaging;

use App\Chat\ChatRequest;
use App\Chat\ChatService;
use App\Tenancy\TenantContext;

/** Runs one full chat turn (reserve credits -> AI -> settle) for a linked user and returns the whole answer. */
final class ServiceChatBridge implements ChatBridge
{
    public function __construct(private readonly ChatService $service, private readonly TenantContext $tenant) {}

    public function ask(string $tenantId, string $userId, ?string $conversationId, string $text, array $attachments): array
    {
        return $this->tenant->run($tenantId, function () use ($tenantId, $userId, $conversationId, $text, $attachments): array {
            $text = \App\Skills\SkillExpander::expand($tenantId, $userId, $text);   // "/skill-name request" works from Telegram, WhatsApp and scheduled jobs too
            $out = $this->turn(new ChatRequest($tenantId, $userId, $conversationId, $text, $attachments));
            if ($out['error_code'] === 'not_found' && $conversationId !== null) {     // conversation was deleted on the web: start a new one
                $out = $this->turn(new ChatRequest($tenantId, $userId, null, $text, $attachments));
            }

            return ['conversation_id' => $out['conversation_id'], 'text' => $out['text'], 'error' => $out['error'], 'credits' => $out['credits']];
        });
    }

    /** @return array{conversation_id: ?string, text: string, error: ?string, error_code: ?string, credits: ?string} */
    private function turn(ChatRequest $r): array
    {
        $conv = $r->conversationId;
        $text = '';
        $error = null;
        $code = null;
        $credits = null;
        foreach ($this->service->send($r) as $ev) {
            switch ($ev['type']) {
                case 'meta':
                    $conv = $ev['conversation_id'];
                    break;
                case 'delta':
                    $text .= $ev['text'];
                    break;
                case 'done':
                    $credits = (string) $ev['credits'];
                    break;
                case 'error':
                    $error = (string) $ev['message'];
                    $code = (string) $ev['code'];
                    $credits = $ev['credits'] ?? null;
                    break;
            }
        }

        return ['conversation_id' => $conv, 'text' => $text, 'error' => $error, 'error_code' => $code, 'credits' => $credits];
    }
}
