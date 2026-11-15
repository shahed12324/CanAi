<?php

declare(strict_types=1);

namespace App\Couriers;

/** What the courier screens say. Pure. */
final class CourierText
{
    public static function name(string $courier): string
    {
        return ['steadfast' => 'Steadfast', 'pathao' => 'Pathao', 'redx' => 'RedX'][$courier] ?? $courier;
    }

    /** @return string|null null = success */
    public static function bookError(string $result, ?string $message = null): ?string
    {
        return match ($result) {
            'ok' => null,
            'not_found' => 'The order was not found.',
            'bad_status' => 'Only "Confirmed" or "packed" orders can be handed to the courier.',
            'emergency' => 'The emergency stop is on — no new parcel can be handed to the courier.',
            'no_account' => 'This courier’s account is not set up.',
            'account_disabled' => 'This courier account is disabled.',
            'already_booked' => 'A parcel is already in progress for this order.',
            'no_credentials' => 'The courier’s key could not be opened — save again in settings.',
            'refused' => 'The courier did not take the parcel: '.($message ?? ''),
            'uncertain' => 'Not sure whether the courier responded ('.($message ?? '').'). Check in the courier’s panel whether the parcel was created. If not, after 10 minutes "release" press; if done, the order by hand "Sent" do it yourself.',
            default => 'The parcel could not be booked.',
        };
    }

    public static function statusBn(string $s): string
    {
        return ['booking' => 'Booking in progress', 'booked' => 'Booked', 'in_transit' => 'On the way', 'attention' => 'Needs attention', 'delivered' => 'Arrived', 'returned' => 'return', 'cancelled' => 'Courier cancelled', 'failed' => 'Failed'][$s] ?? $s;
    }

    /** @return array<string, string> validation messages */
    public static function fieldLabel(string $f): string
    {
        return ['api_key' => 'API Key', 'secret_key' => 'Secret Key', 'client_id' => 'Client ID', 'client_secret' => 'Client Secret', 'username' => 'Email/username', 'password' => 'Password', 'access_token' => 'Access Token'][$f] ?? $f;
    }
}
