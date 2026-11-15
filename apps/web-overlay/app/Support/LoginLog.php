<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;

/** Append-only record of sign-in attempts (login_logs). Never throws into the auth flow. */
final class LoginLog
{
    public static function record(mixed $userId, ?string $email, string $result): void
    {
        try {
            $request = request();
            DB::table('login_logs')->insert([
                'user_id' => $userId,
                'email' => $email !== null ? mb_substr($email, 0, 255) : null,
                'ip' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 1000),
                'result' => $result,
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
