<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Providers\FortifyServiceProvider;
use App\Support\LoginLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Sanctum\PersonalAccessToken;

final class AuthController extends Controller
{
    private const MAX_TOKENS = 20;

    public const PAIR_PREFIX = 'mobile_pair:';

    public const PAIR_TTL_MINUTES = 5;

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:100'],
            'code' => ['nullable', 'string', 'max:10'],
            'recovery_code' => ['nullable', 'string', 'max:64'],
        ]);

        $user = User::where('email', Str::lower($data['email']))->first();
        $passwordOk = Hash::check($data['password'], $user?->password ?? FortifyServiceProvider::DUMMY_HASH);
        if ($user === null || ! $passwordOk || $user->status !== 'active') {
            LoginLog::record($user?->getKey(), $data['email'], 'failed');
            throw ValidationException::withMessages(['email' => 'The email or password is not correct.']);
        }
        if ($user->email_verified_at === null) {
            return response()->json(['message' => 'First verify your email.', 'code' => 'email_unverified'], 403);
        }

        if ($user->two_factor_secret !== null && $user->two_factor_confirmed_at !== null
            && ! $this->secondFactorPasses($user, $data)) {
            LoginLog::record($user->getKey(), $user->email, '2fa_failed');

            return response()->json([
                'message' => 'A two-step verification code is required or the code is not correct.',
                'code' => 'two_factor_required',
            ], 422);
        }

        return $this->issue($user, $data['device_name']);
    }

    /** Exchange a one-time QR code (made on the website by a signed-in user) for a phone token. */
    public function pair(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'size:40', 'regex:/\A[0-9a-f]{40}\z/'], 'device_name' => ['required', 'string', 'max:100']]);
        $userId = Cache::pull(self::PAIR_PREFIX.hash('sha256', $data['code']));        // pull = read and delete: a code works once
        $user = is_string($userId) ? User::find($userId) : null;
        if ($user === null || $user->status !== 'active' || $user->email_verified_at === null) {
            throw ValidationException::withMessages(['code' => 'This QR code is not valid or has expired. Make a new one on the website.']);
        }

        return $this->issue($user, $data['device_name']);
    }

    /** @return string the plain one-time code to put in a QR (valid PAIR_TTL_MINUTES) */
    public static function makePairCode(string $userId): string
    {
        $code = bin2hex(random_bytes(20));
        Cache::put(self::PAIR_PREFIX.hash('sha256', $code), $userId, now()->addMinutes(self::PAIR_TTL_MINUTES));

        return $code;
    }

    private function issue(User $user, string $deviceName): JsonResponse
    {
        // Keep the newest tokens only, so a lost device cannot pile up credentials forever.
        $stale = $user->tokens()->orderByDesc('id')->skip(self::MAX_TOKENS - 1)->take(1000)->pluck('id');
        if ($stale->isNotEmpty()) {
            $user->tokens()->whereKey($stale)->delete();
            foreach ($stale as $tokenId) {
                DB::select('select mobile_revoke_token(?, ?)', [(string) $user->getKey(), (int) $tokenId]);
            }
        }

        $expires = now()->addDays(30);
        $token = $user->createToken($deviceName, ['*'], $expires);
        LoginLog::record($user->getKey(), $user->email, 'success');
        DB::table('users')->where('id', $user->getKey())->update(['last_login_at' => now()]);

        return response()->json(['data' => [
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $expires->toIso8601String(),
            'user' => $this->userPayload($user),
        ]]);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            DB::select('select mobile_revoke_token(?, ?)', [(string) $request->user()->getKey(), $token->getKey()]);   // this phone stops receiving push
            $token->delete();
        }

        return response()->json(['data' => ['logged_out' => true]]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $workspaces = DB::table('tenant_users as tu')
            ->join('tenants as t', 't.id', '=', 'tu.tenant_id')
            ->where('tu.user_id', $user->getKey())->where('tu.status', 'active')
            ->where('t.status', 'active')->whereNull('t.deleted_at')
            ->orderBy('t.name')->get(['t.id', 't.name', 't.type']);

        return response()->json(['data' => ['user' => $this->userPayload($user), 'workspaces' => $workspaces]]);
    }

    public function tokens(Request $request): JsonResponse
    {
        return response()->json(['data' => $request->user()->tokens()->orderByDesc('id')
            ->get(['id', 'name', 'last_used_at', 'expires_at', 'created_at'])]);
    }

    public function revokeToken(Request $request, int $id): JsonResponse
    {
        abort_if($request->user()->tokens()->whereKey($id)->delete() === 0, 404);
        DB::select('select mobile_revoke_token(?, ?)', [(string) $request->user()->getKey(), $id]);

        return response()->json(['data' => ['revoked' => true]]);
    }

    /** @param  array<string, mixed>  $data */
    private function secondFactorPasses(User $user, array $data): bool
    {
        if (! empty($data['code'])) {
            return app(TwoFactorAuthenticationProvider::class)->verify(decrypt($user->two_factor_secret), $data['code']);
        }
        if (! empty($data['recovery_code'])) {
            foreach ($user->recoveryCodes() as $code) {
                if (hash_equals($code, $data['recovery_code'])) {
                    $user->replaceRecoveryCode($code);

                    return true;
                }
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function userPayload(User $user): array
    {
        return ['id' => $user->getKey(), 'name' => $user->name, 'email' => $user->email,
            'locale' => $user->locale, 'timezone' => $user->timezone];
    }
}
