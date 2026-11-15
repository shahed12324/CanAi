<?php

declare(strict_types=1);

namespace App\Messaging;

use App\Ai\CredentialVault;
use Illuminate\Support\Facades\DB;

/** Sends as the platform bot. The encrypted token comes from a SECURITY DEFINER function and is decrypted only in memory. */
final class TelegramClient implements ChannelSender
{
    /** @var array<string, string> */
    private array $tokens = [];

    public function __construct(private readonly TelegramApi $api, private readonly CredentialVault $vault) {}

    public function sendText(string $accountId, string $chatId, string $text): void
    {
        $this->api->call($this->token($accountId), 'sendMessage', ['chat_id' => $chatId, 'text' => mb_substr($text, 0, 4096), 'disable_web_page_preview' => true]);
    }

    public function typing(string $accountId, string $chatId): void
    {
        try {
            $this->api->call($this->token($accountId), 'sendChatAction', ['chat_id' => $chatId, 'action' => 'typing']);
        } catch (ChannelSendException) {
            // cosmetic only
        }
    }

    public function answerCallback(string $accountId, string $callbackId, string $text): void
    {
        $this->api->call($this->token($accountId), 'answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => mb_substr($text, 0, 190)]);
    }

    public function download(string $accountId, string $fileId, int $maxBytes): ?string
    {
        $token = $this->token($accountId);
        $info = $this->api->call($token, 'getFile', ['file_id' => $fileId]);
        $size = (int) ($info['file_size'] ?? 0);
        $path = (string) ($info['file_path'] ?? '');
        if ($path === '' || $size <= 0 || $size > $maxBytes) {
            return null;
        }
        $body = $this->api->file($token, $path);

        return ($body === null || strlen($body) > $maxBytes) ? null : $body;
    }

    private function token(string $accountId): string
    {
        if (isset($this->tokens[$accountId])) {
            return $this->tokens[$accountId];
        }
        $r = DB::selectOne('select * from channel_account_for_send(?::char(26))', [$accountId]);
        if ($r === null) {
            throw new ChannelSendException('Bot is not active.');
        }

        return $this->tokens[$accountId] = $this->vault->decrypt($r->token_encrypted, (int) $r->token_key_version);
    }
}
