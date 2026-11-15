<?php

declare(strict_types=1);

namespace App\Ai;

use Illuminate\Support\Facades\DB;

/** Reads providers with the normal login. The credential comes ONLY from provider_secret_for_call(). */
final class DbProviderStore implements ProviderStore
{
    public function provider(string $id): ?array
    {
        $r = DB::table('ai_providers')->where('id', $id)
            ->first(['id', 'adapter', 'base_url', 'timeout_ms', 'allow_private', 'status']);

        return $r === null ? null : [
            'id' => $r->id, 'adapter' => $r->adapter, 'base_url' => $r->base_url,
            'timeout_ms' => (int) $r->timeout_ms, 'allow_private' => (bool) $r->allow_private, 'status' => $r->status,
        ];
    }

    public function secret(string $providerId, bool $forTest = false): ?array
    {
        // forTest is the admin-only path (works for a not-yet-enabled provider); it needs the admin login.
        $r = $forTest
            ? DB::connection('admin')->selectOne('select secret_encrypted, key_version from provider_secret_for_test(?)', [$providerId])
            : DB::selectOne('select secret_encrypted, key_version from provider_secret_for_call(?)', [$providerId]);

        return $r === null ? null : ['cipher' => $r->secret_encrypted, 'version' => (int) $r->key_version];
    }
}
