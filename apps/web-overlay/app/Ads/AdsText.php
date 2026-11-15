<?php

declare(strict_types=1);

namespace App\Ads;

/** Words and fixed lists for ad drafts.  Pure. */
final class AdsText
{
    public const OBJECTIVES = ['sales' => 'Sale', 'leads' => 'Leads / form', 'messages' => 'Messages', 'traffic' => 'Website visits', 'awareness' => 'Awareness'];

    public const CTAS = ['SHOP_NOW' => 'Shop now', 'LEARN_MORE' => 'Learn more', 'SIGN_UP' => 'Sign up', 'CONTACT_US' => 'Contact us', 'SEND_MESSAGE' => 'Send message', 'ORDER_NOW' => 'Order now', 'GET_OFFER' => 'Get offer'];

    public const GENDERS = ['all' => 'Everyone', 'men' => 'Male', 'women' => 'Female'];

    public const STATUS = ['draft' => 'draft', 'pending' => 'Awaiting approval', 'approved' => 'Allowed'];

    /** Objectives whose ad must open a web page. */
    public const NEEDS_LINK = ['sales', 'leads', 'traffic'];

    private const MESSAGES = [
        'not_found' => 'The draft was not found or this cannot be done in this state.', 'locked' => 'A submitted draft cannot be changed — first "Return to draft".', 'bad_input' => 'There is an error in the data — check the fields again.',
        'bad_account' => 'The ad account was not found.', 'limit' => 'At most 100 drafts can be kept — delete old ones.', 'incomplete' => 'The draft is incomplete — main text, headline, link and start date are needed.',
        'no_cap' => 'First set the spending limit (owner/admin) — without a limit nothing can be submitted or approved.', 'over_cap' => 'The budget exceeds the set limit — reduce the budget or days, or change the limit.',
        'emergency' => 'The emergency stop is on — approval will not happen now.', 'busy' => 'Drafts of this account are awaiting approval or being created in Meta — first return them to draft / let them finish.', 'failed' => 'Could not be done. Try again.',
        'unsupported' => 'This objective/button cannot be created in Meta yet — supported: website visits, awareness, sales ("Send message" except the button).', 'no_page' => 'Pick the Facebook page the ad will run from (an active connected page is needed).',
        'no_pixel' => 'Sales ads need the account’s Pixel ID — set the Pixel on the account on the ads page.', 'bad_page' => 'The page is not valid.',
        'launched' => 'Meta-Its objects were or may have been created in; they can no longer be changed from here — go to Ads Manager to check.', 'confirm' => 'The result of the earlier attempt is unknown — tick to confirm after checking in Ads Manager.',
    ];

    public static function message(string $code): string
    {
        return self::MESSAGES[$code] ?? self::MESSAGES['failed'];
    }
}
