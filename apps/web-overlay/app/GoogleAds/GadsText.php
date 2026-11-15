<?php

declare(strict_types=1);

namespace App\GoogleAds;

/** Words and fixed lists for Google Ads drafts.  Pure. */
final class GadsText
{
    public const LANGUAGES = ['bn' => 'Bengali', 'en' => 'English', 'hi' => 'हिन्दी', 'ar' => 'العربية', 'ur' => 'اردو', 'ms' => 'Bahasa Melayu', 'id' => 'Bahasa Indonesia'];

    public const STATUS = ['draft' => 'draft', 'pending' => 'Awaiting approval', 'approved' => 'Allowed'];

    private const MESSAGES = [
        'not_found' => 'The draft was not found or this cannot be done in this state.', 'locked' => 'A submitted draft cannot be changed — first "Return to draft".', 'bad_input' => 'There is an error in the data — check the fields again.',
        'bad_account' => 'Google Ads The account was not found.', 'limit' => 'At most 100 drafts can be kept — delete old ones.',
        'incomplete' => 'The draft is incomplete — a link, start date, at least 3 headlines, 2 descriptions and 1 keyword are needed.',
        'no_cap' => 'First set the Google Ads spending limit (owner/admin) — without a limit nothing can be submitted or approved.', 'over_cap' => 'The budget exceeds the set limit — reduce the budget or days, or change the limit.',
        'unsupported' => 'This country is not supported yet (supported: BD, IN, PK, US, GB, AE, SA, MY, ID, SG, CA, AU).',
        'launched' => 'Google Ads-Its objects were or may have been created in; they can no longer be changed from here — go to Google Ads to check.', 'confirm' => 'The result of the earlier attempt is unknown — tick to confirm after checking in Google Ads.',
        'emergency' => 'The emergency stop is on — approval will not happen now.', 'failed' => 'Could not be done. Try again.',
    ];

    public static function message(string $code): string
    {
        return self::MESSAGES[$code] ?? self::MESSAGES['failed'];
    }
}
