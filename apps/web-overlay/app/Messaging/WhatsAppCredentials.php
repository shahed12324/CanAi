<?php

declare(strict_types=1);

namespace App\Messaging;

use App\Ai\CredentialVault;
use Illuminate\Support\Facades\DB;

/**
 * The four secrets of one WhatsApp Cloud API number, kept together as one encrypted JSON bundle in
 * channel_accounts.token_encrypted: phone_number_id, access_token, app_secret, verify_token.
 */
final class WhatsAppCredentials
{
    /** @param array{phone_number_id: string, access_token: string, app_secret: string, verify_token: string} $v */
    public function __construct(public readonly array $v) {}

    public function phoneNumberId(): string
    {
        return $this->v['phone_number_id'];
    }

    public function accessToken(): string
    {
        return $this->v['access_token'];
    }

    public function appSecret(): string
    {
        return $this->v['app_secret'];
    }

    public function verifyToken(): string
    {
        return $this->v['verify_token'];
    }

    public function toJson(): string
    {
        return (string) json_encode($this->v, JSON_UNESCAPED_SLASHES);
    }

    public static function fromJson(string $json): ?self
    {
        $a = json_decode($json, true);
        if (! is_array($a)) {
            return null;
        }
        foreach (['phone_number_id', 'access_token', 'app_secret', 'verify_token'] as $k) {
            if (! isset($a[$k]) || ! is_string($a[$k]) || $a[$k] === '') {
                return null;
            }
        }

        return new self(['phone_number_id' => $a['phone_number_id'], 'access_token' => $a['access_token'], 'app_secret' => $a['app_secret'], 'verify_token' => $a['verify_token']]);
    }

    /** Meta signs the raw body: "sha256=" . HMAC-SHA256(app_secret, body). */
    public static function signatureValid(string $rawBody, string $header, string $appSecret): bool
    {
        if ($appSecret === '' || ! str_starts_with($header, 'sha256=')) {
            return false;
        }

        return hash_equals('sha256='.hash_hmac('sha256', $rawBody, $appSecret), $header);
    }

    /** Loads (and decrypts in memory) the bundle of one WhatsApp account; null when the account is not a WhatsApp one. @return array{creds: self, status: string}|null */
    public static function load(CredentialVault $vault, string $accountId): ?array
    {
        $r = DB::selectOne('select * from channel_account_for_webhook(?::char(26))', [$accountId]);
        if ($r === null) {
            return null;
        }
        $c = self::fromJson($vault->decrypt($r->token_encrypted, (int) $r->token_key_version));

        return $c === null ? null : ['creds' => $c, 'status' => (string) $r->status];
    }
}
