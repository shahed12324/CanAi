<?php

declare(strict_types=1);

namespace App\Messaging;

interface VoiceInbound
{
    /**
     * Turn a voice note into text and, when it is a spoken workflow command, run it.
     *
     * @return array{status: string, transcript: ?string, reply: ?string} status: ok|<VoiceService failure code>; reply is set when a command was handled (no AI chat needed)
     */
    public function handle(string $tenantId, string $userId, string $audio, string $requestId): array;
}
