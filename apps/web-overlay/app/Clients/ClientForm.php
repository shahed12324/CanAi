<?php

declare(strict_types=1);

namespace App\Clients;

/** Pure helpers for the client (agency mode) form. */
final class ClientForm
{
    /** Base64 of a PNG/JPEG logo, or null when the bytes are not really a PNG/JPEG image (or are over 600 KB). */
    public static function logo(string $raw): ?string
    {
        if (strlen($raw) > 600 * 1024) {
            return null;
        }
        if (! (str_starts_with($raw, "\x89PNG\r\n\x1a\n") || str_starts_with($raw, "\xFF\xD8\xFF")) || @getimagesizefromstring($raw) === false) {
            return null;
        }

        return base64_encode($raw);
    }

    /** '#AABBCC' → '#aabbcc'; anything that is not a 6-digit hex colour → null. */
    public static function colour(string $c): ?string
    {
        return preg_match('/\A#[0-9a-fA-F]{6}\z/', $c) === 1 ? strtolower($c) : null;
    }

    /** Bengali message for a database result code. */
    public static function message(string $kind, string $result): string
    {
        $m = [
            'save' => ['ok' => 'Client saved.', 'exists' => 'An active client with this name already exists.', 'limit' => 'At most 100 active clients can be kept; archive old ones.', 'missing' => 'The client was not found or is archived.'],
            'archive' => ['ok' => 'The client was archived; its accounts now belong to no one (the connection remains).', 'missing' => 'The client was not found.'],
            'assign' => ['ok' => 'Saved.', 'account' => 'The account was not found.', 'client' => 'The client was not found or is archived.'],
        ];

        return $m[$kind][$result] ?? 'The action could not be done.';
    }
}
