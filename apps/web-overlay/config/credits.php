<?php

return [
    // Credits granted once to every new workspace (0 disables). Idempotent per workspace.
    'signup_grant' => (string) env('CREDITS_SIGNUP_GRANT', '100'),
    // Days the signup grant stays usable; empty = never expires.
    'signup_grant_days' => env('CREDITS_SIGNUP_GRANT_DAYS') === null || env('CREDITS_SIGNUP_GRANT_DAYS') === '' ? null : (int) env('CREDITS_SIGNUP_GRANT_DAYS'),
    // A reservation not settled within this many seconds is released by credits:release-stale.
    'reservation_ttl' => (int) env('CREDITS_RESERVATION_TTL', 900),

    // Chat pricing: credits per 1,000 tokens at multiplier x1. Each model's credit_multiplier scales these.
    'rate_input_per_1k' => (string) env('CREDITS_RATE_INPUT_PER_1K', '1'),
    'rate_output_per_1k' => (string) env('CREDITS_RATE_OUTPUT_PER_1K', '3'),

    // Voice pricing: credits per megabyte of audio listened to, and per 1,000 characters spoken, at multiplier x1.
    'voice_listen_per_mb' => (string) env('CREDITS_VOICE_LISTEN_PER_MB', '1'),
    'voice_speak_per_1k_chars' => (string) env('CREDITS_VOICE_SPEAK_PER_1K', '1'),
    // Image Studio: credits per AI picture at multiplier x1 (drawing the text, logo and sizes is free).
    // Slide videos: credits per second of finished video (a voice-over is charged separately, as speech).
    'video_per_second' => (string) env('CREDITS_VIDEO_PER_SECOND', '0.5'),
    'image_per_image' => (string) env('CREDITS_IMAGE_PER_IMAGE', '5'),
];
