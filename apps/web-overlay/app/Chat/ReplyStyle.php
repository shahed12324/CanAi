<?php

declare(strict_types=1);

namespace App\Chat;

/**
 * How the CAN AI desktop window wants the answer: the character's personality (tone only) and whether the reply will be READ ALOUD.
 * Only fixed, known values are accepted - the window's text is never copied into the prompt, so it cannot smuggle instructions in.
 * It changes tone and format only; it can never loosen the assistant's rules, which come before and after this block.
 */
final class ReplyStyle
{
    public const PERSONAS = [
        'friendly' => 'Tone: warm, friendly and encouraging.',
        'calm' => 'Tone: calm, steady and concise.',
        'playful' => 'Tone: light and playful, with a little humour, but still accurate.',
    ];

    public const SOURCES = ['text', 'voice'];

    private const VOICE = 'The answer will be read aloud by a speech engine: use short plain sentences, no tables, no code blocks, no markdown symbols, no URLs read out letter by letter, and keep it brief unless asked for detail.';

    /** The prompt block, or null when nothing (known) was asked for. A character conversation keeps its own persona, so only the voice rule applies there. */
    public static function block(?string $persona, ?string $source, bool $hasCharacter = false): ?string
    {
        $lines = [];
        if (! $hasCharacter && $persona !== null && isset(self::PERSONAS[$persona])) {
            $lines[] = self::PERSONAS[$persona];
        }
        if ($source === 'voice') {
            $lines[] = self::VOICE;
        }

        return $lines === [] ? null : implode("\n", $lines);
    }

    public static function personaOrNull(mixed $v): ?string
    {
        return is_string($v) && isset(self::PERSONAS[$v]) ? $v : null;
    }

    public static function sourceOrNull(mixed $v): ?string
    {
        return is_string($v) && in_array($v, self::SOURCES, true) ? $v : null;
    }
}
