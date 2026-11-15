<?php

declare(strict_types=1);

namespace App\Voice;

/** Bengali messages for every outcome of the voice service. */
final class VoiceText
{
    public const MESSAGES = [
        'empty' => 'No recording was found.',
        'too_small' => 'The recording is too short — speak again.',
        'too_big' => 'The recording is large (max 5 MB, about 5 minutes). Speak shorter.',
        'bad_type' => 'This kind of audio is not accepted (webm, ogg, mp3, wav, m4a work).',
        'no_model' => 'No model is enabled for voice. The admin should enable the "audio_in" (listening) and "audio_out" (speaking) capabilities on a model.',
        'no_credits' => 'Credits ran out.',
        'provider' => 'The voice service is not responding now. Try again shortly.',
        'unsupported' => 'This provider does not support voice (a provider like OpenAI or Groq is needed).',
        'failed' => 'Could not be done. Try again.',
        'no_speech' => 'No speech could be understood — speak clearly again.',
        'empty_text' => 'The text is blank.',
        'too_long' => 'The text is too long (max 2000 characters).',
        'bad_voice' => 'The voice is not valid.',
        'bad_language' => 'The language is not valid.',
    ];

    public const VOICES = ['alloy' => 'Alloy', 'echo' => 'Echo', 'fable' => 'Fable', 'onyx' => 'Onyx', 'nova' => 'Nova', 'shimmer' => 'Shimmer'];

    public const LANGUAGES = ['' => 'Automatic', 'bn' => 'Bengali', 'en' => 'English', 'hi' => 'हिन्दी', 'ar' => 'العربية'];

    public static function message(string $code): string
    {
        return self::MESSAGES[$code] ?? 'Could not be done.';
    }
}
