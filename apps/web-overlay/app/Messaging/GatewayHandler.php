<?php

declare(strict_types=1);

namespace App\Messaging;

/**
 * Channel-neutral brain of the messaging gateway. Collaborators are interfaces, so the whole policy
 * (who may talk, what is ignored, how a link is made, how files are accepted) runs in plain tests.
 *
 * Rules that matter:
 *  - Unknown senders get NO answer, except a static "how to connect" text when they send /start or /link.
 *  - Bots, group chats and unsupported update types are ignored.
 *  - Only active members of active workspaces are resolved (checked in the database, every message).
 *  - Link-code guessing is rate limited per Telegram identity.
 */
final class GatewayHandler
{
    public const HELP = "CANAI Bot\n\nWrite your question directly and I will answer. If you send a text file (txt, md, csv…) I will read it and answer.\n\n/new — Start a new chat\n/unlink — Unlink this chat\n/help — Help";

    public const HOWTO = "To use this bot, log in to CANAI, take a code from Settings → Telegram or WhatsApp, then write it here:\n/link your-code";

    /** @param list<string> $allowedExtensions */
    public function __construct(
        private readonly ChannelStore $store,
        private readonly ChannelSender $sender,
        private readonly ChatBridge $chat,
        private readonly AttemptLimiter $limiter,
        private readonly ApprovalDecider $approvals,
        private readonly array $allowedExtensions = ['txt', 'md', 'csv', 'json', 'log', 'xml', 'yml', 'yaml', 'html', 'css', 'js', 'php', 'py', 'sql'],
        private readonly int $maxFileBytes = 204800,
        private readonly int $messagesPerMinute = 20,
        private readonly bool $showCost = true,
        private readonly ?VoiceInbound $voice = null,
    ) {}

    /** The update must already be claimed (channel_event_claim = 'new'). Never throws. */
    public function handle(string $accountId, InboundMessage $m): void
    {
        $tenantId = null;
        $linkId = null;
        try {
            [$outcome, $code, $tenantId, $linkId] = $this->route($accountId, $m, $tenantId, $linkId);
            $this->store->finish($accountId, $m->updateId, $outcome, $code, $tenantId, $linkId);
        } catch (\Throwable $e) {
            if (function_exists('report')) {
                report($e);
            }
            try {
                $this->store->finish($accountId, $m->updateId, 'error', 'exception', $tenantId, $linkId);
                if ($m->privateChat && $m->chatId !== '' && $m->kind !== InboundMessage::CALLBACK && $linkId !== null) {
                    $this->sender->sendText($accountId, $m->chatId, 'A temporary problem occurred. Try again shortly.');
                }
            } catch (\Throwable) {
                // nothing more can be done; the event stays 'processing' and the retry window reopens it
            }
        }
    }

