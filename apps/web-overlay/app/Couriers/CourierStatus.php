<?php

declare(strict_types=1);

namespace App\Couriers;

/** Maps each courier's own wording to our five outcomes. Anything unknown is "attention": a person looks, nothing is guessed. */
final class CourierStatus
{
    /** @return 'in_transit'|'attention'|'delivered'|'returned'|'cancelled' */
    public static function map(string $courier, string $raw): string
    {
        $s = mb_strtolower(trim($raw));
        if ($courier === 'steadfast') {
            return match ($s) {
                'pending', 'in_review' => 'in_transit',
                'delivered' => 'delivered',
                'cancelled' => 'cancelled',
                default => 'attention',                       // partial_delivered, hold, unknown, *_approval_pending, anything new
            };
        }
        // Pathao and RedX report free text.
        if (preg_match('/\b(partial|hold|on hold|unknown|exception|lost|damage)/', $s) === 1) {
            return 'attention';
        }
        if (preg_match('/(un-?delivered|not delivered|failed|out for delivery|delivery (?:in progress|assigned|requested))/', $s) === 1) {
            return preg_match('/(failed|un-?delivered|not delivered)/', $s) === 1 ? 'attention' : 'in_transit';
        }
        if (preg_match('/\bdeliver(ed|y completed)\b/', $s) === 1) {
            return 'delivered';
        }
        if (preg_match('/(return|rto)/', $s) === 1) {
            return 'returned';
        }
        if (preg_match('/cancel/', $s) === 1) {
            return 'cancelled';
        }

        return 'in_transit';
    }
}
