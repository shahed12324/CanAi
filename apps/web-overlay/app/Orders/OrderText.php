<?php

declare(strict_types=1);

namespace App\Orders;

/** What the order screens say. Pure functions: easy to test, one place to change wording. */
final class OrderText
{
    public static function createError(string $result): ?string
    {
        return match ($result) {
            'ok' => null,
            'emergency' => 'The emergency stop is on — no new orders can be taken.',
            'bad_name' => 'Give the customer name.',
            'bad_phone' => 'The mobile number is not valid (for example 01712345678).',
            'bad_address' => 'Give the address in more detail (at least 10 characters).',
            'bad_zone' => 'Pick a delivery area.',
            'no_items', 'bad_item' => 'Give at least one product and quantity.',
            'unavailable' => 'A product is not for sale now (unpublished or closed).',
            'insufficient_stock' => 'Stock is not enough.',
            'mixed_currency' => 'Products in different currencies cannot be in the same order.',
            'discount_too_high' => 'The discount rate is out of range.',
            'too_many_open' => 'This number already has 3 orders in progress.',
            default => 'The order could not be created.',
        };
    }

    public static function transitionError(string $result): ?string
    {
        return match ($result) {
            'ok' => null,
            'emergency' => 'The emergency stop is on — orders cannot be confirmed.',
            'bad_transition' => 'Cannot move to that step from this state (the order’s state may have changed).',
            'not_found' => 'The order was not found.',
            default => 'The state could not be changed.',
        };
    }

    public static function statusBn(string $s): string
    {
        return ['pending' => 'awaiting confirmation', 'confirmed' => 'Confirmed', 'packed' => 'packed', 'shipped' => 'Sent', 'delivered' => 'Arrived', 'cancelled' => 'Cancel', 'returned' => 'return'][$s] ?? $s;
    }

    public static function riskBn(string $code): string
    {
        return ['prev_bad' => 'This number’s earlier order was cancelled/returned', 'dup_open' => 'there is another order in progress within 24 hours', 'fake_phone' => 'The number is suspicious (same digit repeated)', 'short_address' => 'The address is too short',
            'big_qty' => 'Unusually high quantity', 'big_value' => 'The order value is very high', 'trusted' => 'Received successfully before (risk reduced)'][$code] ?? $code;
    }

    /** The only moves the screen offers. @return list<array{0: string, 1: string}> [target status, button label] */
    public static function actions(string $status): array
    {
        return match ($status) {
            'pending' => [['confirmed', 'Confirm'], ['cancelled', 'Cancel']],
            'confirmed' => [['packed', 'packed'], ['cancelled', 'Cancel']],
            'packed' => [['shipped', 'Sent'], ['cancelled', 'Cancel']],
            'shipped' => [['delivered', 'Arrived'], ['returned', 'Bounced']],
            'delivered' => [['returned', 'Bounced']],
            default => [],
        };
    }

    public static function money(int $minor, string $currency = 'BDT'): string
    {
        $sym = $currency === 'BDT' ? '৳' : $currency.' ';

        return $sym.number_format($minor / 100, $minor % 100 === 0 ? 0 : 2);
    }

    /** Form lines -> clean items. Blank lines are skipped, duplicates are merged by the database. @return list<array{variant_id: string, qty: int}> */
    public static function items(mixed $lines): array
    {
        $out = [];
        foreach (is_array($lines) ? array_slice($lines, 0, 20) : [] as $l) {
            if (! is_array($l) || ! is_string($l['variant_id'] ?? null) || preg_match('/\A[0-9A-Za-z]{26}\z/', $l['variant_id']) !== 1) {
                continue;
            }
            $q = (int) ($l['qty'] ?? 0);
            if ($q >= 1 && $q <= 100) {
                $out[] = ['variant_id' => $l['variant_id'], 'qty' => $q];
            }
        }

        return $out;
    }
}