    /** @return array{0: string, 1: ?string, 2: ?string, 3: ?string} outcome, code, tenant, link */
    private function route(string $accountId, InboundMessage $m, ?string &$seenTenant, ?string &$seenLink): array
    {
        if ($m->kind === InboundMessage::UNSUPPORTED && $m->fromId === '') {
            return ['ignored', 'unsupported_update', null, null];
        }
        if ($m->fromBot) {
            return ['ignored', 'bot_sender', null, null];
        }
        if (! $m->privateChat || $m->fromId === '' || $m->chatId === '') {
            return ['ignored', 'not_private_chat', null, null];
        }

        $link = $this->store->resolve($accountId, $m->fromId);
        $cmd = $m->kind === InboundMessage::TEXT ? self::command($m->text) : null;

        if ($cmd !== null && in_array($cmd[0], ['start', 'link'], true)) {
            return $this->linkFlow($accountId, $m, $link, $cmd[1]);
        }
        if ($link === null) {
            return ['ignored', 'unknown_sender', null, null];
        }
        $t = $seenTenant = $link['tenant_id'];
        $l = $seenLink = $link['link_id'];

        if ($m->kind === InboundMessage::CALLBACK) {
            return $this->callback($accountId, $m, $link);
        }
        if ($this->limiter->tooMany('chat:'.$l, $this->messagesPerMinute)) {
            $this->sender->sendText($accountId, $m->chatId, 'Messages are coming in too fast. Write again after a minute.');

            return ['ignored', 'rate_limited', $t, $l];
        }
        $this->limiter->hit('chat:'.$l, 60);

        if ($cmd !== null) {
            switch ($cmd[0]) {
                case 'help':
                    $this->sender->sendText($accountId, $m->chatId, self::HELP);

                    return ['processed', null, $t, $l];
                case 'new':
                    $this->store->setConversation($t, $l, null);
                    $this->sender->sendText($accountId, $m->chatId, 'A new chat has started. Write your question.');

                    return ['processed', null, $t, $l];
                case 'unlink':
                    $this->store->revoke($t, $l);
                    $this->sender->sendText($accountId, $m->chatId, 'This chat was unlinked. To link again, take a new code from the web.');

                    return ['processed', null, $t, $l];
                default:
                    $this->sender->sendText($accountId, $m->chatId, "Unknown command.\n\n".self::HELP);

                    return ['processed', 'unknown_command', $t, $l];
            }
        }

        $attachments = [];
        $text = $m->text;
        if ($m->kind === InboundMessage::VOICE && $this->voice !== null && $m->fileId !== null) {
            $heard = $this->listen($accountId, $m, $link);
            if (is_array($heard)) {
                return [$heard[0], $heard[1], $t, $l];
            }
            $text = $heard;
        } elseif ($m->kind === InboundMessage::PHOTO || $m->kind === InboundMessage::VOICE) {
            $this->sender->sendText($accountId, $m->chatId, $m->kind === InboundMessage::PHOTO
                ? 'Image analysis is not enabled yet. For now ask in writing or send a text file.'
                : 'Understanding voice messages is not enabled yet. For now ask in writing.');

            return ['ignored', 'unsupported_media', $t, $l];
        }
        if ($m->kind === InboundMessage::UNSUPPORTED) {
            return ['ignored', 'unsupported_update', $t, $l];
        }
        if ($m->kind === InboundMessage::DOCUMENT) {
            $file = $this->readDocument($accountId, $m);
            if (is_string($file)) {
                $this->sender->sendText($accountId, $m->chatId, $file);

                return ['ignored', 'file_rejected', $t, $l];
            }
            $attachments[] = $file;
            $text = trim($text) === '' ? 'Look at this file.' : $text;
        }
        if (trim($text) === '' && $attachments === []) {
            return ['ignored', 'empty', $t, $l];
        }

        $this->sender->typing($accountId, $m->chatId);
        $res = $this->chat->ask($t, $link['user_id'], $link['conversation_id'], $text, $attachments);
        if ($res['conversation_id'] !== null && $res['conversation_id'] !== $link['conversation_id']) {
            $this->store->setConversation($t, $l, $res['conversation_id']);
        }
        $this->store->touch($t, $l);

        $body = $res['text'] !== '' ? $res['text'] : (string) $res['error'];
        if ($res['text'] !== '' && $res['error'] !== null) {
            $body .= "\n\n⚠ ".$res['error'];
        }
        if ($this->showCost && $res['credits'] !== null && $res['error'] === null && $res['text'] !== '') {
            $body .= "\n\n— Credits: ".$res['credits'];
        }
        foreach (TextSplitter::split($body) as $part) {
            $this->sender->sendText($accountId, $m->chatId, $part);
        }

        return [$res['error'] !== null && $res['text'] === '' ? 'error' : 'processed', $res['error'] !== null ? 'chat_failed' : null, $t, $l];
    }

    private const VOICE_ERRORS = [
        'no_speech' => 'I could not hear any speech in that voice message.', 'too_small' => 'That voice message is too short.',
        'too_big' => 'That voice message is too long. Keep it under about a minute.', 'no_model' => 'Voice is not set up yet. Ask your admin to enable a speech model.',
        'no_credits' => 'Not enough credits for voice. Top up in CANAI.', 'bad_type' => 'I could not read that audio format.',
    ];

    /**
     * @param array{link_id: string, tenant_id: string, user_id: string, chat_id: string, conversation_id: ?string} $link
     * @return array{0: string, 1: ?string}|string a finished outcome (reply already sent) or the transcript to ask the AI
     */
    private function listen(string $accountId, InboundMessage $m, array $link): array|string
    {
        $this->sender->typing($accountId, $m->chatId);
        $audio = $this->sender->download($accountId, (string) $m->fileId, 5_000_000);
        if ($audio === null || $audio === '') {
            $this->sender->sendText($accountId, $m->chatId, 'I could not download that voice message. Try again.');

            return ['ignored', 'voice_download_failed'];
        }
        $r = $this->voice->handle($link['tenant_id'], $link['user_id'], $audio, 'wa'.$m->updateId);
        if ($r['status'] !== 'ok' || $r['transcript'] === null) {
            $this->sender->sendText($accountId, $m->chatId, self::VOICE_ERRORS[$r['status']] ?? 'I could not understand that voice message.');

            return ['ignored', 'voice_'.$r['status']];
        }
        $this->sender->sendText($accountId, $m->chatId, '🎤 '.mb_substr($r['transcript'], 0, 500));
        if ($r['reply'] !== null) {
            $this->store->touch($link['tenant_id'], $link['link_id']);
            $this->sender->sendText($accountId, $m->chatId, $r['reply']);

            return ['processed', null];
        }

        return $r['transcript'];
    }

