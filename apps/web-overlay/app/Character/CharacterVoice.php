<?php

declare(strict_types=1);

namespace App\Character;

use App\Voice\VoiceText;

/** Which of the workspace's speaking voices a character uses.  The character's own "voice" word wins when the speech service knows it; otherwise a calm default by kind. */
final class CharacterVoice
{
    private const DEFAULT = ['male' => 'onyx', 'female' => 'nova', 'custom' => 'alloy'];

    public static function pick(?string $voice, string $kind): string
    {
        $v = mb_strtolower(trim((string) $voice));
        if (isset(VoiceText::VOICES[$v])) {
            return $v;
        }

        return self::DEFAULT[$kind] ?? 'alloy';
    }
}
