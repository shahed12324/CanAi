<?php

declare(strict_types=1);

namespace App\Outreach;

use App\Proposals\TemplateFiller;

/** Builds the text that actually leaves: filled template + a footer that always carries an unsubscribe link. */
final class Compose
{
    /**
     * @param  array<string, string>  $vars
     * @return array{subject: string, body: string}
     */
    public static function fill(string $subjectTpl, string $bodyTpl, array $vars): array
    {
        // header injection: a subject is one line, whatever the client name contains
        $subject = trim((string) preg_replace('/[\r\n\t]+/', ' ', TemplateFiller::one($subjectTpl, $vars)));

        return ['subject' => mb_substr($subject, 0, 200), 'body' => str_replace(["\r\n", "\r"], "\n", TemplateFiller::one($bodyTpl, $vars))];
    }

    public static function footer(string $unsubscribeUrl, ?string $senderName, ?string $address): string
    {
        $who = trim(($senderName ?? '').($address !== null && $address !== '' ? ', '.$address : ''), ' ,');

        return "\n\n--\n".($who !== '' ? $who."\n" : '')."To stop receiving these emails:\n".$unsubscribeUrl."\n";
    }

    public static function finalBody(string $body, string $unsubscribeUrl, ?string $senderName, ?string $address, ?string $whatsappUrl = null): string
    {
        $wa = $whatsappUrl === null ? '' : "\nWhatsApp-Chat on WhatsApp:\n".$whatsappUrl."\n";

        return rtrim($body)."\n".$wa.self::footer($unsubscribeUrl, $senderName, $address);
    }

    /** @return array<string, string> */
    public static function headers(string $unsubscribeUrl): array
    {
        return ['List-Unsubscribe' => '<'.$unsubscribeUrl.'>', 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click'];
    }
}
