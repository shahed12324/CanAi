<?php

declare(strict_types=1);

namespace App\Video;

/** Bengali messages for every outcome of the video studio. */
final class VideoText
{
    public const MESSAGES = [
        'bad_title' => 'Give the video a name (max 120 characters).',
        'bad_size' => 'The size is not valid.',
        'bad_scenes' => 'The video needs 1 to 10 scenes, each with at least one text.',
        'bad_seconds' => 'Each scene must be 2 to 8 seconds.',
        'too_long' => 'A video can be at most 60 seconds.',
        'bad_text' => 'The text is too long (headline 200, subtitle 400, button 100 characters).',
        'bad_theme' => 'The theme is not valid.',
        'bad_photo' => 'The image must be PNG, JPEG or WEBP (max 4 MB).',
        'bad_brand' => 'The brand kit’s colors or logo are not right — see the brand kit page.',
        'bad_voice' => 'The voice is not valid.',
        'bad_narration' => 'Voice-over text is at most 2000 characters.',
        'bad_captions' => 'To add subtitles you must write the voice-over text (subtitles come from that text).',
        'bad_captions' => 'To add subtitles you must write the voice-over text (subtitles come from that text).',
        'bad_video' => 'The video file could not be read. Give MP4/MOV/WEBM (max 25 MB).',
        'bad_trim' => 'After trimming at least 2 seconds must remain, and the end time must be after the start.',
        'clip_long' => 'An uploaded video can be at most 2 minutes.',
        'engine' => 'The video engine could not be reached. Check that the engine and FFmpeg are running.',
        'bad_prompt' => 'AI The image description must be at least 3 characters.',
        'too_many_ai' => 'AI images can be given in at most 5 scenes in one video.',
        'no_image_model' => 'AI No model is enabled for images. The admin should enable the "image_gen" capability on a model, or upload your own image.',
        'bad_music' => 'The music is not valid.',
        'limit' => 'No more than 3 videos can be made at a time, and at most 30 can be kept. Delete some or wait for them to finish.',
        'no_credits' => 'Credits ran out.',
        'no_model' => 'No text-to-speech model is enabled for the voice-over. Make it without a voice-over, or ask the admin "audio_out" to enable a model.',
        'provider' => 'The voice service is not responding now. Try again shortly.',
        'unsupported' => 'This provider cannot make voice.',
        'failed' => 'Could not be done. Try again.',
        'not_found' => 'Not found.',
        // reasons saved on a failed job
        'job_engine' => 'The video engine could not be reached. Credits were refunded.',
        'job_busy' => 'The video engine is busy or FFmpeg is missing. Credits were refunded.',
        'job_refused' => 'The engine did not agree to make the video (a problem with the text or image). Credits were refunded.',
        'job_silent' => 'The whole video is silent — nothing was left to cut. Credits were refunded.',
        'job_trim' => 'The trim time is not valid. Credits were refunded.',
        'job_badvideo' => 'The video file could not be read. Credits were refunded.',
        'job_store' => 'The video could not be saved. Credits were refunded.',
        'job_gone' => 'The video’s material was not found. Credits were refunded.',
    ];

    public const SCRIPT_MESSAGES = [
        'empty' => 'Write the video topic.',
        'brief_long' => 'The topic is at most 1500 characters.',
        'bad_count' => 'The number of scenes must be 1 to 10.',
        'no_model' => 'No chat model is enabled for writing.',
        'no_credits' => 'Credits ran out.',
        'provider' => 'AI The service is not responding right now. Try again shortly.',
        'unreadable' => 'AI-The reply could not be read. Try again (credits were deducted).',
        'failed' => 'Could not be done. Try again.',
    ];

    public const STATUS = ['queued' => 'In the queue', 'rendering' => 'Being made', 'done' => 'Created', 'failed' => 'Failed', 'cancelled' => 'Cancel'];

    public static function script(string $code): string
    {
        return self::SCRIPT_MESSAGES[$code] ?? self::SCRIPT_MESSAGES['failed'];
    }

    public static function message(string $code): string
    {
        return self::MESSAGES[$code] ?? self::MESSAGES['failed'];
    }
}
