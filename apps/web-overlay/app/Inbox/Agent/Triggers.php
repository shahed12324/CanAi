<?php

declare(strict_types=1);

namespace App\Inbox\Agent;

/**
 * Cases the AI must not handle at all: no model call, no credits spent, a person takes over. Cheap, predictable, and checked first.
 * Bengali, English and Banglish (romanised Bengali) spellings; Banglish spelling varies a lot, so this list is a floor, not a guarantee.
 */
final class Triggers
{
    private const RULES = [
        'wants_human' => ['human', 'real person', 'live agent', 'customer care', 'manush', 'manusher shathe', 'kothar bolte chai', 'call me', 'phone korun', 'মানুষ', 'কর্মীর সাথে', 'কথা বলতে চাই', 'ফোন করুন', 'কল করুন', 'এজেন্ট'],
        'complaint' => ['complain', 'complaint', 'refund', 'money back', 'fraud', 'scam', 'cheat', 'fake product', 'lawyer', 'police', 'legal action', 'consumer', 'ferot', 'taka ferot', 'protarona', 'obhijog', 'অভিযোগ', 'ফেরত', 'প্রতারণা', 'প্রতারক', 'পুলিশ', 'আইনি', 'ভাঙা', 'নষ্ট', 'খারাপ পণ্য', 'ঠকিয়ে'],
        'order_intent' => ['i want to order', 'want to buy', 'place an order', 'place order', 'order korte', 'order korbo', 'order dibo', 'kinbo', 'kinte chai', 'nite chai', 'confirm order', 'অর্ডার করতে', 'অর্ডার করব', 'অর্ডার করবো', 'অর্ডার দিতে', 'কিনব', 'কিনবো', 'কিনতে চাই', 'নিতে চাই', 'অর্ডার কনফার্ম'],
        'order_status' => ['where is my order', 'order status', 'track my order', 'tracking', 'delivery hoy ni', 'order koresi', 'অর্ডার কোথায়', 'আমার অর্ডার', 'ট্র্যাকিং', 'পার্সেল কোথায়', 'এখনো পাইনি', 'পাইনি'],
    ];

    /** @return string|null why a person is needed, or null when the AI may try */
    public static function needsHuman(string $text): ?string
    {
        $t = mb_strtolower(trim($text));
        if ($t === '') {
            return 'empty';
        }
        if (preg_match('/\A\[[^\]]{2,60}\]\z/u', $t) === 1) {
            return 'attachment';                                     // "[sent a photo]": the AI cannot see files
        }
        foreach (self::RULES as $reason => $words) {
            foreach ($words as $w) {
                if (str_contains($t, $w)) {
                    return $reason;
                }
            }
        }

        return null;
    }

    public static function reasonBn(string $reason): string
    {
        return [
            'wants_human' => 'Customer wants to talk to a human',
            'complaint' => 'Complaint / return / suspicion',
            'order_intent' => 'Customer wants to place an order',
            'order_status' => 'Wants to know the order status',
            'attachment' => 'Customer sent a photo/file',
            'empty' => 'Blank message',
            'unsure' => 'AI Not sure',
            'guard' => 'AI-reply was stopped by the safety check',
            'no_model' => 'AI Model not enabled',
            'credits' => 'Credits ran out',
            'engine' => 'AI Cannot reply now',
            'limit' => 'AI reply limit per hour exceeded',
            'order_placed' => 'AI Took an order — confirm by phone',
            'order_turns' => 'It took too many messages to take the order',
            'order_stock' => 'Stock ran out / product off at order time',
            'order_failed' => 'AI Could not take the order',
        ][$reason] ?? $reason;
    }
}
