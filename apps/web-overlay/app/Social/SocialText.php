<?php

declare(strict_types=1);

namespace App\Social;

/** Bengali wording for the social planner. */
final class SocialText
{
    public const PLATFORMS = ['facebook' => 'Facebook', 'instagram' => 'Instagram', 'youtube' => 'YouTube', 'tiktok' => 'TikTok', 'linkedin' => 'LinkedIn', 'other' => 'Other'];

    /** Characters a platform really allows in a caption; the planner warns past this (the database allows up to 3000). */
    public const LIMITS = ['facebook' => 3000, 'instagram' => 2200, 'youtube' => 3000, 'tiktok' => 2200, 'linkedin' => 3000, 'other' => 3000];

    public const STATUS = ['draft' => 'Drafts', 'pending' => 'Awaiting approval', 'approved' => 'Approved (scheduled)', 'posted' => 'Posted', 'cancelled' => 'Cancel'];

    public const MESSAGES = [
        'bad_title' => 'Give the post a name (max 120 characters).',
        'bad_platform' => 'The platform is not valid.',
        'bad_caption' => 'Write a caption (max 3000 characters).',
        'bad_time' => 'The time is not valid.',
        'bad_media' => 'The image or video must be your own created work (video "Created" in state, the design must have that size).',
        'bad_input' => 'The details are not valid.',
        'locked' => 'It can no longer be changed — first return it to draft.',
        'limit' => 'At most 500 posts can be kept. Delete some old ones.',
        'no_schedule' => 'To send for approval, first set the post time.',
        'not_found' => 'Not found, or this cannot be done in this state.',
        'bad_url' => 'The link must start with https:// (max 300 characters), or leave it blank.',
        'bad_account' => 'This page cannot be chosen (it must be an active page of this platform).',
        'busy' => 'A post is going to this page now — try shortly.',
        'bad_page' => 'The page ID must be digits only (5–30 digits).',
        'bad_token' => 'The token does not look right — copy it whole (with no spaces).',
        'mismatch' => 'This token belongs to another page — give the Page Access Token of the page whose ID you gave.',
        'token' => 'Facebook This token was not accepted (expired or no permission).',
        'rejected' => 'Facebook-could not be matched with.',
        'forbidden' => 'You do not have permission for.',
        'failed' => 'Could not be done. Try again.',
    ];

    public const CAPTION_MESSAGES = [
        'empty' => 'Write the post topic.',
        'brief_long' => 'The topic is at most 1500 characters.',
        'bad_platform' => 'The platform is not valid.',
        'bad_tone' => 'The tone is not valid.',
        'no_model' => 'No chat model is enabled for writing.',
        'no_credits' => 'Credits ran out.',
        'provider' => 'AI The service is not responding right now. Try again shortly.',
        'unreadable' => 'AI-The reply could not be read. Try again (credits were deducted).',
        'failed' => 'Could not be done. Try again.',
    ];

    public const PLAN_MESSAGES = [
        'empty' => 'Write what you want the plan to be about (business, offer, goal).',
        'brief_long' => 'The description is at most 1500 characters.',
        'bad_days' => 'Days must be between 1–14.',
        'bad_count' => 'The number of posts must be between 1–14.',
        'bad_platform' => 'Pick at least one platform.',
        'bad_tone' => 'The tone is not valid.',
        'person_cap' => 'You have already made 10 plans today — try again tomorrow.',
        'workspace_cap' => '30 plans have been made in this workspace today — try again tomorrow.',
        'no_model' => 'No chat model is enabled for writing.',
        'no_credits' => 'Credits ran out.',
        'provider' => 'AI The service is not responding right now. Try again shortly.',
        'unreadable' => 'AI-The reply could not be read. Try again (credits were deducted).',
        'failed' => 'Could not be done. Try again.',
    ];

    public const GROWTH_MESSAGES = [
        'too_little' => 'There are not yet enough posts for analysis (insights of at least 5 auto-posted posts are needed). Widen the days.',
        'goal_long' => 'The goal is at most 500 characters.',
        'no_model' => 'No chat model is enabled for writing.',
        'no_credits' => 'Credits ran out.',
        'provider' => 'AI The service is not responding right now. Try again shortly.',
        'unreadable' => 'AI-The reply could not be read. Try again (credits were deducted).',
        'failed' => 'Could not be done. Try again.',
    ];

    public const REPLY_MESSAGES = [
        'notes_long' => 'Business information is at most 500 characters.',
        'not_found' => 'The comment was not found.',
        'locked' => 'The reply to this comment can no longer be changed (approved, being sent or done).',
        'needs_human' => 'The AI could not safely write a reply to this comment (complaint/fact-dependent) — write it yourself. Credits were deducted.',
        'no_model' => 'No chat model is enabled for writing.',
        'no_credits' => 'Credits ran out.',
        'provider' => 'AI The service is not responding right now. Try again shortly.',
        'unreadable' => 'AI-The reply could not be read. Try again (credits were deducted).',
        'failed' => 'Could not be done. Try again.',
        'bad_input' => 'The reply must be 1–1000 characters.',
    ];

    public const COMMENT_STATUS = ['new' => 'New', 'drafted' => 'Draft (approval pending)', 'approved' => 'Approved — will be sent', 'replying' => 'Sending…', 'replied' => 'Reply sent', 'failed' => 'Could not be sent', 'ignored' => 'Dismissed'];

    public const GROUP_LABELS = ['weekday' => 'Day of week', 'hour' => 'Time of day', 'media' => 'Media type', 'length' => 'Caption length', 'tags' => 'Hashtags'];

    public const TONES = ['friendly' => 'Friendly', 'formal' => 'Formal', 'exciting' => 'Excited / sales-oriented', 'short' => 'Brief'];

    public static function message(string $code): string
    {
        return self::MESSAGES[$code] ?? self::MESSAGES['failed'];
    }

    public static function caption(string $code): string
    {
        return self::CAPTION_MESSAGES[$code] ?? self::CAPTION_MESSAGES['failed'];
    }

    public static function plan(string $code): string
    {
        return self::PLAN_MESSAGES[$code] ?? self::PLAN_MESSAGES['failed'];
    }

    public static function reply(string $code): string
    {
        return self::REPLY_MESSAGES[$code] ?? self::REPLY_MESSAGES['failed'];
    }

    public static function growth(string $code): string
    {
        return self::GROWTH_MESSAGES[$code] ?? self::GROWTH_MESSAGES['failed'];
    }

    /** Bengali label of a bucket key within a group. */
    public static function bucketLabel(string $group, string $key): string
    {
        $map = match ($group) {
            'weekday' => SocialGrowth::WEEKDAYS, 'hour' => SocialGrowth::HOURS, 'media' => SocialGrowth::MEDIA, 'length' => SocialGrowth::LENGTH, 'tags' => SocialGrowth::TAGS, default => [],
        };

        return $map[$key] ?? $key;
    }
}
