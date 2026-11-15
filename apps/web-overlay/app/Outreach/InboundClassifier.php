<?php

declare(strict_types=1);

namespace App\Outreach;

/**
 * Decides what an inbound mail is. Pure and conservative: when in doubt it says "other", which changes nothing.
 * kinds: bounce (delivery failure notice) | auto (out-of-office and other robots, NOT a reply) | reply | other
 */
final class InboundClassifier
{
    private const BOUNCE_SUBJECT = '/delivery status notification|undeliverable|undelivered mail|mail delivery (failed|subsystem|system)|returned mail|failure notice|delivery (has )?failed|message (could not be|couldn\'t be|wasn\'t) delivered|delivery incomplete/i';

    private const AUTO_SUBJECT = '/\A\s*(automatic reply|auto(matic)?[- :]?reply|auto:|out of office|out-of-office|vacation|away from)/i';

    /**
     * @param  array{from: string, subject: string, content_type?: ?string, auto_submitted?: ?string, precedence?: ?string, x_auto_response_suppress?: ?string}  $m
     * @param  list<string>  $own  addresses of the connected mailbox (our own sent copies are not replies)
     */
    public static function kind(array $m, array $own): string
    {
        $from = strtolower(trim($m['from']));
        if ($from === '' || in_array($from, array_map('strtolower', $own), true)) {
            return 'other';
        }
        $local = strstr($from, '@', true) ?: $from;
        $ct = strtolower((string) ($m['content_type'] ?? ''));
        if (in_array($local, ['mailer-daemon', 'postmaster', 'mail-daemon'], true) || str_contains($ct, 'report-type=delivery-status') || preg_match(self::BOUNCE_SUBJECT, $m['subject']) === 1) {
            return 'bounce';
        }
        $auto = strtolower(trim((string) ($m['auto_submitted'] ?? '')));
        $prec = strtolower(trim((string) ($m['precedence'] ?? '')));
        $suppress = trim((string) ($m['x_auto_response_suppress'] ?? ''));
        if (($auto !== '' && $auto !== 'no') || in_array($prec, ['bulk', 'junk', 'auto_reply', 'list'], true) || $suppress !== '' || preg_match(self::AUTO_SUBJECT, $m['subject']) === 1) {
            return 'auto';
        }

        return 'reply';
    }

    /**
     * The address that could not be reached, taken from the notice. null when it can not be told.
     *
     * @param  array{x_failed_recipients?: ?string, text?: ?string}  $m
     */
    public static function failedRecipient(array $m, array $own): ?string
    {
        $own = array_map('strtolower', $own);
        $cands = [];
        if (($m['x_failed_recipients'] ?? '') !== '' && preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', (string) $m['x_failed_recipients'], $x) === 1) {
            $cands[] = $x[0];
        }
        $text = (string) ($m['text'] ?? '');
        if (preg_match('/Final-Recipient:\s*rfc822;\s*([^\s;]+@[^\s;]+)/i', $text, $x) === 1) {
            $cands[] = $x[1];
        }
        if (preg_match('/(?:delivered to|deliver to|reach|find|recipient|address)\D{0,40}?<?([A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,})>?/i', $text, $x) === 1) {
            $cands[] = $x[1];
        }
        foreach ($cands as $c) {
            $c = strtolower(trim($c, " <>.,;:'\""));
            if (! in_array($c, $own, true) && filter_var($c, FILTER_VALIDATE_EMAIL) !== false) {
                return $c;
            }
        }

        return null;
    }
}
