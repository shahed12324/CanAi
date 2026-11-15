<?php

declare(strict_types=1);

namespace App\Inbox\Agent\Orders;

/** A plain "yes" to an order summary — decided by code, never by the model. Anything with a negation or a change request is NOT a yes. */
final class Affirm
{
    private const YES = ['হ্যাঁ', 'হ্যা', 'হা', 'হাঁ', 'জি', 'জী', 'ঠিক', 'আছে', 'ওকে', 'ওকেই', 'ok', 'okay', 'yes', 'y', 'yep', 'yeah', 'confirm', 'confirmed', 'done', 'ha', 'han', 'haa', 'hya', 'hyan', 'ji', 'thik', 'thikache', 'ache', 'ase', 'kor', 'koren', 'korun', 'den', 'din', 'দিন', 'করুন', 'করে', 'দেন', 'কনফার্ম', 'নিশ্চিত', 'অর্ডার', 'order', 'sure', 'right', 'correct', 'sob', 'সব', 'পাঠান', 'pathan', 'thanks', 'thank', 'you', 'ধন্যবাদ'];

    private const CORE = ['হ্যাঁ', 'হ্যা', 'হা', 'হাঁ', 'জি', 'জী', 'ঠিক', 'ওকে', 'ok', 'okay', 'yes', 'y', 'yep', 'yeah', 'confirm', 'confirmed', 'done', 'ha', 'han', 'haa', 'hya', 'hyan', 'ji', 'thik', 'thikache', 'কনফার্ম', 'নিশ্চিত', 'sure', 'correct', 'right'];

    private const NO = ['না', 'no', 'na', 'nah', 'নয়', 'নাহ', 'not', 'dont', "don't", 'change', 'বদল', 'বদলান', 'পরিবর্তন', 'cancel', 'বাতিল', 'bad', 'wait', 'দাঁড়ান', 'ekhon', 'এখন', 'later', 'পরে', 'ভুল', 'wrong', 'but', 'কিন্তু', 'তবে'];

    public static function isYes(string $text): bool
    {
        $t = mb_strtolower(trim($text));
        if ($t === '' || mb_strlen($t) > 40) {
            return false;
        }
        preg_match_all('/[\p{L}\p{M}\p{N}\']+/u', $t, $m);
        $tokens = $m[0];
        if ($tokens === [] || count($tokens) > 6) {
            return false;
        }
        $core = false;
        foreach ($tokens as $w) {
            if (in_array($w, self::NO, true) || preg_match('/\d/u', $w) === 1) {
                return false;                       // a "no", a change, or new numbers (a phone / quantity) is not a plain yes
            }
            if (! in_array($w, self::YES, true)) {
                return false;
            }
            $core = $core || in_array($w, self::CORE, true);
        }

        return $core;
    }
}
