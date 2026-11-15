<?php

declare(strict_types=1);

namespace App\Inbox\Agent;

/**
 * Last line of defence before an AI sentence reaches a customer. The model may be confused, tricked by the customer's text, or simply wrong,
 * so what it says is checked in code against what is actually known: prices must be real prices, links and discounts must come from the owner.
 */
final class ReplyGuard
{
    public const MAX_CHARS = 900;

    /**
     * @param  list<int>  $knownMinor  every price (paisa) the agent was shown: product, sale and variant prices
     * @param  string  $ownerText  the owner's business info: anything stated there may be repeated
     * @return string|null a reason to refuse, or null when the reply may be sent
     */
    public static function check(string $reply, array $knownMinor, string $ownerText): ?string
    {
        $r = trim($reply);
        if ($r === '' || mb_strlen($r) > self::MAX_CHARS) {
            return 'length';
        }
        $ownerLatin = self::latin($ownerText);
        $replyLatin = self::latin($r);

        // links and e-mail addresses only if the owner wrote them
        if (preg_match_all('~(?:https?://|www\.)[^\s)>\]]+|[\w.+-]+@[\w-]+\.[\w.-]+~iu', $replyLatin, $m) > 0) {
            foreach ($m[0] as $link) {
                if (stripos($ownerLatin, rtrim($link, '.,;!?')) === false) {
                    return 'link';
                }
            }
        }
        // percentages ("10% discount") are promises: only if the owner wrote the same percentage
        if (preg_match_all('/(\d+(?:\.\d+)?)\s*(?:%|শতাংশ|percent|persent)/iu', $replyLatin, $m) > 0) {
            foreach ($m[1] as $p) {
                if (! preg_match('/(?<![\d.])'.preg_quote($p, '/').'\s*(?:%|শতাংশ|percent)/iu', $ownerLatin)) {
                    return 'percent';
                }
            }
        }
        // every money amount must be a known price, or a number the owner wrote
        $ownerMinor = self::amounts($ownerLatin, false);
        $allowed = array_flip(array_merge($knownMinor, $ownerMinor));
        foreach (self::amounts($replyLatin, true) as $minor) {
            if (! isset($allowed[$minor])) {
                return 'price';
            }
        }

        return null;
    }

    /** Bengali digits -> Latin, so "১২৫০" and "1250" are the same. */
    public static function latin(string $s): string
    {
        return strtr($s, ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9']);
    }

    /**
     * Money amounts in text, in paisa. With $onlyMarked, only numbers touching a currency word/sign count (replies); otherwise
     * every plain number counts as allowed (owner text like "delivery 60").
     *
     * @return list<int>
     */
    public static function amounts(string $latin, bool $onlyMarked): array
    {
        $out = [];
        $num = '(\d{1,3}(?:,\d{2,3})+(?:\.\d{1,2})?|\d+(?:\.\d{1,2})?)';
        $cur = '(?:৳|tk\.?|taka|bdt|Amount|\/-)';
        if ($onlyMarked) {
            $found = [];
            if (preg_match_all("/$num\\s*$cur/iu", $latin, $a)) {
                $found = array_merge($found, $a[1]);
            }
            if (preg_match_all("/$cur\\s*$num/iu", $latin, $b)) {
                $found = array_merge($found, $b[1]);
            }
        } else {
            preg_match_all("/$num/u", $latin, $a);
            $found = $a[1];
        }
        foreach ($found as $f) {
            $out[] = (int) round(((float) str_replace(',', '', $f)) * 100);
        }

        return array_values(array_unique($out));
    }
}
