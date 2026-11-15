<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/** Reads (app role) and writes (admin connection only) the system_settings table. */
final class SystemSettings
{
    private const CACHE_KEY = 'system_settings.v1';

    public static function get(string $key, mixed $default = null): mixed
    {
        $all = self::all();

        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        return filter_var(self::get($key, $default), FILTER_VALIDATE_BOOLEAN);
    }

    /** @return array<string, mixed> decoded values; empty when the table is not available yet. */
    public static function all(): array
    {
        try {
            return Cache::remember(self::CACHE_KEY, 30, function (): array {
                $out = [];
                foreach (DB::table('system_settings')->get(['key', 'value', 'is_encrypted']) as $row) {
                    $value = json_decode($row->value, true);
                    $out[$row->key] = $row->is_encrypted ? Crypt::decryptString((string) $value) : $value;
                }

                return $out;
            });
        } catch (\Throwable) {
            return [];   // e.g. during the first migration; failures are not cached
        }
    }

    /** Must run inside the admin request (admin connection). */
    public static function set(string $key, mixed $value, ?string $userId, bool $encrypted = false): void
    {
        $stored = $encrypted ? Crypt::encryptString((string) $value) : $value;
        DB::connection('admin')->table('system_settings')->upsert([[
            'key' => $key,
            'value' => json_encode($stored, JSON_UNESCAPED_UNICODE),
            'is_encrypted' => $encrypted,
            'updated_by' => $userId,
            'updated_at' => now(),
        ]], ['key'], ['value', 'is_encrypted', 'updated_by', 'updated_at']);
        Cache::forget(self::CACHE_KEY);
    }
}