    /** @param array{link_id: string, tenant_id: string, user_id: string, chat_id: string, conversation_id: ?string}|null $link */
    private function linkFlow(string $accountId, InboundMessage $m, ?array $link, string $arg): array
    {
        if ($link !== null) {
            $this->sender->sendText($accountId, $m->chatId, "This Telegram is already linked. Write directly to talk.\n\n".self::HELP);

            return ['processed', 'already_linked', $link['tenant_id'], $link['link_id']];
        }
        $key = 'link:'.$m->fromId;
        if ($arg === '') {
            $this->sender->sendText($accountId, $m->chatId, self::HOWTO);

            return ['processed', 'howto', null, null];
        }
        if ($this->limiter->tooMany($key, 5)) {
            $this->sender->sendText($accountId, $m->chatId, 'Wrong codes were given many times. Try again after 10 minutes.');

            return ['ignored', 'link_rate_limited', null, null];
        }
        $code = LinkCode::normalize($arg);
        $res = $code === '' ? ['ok' => false, 'reason' => 'invalid_code', 'tenant_id' => null, 'user_id' => null]
            : $this->store->redeem($accountId, LinkCode::hash($code), $m->fromId, $m->chatId, $m->username);
        if (! $res['ok']) {
            $this->limiter->hit($key, 600);
            $this->sender->sendText($accountId, $m->chatId, $res['reason'] === 'user_already_linked'
                ? 'Another Telegram is linked to your CANAI account. First unlink it from the web.'
                : 'The code is not correct or has expired. Take a new code from the web.');

            return ['ignored', 'link_failed', null, null];
        }
        $this->sender->sendText($accountId, $m->chatId, "The connection was successful! Now write your question directly.\n\n".self::HELP);

        return ['processed', 'linked', $res['tenant_id'], null];
    }

    /** @param array{link_id: string, tenant_id: string, user_id: string, chat_id: string, conversation_id: ?string} $link */
    private function callback(string $accountId, InboundMessage $m, array $link): array
    {
        $toast = 'This request is no longer valid.';
        if ($m->callbackData !== null && preg_match('/\Aap:([0-9A-Za-z]{26}):([ar])\z/', $m->callbackData, $x) === 1) {
            $toast = $this->approvals->decide($link['tenant_id'], $link['user_id'], $x[1], $x[2] === 'a');
        }
        if ($m->callbackId !== null) {
            $this->sender->answerCallback($accountId, $m->callbackId, $toast);
        }

        return ['processed', null, $link['tenant_id'], $link['link_id']];
    }

    /** @return array{filename: string, mime: string, size: int, text: string}|string  string = user-facing rejection */
    private function readDocument(string $accountId, InboundMessage $m): array|string
    {
        $name = (string) $m->fileName;
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($m->fileId === null || ! in_array($ext, $this->allowedExtensions, true)) {
            return 'I cannot read this type of file yet. Only text files ('.implode(', ', array_slice($this->allowedExtensions, 0, 6)).'…) send.';
        }
        if ($m->fileSize <= 0 || $m->fileSize > $this->maxFileBytes) {
            return 'The file is large. Max '.intdiv($this->maxFileBytes, 1024).' KB.';
        }
        $bytes = $this->sender->download($accountId, $m->fileId, $this->maxFileBytes);
        if ($bytes === null || $bytes === '' || strlen($bytes) > $this->maxFileBytes || ! mb_check_encoding($bytes, 'UTF-8') || str_contains($bytes, "\0")) {
            return 'The file could not be read. Give only a UTF-8 text file.';
        }

        return ['filename' => mb_substr($name, 0, 255), 'mime' => (string) ($m->mime ?? 'text/plain'), 'size' => strlen($bytes), 'text' => $bytes];
    }

    /** "/link@MyBot ABCD-1234" -> ['link', 'ABCD-1234'] ; null when not a command. */
    public static function command(string $text): ?array
    {
        if (preg_match('/\A\/([A-Za-z]{1,32})(?:@[A-Za-z0-9_]{3,64})?(?:\s+(.*))?\z/su', trim($text), $x) !== 1) {
            return null;
        }

        return [strtolower($x[1]), trim($x[2] ?? '')];
    }
}
